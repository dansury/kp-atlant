<?php
/**
 * Модуль 055: полнотекстовый поиск по всему, на выбрасываемой базе:
 *
 *   — «легион» находит «ООО ЛЕГИОН» и «Легион» (SQLite LIKE кириллицу не сворачивал);
 *   — «е» находит «ё», телефон ищется в любом написании, ИНН — частью;
 *   — письмо находится по компании, её контакту, товару запроса;
 *   — правка строки переиндексирует её триггером, удалённая строка уходит из индекса;
 *   — цепочки, доска, запросы и компании ищут одним индексом.
 *
 * Запуск:  php tests/module_055.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-055-' . getmypid() . '.db';
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
require_once ROOT . '/lib/boards.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');

echo "\n== 1. Нормализация ==\n";
ok('регистр и ё', SearchIndex::normalize('ООО «ЛЕГИОН» Счёт') === 'ооо «легион» счет', SearchIndex::normalize('ООО «ЛЕГИОН» Счёт'));
$n = SearchIndex::normalize('тел. 8 (916) 123-45-67');
ok('телефон ещё и цифрами, с 8 и с 7', str_contains($n, '89161234567') && str_contains($n, '79161234567'), $n);
ok('слово-телефон → цифры', SearchIndex::normalizeTerm('+7 916 123-45-67') === '79161234567');
ok('8 впереди → 7', SearchIndex::normalizeTerm('89161234567') === '79161234567');
ok('короткое число не трогается', SearchIndex::normalizeTerm('12') === '12');
ok('% и _ — буквы', SearchIndex::like('50%_') === '%50\\%\\_%', SearchIndex::like('50%_'));

echo "\n== 2. Письмо находится по любой сущности ==\n";
$box = Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru', 'is_active' => 1, 'is_default' => 1]);
$cp = Db::insert('counterparties', ['name' => 'ООО «ЛЕГИОН»', 'inn' => '7701234567',
                                    'contact_phone' => '8 (916) 123-45-67']);
Db::insert('contacts', ['counterparty_id' => $cp, 'name' => 'Сёмин Пётр', 'email' => 'semin@legion.ru']);
$req = Db::insert('requests', ['source' => 'email', 'raw_text' => 'нужны каски', 'counterparty_id' => $cp]);
Db::insert('request_items', ['request_id' => $req, 'raw_name' => 'Шлем защитный', 'product_name' => 'Шлем «Рысь-Т»', 'article' => 'RT-500']);
$msg = Db::insert('mail_messages', [
    'mailbox_id' => $box, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
    'subject' => 'Заявка', 'from_email' => 'semin@legion.ru', 'to_emails' => 'info@atlant-armour.ru',
    'body_text' => 'Добрый день, Легион просит КП', 'counterparty_id' => $cp, 'request_id' => $req,
    'date_at' => '2026-09-20 10:00:00', 'thread_key' => 's:legion',
]);
$other = Db::insert('mail_messages', [
    'mailbox_id' => $box, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
    'subject' => 'Другое', 'from_email' => 'x@other.ru', 'body_text' => 'ничего общего',
    'date_at' => '2026-09-21 10:00:00', 'thread_key' => 's:other',
]);

$find = fn(string $q) => array_column(MailArchive::query(['q' => $q, 'archived' => 'all'])['items'], 'id');
$threads = fn(string $q) => array_column(MailThreads::query(['q' => $q, 'archived' => 'all'])['items'], 'thread_key');

ok('«легион» строчными', in_array($msg, $find('легион'), true));
ok('«ЛЕГИОН» заглавными', in_array($msg, $find('ЛЕГИОН'), true));
ok('часть слова «леги»', in_array($msg, $find('леги'), true));
ok('ИНН целиком', in_array($msg, $find('7701234567'), true));
ok('ИНН частью', in_array($msg, $find('012345'), true));
ok('телефон без разделителей', in_array($msg, $find('9161234567'), true));
ok('телефон с +7', in_array($msg, $find('+7 916 123-45-67'), true));
ok('телефон с 8', in_array($msg, $find('89161234567'), true));
ok('контакт компании через «е»', in_array($msg, $find('семин петр'), true));
ok('товар запроса', in_array($msg, $find('рысь'), true));
ok('артикул', in_array($msg, $find('rt-500'), true));
ok('все слова сразу', in_array($msg, $find('легион шлем'), true));
ok('лишнее слово отсекает', !in_array($msg, $find('легион слон'), true));
ok('чужое письмо не находится', !in_array($other, $find('легион'), true));
ok('цепочка находится', in_array('s:legion', $threads('Легион'), true));

echo "\n== 3. Индекс свежий: триггеры ==\n";
Db::update('counterparties', ['name' => 'ООО «Центурион»'], 'id=?', [$cp]);
ok('новое имя находится', in_array($msg, $find('центурион'), true));
Db::update('mail_messages', ['body_text' => 'обновлённый текст'], 'id=?', [$msg]);
ok('правка тела письма', in_array($msg, $find('обновленный'), true));
ok('старое слово тела ушло', !in_array($msg, $find('просит'), true));
Db::insert('attachments', ['mail_message_id' => $other, 'filename' => 'Прайс.xlsx', 'path' => 'x', 'extracted_text' => 'Бронежилет КИРАСА']);
ok('новое вложение', in_array($other, $find('кираса'), true));
Db::q("DELETE FROM attachments WHERE mail_message_id=?", [$other]);
Db::q("DELETE FROM mail_messages WHERE id=?", [$other]);
SearchIndex::refresh();
ok('удалённое письмо ушло из индекса', !Db::val("SELECT 1 FROM search_docs WHERE rowid=?", [$other * 4]));

echo "\n== 4. Доска, запросы, компании ==\n";
$board = Db::insert('boards', ['name' => 'Письма']);
$col = Db::insert('board_columns', ['board_id' => $board, 'title' => 'Входящие', 'position' => 1, 'kind' => 'inbox']);
$card = Db::insert('board_cards', ['column_id' => $col, 'title' => 'Заявка', 'counterparty_id' => $cp, 'thread_key' => 's:legion']);
$card2 = Db::insert('board_cards', ['column_id' => $col, 'title' => 'Заметка Ёлка', 'thread_key' => 's:none']);
ok('доска: по ИНН компании', in_array($card, array_column(Boards::search('7701234567'), 'card_id'), true));
ok('доска: по заголовку через «е»', in_array($card2, array_column(Boards::search('елка'), 'card_id'), true));
ok('доска: по товару запроса письма', in_array($card, array_column(Boards::search('РЫСЬ'), 'card_id'), true));

// Компания слита в другую — её письма находятся по имени новой
$main = Db::insert('counterparties', ['name' => 'АО Вымпел']);
Db::update('counterparties', ['merged_into_id' => $main], 'id=?', [$cp]);
ok('письмо слитой компании по имени главной', in_array($msg, $find('вымпел'), true));

echo "\n== 5. Индекс строится с нуля ==\n";
Db::q("DELETE FROM search_docs");
SearchIndex::markAll();
ok('очередь полная', (int)Db::val("SELECT COUNT(*) FROM search_dirty") > 0);
ok('refresh её опустошает', SearchIndex::refresh() === 0);
ok('и поиск снова работает', in_array($msg, $find('вымпел'), true));

echo $fail ? "\n$fail FAIL\n" : "\nВсё ок\n";
exit($fail ? 1 : 0);
