<?php
/**
 * Cron: pull the company wiki from GitHub (module 005).
 * Usage: php cron/sync_knowledge.php [--force]
 *
 * Generations refresh the base themselves, but a cron pass keeps the copy warm so the
 * first letter of the day does not pay for the download.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

if (!Knowledge::enabled()) {
    echo "Knowledge base disabled — nothing to do\n";
    exit(0);
}

try {
    $r = Knowledge::sync(in_array('--force', $argv, true));
    echo "{$r['status']}: обновлено {$r['updated']}, удалено {$r['deleted']}, коммит " . substr((string)$r['commit'], 0, 8) . "\n";
    // The FTS index mirrors the docs; sync() rebuilds it on a change, --force always
    if (in_array('--reindex', $argv, true)) {
        echo 'переиндексировано разделов: ' . Knowledge::reindex() . "\n";
    }
    if (!Knowledge::ftsAvailable()) {
        echo "SQLite без FTS5 — поиск по вики работает перебором в PHP\n";
    }
} catch (Throwable $e) {
    Logger::exception('knowledge', $e, ['source' => 'cron']);
    fwrite(STDERR, 'Ошибка синхронизации базы знаний: ' . $e->getMessage() . "\n");
    exit(1);
}
