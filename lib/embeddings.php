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
        return self::runIndex(
            fn(int $limit) => self::pending($limit),
            fn(array $item, string $model, array $vec) => self::store($item['id'], $item['hash'], $model, $vec),
            'каталога',
            $opts
        );
    }

    /**
     * The same step over the wiki sections (module 005). The base of knowledge is
     * searched the way the catalog is — words first, meaning as the second
     * opinion — so it is indexed by the same engine and not by a copy of it.
     */
    public static function indexKnowledge(array $opts = []): array {
        return self::runIndex(
            fn(int $limit) => self::pendingKnowledge($limit),
            fn(array $item, string $model, array $vec) => self::storeKnowledge($item['hash'], $model, $vec),
            'базы знаний',
            $opts
        );
    }

    /**
     * The indexing loop itself: batched, time-budgeted and resumable. The only
     * things that differ between the catalog and the wiki are where the queue
     * comes from and where a vector goes.
     *
     * @param callable(int):array $queueFn rows still needing a vector
     * @param callable(array,string,array):void $storeFn
     */
    private static function runIndex(callable $queueFn, callable $storeFn, string $what, array $opts = []): array {
        $report = ['enabled' => self::enabled(), 'indexed' => 0, 'failed' => 0, 'left' => 0,
                   'done' => true, 'elapsed' => 0.0, 'error' => null, 'http' => null];
        if (!self::enabled()) {
            $report['error'] = self::credentials() === null
                ? 'Не заданы YANDEX_API_KEY и YANDEX_FOLDER_ID — векторизация выключена'
                : 'Векторный поиск выключен в настройках';
            return $report;
        }

        $started  = microtime(true);
        $budget   = max(5, (int)($opts['budget'] ?? Settings::get('VECTOR_BUDGET_SEC', 20)));
        $batch    = max(1, min(50, (int)($opts['batch'] ?? Settings::get('VECTOR_BATCH', 20))));
        $pause    = max(0, (int)Settings::get('VECTOR_PAUSE_MS', 100)) * 1000;
        $deadline = $started + $budget;

        $queue = $queueFn((int)($opts['limit'] ?? 100000));
        $report['left'] = count($queue);
        if (!$queue) return $report + ['elapsed' => 0.0];

        $model = self::docModel();
        $failure = null;
        foreach (array_chunk($queue, $batch) as $chunk) {
            if (microtime(true) >= $deadline) break;

            $vectors = self::embedMany(array_column($chunk, 'text'), $model, $deadline);
            foreach ($chunk as $i => $item) {
                $vec = $vectors[$i] ?? null;
                if (!$vec) { $report['failed']++; continue; }
                $storeFn($item, $model, $vec);
                $report['indexed']++;
            }
            // The reason belongs to the batch that hit it: a later clean batch
            // must not erase why the earlier one came back empty
            $failure = self::lastFailure() ?: ($failure ?? null);
            if ($pause > 0 && microtime(true) < $deadline) usleep($pause);
        }

        $report['left']    = max(0, count($queueFn(100000)));
        $report['done']    = $report['left'] === 0;
        $report['elapsed'] = round(microtime(true) - $started, 2);
        self::$index = null;
        self::$knowledgeIndex = null;

        // A step that embedded nothing is not «идёт медленно», it is a wall —
        // and the panel has to name it instead of showing a mute progress bar.
        if ($report['failed'] > 0 && ($f = $failure)) {
            $report['http']  = (int)$f['code'];
            $report['error'] = self::explainFailure($f);
        }

        Logger::info('catalog', "Векторизация $what: +{$report['indexed']}, осталось {$report['left']}"
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

    // ---- knowledge base vectors (modules 005 + 009) --------------------------

    /** Unpacked wiki vectors, section id => vector. Read once per request. */
    private static ?array $knowledgeIndex = null;

    /**
     * Wiki sections with no vector yet. The queue is keyed by the section's
     * `text_hash` — the same hash `Knowledge::vectorText()` builds — so a wiki
     * that is re-read from GitHub keeps the vectors of every section whose text
     * did not move, and an interrupted run continues where it stopped.
     */
    private static function pendingKnowledge(int $limit): array {
        if (!Db::hasTable('knowledge_sections') || !Db::hasTable('knowledge_vectors')) return [];
        $model = self::docModel();
        $rows = Db::all(
            "SELECT s.text_hash, s.title, s.heading, s.tags, s.body
             FROM knowledge_sections s
             LEFT JOIN knowledge_vectors v ON v.text_hash = s.text_hash AND v.model = ?
             WHERE v.text_hash IS NULL AND s.text_hash <> ''
             GROUP BY s.text_hash",
            [$model]
        );
        $out = [];
        foreach ($rows as $r) {
            $text = Knowledge::vectorText($r);
            if ($text === '') continue;
            $out[] = ['id' => (string)$r['text_hash'], 'text' => $text, 'hash' => (string)$r['text_hash']];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** How much of the wiki is vectorized right now. */
    public static function knowledgeStats(): array {
        $has = Db::hasTable('knowledge_sections') && Db::hasTable('knowledge_vectors');
        $total = $has ? (int)Db::val("SELECT COUNT(DISTINCT text_hash) FROM knowledge_sections WHERE text_hash <> ''") : 0;
        $rows  = $has ? (int)Db::val(
            "SELECT COUNT(*) FROM knowledge_vectors v
             WHERE EXISTS (SELECT 1 FROM knowledge_sections s WHERE s.text_hash = v.text_hash)") : 0;
        return [
            'enabled'   => self::knowledgeEnabled(),
            'configured'=> self::credentials() !== null,
            'total'     => $total,
            'indexed'   => min($rows, $total),
            'pending'   => $has ? count(self::pendingKnowledge(100000)) : 0,
            'model'     => self::docModel(),
            'dim'       => $has ? (int)Db::val("SELECT dim FROM knowledge_vectors LIMIT 1") : 0,
            'updated_at'=> $has ? Db::val("SELECT MAX(updated_at) FROM knowledge_vectors") : null,
        ];
    }

    /** Vectors over the wiki are a second opinion and can be switched off alone. */
    public static function knowledgeEnabled(): bool {
        return self::enabled() && (string)Settings::get('KNOWLEDGE_VECTORS', 1) === '1';
    }

    public static function resetKnowledge(): int {
        if (!Db::hasTable('knowledge_vectors')) return 0;
        $n = (int)Db::val("SELECT COUNT(*) FROM knowledge_vectors");
        Db::q("DELETE FROM knowledge_vectors");
        self::$knowledgeIndex = null;
        Logger::info('knowledge', "Векторы базы знаний очищены ($n разделов)");
        return $n;
    }

    /**
     * Wiki sections closest in meaning to the letter.
     * @return array<int,array{section_id:int,score:float}> best first
     */
    public static function searchKnowledge(string $query, int $limit = 5): array {
        if (!self::knowledgeEnabled()) return [];
        $vec = self::embedOne($query);
        if (!$vec) return [];

        $index = self::knowledgeIndex();
        if (!$index) return [];
        $q = self::normalize($vec);
        $dim = count($q);
        $scored = [];
        foreach ($index as $sectionId => $sv) {
            if (count($sv) !== $dim) continue;
            $dot = 0.0;
            foreach ($q as $i => $v) $dot += $v * $sv[$i];
            if ($dot > 0) $scored[$sectionId] = $dot;
        }
        arsort($scored);
        $out = [];
        foreach (array_slice($scored, 0, $limit, true) as $id => $score) {
            $out[] = ['section_id' => (int)$id, 'score' => round($score, 4)];
        }
        return $out;
    }

    /** section id => vector, joined through the hash the section was indexed by. */
    private static function knowledgeIndex(): array {
        if (self::$knowledgeIndex !== null) return self::$knowledgeIndex;
        self::$knowledgeIndex = [];
        if (!Db::hasTable('knowledge_vectors') || !Db::hasTable('knowledge_sections')) return self::$knowledgeIndex;
        foreach (Db::all("SELECT s.id, v.vec FROM knowledge_sections s
                          JOIN knowledge_vectors v ON v.text_hash = s.text_hash") as $row) {
            $vec = self::unpack((string)$row['vec']);
            if ($vec) self::$knowledgeIndex[(int)$row['id']] = $vec;
        }
        return self::$knowledgeIndex;
    }

    public static function forgetKnowledgeIndex(): void { self::$knowledgeIndex = null; }

    private static function storeKnowledge(string $hash, string $model, array $vec): void {
        $stmt = Db::pdo()->prepare(
            "INSERT INTO knowledge_vectors (text_hash, model, dim, vec, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(text_hash) DO UPDATE SET model=excluded.model, dim=excluded.dim,
                 vec=excluded.vec, updated_at=excluded.updated_at"
        );
        $stmt->bindValue(1, $hash);
        $stmt->bindValue(2, $model);
        $stmt->bindValue(3, count($vec), PDO::PARAM_INT);
        $stmt->bindValue(4, self::pack(self::normalize($vec)), PDO::PARAM_LOB);
        $stmt->bindValue(5, date('Y-m-d H:i:s'));
        $stmt->execute();
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
     * Why the last batch failed, for the report, the log and the panel. A run
     * that ends with «не получил эмбеддинг» has to say WHICH wall it hit —
     * a wrong key, a folder without rights, a rate limit and a host that cannot
     * open the connection at all look identical from the progress bar.
     *
     * @var array{code:int,curl:string,body:string,count:int}|null
     */
    private static ?array $lastFailure = null;

    /**
     * Some shared hosts answer every curl_multi handle with code 0 while a plain
     * curl_exec to the same URL works. The first all-zero wave flips this on and
     * the rest of the run goes one request at a time — slower, but it finishes.
     */
    private static bool $solo = false;

    public static function lastFailure(): ?array { return self::$lastFailure; }

    /** What the operator has to fix, in one sentence. */
    public static function explainFailure(?array $f = null): string {
        $f = $f ?? self::$lastFailure;
        if (!$f) return '';
        $code = (int)$f['code'];
        $body = trim((string)$f['body']);
        $msg = match (true) {
            $code === 0   => 'запрос до Yandex Cloud не ушёл (сеть, TLS или прокси хостинга)'
                             . ($f['curl'] !== '' ? ': ' . $f['curl'] : ''),
            $code === 401 => 'Yandex отклонил ключ (401) — проверьте «Ключ Yandex»',
            $code === 403 => 'нет доступа к каталогу Yandex (403) — проверьте Folder ID и права сервисного аккаунта',
            $code === 404 => 'адрес или модель эмбеддингов не найдены (404)',
            $code === 429 => 'лимит запросов Yandex (429) — уменьшите «Позиций в одной пачке» и «Параллельных запросов»',
            $code >= 500  => "Yandex ответил HTTP $code — сервис недоступен, шаг можно повторить позже",
            default       => "Yandex ответил HTTP $code",
        };
        return $msg . ($body !== '' ? '. Ответ: ' . mb_substr($body, 0, 200) : '');
    }

    /**
     * Embed a batch. Never throws: a text that could not be embedded comes back
     * as null in its slot and the caller counts it as a failure.
     *
     * @return array<int,array<int,float>|null> same order as $texts
     */
    public static function embedMany(array $texts, string $model, ?float $deadline = null): array {
        $creds = self::credentials();
        if (!$creds) return array_fill(0, count($texts), null);
        [$key, $folder] = $creds;

        $uri     = self::modelUri($model);
        $headers = ['Content-Type: application/json', 'Authorization: Api-Key ' . $key,
                    'x-folder-id: ' . $folder];
        $retries = max(0, (int)Settings::get('VECTOR_RETRIES', 3));
        // A whole batch in flight at once is what earns the 429. The batch stays
        // the unit of work; the wave is how many of it are on the wire together.
        $conc    = max(1, min(50, (int)Settings::get('VECTOR_CONCURRENCY', 5)));

        $out     = array_fill(0, count($texts), null);
        $pending = array_keys($texts);
        $attempt = 0;
        $fail    = null;
        $skipped = 0;

        while ($pending && $attempt <= $retries) {
            if ($deadline !== null && microtime(true) >= $deadline) break;

            $retry = [];
            $left  = [];
            foreach (array_chunk($pending, $conc) as $i => $waveSlots) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $left = array_merge($left, $waveSlots);   // never tried — not a failure
                    continue;
                }
                $results = self::wave($waveSlots, $texts, $uri, $headers, $deadline);

                $zero = 0;
                foreach ($results as $slot => $r) {
                    if (!empty($r['vec'])) { $out[$slot] = $r['vec']; continue; }
                    $fail = ['code' => (int)$r['code'], 'curl' => (string)$r['curl'],
                             'body' => (string)$r['body'], 'count' => 1 + (int)($fail['count'] ?? 0)];
                    if ((int)$r['code'] === 0) $zero++;
                    // 429 and 5xx are «позже», a connection that never opened is
                    // «может быть», everything else is «не выйдет»
                    if ($r['code'] === 429 || $r['code'] >= 500 || $r['code'] === 0) $retry[] = $slot;
                }
                // Every handle of a full wave came back with nothing at all:
                // curl_multi is not usable on this host — finish one by one.
                if (!self::$solo && $zero === count($waveSlots) && $zero > 1) {
                    self::$solo = true;
                    Logger::warning('catalog', 'curl_multi не отвечает — векторизация переходит на одиночные запросы',
                                    ['wave' => count($waveSlots), 'curl' => (string)($fail['curl'] ?? '')]);
                }
                if ($i > 0 || count($waveSlots) === $conc) {
                    $pauseMs = max(0, (int)Settings::get('VECTOR_PAUSE_MS', 100));
                    if ($pauseMs > 0) usleep($pauseMs * 1000);
                }
            }

            // Slots the budget cut off were never tried: they are not failures
            // and must not be logged as «не получен» — the next step picks them
            // up from the queue like any other row without a vector.
            $skipped = count($left);
            $pending = $retry;
            $attempt++;
            if (!$retry) break;
            if ($attempt <= $retries) {
                // Growing pause: the rate limit wants time, not another burst
                usleep(min(4000000, 250000 * (1 << ($attempt - 1))));
            }
        }

        self::$lastFailure = $fail;
        if ($pending) {
            // ONE line for the whole batch, and it says what went wrong: forty
            // copies of «слот 0» tell the operator nothing he can act on.
            Logger::warning('catalog',
                'Эмбеддинг не получен: ' . count($pending) . ' из ' . count($texts) . ' — ' . self::explainFailure($fail),
                ['code' => (int)($fail['code'] ?? 0), 'curl' => (string)($fail['curl'] ?? ''),
                 'body' => mb_substr((string)($fail['body'] ?? ''), 0, 200),
                 'endpoint' => self::endpoint(), 'model' => $model, 'solo' => self::$solo,
                 'skipped' => $skipped]);
        }
        return $out;
    }

    /**
     * One wave of requests. Parallel through curl_multi, or one after another
     * when this host has shown that curl_multi gives nothing back.
     *
     * @return array<int,array{vec:?array,code:int,curl:string,body:string}>
     */
    private static function wave(array $slots, array $texts, string $uri, array $headers, ?float $deadline): array {
        $out = [];
        if (self::$solo) {
            foreach ($slots as $slot) {
                if ($deadline !== null && microtime(true) >= $deadline) break;
                $out[$slot] = self::requestOne($uri, (string)$texts[$slot], $headers, $deadline);
            }
            return $out;
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach ($slots as $slot) {
            $ch = self::handle($uri, (string)$texts[$slot], $headers, $deadline);
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

        foreach ($handles as $slot => $ch) {
            $body = (string)curl_multi_getcontent($ch);
            $out[$slot] = [
                'vec'  => self::readVector($body),
                'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'curl' => curl_error($ch),
                'body' => $body,
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /** @return array{vec:?array,code:int,curl:string,body:string} */
    private static function requestOne(string $uri, string $text, array $headers, ?float $deadline = null): array {
        $ch = self::handle($uri, $text, $headers, $deadline);
        $body = (string)curl_exec($ch);
        $res = ['vec' => self::readVector($body), 'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'curl' => curl_error($ch), 'body' => $body];
        curl_close($ch);
        return $res;
    }

    /**
     * One request against the live API, for «Проверить эмбеддинги» in the panel.
     * This is the answer to «векторизация не идёт, а почему» — it names the HTTP
     * code and prints what Yandex actually said.
     */
    public static function diagnose(string $text = 'бронежилет скрытого ношения'): array {
        $creds = self::credentials();
        $out = [
            'configured' => $creds !== null,
            'enabled'    => self::enabled(),
            'endpoint'   => self::endpoint(),
            'model'      => self::docModel(),
            'folder'     => $creds ? $creds[1] : '',
            'key'        => $creds ? Settings::mask($creds[0]) : '',
            'proxy'      => LLM::proxyFor('yandex')['proxy'] ?? '',
        ];
        if (!$creds) return $out + ['ok' => false, 'http' => 0, 'error' => 'Не заданы ключ Yandex и Folder ID', 'ms' => 0];

        [$key, $folder] = $creds;
        $headers = ['Content-Type: application/json', 'Authorization: Api-Key ' . $key, 'x-folder-id: ' . $folder];
        $t0 = microtime(true);
        $r  = self::requestOne(self::modelUri(self::docModel()), $text, $headers, microtime(true) + self::timeout());
        $ms = (int)round((microtime(true) - $t0) * 1000);

        $fail = ['code' => $r['code'], 'curl' => $r['curl'], 'body' => $r['body'], 'count' => 1];
        return $out + [
            'ok'    => $r['vec'] !== null,
            'http'  => $r['code'],
            'dim'   => $r['vec'] ? count($r['vec']) : 0,
            'ms'    => $ms,
            'error' => $r['vec'] !== null ? '' : self::explainFailure($fail),
        ];
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

    private static function handle(string $uri, string $text, array $headers, ?float $deadline = null): CurlHandle {
        // A request may not outlive the step that started it: with the old fixed
        // timeout one slow batch ate the whole budget and left no time for the
        // retry, so every slot was reported as «не получен» without a second try.
        $timeout = self::timeout();
        if ($deadline !== null) $timeout = max(5, min($timeout, (int)ceil($deadline - microtime(true))));

        $ch = curl_init(self::endpoint());
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['modelUri' => $uri, 'text' => $text], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'AtlantArmourKP/1.0',
        ]);
        // The same proxy the model calls use, gated by the same per-provider
        // toggle (item 6) — embeddings always talk to Yandex Cloud
        $p = LLM::proxyFor('yandex');
        if ($p !== null) {
            curl_setopt($ch, CURLOPT_PROXY, $p['proxy']);
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
            if (str_starts_with($p['proxy'], 'socks5')) curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            if ($p['auth'] !== '') curl_setopt($ch, CURLOPT_PROXYUSERPWD, $p['auth']);
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
