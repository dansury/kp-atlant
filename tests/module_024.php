<?php
/**
 * Модуль 024 — разбор почты партиями, единая раскладка карточки и документ,
 * который правится в браузере. На выбрасываемой базе и без сети:
 *
 *   — групповые операции на доске: прочитано, в архив, спам, перемещение,
 *     снятие с доски — и «сделано/не вышло» в ответе, а не молчание;
 *   — групповые операции по перепискам: архив разбирается партиями;
 *   — карточка компании знает, в какие ящики писала компания (фильтр доски);
 *   — превью письма — это письмо, а не служебная шапка пересылки;
 *   — настоящий отправитель пересланного письма, а не наш собственный ящик;
 *   — текст документа читается и правится блоками в порядке документа, а
 *     правка видна администратору в ленте изменений;
 *   — файл, собранный сервисом, прикладывается к письму без загрузки через
 *     браузер.
 *
 * Запуск:  php tests/module_024.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе,
 * так что прогон на сервере не может задеть живые данные.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-024-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);

$savedConfig = $hadConfig ? file_get_contents($configPath) : null;
$existing = $hadConfig ? (array)(require $configPath) : [];
$effective = ['DB_PATH' => $tmpDb] + $existing;
if ($effective['DB_PATH'] !== $tmpDb) {   // belt and braces: never run on anything else
    fwrite(STDERR, "tests: refusing to run against {$effective['DB_PATH']}\n");
    exit(2);
}
file_put_contents($configPath, "<?php return " . var_export($effective, true) . ";");
register_shutdown_function(function () use ($configPath, $savedConfig, $tmpDb) {
    if ($savedConfig === null) @unlink($configPath); else file_put_contents($configPath, $savedConfig);
    foreach ([$tmpDb, $tmpDb . '-wal', $tmpDb . '-shm'] as $f) @unlink($f);
});

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/mail_threads.php';
require_once ROOT . '/lib/mail_text.php';
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/outbox.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');

$mgr = Db::insert('managers', ['login' => 'yana', 'name' => 'Яна',
                               'email' => 'yana@atlant-armour.ru',
                               'password_hash' => 'x', 'is_admin' => 1]);
$boxId = Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru',
                                  'is_active' => 1, 'is_default' => 1]);

/** Письмо в базе — ровно столько полей, сколько нужно проверке. */
function letter(array $o): int {
    return Db::insert('mail_messages', $o + [
        'mailbox_id' => $GLOBALS['boxId'], 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
        'subject' => 'Тема', 'from_email' => 'client@zavod.ru', 'from_name' => 'Клиент',
        'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'текст', 'is_read' => 0,
        'date_at' => '2026-09-10 10:00:00',
    ]);
}

// =====================================================================  1

echo "\n== 1. Превью — письмо, а не шапка пересылки ==\n";

$fwd = "-------- Исходное сообщение --------\n"
     . "ТЕМА: Запрос КП\nДАТА: 2026-09-14 10:21\n"
     . "ОТ: Евгений Шигуев <ip.shiguev@yandex.ru>\nКОМУ: info@atlant-armour.ru\n\n"
     . "Добрый день! Прошу направить КП на бронешлемы Бр1 СВМПЭ.";

$preview = MailText::preview($fwd);
ok('служебная шапка в превью не попала', !str_contains($preview, 'Исходное сообщение'), $preview);
ok('и полей ТЕМА/ДАТА/ОТ там тоже нет',
   !str_contains($preview, 'ТЕМА:') && !str_contains($preview, 'ОТ:'), $preview);
ok('а само письмо — попало', str_starts_with($preview, 'Добрый день!'), $preview);

$plain = MailText::preview('Здравствуйте, пришлите счёт.');
ok('обычное письмо превью не портит', $plain === 'Здравствуйте, пришлите счёт.', $plain);
ok('письмо без пересылки шапки не теряет',
   MailText::stripForwardHeader('Просто текст') === 'Просто текст');

// =====================================================================  2

echo "\n== 2. Кто написал: человек, а не наш ящик ==\n";

$from = MailText::forwardedFrom($fwd);
ok('отправитель вынут из шапки пересылки', ($from['name'] ?? '') === 'Евгений Шигуев',
   json_encode($from, JSON_UNESCAPED_UNICODE));
