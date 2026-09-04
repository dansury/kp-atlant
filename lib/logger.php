<?php
/**
 * Application log. Everything that fails — PHP errors, LLM and МойСклад calls,
 * IMAP/SMTP, API responses — lands in one table so the admin sees it in the UI
 * instead of hunting for a server-side error_log nobody can open on shared hosting.
 */
final class Logger {
    public const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    private static bool $installed = false;
    private static bool $inLog = false;   // never let logging recurse through the error handler

    /** Repeats of the same message inside this window collapse into one row. */
    private const DEDUP_SEC = 600;

    public static function debug(string $channel, string $message, array $ctx = []): void { self::log('debug', $channel, $message, $ctx); }
    public static function info(string $channel, string $message, array $ctx = []): void { self::log('info', $channel, $message, $ctx); }
    public static function warning(string $channel, string $message, array $ctx = []): void { self::log('warning', $channel, $message, $ctx); }
    public static function error(string $channel, string $message, array $ctx = []): void { self::log('error', $channel, $message, $ctx); }

    public static function exception(string $channel, Throwable $e, array $ctx = []): void {
        self::log('error', $channel, get_class($e) . ': ' . $e->getMessage(), $ctx + [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => self::shortTrace($e),
        ]);
    }

    public static function log(string $level, string $channel, string $message, array $ctx = []): void {
        if (self::$inLog) return;
        $level = isset(self::LEVELS[$level]) ? $level : 'info';

        // Keep the server log working even if the DB write below fails
        if ($level === 'error') error_log("[$channel] $message");

        $min = self::LEVELS[(string)Settings::get('LOG_LEVEL', 'info')] ?? 20;
        if (self::LEVELS[$level] < $min) return;

        self::$inLog = true;
        try {
            $text   = self::clip($message, 4000);
            $source = isset($ctx['file']) ? basename((string)$ctx['file']) . ':' . ($ctx['line'] ?? '?') : null;
            $now    = self::now();

            // The same deprecation fires hundreds of times per archive download —
            // the admin needs to see it once, with a counter, not to scroll past it
            $dup = Db::one(
                "SELECT id FROM app_log
                 WHERE level=? AND channel=? AND message=? AND COALESCE(source,'')=? AND last_at >= ?
                 ORDER BY id DESC LIMIT 1",
                [$level, $channel, $text, (string)$source, self::now(time() - self::DEDUP_SEC)]
            );
            if ($dup) {
                Db::q("UPDATE app_log SET repeat_count = COALESCE(repeat_count,1) + 1, last_at = ? WHERE id = ?",
                      [$now, (int)$dup['id']]);
                return;
            }

            Db::insert('app_log', [
                'level'      => $level,
                'channel'    => $channel,
                'message'    => $text,
                'context'    => $ctx ? self::clip(json_encode(self::scrub($ctx), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 16000) : null,
                'source'     => $source,
                'manager_id' => $_SESSION['manager_id'] ?? null,
                'request_uri'=> isset($_SERVER['REQUEST_URI']) ? self::clip((string)$_SERVER['REQUEST_URI'], 300) : null,
                // Written by PHP, not by SQLite: datetime('now') is UTC and the
                // admin reads the log in the timezone set in the settings
                'created_at' => $now,
                'last_at'    => $now,
                'repeat_count' => 1,
            ]);
        } catch (Throwable) {
            // Logging must never break the request it is describing
        } finally {
            self::$inLog = false;
        }
    }

    /** PHP notices, warnings, fatals and uncaught exceptions → the same table. */
    public static function install(): void {
        if (self::$installed) return;
        self::$installed = true;

        set_exception_handler(function (Throwable $e): void {
            self::exception('php', $e);
        });

        set_error_handler(function (int $no, string $str, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $no)) return false;
            if (!Settings::get('LOG_PHP_ERRORS', 1)) return false;
            // Deprecation notices of the PHP version itself say nothing about the
            // request that triggered them — off unless the admin asks for them
            if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)
                && !Settings::get('LOG_PHP_DEPRECATED', 0)) return false;
            self::log(self::levelForErrno($no), 'php', $str, ['file' => $file, 'line' => $line, 'errno' => $no]);
            return false;   // let PHP's own handling continue
        });

        register_shutdown_function(function (): void {
            $e = error_get_last();
            if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
            self::log('error', 'php', $e['message'], ['file' => $e['file'], 'line' => $e['line'], 'errno' => $e['type']]);
        });
    }

    private static function levelForErrno(int $no): string {
        return match ($no) {
            E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR, E_PARSE => 'error',
            E_WARNING, E_USER_WARNING, E_DEPRECATED, E_USER_DEPRECATED => 'warning',
            default => 'info',
        };
    }

    /** Log rows for the admin panel. */
    public static function query(array $f = []): array {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['level'])) {
            $min = self::LEVELS[$f['level']] ?? 0;
            $names = array_keys(array_filter(self::LEVELS, fn($v) => $v >= $min));
            $where[] = 'level IN (' . implode(',', array_fill(0, count($names), '?')) . ')';
            $params = array_merge($params, $names);
        }
        if (!empty($f['channel'])) { $where[] = 'channel = ?'; $params[] = $f['channel']; }
        if (!empty($f['q'])) {
            $where[] = '(message LIKE ? OR context LIKE ?)';
            $params[] = '%' . $f['q'] . '%';
            $params[] = '%' . $f['q'] . '%';
        }
        $limit  = min(500, max(1, (int)($f['limit'] ?? 100)));
        $offset = max(0, (int)($f['offset'] ?? 0));
        $sql = 'SELECT * FROM app_log WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(last_at, created_at) DESC, id DESC LIMIT ? OFFSET ?';
        return [
            'items'    => Db::all($sql, [...$params, $limit, $offset]),
            'total'    => (int)Db::val('SELECT COUNT(*) FROM app_log WHERE ' . implode(' AND ', $where), $params),
            'channels' => array_column(Db::all("SELECT DISTINCT channel FROM app_log ORDER BY channel"), 'channel'),
            'counts'   => self::counts(),
        ];
    }

    /** Errors and warnings of the last 24 hours — the badge in the header. */
    public static function counts(): array {
        $since = self::now(time() - 86400);
        return [
            'errors_24h'   => (int)Db::val("SELECT COALESCE(SUM(repeat_count),0) FROM app_log WHERE level='error' AND last_at >= ?", [$since]),
            'warnings_24h' => (int)Db::val("SELECT COALESCE(SUM(repeat_count),0) FROM app_log WHERE level='warning' AND last_at >= ?", [$since]),
            'total'        => (int)Db::val("SELECT COUNT(*) FROM app_log"),
        ];
    }

    /** Timestamps in the log are local: TIMEZONE from the settings, not UTC. */
    private static function now(?int $ts = null): string {
        return date('Y-m-d H:i:s', $ts ?? time());
    }

    public static function clear(?string $level = null): int {
        if ($level) return Db::q("DELETE FROM app_log WHERE level=?", [$level])->rowCount();
        return Db::q("DELETE FROM app_log")->rowCount();
    }

    /** Drop rows older than LOG_RETENTION_DAYS. Called from cron. */
    public static function prune(): int {
        $days = max(1, (int)Settings::get('LOG_RETENTION_DAYS', 30));
        return Db::q("DELETE FROM app_log WHERE COALESCE(last_at, created_at) < ?", [self::now(time() - $days * 86400)])->rowCount();
    }

    /** Credentials never go into the log (Constitution, V). */
    private static function scrub(array $ctx): array {
        foreach ($ctx as $k => $v) {
            if (is_array($v)) { $ctx[$k] = self::scrub($v); continue; }
            if (preg_match('/pass|token|secret|api_?key|authorization/i', (string)$k)) {
                $ctx[$k] = $v === '' || $v === null ? '' : '***';
            }
        }
        return $ctx;
    }

    private static function shortTrace(Throwable $e): string {
        $lines = [];
        foreach (array_slice($e->getTrace(), 0, 8) as $t) {
            $lines[] = ($t['file'] ?? '?') . ':' . ($t['line'] ?? '?') . ' ' . ($t['function'] ?? '');
        }
        return implode("\n", $lines);
    }

    private static function clip(string $s, int $max): string {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }
}
