<?php
/**
 * Company knowledge base (module 005).
 *
 * The wiki lives in a separate GitHub repo (dansury/Atlant, GRAPH/wiki). This class
 * keeps a local copy in SQLite, checks the remote version before every use (throttled
 * by KNOWLEDGE_SYNC_TTL_SEC) and hands the LLM only the sections that match the task
 * at hand — the whole wiki is ~100 KB and would eat the context window for nothing.
 */
final class Knowledge {

    /** Tasks that may receive wiki context: prompt key => [label, share of the char budget] */
    public const TASKS = [
        'mail_reply'      => ['Ответ на письмо клиента', 1.0],
        'cover_letter'    => ['Сопроводительное письмо к КП', 0.6],
        'followup'        => ['Письмо вдогонку', 0.4],
        'normalize_names' => ['Нормализация наименований', 0.5],
    ];

    private const API = 'https://api.github.com';
    private const MIN_TOKEN_LEN = 3;
    private const STEM_LEN = 6;

    /** Frequent Russian words carry no signal and would match every section. */
    private const STOPWORDS = [
        'что', 'как', 'для', 'или', 'это', 'над', 'при', 'без', 'вас', 'нас', 'наш', 'ваш', 'вам', 'нам',
        'его', 'ещё', 'еще', 'так', 'там', 'тот', 'тем', 'том', 'той', 'все', 'всё', 'был', 'была', 'было',
        'быть', 'есть', 'нет', 'да', 'же', 'бы', 'ли', 'по', 'на', 'из', 'от', 'до', 'за', 'об', 'под',
        'здравствуйте', 'добрый', 'день', 'уважаемый', 'уважаемая', 'спасибо', 'пожалуйста', 'просим',
        'прошу', 'ооо', 'зао', 'ип', 'инн', 'кпп', 'https', 'http', 'www', 'com', 'org',
    ];

    // ---------------------------------------------------------------- public API

    public static function enabled(): bool {
        return (int)Settings::get('KNOWLEDGE_ENABLED', 0) === 1;
    }

    /** Is this generation task allowed to use the wiki? */
    public static function taskEnabled(string $task): bool {
        if (!self::enabled() || !isset(self::TASKS[$task])) return false;
        $list = array_filter(array_map('trim', explode(',', (string)Settings::get('KNOWLEDGE_TASKS', ''))));
        return in_array($task, $list, true);
    }

    /**
     * System prompt for a generation, with the wiki block filled in.
     * Falls back to the plain prompt whenever the base is off, stale-free or irrelevant.
     */
    public static function augment(string $promptKey, array $vars, string $query): string {
        $block = self::context($query, $promptKey);
        $system = Prompts::render($promptKey, $vars + ['knowledge' => $block]);
        // A prompt edited in the admin panel before module 005 has no {{knowledge}} —
        // append the block instead of silently dropping it.
        if ($block !== '' && !str_contains($system, $block)) {
            $system .= "\n\n" . $block;
        }
        return $system;
    }

    /**
     * Wiki sections relevant to $query, as a ready-to-paste prompt block.
     * Returns '' when the base is off, empty or nothing matches the query.
     */
    public static function context(string $query, string $task): string {
        if (!self::taskEnabled($task)) return '';
        try {
            self::ensureFresh();          // never throws — a stale copy still answers
            $picked = self::search($query, self::budget($task));
        } catch (Throwable $e) {
            Logger::exception('knowledge', $e, ['task' => $task]);
            return '';
        }
        if (!$picked) return '';

        $out = "===== БАЗА ЗНАНИЙ ATLANT ARMOUR =====\n"
             . "Проверенные факты о компании и товарах. Используй их как источник истины.\n"
             . "Если нужного факта здесь нет — не выдумывай, напиши, что уточнишь.\n\n";
        foreach ($picked as $s) {
            $out .= "--- {$s['title']} ---\n" . $s['text'] . "\n\n";
        }
        return rtrim($out) . "\n===== КОНЕЦ БАЗЫ ЗНАНИЙ =====";
    }

    /** Admin panel: what would be injected for this query. */
    public static function preview(string $query, string $task): array {
        $picked = self::search($query, self::budget($task));
        return array_map(fn($s) => [
            'title' => $s['title'],
            'path'  => $s['path'],
            'score' => round($s['score'], 2),
            'hits'  => $s['hits'],
            'chars' => mb_strlen($s['text']),
        ], $picked);
    }