ok('вместе с адресом', ($from['email'] ?? '') === 'ip.shiguev@yandex.ru');
ok('в обычном письме искать нечего', MailText::forwardedFrom('Здравствуйте!') === null);

// Письмо, пришедшее «от нас самих», — ровно тот случай из mbox-импорта
$real = MailThreads::realSender([
    'from_name' => '', 'from_email' => 'info@atlant-armour.ru',
    'mailbox_email' => 'info@atlant-armour.ru', 'body_text' => $fwd,
]);
ok('подменяем отправителя, когда в заголовке наш же ящик',
   $real['name'] === 'Евгений Шигуев' && $real['forwarded'], json_encode($real, JSON_UNESCAPED_UNICODE));

// А вот у письма с живым внешним отправителем заголовок трогать нельзя
$keep = MailThreads::realSender([
    'from_name' => 'Пётр', 'from_email' => 'petr@zavod.ru',
    'mailbox_email' => 'info@atlant-armour.ru', 'body_text' => $fwd,
]);
ok('чужой заголовок остаётся как есть', $keep['name'] === 'Пётр' && $keep['email'] === 'petr@zavod.ru',
   json_encode($keep, JSON_UNESCAPED_UNICODE));

// =====================================================================  3

echo "\n== 3. Групповые операции на доске ==\n";

$cpA = Db::insert('counterparties', ['name' => 'ООО «Альфа»']);
$cpB = Db::insert('counterparties', ['name' => 'ООО «Бета»']);

letter(['thread_key' => 's:a1', 'counterparty_id' => $cpA, 'subject' => 'Запрос КП']);
letter(['thread_key' => 's:a2', 'counterparty_id' => $cpA, 'subject' => 'Ещё вопрос']);
letter(['thread_key' => 's:b1', 'counterparty_id' => $cpB, 'subject' => 'Прайс']);

$board = Db::insert('boards', ['name' => 'Письма']);
$inbox = Db::insert('board_columns', ['board_id' => $board, 'title' => 'Входящие', 'position' => 1, 'kind' => 'inbox']);
$work  = Db::insert('board_columns', ['board_id' => $board, 'title' => 'В работе', 'position' => 2]);

$cardA = Db::insert('board_cards', ['column_id' => $inbox, 'title' => 'ООО «Альфа»', 'counterparty_id' => $cpA]);
$cardB = Db::insert('board_cards', ['column_id' => $inbox, 'title' => 'ООО «Бета»',  'counterparty_id' => $cpB]);

$res = Boards::bulk([$cardA, $cardB], 'read', [], $mgr);
ok('«прочитано» отчитывается по каждой карточке', $res['done'] === 2 && $res['failed'] === 0,
   json_encode($res));
