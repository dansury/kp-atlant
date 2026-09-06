<?php
/**
 * Catalog vectors — Yandex Cloud embeddings for «подходящие позиции» (module 009).
 *
 * The whole point is that indexing 1 200 positions on a shared host must not fall
 * over: one request per product done in sequence takes twenty minutes and dies on
 * the first `max_execution_time`, and firing all 1 200 at once earns a 429 and a
 * half-written index. So the run is
 *
 *   - **batched**: `VECTOR_BATCH` positions leave together through `curl_multi`;
 *   - **budgeted**: it stops at `VECTOR_BUDGET_SEC` and reports where it stopped;
 *   - **resumable**: a row is re-embedded only when the text it was built from
 *     changed, so the next step continues instead of starting over;
 *   - **patient**: 429 and 5xx go back into the queue with a growing pause, and a
 *     position that keeps failing is skipped, not fatal.
 *
 * Nothing here may ever break a KP: with no Yandex key, no vectors or a dead API
 * the search silently returns nothing and `ProductMatcher` stays lexical.
 */
final class Embeddings {

    private const ENDPOINT = 'https://llm.api.cloud.yandex.net/foundationModels/v1/textEmbedding';

    /**
     * Where the embeddings request goes. Same reason `OPENROUTER_BASE_URL` exists
     * (module 008): on a filtered network the provider is reached through a
     * mirror, and «поменять адрес» must not mean «поменять код».
     */
    private static function endpoint(): string {
        return trim((string)Settings::get('VECTOR_ENDPOINT', '')) ?: self::ENDPOINT;
    }

    /** Vectors are only worth reading in bulk once — a request matches many lines. */
    private static ?array $index = null;

    // ---- configuration -------------------------------------------------------

    public static function enabled(): bool {
        return (string)Settings::get('VECTOR_ENABLED', 1) === '1' && self::credentials() !== null;
    }

    /** [api key, folder id] or null when Yandex is not configured. */
    private static function credentials(): ?array {
        $key    = trim((string)Settings::get('YANDEX_API_KEY', ''));
        $folder = trim((string)Settings::get('YANDEX_FOLDER_ID', ''));
        return ($key !== '' && $folder !== '') ? [$key, $folder] : null;
    }

    private static function modelUri(string $model): string {
        [, $folder] = self::credentials() ?? ['', ''];
        return "emb://$folder/$model/latest";
    }

    private static function docModel(): string {
        return trim((string)Settings::get('VECTOR_MODEL_DOC', 'text-search-doc')) ?: 'text-search-doc';
    }

    private static function queryModel(): string {
        return trim((string)Settings::get('VECTOR_MODEL_QUERY', 'text-search-query')) ?: 'text-search-query';
    }

    // ---- indexing ------------------------------------------------------------

    /**
     * The text a product is embedded from. Name, article, characteristics and a
     * short description — the same things a manager reads to decide what a line
     * of a letter means.
     */
    public static function productText(array $p): string {
        $parts = array_filter([
            (string)($p['name'] ?? ''),
            (string)($p['characteristics'] ?? ''),
            (string)($p['category'] ?? ''),
            (string)($p['article'] ?? ''),
            mb_substr(trim((string)($p['description'] ?? '')), 0, 600),
        ], fn($v) => trim($v) !== '');
        return mb_substr(trim(implode('. ', $parts)), 0, 2000);
    }

    /** How much of the catalog is vectorized right now. */
    public static function stats(): array {
        $total = (int)Db::val("SELECT COUNT(*) FROM products_cache WHERE is_archived IS NOT 1");
        $rows  = (int)Db::val("SELECT COUNT(*) FROM product_vectors");
        return [
            'enabled'   => self::enabled(),
            'configured'=> self::credentials() !== null,
            'total'     => $total,
            'indexed'   => $rows,
            'pending'   => max(0, count(self::pending(100000))),
            'model'     => self::docModel(),
            'dim'       => (int)Db::val("SELECT dim FROM product_vectors LIMIT 1"),
            'updated_at'=> Db::val("SELECT MAX(updated_at) FROM product_vectors"),
        ];
    }