    /** State of the local copy for «Админ → База знаний». */
    public static function status(): array {
        $docs = Db::all("SELECT path, title, tags, size, updated_at FROM knowledge_docs ORDER BY path");
        return [
            'enabled'    => self::enabled(),
            'repo'       => (string)Settings::get('KNOWLEDGE_REPO', ''),
            'branch'     => (string)Settings::get('KNOWLEDGE_BRANCH', ''),
            'path'       => (string)Settings::get('KNOWLEDGE_PATH', ''),
            'token_set'  => (string)Settings::get('GITHUB_TOKEN', '') !== '',
            'commit'     => self::state('commit_sha'),
            'commit_at'  => self::state('commit_at'),
            'checked_at' => self::state('checked_at'),
            'synced_at'  => self::state('synced_at'),
            'last_error' => self::state('last_error'),
            'ttl_sec'    => (int)Settings::get('KNOWLEDGE_SYNC_TTL_SEC', 600),
            'tasks'      => array_map(fn($t) => [
                'key'     => $t,
                'label'   => self::TASKS[$t][0],
                'enabled' => self::taskEnabled($t),
                'budget'  => self::budget($t),
            ], array_keys(self::TASKS)),
            'docs'       => $docs,
            'docs_count' => count($docs),
            'total_size' => array_sum(array_column($docs, 'size')),
        ];
    }

    // ---------------------------------------------------------------- syncing

    /**
     * Check the remote version and pull what changed. Throttled: within TTL the
     * check is skipped, so a burst of generations does not hammer the GitHub API.
     * TTL 0 means «check on every use».
     */
    public static function ensureFresh(): void {
        if (!self::enabled()) return;
        $ttl = (int)Settings::get('KNOWLEDGE_SYNC_TTL_SEC', 600);
        $last = strtotime((string)self::state('checked_at')) ?: 0;
        if ($ttl > 0 && $last && (time() - $last) < $ttl) return;
        try {
            self::sync();
        } catch (Throwable $e) {
            // GitHub down, bad token, rate limit — keep answering from the cached copy
            Logger::exception('knowledge', $e, ['stage' => 'ensure_fresh']);
        }
    }

    /**
     * Compare the remote head commit with the stored one and download changed files.
     * A failure never breaks generation — it is logged and the cached copy stays in use.
     * $force re-reads every file even when the commit did not move.
     */
    public static function sync(bool $force = false): array {
        $repo   = trim((string)Settings::get('KNOWLEDGE_REPO', ''));
        $branch = trim((string)Settings::get('KNOWLEDGE_BRANCH', 'main'));
        $path   = trim((string)Settings::get('KNOWLEDGE_PATH', ''), '/');
        if ($repo === '') throw new RuntimeException('KNOWLEDGE_REPO не задан');

        $report = ['repo' => $repo, 'branch' => $branch, 'status' => 'up_to_date',
                   'updated' => 0, 'deleted' => 0, 'commit' => self::state('commit_sha')];
        try {
            $head = self::headCommit($repo, $branch, $path);
            self::state('checked_at', self::now());
            $report['commit'] = $head['sha'];

            $known = (int)Db::val("SELECT COUNT(*) FROM knowledge_docs");
            if (!$force && $known > 0 && $head['sha'] !== '' && $head['sha'] === self::state('commit_sha')) {
                self::state('last_error', '');
                return $report;
            }

            $remote = self::listFiles($repo, $branch, $path);
            if (!$remote) throw new RuntimeException("В $repo:$branch/$path нет .md файлов");

            $local = [];
            foreach (Db::all("SELECT path, sha FROM knowledge_docs") as $row) $local[$row['path']] = $row['sha'];

            foreach ($remote as $file) {
                if (!$force && ($local[$file['path']] ?? null) === $file['sha']) continue;
                $content = self::blob($repo, $file['sha']);
                self::store($file, $content);
                $report['updated']++;
            }
            foreach (array_diff(array_keys($local), array_column($remote, 'path')) as $gone) {
                Db::q("DELETE FROM knowledge_docs WHERE path=?", [$gone]);
                $report['deleted']++;
            }

            self::state('commit_sha', $head['sha']);
            self::state('commit_at', $head['date']);
            self::state('synced_at', self::now());
            self::state('last_error', '');
            $report['status'] = ($report['updated'] || $report['deleted']) ? 'updated' : 'up_to_date';
            if ($report['status'] === 'updated') {
                Logger::info('knowledge', "База знаний обновлена: {$report['updated']} файлов, удалено {$report['deleted']}",
                    ['repo' => $repo, 'commit' => $head['sha']]);
            }
            return $report;
        } catch (Throwable $e) {
            self::state('checked_at', self::now());
            self::state('last_error', $e->getMessage());
            throw $e;
        }
    }

