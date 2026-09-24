<?php
/**
 * Cron: mail sync — every 2 minutes.
 * Pulls INBOX and «Отправленные» of every active mailbox into the archive, then
 * turns fresh inbound letters into КП requests.
 *
 * Usage: php cron/check_mail.php [mailbox_id]
 *        php cron/check_mail.php [mailbox_id] backfill [seconds]
 *          — download the WHOLE history instead, in a run of the given length.
 *            Resumable: schedule it nightly and the archive fills up over time.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/mailsync.php';

$mailboxId = isset($argv[1]) ? (int)$argv[1] : null;

if (($argv[2] ?? '') === 'backfill') {
    $seconds = max(10, (int)($argv[3] ?? 300));
    $boxes = $mailboxId ? array_filter([Mailboxes::get($mailboxId)]) : Mailboxes::all(true);
    if (!$boxes) {
        echo "Нет активных почтовых ящиков\n";
        exit(0);
    }
    $deadline = microtime(true) + $seconds;
    $failed = false;
    foreach ($boxes as $box) {
        $stored = 0;
        while (microtime(true) < $deadline) {
            $r = MailSync::backfill($box, (int)ceil($deadline - microtime(true)));
            $stored += $r['stored'];
            if ($r['error']) {
                $failed = true;
                echo "[{$r['name']}] ОШИБКА: {$r['error']}\n";
                break;
            }
            if ($r['done']) { echo "[{$r['name']}] архив скачан полностью\n"; break; }
            if (!$r['scanned']) break;
        }
        echo "[{$box['name']}] загружено писем: $stored\n";
    }
    Logger::prune();
    exit($failed ? 1 : 0);
}

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
    // Папка отправленных не роняет разбор входящих, но о ней надо сказать вслух
    if (!empty($r['sent_error'])) echo "[{$r['name']}] Отправленные: {$r['sent_error']}\n";
}

// Отложенные письма (issue #60): на хостинге с одной записью в кроне
// собственный `cron/send_scheduled.php` может быть не заведён — тогда письма
// уходят отсюда, с точностью до периода этого крона
require_once ROOT . '/lib/mail_schedule.php';
$scheduled = MailSchedule::run();
if ($scheduled['sent'] || $scheduled['failed']) {
    echo "отложенные письма — отправлено: {$scheduled['sent']}, не удалось: {$scheduled['failed']}\n";
}

// Поисковый индекс догоняет новые письма здесь, а не на первом поиске (модуль 055)
$waiting = SearchIndex::ready(60.0);
if ($waiting) echo "поисковый индекс: осталось $waiting\n";

// Housekeeping: the log must not grow without bound on shared hosting
Logger::prune();

exit($failed ? 1 : 0);