    /**
     * Catalog rows whose vector is missing or built from text that has since
     * changed. This query IS the resume cursor: nothing else has to be stored,
     * so an interrupted run never loses its place and never re-embeds a row
     * that is already current.
     */
    private static function pending(int $limit): array {
        $model = self::docModel();
        $rows = Db::all(
            "SELECT p.moysklad_id, p.name, p.article, p.category, p.characteristics, p.description,
                    v.text_hash AS have_hash, v.model AS have_model
             FROM products_cache p
             LEFT JOIN product_vectors v ON v.product_id = p.moysklad_id
             WHERE p.is_archived IS NOT 1 AND p.name IS NOT NULL AND p.name <> ''"
        );
        $out = [];
        foreach ($rows as $r) {
            $text = self::productText($r);
            if ($text === '') continue;
            $hash = md5($text);
            if ($r['have_hash'] === $hash && $r['have_model'] === $model) continue;
            $out[] = ['id' => (string)$r['moysklad_id'], 'text' => $text, 'hash' => $hash];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /**
     * One step of the indexing. Returns a report the panel and the cron both read;
     * `done` says whether anything is still waiting.
     *
     * @param array{budget?:int,batch?:int,limit?:int} $opts
     */
    public static function indexCatalog(array $opts = []): array {
        $report = ['enabled' => self::enabled(), 'indexed' => 0, 'failed' => 0, 'left' => 0,
                   'done' => true, 'elapsed' => 0.0, 'error' => null];
        if (!self::enabled()) {
            $report['error'] = self::credentials() === null
                ? 'Не заданы YANDEX_API_KEY и YANDEX_FOLDER_ID — векторизация выключена'
                : 'Векторный поиск выключен в настройках';
            return $report;
        }

        $started = microtime(true);
        $budget  = max(5, (int)($opts['budget'] ?? Settings::get('VECTOR_BUDGET_SEC', 20)));
        $batch   = max(1, min(50, (int)($opts['batch'] ?? Settings::get('VECTOR_BATCH', 20))));
        $pause   = max(0, (int)Settings::get('VECTOR_PAUSE_MS', 100)) * 1000;
        $deadline = $started + $budget;

        $queue = self::pending((int)($opts['limit'] ?? 100000));
        $report['left'] = count($queue);
        if (!$queue) return $report + ['elapsed' => 0.0];

        $model = self::docModel();
        foreach (array_chunk($queue, $batch) as $chunk) {
            if (microtime(true) >= $deadline) break;

            $vectors = self::embedMany(array_column($chunk, 'text'), $model, $deadline);
            foreach ($chunk as $i => $item) {
                $vec = $vectors[$i] ?? null;
                if (!$vec) { $report['failed']++; continue; }
                self::store($item['id'], $item['hash'], $model, $vec);
                $report['indexed']++;
            }
            if ($pause > 0 && microtime(true) < $deadline) usleep($pause);
        }

        $report['left']    = max(0, count(self::pending(100000)));
        $report['done']    = $report['left'] === 0;
        $report['elapsed'] = round(microtime(true) - $started, 2);
        self::$index = null;

        Logger::info('catalog', "Векторизация каталога: +{$report['indexed']}, осталось {$report['left']}"
            . ($report['failed'] ? ", неудач {$report['failed']}" : ''),
            ['elapsed' => $report['elapsed'], 'model' => $model]);
        return $report;
    }

    /** Drop the index — used when the model or the folder changes. */
    public static function reset(): int {
        $n = (int)Db::val("SELECT COUNT(*) FROM product_vectors");
        Db::q("DELETE FROM product_vectors");
        self::$index = null;
        Logger::info('catalog', "Индекс векторов очищен ($n позиций)");
        return $n;
    }

    private static function store(string $productId, string $hash, string $model, array $vec): void {
        $packed = self::pack(self::normalize($vec));
        $stmt = Db::pdo()->prepare(
            "INSERT INTO product_vectors (product_id, model, dim, text_hash, vec, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(product_id) DO UPDATE SET model=excluded.model, dim=excluded.dim,
                 text_hash=excluded.text_hash, vec=excluded.vec, updated_at=excluded.updated_at"
        );
        $stmt->bindValue(1, $productId);
        $stmt->bindValue(2, $model);
        $stmt->bindValue(3, count($vec), PDO::PARAM_INT);
        $stmt->bindValue(4, $hash);
        $stmt->bindValue(5, $packed, PDO::PARAM_LOB);
        $stmt->bindValue(6, date('Y-m-d H:i:s'));
        $stmt->execute();
    }

    // ---- HTTP: one batch, many requests in flight -----------------------------

    /**
     * Embed a batch in parallel. Never throws: a text that could not be embedded
     * comes back as null in its slot and the caller counts it as a failure.
     *
     * @return array<int,array<int,float>|null> same order as $texts
     */
    public static function embedMany(array $texts, string $model, ?float $deadline = null): array {
        $creds = self::credentials();
        if (!$creds) return array_fill(0, count($texts), null);
        [$key, ] = $creds;

        $uri     = self::modelUri($model);
        $headers = ['Content-Type: application/json', 'Authorization: Api-Key ' . $key,
                    'x-folder-id: ' . $creds[1]];
        $retries = max(0, (int)Settings::get('VECTOR_RETRIES', 3));

        $out     = array_fill(0, count($texts), null);
        $pending = array_keys($texts);
        $attempt = 0;

        while ($pending && $attempt <= $retries) {
            if ($deadline !== null && microtime(true) >= $deadline) break;

            $mh = curl_multi_init();
            $handles = [];
            foreach ($pending as $slot) {
                $ch = self::handle($uri, (string)$texts[$slot], $headers);
                curl_multi_add_handle($mh, $ch);
                $handles[$slot] = $ch;
            }

            // curl_multi_select() sleeps until a socket is ready; the busy loop it
            // replaces burns a whole CPU on a host that is shared with everyone else
            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running > 0) curl_multi_select($mh, 0.5);
            } while ($running > 0 && $status === CURLM_OK);

            $retry = [];
            foreach ($handles as $slot => $ch) {
                $body = (string)curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                $vec = self::readVector($body);
                if ($vec) { $out[$slot] = $vec; continue; }

                // 429 and 5xx are «позже», everything else is «не выйдет»
                if ($code === 429 || $code >= 500 || $code === 0) {
                    $retry[] = $slot;
                } else {
                    Logger::warning('catalog', "Эмбеддинг не получен (HTTP $code)", [
                        'body' => mb_substr($body, 0, 200), 'curl' => $err,
                    ]);
                }
            }
            curl_multi_close($mh);

            $pending = $retry;
            $attempt++;
            if ($pending && $attempt <= $retries) {
                // Growing pause: the rate limit wants time, not another burst
                usleep(min(4000000, 250000 * (1 << ($attempt - 1))));
            }
        }

        foreach ($pending as $slot) {
            Logger::warning('catalog', 'Эмбеддинг не получен после повторов', ['slot' => $slot]);
        }
        return $out;
    }

