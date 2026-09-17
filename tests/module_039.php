<?php
/**
 * Модуль 039: подбор перестал врать, почта перестала терять письма.
 *
 * Проверяется ровно то, ради чего модуль появился:
 *   — «Бр2» больше не находит «Бр3»: чужой класс защиты не совпадение;
 *   — товар с модификациями выбирается сам, с вилкой цен и суммой остатков;
 *   — обновление каталога не обнуляет остатки, которых оно не знает;
 *   — имя вложения уходит клиенту человеческим, без служебной приставки;
 *   — удалённое письмо лежит в корзине и возвращается оттуда целым;
 *   — отвеченное письмо не считается непрочитанным;
 *   — разбор, упавший на модели, не помечает письмо разобранным.
 *
 * Run:  php tests/module_039.php
 *
 * База создаётся в системном временном каталоге — `data/kp.db` не открывается.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-039-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);
$savedConfig = $hadConfig ? file_get_contents($configPath) : null;
$existing = $hadConfig ? (array)(require $configPath) : [];
$effective = ['DB_PATH' => $tmpDb] + $existing;
if ($effective['DB_PATH'] !== $tmpDb) {
    fwrite(STDERR, "tests: refusing to run against {$effective['DB_PATH']}\n");
    exit(2);
}
file_put_contents($configPath, "<?php return " . var_export($effective, true) . ";");
register_shutdown_function(function () use ($configPath, $savedConfig, $tmpDb) {
    if ($savedConfig === null) @unlink($configPath); else file_put_contents($configPath, $savedConfig);
    foreach ([$tmpDb, $tmpDb . '-wal', $tmpDb . '-shm'] as $f) @unlink($f);
});

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/variants.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/mail_threads.php';
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/outbox.php';
require_once ROOT . '/lib/boards.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('VECTOR_ENABLED', '0');
Settings::set('MATCH_VECTOR_WEIGHT', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');

echo "\n1. Класс защиты — это другой товар, а не «почти то же самое»\n";

foreach ([['pl-2', 'Боковая плита для бронежилета Бр2', 4200],
          ['pl-3', 'Боковая плита для бронежилета Бр3', 9000],
          ['pl-5', 'Боковая плита для бронежилета Бр5', 11500]] as [$id, $name, $price]) {
    Db::insert('products_cache', [
        'moysklad_id' => $id, 'name' => $name, 'name_normalized' => mb_strtolower($name),
        'price' => $price, 'stock' => 3, 'reserved' => 0, 'unit' => 'шт.', 'product_type' => 'product',
    ]);
}

$found = ProductMatcher::findCandidates('Боковая плита для бронежилета Бр2', 5);
ok('нашлась именно Бр2', ($found[0]['moysklad_id'] ?? '') === 'pl-2',
   json_encode(array_column($found, 'name'), JSON_UNESCAPED_UNICODE));
ok('и ни одной чужой плиты рядом', count($found) === 1,
   json_encode(array_column($found, 'name'), JSON_UNESCAPED_UNICODE));
ok('запрос без класса вообще находит все три',
   count(ProductMatcher::findCandidates('Боковая плита для бронежилета', 5)) === 3);

echo "\n2. Товар с модификациями выбирается сам — с вилкой цен\n";

Db::insert('products_cache', ['moysklad_id' => 'hl', 'name' => 'Шлем Протон',
    'name_normalized' => 'шлем протон', 'article' => 'PR', 'price' => 0, 'stock' => 0, 'reserved' => 0,
    'unit' => 'шт.', 'product_type' => 'product']);
foreach ([['S', 9000, 4], ['M', 10000, 20], ['L', 11500, 9]] as [$size, $price, $stock]) {
    Db::insert('products_cache', ['moysklad_id' => 'hl-' . $size, 'name' => 'Шлем Протон (Размер: ' . $size . ')',
        'name_normalized' => 'шлем протон размер ' . mb_strtolower($size), 'article' => 'PR-' . $size,
        'price' => $price, 'stock' => $stock, 'reserved' => 0, 'unit' => 'шт.',
        'product_type' => 'variant', 'parent_id' => 'hl', 'characteristics' => 'Размер: ' . $size]);
}
$rows = Db::all("SELECT * FROM products_cache WHERE moysklad_id='hl'");
$suggest = Variants::expandSuggest($rows);
$group = array_values(array_filter($suggest, fn($r) => !empty($r['is_group'])))[0] ?? [];
ok('товар целиком стоит в подсказке', ($group['moysklad_id'] ?? '') === 'hl');
ok('низ вилки — самая дешёвая модификация', (float)($group['price'] ?? 0) === 9000.0, (string)($group['price'] ?? ''));
ok('верх вилки — самая дорогая', (float)($group['price_max'] ?? 0) === 11500.0, (string)($group['price_max'] ?? ''));
ok('остаток — сумма по размерам', (int)($group['stock'] ?? 0) === 33, (string)($group['stock'] ?? ''));
ok('и размеры никуда не делись', count($suggest) === 4, (string)count($suggest));

echo "\n3. Обновление каталога не обнуляет остатки\n";

Db::q("UPDATE products_cache SET stock=12 WHERE moysklad_id='pl-2'");
// Ровно тот upsert, которым обновляется каталог: остаток в нём — ноль-заглушка
Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, price, stock, reserved, unit, product_type, source, updated_at)
       VALUES ('pl-2', 'Боковая плита для бронежилета Бр2', 'боковая плита для бронежилета бр2', 4300, 0, 0, 'шт.', 'product', 'moysklad', datetime('now'))
       ON CONFLICT(moysklad_id) DO UPDATE SET name=excluded.name, price=excluded.price,
            unit=excluded.unit, source='moysklad', updated_at=datetime('now')");
ok('цена обновилась', (float)Db::val("SELECT price FROM products_cache WHERE moysklad_id='pl-2'") === 4300.0);
ok('а остаток остался прежним', (int)Db::val("SELECT stock FROM products_cache WHERE moysklad_id='pl-2'") === 12);

echo "\n4. Имя вложения — человеческое\n";

$mgr = (int)Db::insert('managers', ['login' => 'yana', 'password_hash' => 'x', 'name' => 'Яна']);
$tmp = tempnam(sys_get_temp_dir(), 'kp');
file_put_contents($tmp, '%PDF-1.4');
$att = Outbox::accept(['name' => 'Счет_на_турникеты_для_АО_ТИКО_ПЛАСТИК.pdf', 'tmp_name' => $tmp,
                       'size' => 8, 'error' => UPLOAD_ERR_OK], $mgr);
ok('на диске имя со служебной приставкой', (bool)preg_match('/^[0-9a-f]{16}__/', $att['name']), $att['name']);
$resolved = Outbox::resolve([$att['name']], $mgr);
ok('а в письмо уходит имя без неё',
   ($resolved[0]['name'] ?? '') === 'Счет_на_турникеты_для_АО_ТИКО_ПЛАСТИК.pdf',
   (string)($resolved[0]['name'] ?? ''));
ok('и путь к файлу на месте', is_file($resolved[0]['path'] ?? ''));
@unlink($resolved[0]['path'] ?? '');

echo "\n5. Корзина: удалённое письмо возвращается\n";

$boxId = (int)Db::insert('mailboxes', ['name' => 'info', 'email' => 'info@atlant-armour.ru', 'is_active' => 1]);
$mailId = (int)Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'in', 'thread_key' => 'th-1', 'message_id' => '<a@b>',
    'subject' => 'Запрос КП', 'from_email' => 'client@example.ru', 'to_emails' => 'info@atlant-armour.ru',
    'body_text' => 'Пришлите КП на плиты', 'date_at' => '2026-09-17 10:00:00', 'is_read' => 0,
]);
MailSync::deleteMessage($mailId, $mgr);
ok('письмо ушло из архива', !Db::val("SELECT 1 FROM mail_messages WHERE id=?", [$mailId]));
$trash = MailSync::trash();
ok('и лежит в корзине', count($trash) === 1 && $trash[0]['subject'] === 'Запрос КП', json_encode(count($trash)));

$restored = MailSync::restoreFromTrash((int)$trash[0]['id']);
ok('вернулось обратно', $restored['restored'] === 1);
$back = Db::one("SELECT * FROM mail_messages WHERE id=?", [(int)$restored['mail_message_id']]);
ok('с темой и текстом', ($back['subject'] ?? '') === 'Запрос КП'
   && str_contains((string)($back['body_text'] ?? ''), 'КП на плиты'));
ok('и в той же переписке', ($back['thread_key'] ?? '') === 'th-1');
ok('корзина опустела', MailSync::trash() === []);

echo "\n6. Отвеченное письмо — не непрочитанное\n";

ok('пока не ответили — одно непрочитанное', MailThreads::unreadCount() === 1,
   (string)MailThreads::unreadCount());
Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'out', 'thread_key' => 'th-1', 'message_id' => '<our@b>',
    'subject' => 'Re: Запрос КП', 'from_email' => 'info@atlant-armour.ru', 'to_emails' => 'client@example.ru',
    'body_text' => 'Направляем КП', 'date_at' => '2026-09-17 11:00:00', 'is_read' => 1,
]);
ok('ответили — счётчик обнулился', MailThreads::unreadCount() === 0, (string)MailThreads::unreadCount());

echo "\n7. Письмо, которое модель не разобрала, не теряется\n";

$hard = (int)Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'in', 'thread_key' => 'th-2', 'message_id' => '<c@d>',
    'subject' => 'Прошу КП', 'from_email' => 'two@example.ru', 'to_emails' => 'info@atlant-armour.ru',
    'body_text' => "Добрый день!\nПрошу направить КП:\nБоковая плита для бронежилета Бр2 — 10 шт.",
    'date_at' => '2026-09-17 12:00:00',
]);
// Ключей нет — разбор моделью падает на каждом заходе
MailSync::processInbound($boxId);
$row = Db::one("SELECT processed_at, triage_attempts FROM mail_messages WHERE id=?", [$hard]);
ok('письмо не помечено разобранным', $row['processed_at'] === null, (string)($row['processed_at'] ?? 'null'));
ok('но попытка засчитана', (int)$row['triage_attempts'] === 1, (string)$row['triage_attempts']);

MailSync::processInbound($boxId);
MailSync::processInbound($boxId);
$row = Db::one("SELECT processed_at, request_id FROM mail_messages WHERE id=?", [$hard]);
ok('после трёх неудач запрос заведён правилами', !empty($row['request_id']), json_encode($row));
ok('и письмо наконец разобрано', $row['processed_at'] !== null);
$items = Db::all("SELECT * FROM request_items WHERE request_id=?", [(int)$row['request_id']]);
ok('позиция из письма нашла свою плиту',
   count($items) === 1 && ($items[0]['moysklad_product_id'] ?? '') === 'pl-2',
   json_encode(array_column($items, 'product_name'), JSON_UNESCAPED_UNICODE));

echo "\n8. Пустая карточка убирается с доски насовсем\n";

$boardId = (int)Boards::singleton()['id'];
$colId = (int)Db::val("SELECT id FROM board_columns WHERE board_id=? ORDER BY position LIMIT 1", [$boardId]);
$cpId = (int)Db::insert('counterparties', ['name' => 'ООО «Пусто»']);
$cardId = (int)Db::insert('board_cards', ['column_id' => $colId, 'position' => 0, 'counterparty_id' => $cpId]);
ok('карточка без писем убирается совсем', Boards::dismissCard($cardId) === true);
ok('и строки её больше нет', !Db::val("SELECT 1 FROM board_cards WHERE id=?", [$cardId]));

echo "\n" . ($fail ? "ПРОВАЛЕНО: $fail\n" : "Всё сошлось\n");
exit($fail ? 1 : 0);