ok('и непрочитанных писем не осталось',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE is_read=0 AND direction='in'") === 0);

$res = Boards::bulk([$cardA, $cardB], 'move', ['column_id' => $work], $mgr);
ok('перемещение переносит обе карточки', $res['done'] === 2
   && (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE column_id=?", [$work]) === 2, json_encode($res));

$res = Boards::bulk([$cardA], 'archive', [], $mgr);
ok('«в архив» уносит ВСЮ переписку компании, а не одну цепочку',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages
                 WHERE counterparty_id=? AND archived_at IS NULL", [$cpA]) === 0, json_encode($res));
ok('и саму карточку с доски',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE id=?", [$cardA]) === 0);

$res = Boards::bulk([$cardB], 'spam', [], $mgr);
ok('«спам» помечает входящие письма',
   (string)Db::val("SELECT category FROM mail_messages WHERE thread_key='s:b1'") === 'spam', json_encode($res));
ok('и тоже убирает карточку',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE id=?", [$cardB]) === 0);

// Операция над тем, чего нет, не должна валить остальные
$res = Boards::bulk([999999], 'read', [], $mgr);
ok('несуществующая карточка просто ничего не делает', $res['done'] === 0 && $res['failed'] === 0,
   json_encode($res));

$res = Boards::bulk([], 'read', [], $mgr);
ok('пустой список — пустой ответ, а не падение', $res['done'] === 0, json_encode($res));

// Незнакомая операция обязана сказать об этом, а не сделать что-то своё
$cardC = Db::insert('board_cards', ['column_id' => $inbox, 'title' => 'Проверка']);
$res = Boards::bulk([$cardC], 'сделай-хорошо', [], $mgr);
ok('неизвестная операция считается несделанной', $res['done'] === 0 && $res['failed'] === 1,
   json_encode($res, JSON_UNESCAPED_UNICODE));
ok('и объясняет, что не так', str_contains($res['errors'][0] ?? '', 'Неизвестная операция'),
   json_encode($res, JSON_UNESCAPED_UNICODE));

// =====================================================================  4

echo "\n== 4. Ящики компании — то, по чему фильтруется доска ==\n";

$box2 = Db::insert('mailboxes', ['name' => 'Продажи', 'email' => 'sales@atlant-armour.ru', 'is_active' => 1]);
$cpC  = Db::insert('counterparties', ['name' => 'ООО «Гамма»']);
letter(['thread_key' => 's:c1', 'counterparty_id' => $cpC]);
letter(['thread_key' => 's:c2', 'counterparty_id' => $cpC, 'mailbox_id' => $box2]);

$cardD = Db::insert('board_cards', ['column_id' => $inbox, 'title' => 'ООО «Гамма»', 'counterparty_id' => $cpC]);
$got = Boards::get($board);
$card = null;
foreach ($got['columns'] as $col) {
    foreach ($col['cards'] as $c) if ((int)$c['id'] === $cardD) $card = $c;
}
$boxes = $card['company']['mailbox_ids'] ?? [];
sort($boxes);
ok('карточка знает оба ящика, в которые писала компания', $boxes === [$boxId, $box2],
   json_encode($boxes));

// =====================================================================  5

echo "\n== 5. Архив разбирается партиями ==\n";

letter(['thread_key' => 's:junk1', 'subject' => 'Реклама', 'from_email' => 'ad@spam.cn']);
letter(['thread_key' => 's:junk2', 'subject' => 'Вакансия', 'from_email' => 'hr@jobs.ru']);
MailSync::archiveThread('s:junk1', $mgr);
MailSync::archiveThread('s:junk2', $mgr);

$archived = array_column(MailThreads::query(['archived' => true, 'limit' => 50])['items'], 'thread_key');
ok('обе переписки в архиве',
   in_array('s:junk1', $archived, true) && in_array('s:junk2', $archived, true),
   implode(', ', $archived));

MailSync::unarchiveThread('s:junk1');
MailSync::unarchiveThread('s:junk2');
ok('и возвращаются из него', (int)Db::val("SELECT COUNT(*) FROM mail_messages
    WHERE thread_key IN ('s:junk1','s:junk2') AND archived_at IS NOT NULL") === 0);

// =====================================================================  6

echo "\n== 6. Свой файл к письму — без загрузки через браузер ==\n";

$tmp = sys_get_temp_dir() . '/kp-test-024-invoice-' . getmypid() . '.pdf';
file_put_contents($tmp, '%PDF-1.4 fake');
$att = Outbox::adopt($tmp, 'Счёт 00123.pdf', $mgr);
ok('файл принят с человеческим именем', ($att['filename'] ?? '') === 'Счёт 00123.pdf',
   json_encode($att, JSON_UNESCAPED_UNICODE));
ok('и лежит на диске под своим', count(Outbox::resolve([$att['name']], $mgr)) === 1);
ok('оригинал остался на месте', is_file($tmp));
@unlink($tmp);

$threw = false;
try { Outbox::adopt('/no/such/file.pdf', 'x.pdf', $mgr); } catch (Throwable $e) { $threw = true; }
ok('файла нет — это ошибка, а не пустое вложение', $threw);

// =====================================================================  7

echo "\n== 7. Текст документа правится блоками, и правку видит админ ==\n";

$before = ContentLog::countSince('-1 day');
ContentLog::record('kp', 'proposal.13', 'Текст КП #13 правил менеджер', $mgr, '', 'изменено блоков: 3');
ok('правка легла в ленту изменений', ContentLog::countSince('-1 day') === $before + 1);
$recent = ContentLog::recent(5);
ok('и видна с понятным заголовком',
   str_contains($recent[0]['title'] ?? '', 'Текст КП #13'), json_encode($recent[0] ?? [], JSON_UNESCAPED_UNICODE));

// =====================================================================

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "Все проверки прошли.\n");
exit($fail ? 1 : 0);