    /**
     * Embeddings for several search phrases at once. A letter with fourteen
     * positions would otherwise open the request card with fourteen sequential
     * HTTP requests; this makes it one batch, the same way the indexing works.
     *
     * @return array<int,array<int,float>|null> same order as $texts
     */
    public static function embedQueries(array $texts): array {
        if (!$texts || !self::enabled()) return array_fill(0, count($texts), null);
        return self::embedMany(array_values($texts), self::queryModel(), microtime(true) + self::timeout() * 3);
    }

    /** One embedding, used for a search query. Returns null on any failure. */
    public static function embedOne(string $text, ?string $model = null): ?array {
        $text = trim($text);
        if ($text === '' || !self::enabled()) return null;
        $vectors = self::embedMany([$text], $model ?? self::queryModel(), microtime(true) + self::timeout() * 2);
        return $vectors[0] ?? null;
    }

    private static function handle(string $uri, string $text, array $headers): CurlHandle {
        $ch = curl_init(self::endpoint());
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['modelUri' => $uri, 'text' => $text], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::timeout(),
            CURLOPT_CONNECTTIMEOUT => min(10, self::timeout()),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'AtlantArmourKP/1.0',
        ]);
        // The same filtered network the model calls go through (module 008)
        $proxy = trim((string)Settings::get('LLM_PROXY', ''));
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
            if (str_starts_with($proxy, 'socks5')) curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            $auth = trim((string)Settings::get('LLM_PROXY_AUTH', ''));
            if ($auth !== '') curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
        }
        return $ch;
    }

    private static function timeout(): int {
        return max(5, (int)Settings::get('VECTOR_TIMEOUT_SEC', 20));
    }

    private static function readVector(string $body): ?array {
        if ($body === '') return null;
        $data = json_decode($body, true);
        $vec  = $data['embedding'] ?? null;
        if (!is_array($vec) || !$vec) return null;
        return array_map('floatval', $vec);
    }

    // ---- search --------------------------------------------------------------

    /**
     * Nearest catalog rows to a phrase from a letter.
     * @return array<int,array{moysklad_id:string,score:float}> best first
     */
    public static function search(string $query, int $limit = 10): array {
        $vec = self::embedOne($query);
        if (!$vec) return [];
        return self::searchByVector($vec, $limit);
    }

    /** @return array<int,array{moysklad_id:string,score:float}> */
    public static function searchByVector(array $queryVec, int $limit = 10): array {
        $index = self::index();
        if (!$index) return [];

        $q = self::normalize($queryVec);
        $dim = count($q);
        $scored = [];
        foreach ($index as $productId => $vec) {
            if (count($vec) !== $dim) continue;   // a re-indexed catalog can hold two dimensions at once
            $dot = 0.0;
            foreach ($q as $i => $v) $dot += $v * $vec[$i];
            if ($dot > 0) $scored[$productId] = $dot;
        }
        arsort($scored);
        $out = [];
        foreach (array_slice($scored, 0, $limit, true) as $id => $score) {
            $out[] = ['moysklad_id' => (string)$id, 'score' => round($score, 4)];
        }
        return $out;
    }

    /** The whole index, unpacked once per request. 1 200 × 256 floats ≈ 2.5 MB. */
    private static function index(): array {
        if (self::$index !== null) return self::$index;
        self::$index = [];
        if (!Db::hasTable('product_vectors')) return self::$index;
        foreach (Db::all("SELECT product_id, vec FROM product_vectors") as $row) {
            $vec = self::unpack((string)$row['vec']);
            if ($vec) self::$index[(string)$row['product_id']] = $vec;
        }
        return self::$index;
    }

    public static function forgetIndex(): void { self::$index = null; }

    // ---- vector plumbing -----------------------------------------------------

    /** Stored normalized, so cosine similarity is a dot product and nothing else. */
    private static function normalize(array $vec): array {
        $sum = 0.0;
        foreach ($vec as $v) $sum += $v * $v;
        $norm = sqrt($sum);
        if ($norm <= 0) return $vec;
        return array_map(fn($v) => $v / $norm, $vec);
    }

    /** float32, little-endian — a quarter of the size of the JSON it replaces. */
    private static function pack(array $vec): string {
        return pack('g*', ...array_map('floatval', $vec));
    }

    private static function unpack(string $blob): array {
        if ($blob === '' || strlen($blob) % 4 !== 0) return [];
        $vals = unpack('g*', $blob);
        return $vals ? array_values($vals) : [];
    }
}
