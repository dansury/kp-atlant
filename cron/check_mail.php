<?php
/**
 * Cron: mail sync — every 2 minutes.
 * Pulls INBOX and «Отправленные» of every active mailbox into the archive, then
 * turns fresh inbound letters into КП requests. Usage: php cron/check_mail.php [mailbox_id]
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/mailsync.php';

$mailboxId = isset($argv[1]) ? (int)$argv[1] : null;
$report = MailSync::run($mailboxId ?: null);

if (!$report) {
    echo "Нет активных почтовых ящиков — добавьте их в разделе «Админ → Почта»\n";
    exit(0);
}

$failed = false;
foreach ($report as $r) {
    if ($r['error']) {
        $failed = true;
        echo "[{$r['name']}] ОШИБКА: {$r['error']}\n";
        continue;
    }
    echo "[{$r['name']}] входящих: {$r['in']}, исходящих: {$r['out']}, новых запросов: {$r['requests']}\n";
}

// Housekeeping: the log must not grow without bound on shared hosting
Logger::prune();

exit($failed ? 1 : 0);