    /** Head commit that touched the wiki folder — the version we compare against. */
    private static function headCommit(string $repo, string $branch, string $path): array {
        $q = 'sha=' . rawurlencode($branch) . '&per_page=1' . ($path !== '' ? '&path=' . rawurlencode($path) : '');
        $rows = self::api($repo, "commits?$q");
        if (!isset($rows[0]['sha'])) throw new RuntimeException("Не удалось прочитать историю $repo:$branch");
        return ['sha' => (string)$rows[0]['sha'], 'date' => (string)($rows[0]['commit']['committer']['date'] ?? '')];
    }

    /** Markdown files under the wiki folder, recursively (the wiki may grow subfolders). */
    private static function listFiles(string $repo, string $branch, string $path, int $depth = 0): array {
        if ($depth > 3) return [];
        $items = self::api($repo, 'contents/' . self::encodePath($path) . '?ref=' . rawurlencode($branch));
        $out = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'dir') {
                $out = array_merge($out, self::listFiles($repo, $branch, (string)$item['path'], $depth + 1));
            } elseif ($type === 'file' && preg_match('/\.md$/i', (string)$item['name'])) {
                $out[] = ['path' => (string)$item['path'], 'name' => (string)$item['name'],
                          'sha' => (string)$item['sha'], 'size' => (int)($item['size'] ?? 0)];
            }
        }
        return $out;
    }

    /** File content by blob sha — works for private repos, unlike raw.githubusercontent. */
    private static function blob(string $repo, string $sha): string {
        $data = self::api($repo, 'git/blobs/' . $sha);
        $raw = (string)($data['content'] ?? '');
        return ($data['encoding'] ?? '') === 'base64' ? (string)base64_decode(preg_replace('/\s+/', '', $raw)) : $raw;
    }

    private static function store(array $file, string $content): void {
        $meta = self::parseFront($content);
        $row = [
            'path'       => $file['path'],
            'title'      => $meta['title'] !== '' ? $meta['title'] : preg_replace('/\.md$/i', '', $file['name']),
            'tags'       => $meta['tags'],
            'content'    => $content,
            'sha'        => $file['sha'],
            'size'       => $file['size'] ?: strlen($content),
            'updated_at' => self::now(),
        ];
        if (Db::val("SELECT COUNT(*) FROM knowledge_docs WHERE path=?", [$file['path']])) {
            Db::update('knowledge_docs', $row, 'path=?', [$file['path']]);
        } else {
            Db::insert('knowledge_docs', $row);
        }
    }

    /** GitHub REST call. The token is optional for a public repo, required for a private one. */
    private static function api(string $repo, string $endpoint): array {
        $url = self::API . '/repos/' . $repo . '/' . $endpoint;
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: atlant-kp-knowledge',
        ];
        $token = trim((string)Settings::get('GITHUB_TOKEN', ''));
        if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int)Settings::get('KNOWLEDGE_TIMEOUT_SEC', 20),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) throw new RuntimeException("GitHub недоступен: $err");
        if ($code === 401 || $code === 403) {
            throw new RuntimeException("GitHub отклонил запрос (HTTP $code) — проверьте токен и его доступ к репозиторию");
        }
        if ($code === 404) throw new RuntimeException("GitHub: не найдено — $repo/$endpoint");
        if ($code >= 400) throw new RuntimeException("GitHub вернул HTTP $code: " . mb_substr((string)$resp, 0, 300));

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) throw new RuntimeException('GitHub: не JSON в ответе');
        return $data;
    }

    /** Paths carry Russian names and spaces — encode each segment, keep the slashes. */
    private static function encodePath(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

    // ---------------------------------------------------------------- retrieval

    /**
     * Rank wiki sections against the query and take the best ones within the budget.
     * Matching is idf-weighted stem overlap: a section is a candidate only when it
     * shares at least KNOWLEDGE_MIN_HITS distinctive terms with the query, so an
     * off-topic letter gets no knowledge block at all.
     */
    public static function search(string $query, int $budget): array {
        $sections = self::sections();
        if (!$sections) return [];

        $qStems = self::stems(mb_substr(trim($query), 0, 6000));
        if (!$qStems) return [];

        $total = count($sections);
        $df = [];
        foreach ($sections as $s) {
            foreach (array_keys($s['stems']) as $stem) $df[$stem] = ($df[$stem] ?? 0) + 1;
        }

        $minHits = max(1, (int)Settings::get('KNOWLEDGE_MIN_HITS', 2));
        $scored = [];
        foreach ($sections as $i => $s) {
            $score = 0.0;
            $hits = 0;
            foreach ($qStems as $stem => $qtf) {
                if (!isset($df[$stem])) continue;
                $idf = log(($total + 1) / $df[$stem]);
                if ($idf < 0.35) continue;                       // term sits in most sections — no signal
                $inHead = isset($s['head'][$stem]);
                $tf = $s['stems'][$stem] ?? 0;
                if (!$tf && !$inHead) continue;
                // A term that lives in one or two sections is a strong signal on its own
                $hits += $df[$stem] <= 2 ? 2 : 1;
                $score += $idf * min($qtf, 3) * (1 + min($tf, 4) / 4) * ($inHead ? 2.0 : 1.0);
            }
            if ($hits < $minHits) continue;
            $scored[] = ['idx' => $i, 'score' => $score, 'hits' => $hits];
        }
        if (!$scored) return [];

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $out = [];
        $left = $budget;
        foreach ($scored as $row) {
            if ($left < 400) break;
            $s = $sections[$row['idx']];
            $text = self::clip($s['text'], min($left, 2500));
            $left -= mb_strlen($text);
            $out[] = ['title' => $s['title'], 'path' => $s['path'], 'text' => $text,
                      'score' => $row['score'], 'hits' => $row['hits']];
        }
        return $out;
    }

    /** Wiki documents split into `##` sections — the unit we retrieve and inject. */
    private static function sections(): array {
        $out = [];
        foreach (Db::all("SELECT path, title, tags, content FROM knowledge_docs ORDER BY path") as $doc) {
            $body = self::stripFront((string)$doc['content']);
            $parts = preg_split('/^(##\s+.+)$/mu', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
            $chunks = [['', array_shift($parts) ?? '']];
            for ($i = 0; $i < count($parts) - 1; $i += 2) {
                $chunks[] = [trim(ltrim($parts[$i], '# ')), (string)($parts[$i + 1] ?? '')];
            }
            foreach ($chunks as [$heading, $text]) {
                $text = trim($text);
                if (mb_strlen($text) < 40) continue;
                $title = $doc['title'] . ($heading !== '' ? " → $heading" : '');
                $head = self::stems($doc['title'] . ' ' . $doc['tags'] . ' ' . $heading);
                $out[] = [
                    'path'  => $doc['path'],
                    'title' => $title,
                    'text'  => ($heading !== '' ? "## $heading\n" : '') . $text,
                    'head'  => $head,
                    'stems' => self::stems($text) + $head,
                ];
            }
        }
        return $out;
    }

    /** stem => frequency. Prefix stemming is crude but enough to match Russian forms. */
    private static function stems(string $text): array {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $out = [];
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if (mb_strlen($word) < self::MIN_TOKEN_LEN) continue;
            if (in_array($word, self::STOPWORDS, true)) continue;
            $stem = mb_substr($word, 0, self::STEM_LEN);
            $out[$stem] = ($out[$stem] ?? 0) + 1;
        }
        return $out;
    }

    /** YAML front matter of a wiki page: title comes from the first `# ` heading. */
    private static function parseFront(string $content): array {
        $tags = '';
        if (preg_match('/^---\R(.*?)\R---/su', $content, $m) && preg_match('/^tags:\s*(.+)$/mu', $m[1], $t)) {
            $tags = trim($t[1], " \t[]");
        }
        $title = preg_match('/^#\s+(.+)$/mu', self::stripFront($content), $h) ? trim($h[1]) : '';
        return ['tags' => $tags, 'title' => $title];
    }

    private static function stripFront(string $content): string {
        return (string)preg_replace('/^---\R.*?\R---\R/su', '', $content);
    }

    private static function clip(string $text, int $max): string {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . "\n[…]" : $text;
    }

    private static function budget(string $task): int {
        $max = max(500, (int)Settings::get('KNOWLEDGE_MAX_CHARS', 6000));
        return (int)round($max * (self::TASKS[$task][1] ?? 1.0));
    }

    // ---------------------------------------------------------------- state

    /** Sync state lives next to schema_version in `settings`, outside the cfg. namespace. */
    private static function state(string $key, ?string $value = null): string {
        if ($value === null) return (string)(Db::val("SELECT value FROM settings WHERE key=?", ["knowledge.$key"]) ?: '');
        Db::q("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
              ["knowledge.$key", $value]);
        return $value;
    }

    private static function now(): string {
        return date('Y-m-d H:i:s');
    }
}
