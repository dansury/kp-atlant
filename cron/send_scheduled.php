<?php
/**
 * Cron: отложенные письма — раз в минуту (issue #60).
 *
 * Письмо, которому назначили время, уходит тем же кодом, что и по кнопке
 * «Отправить» (`MailCompose::send()`). Здесь только очередь и время.
 *
 * Usage: php cron/send_scheduled.php
 *
 * Отдельная запись в кроне нужна для минутной точности. Её нет — письма всё
 * равно уйдут: `cron/check_mail.php` зовёт то же самое каждые две минуты.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/mail_schedule.php';

$r = MailSchedule::run();
echo "отправлено: {$r['sent']}, не удалось: {$r['failed']}\n";
exit($r['failed'] ? 1 : 0);
