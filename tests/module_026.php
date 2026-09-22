<?php
/**
 * Модуль 026 целиком, на выбрасываемой базе и без сети:
 *
 *   — письмо в архив уносит с доски карточку, в которой писем не осталось,
 *     а «Вернуть в работу» возвращает её;
 *   — «Прочитано» на отмеченных карточках снимает жирный шрифт;
 *   — позиции вытаскиваются из «… в количестве 5 шт. Или аналог» без модели;
 *   — «под заказ» считается по живому остатку и по модификациям, а не
 *     записывается один раз навсегда; своё примечание не трогается;
 *   — условия КП печатаются одним правимым блоком, и последняя правка
 *     становится заготовкой следующего КП;
 *   — оговорки под фотографиями в документе больше нет;
 *   — доставка печатается отдельной строкой и входит в «Итого»;
 *   — при подборе видно количества модификаций, а не ноль товара;
 *   — резерв под неоплаченный счёт просится сняться в срок.
 *
 * Запуск:  php tests/module_026.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-026-' . getmypid() . '.db';
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
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/mail_threads.php';
require_once ROOT . '/lib/item_lines.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/variants.php';
require_once ROOT . '/lib/terms.php';
require_once ROOT . '/lib/kp_terms.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/reserves.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('ALT_ENABLED', '0');
Settings::set('KP_SHOW_SITE_LINK', '0');

$mgr = Db::insert('managers', ['login' => 'yana', 'name' => 'Яна',
                               'password_hash' => 'x', 'is_admin' => 1]);
$boxId = Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru',
                                  'is_active' => 1, 'is_default' => 1]);

/** Письмо в базе — ровно столько полей, сколько нужно проверке. */
function letter(array $o): int {
    return Db::insert('mail_messages', $o + [
        'mailbox_id' => $GLOBALS['boxId'], 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
        'subject' => 'Запрос КП', 'from_email' => 'client@zavod.ru', 'from_name' => 'Клиент',
        'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'текст', 'is_read' => 0,
        'date_at' => '2026-09-10 10:00:00',
    ]);
}

// =====================================================================  1

echo "\n== 1. Архив уносит с доски карточку, в которой писем не осталось ==\n";

$cpId = Db::insert('counterparties', ['name' => 'ООО «Завод»', 'email_domain' => 'zavod.ru']);
letter(['counterparty_id' => $cpId, 'thread_key' => 't:one']);
letter(['counterparty_id' => $cpId, 'thread_key' => 't:two', 'date_at' => '2026-09-11 10:00:00']);

$boardId = (int)Boards::singleton()['id'];
Boards::sync($boardId, true);
$cards = fn() => (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE counterparty_id=?", [$cpId]);
ok('карточка компании появилась сама', $cards() === 1);

MailSync::archiveThread('t:one', $mgr);
ok('одна переписка в архиве — карточка на месте', $cards() === 1);

MailSync::archiveThread('t:two', $mgr);
ok('писем не осталось — карточки на доске тоже', $cards() === 0);

MailSync::unarchiveThread('t:two');
Boards::sync($boardId, true);
ok('вернули письмо в работу — вернулась и карточка', $cards() === 1);

// =====================================================================  2

echo "\n== 2. «Прочитано» снимает жирный шрифт ==\n";

$cardId = (int)Db::val("SELECT id FROM board_cards WHERE counterparty_id=?", [$cpId]);
$card = fn() => Boards::get($boardId)['columns'][0]['cards'][0] ?? [];

ok('клиент написал последним — карточка жирная', !empty($card()['unanswered']));

Boards::bulk([$cardId], 'read', [], $mgr);
ok('письма прочитаны', (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE is_read=0 AND direction='in' AND archived_at IS NULL") === 0);
ok('и карточка больше не жирная', empty($card()['unanswered']));
ok('и не горит', empty($card()['hot']));

letter(['counterparty_id' => $cpId, 'thread_key' => 't:two', 'date_at' => '2026-09-20 10:00:00',
        'subject' => 'Ещё вопрос']);
ok('новое письмо поднимает карточку обратно', !empty($card()['unanswered']));

// =====================================================================  3

echo "\n== 3. Позиции из «в количестве 5 шт. Или аналог» ==\n";

$body = "Добрый день!\n"
      . "Просим вас рассмотреть возможность поставки в адрес ООО «Газпромнефть-Терминал» "
      . "и направить КП на поставку следующей продукции:\n"
      . "Тактические наушники AMP в количестве 5 шт. Или аналог.\n"
      . "Доставку просим включить в стоимость товара.";
$found = ItemLines::extract($body);
ok('позиция вытащена ровно одна', count($found) === 1, (string)count($found));
ok('название без «или аналог»', ($found[0]['name'] ?? '') === 'Тактические наушники AMP',
   (string)($found[0]['name'] ?? ''));
ok('количество взято из письма', (float)($found[0]['qty'] ?? 0) === 5.0);

$list = ItemLines::extract("1. Шлем Протон СВМПЭ - 10 шт.\n2. Бронежилет 6Б45, 3 компл.\n"
                         . "Срок поставки 30 календарных дней.\nС уважением, Иван");
ok('нумерация списка в название не попала', ($list[0]['name'] ?? '') === 'Шлем Протон СВМПЭ',
   (string)($list[0]['name'] ?? ''));
ok('две позиции, а не четыре', count($list) === 2, (string)count($list));
ok('срок поставки позицией не стал',
   !in_array('Срок поставки', array_column($list, 'name'), true));
ok('вежливости позициями не становятся',
   ItemLines::extract('Здравствуйте! Интересует стоимость и наличие.') === []);

// =====================================================================  4

echo "\n== 4. Остаток товара с модификациями — сумма модификаций ==\n";

Db::insert('products_cache', ['moysklad_id' => 'p-1', 'name' => 'Тактические штаны',
                              'name_normalized' => 'тактические штаны', 'article' => 'ТШ',
                              'price' => 5000, 'stock' => 0, 'reserved' => 0, 'unit' => 'шт.',
                              'product_type' => 'product']);
foreach ([['v-s', 'Тактические штаны (Размер: S)', 'Размер: S', 5],
          ['v-m', 'Тактические штаны (Размер: M)', 'Размер: M', 13],
          ['v-l', 'Тактические штаны (Размер: L)', 'Размер: L', 7]] as [$id, $name, $ch, $stock]) {
    Db::insert('products_cache', ['moysklad_id' => $id, 'name' => $name,
                                  'name_normalized' => mb_strtolower($name), 'article' => $id,
                                  'price' => 5000, 'stock' => $stock, 'reserved' => 0, 'unit' => 'шт.',
                                  'product_type' => 'variant', 'parent_id' => 'p-1',
                                  'characteristics' => $ch]);
}

$breakdown = Variants::stockOf('p-1');
ok('остаток родителя — сумма размеров', ($breakdown['free'] ?? 0) === 25, (string)($breakdown['free'] ?? 0));
$bySize = array_column($breakdown['items'], 'free', 'label');
ok('и видно, какого размера сколько',
   $bySize === ['L' => 7, 'M' => 13, 'S' => 5],
   json_encode($bySize, JSON_UNESCAPED_UNICODE));
ok('метка размера читается', array_column($breakdown['items'], 'label') === ['L', 'M', 'S'],
   json_encode(array_column($breakdown['items'], 'label'), JSON_UNESCAPED_UNICODE));

$parent = Db::one("SELECT * FROM products_cache WHERE moysklad_id='p-1'");
ok('freeStock товара с модификациями — 25', Variants::freeStock($parent) === 25);
ok('а самой модификации — её собственный',
   Variants::freeStock(Db::one("SELECT * FROM products_cache WHERE moysklad_id='v-m'")) === 13);
ok('у товара без модификаций остаток свой',
   Variants::stockOf('v-m') === null);

// =====================================================================  5

echo "\n== 5. «Под заказ» пересчитывается, а не живёт вечно ==\n";

ok('пустой склад — надпись ставится', Terms::stockNote(null, 0, true) === 'под заказ');
ok('товар появился — надпись уходит', Terms::stockNote('под заказ', 4, true) === null);
ok('своё примечание менеджера не трогается',
   Terms::stockNote('только предоплата', 0, true) === 'только предоплата');
ok('строки без товара надписи не получают', Terms::stockNote(null, 0, false) === null);

$reqId = Db::insert('requests', ['source' => 'email', 'counterparty_id' => $cpId, 'raw_text' => $body,
                                 'email_from' => 'client@zavod.ru', 'status' => 'new']);
$itemId = Db::insert('request_items', ['request_id' => $reqId, 'position' => 1,
                                       'raw_name' => 'Тактические штаны', 'quantity' => 2,
                                       'moysklad_product_id' => 'p-1', 'product_name' => 'Тактические штаны',
                                       'unit' => 'шт.', 'price' => 5000,
                                       // Так строка и лежала: остаток нулевой, надпись красная
                                       'stock' => 0, 'notes' => 'под заказ']);
RequestItems::refreshStock($reqId);
$row = Db::one("SELECT * FROM request_items WHERE id=?", [$itemId]);
ok('остаток строки пересчитан по модификациям', (int)$row['stock'] === 25, (string)$row['stock']);
ok('и «под заказ» из строки ушло', $row['notes'] === null, (string)$row['notes']);

Db::update('request_items', ['notes' => 'везём от производителя'], 'id=?', [$itemId]);
Db::q("UPDATE products_cache SET stock=0 WHERE parent_id='p-1'");
RequestItems::refreshStock($reqId);
ok('чужое примечание пересчёт не переписал',
   Db::val("SELECT notes FROM request_items WHERE id=?", [$itemId]) === 'везём от производителя');
Db::q("UPDATE products_cache SET stock=13 WHERE moysklad_id='v-m'");

// =====================================================================  6

echo "\n== 6. Условия КП — один правимый блок ==\n";

$proposalId = Db::insert('proposals', ['request_id' => $reqId, 'counterparty_id' => $cpId,
                                       'manager_id' => $mgr, 'vat_rate' => 5,
                                       'execution_days' => 45, 'validity_days' => 10,
                                       'terms_text' => KpTerms::defaultText()]);
Db::insert('proposal_items', ['proposal_id' => $proposalId, 'position' => 1,
                              'product_name' => 'Тактические штаны', 'moysklad_product_id' => 'p-1',
                              'unit' => 'шт.', 'quantity' => 2, 'price' => 5000, 'is_confirmed' => 1]);
Db::insert('proposal_items', ['proposal_id' => $proposalId, 'position' => 2,
                              'product_name' => 'Наушники AMP', 'moysklad_product_id' => null,
                              'unit' => 'шт.', 'quantity' => 5, 'price' => 12000, 'is_confirmed' => 1]);
Requisites::freeze($proposalId);

$html = PdfGenerator::html($proposalId);
ok('годовой гарантии по умолчанию нет', !str_contains($html, 'гарантию в течение года'));
// По умолчанию доставка включена в стоимость товаров, и оговорки про
// отдельную оплату в тексте нет (issue #60)
ok('по умолчанию доставка включена в цену — оговорки об отдельной оплате нет',
   !str_contains($html, 'Доставка в стоимость не включена'));
ok('срок исполнения подставлен из КП', str_contains($html, 'договора 45 календарных дней'));
ok('срок действия цены — тоже', str_contains($html, 'в течение 10 дней'));

Settings::set('KP_DELIVERY_MODE', 'line');
$htmlLine = PdfGenerator::html($proposalId);
ok('настройка «отдельной строкой» возвращает оговорку',
   str_contains($htmlLine, 'Доставка в стоимость не включена'));
Settings::forget('KP_DELIVERY_MODE');

KpTerms::remember("Только самовывоз со склада в Москве.");
ok('правка запомнена на следующее КП', KpTerms::defaultText() === 'Только самовывоз со склада в Москве.');

Db::update('proposals', ['terms_text' => 'Только самовывоз со склада в Москве.'], 'id=?', [$proposalId]);
$html = PdfGenerator::html($proposalId);
ok('и печатается вместо прежних условий', str_contains($html, 'Только самовывоз'));
ok('прежних зашитых фраз в документе нет', !str_contains($html, 'твёрдой и не подлежит изменению'));

// КП, собранное до модуля 026, печатает то, с чем его подписывали
$oldId = Db::insert('proposals', ['request_id' => $reqId, 'counterparty_id' => $cpId,
                                  'execution_days' => 30, 'validity_days' => 14,
                                  'conditions_text' => 'Старые условия поставки.',
                                  'warranty_text' => 'Гарантия год.']);
Db::insert('proposal_items', ['proposal_id' => $oldId, 'position' => 1, 'product_name' => 'Шлем',
                              'unit' => 'шт.', 'quantity' => 1, 'price' => 1000]);
Requisites::freeze($oldId);
$oldHtml = PdfGenerator::html($oldId);
ok('старое КП печатает свои условия', str_contains($oldHtml, 'Старые условия поставки.'));
ok('и свою гарантию', str_contains($oldHtml, 'Гарантия год.'));
ok('и свой срок действия', str_contains($oldHtml, 'в течение 14 дней'));

// =====================================================================  7

echo "\n== 7. Оговорки под фотографиями больше нет ==\n";

ok('значение по умолчанию пустое',
   trim((string)Db::val("SELECT value FROM settings WHERE key='kp_images_note'")) === '',
   (string)Db::val("SELECT value FROM settings WHERE key='kp_images_note'"));
ok('и в документе её нет', !str_contains($html, 'приведены для примера'));

// =====================================================================  8

echo "\n== 8. Доставка отдельной строкой или в цене товара (issue #60) ==\n";

ok('пока не включена — в документе её нет', !str_contains($html, 'Доставка до склада'));

Db::update('proposals', ['delivery_on' => 1, 'delivery_name' => 'Доставка до склада',
                         'delivery_price' => 3500], 'id=?', [$proposalId]);

// По умолчанию доставка распределяется по позициям — отдельной строки нет,
// но «Итого» то же самое
$html = PdfGenerator::html($proposalId);
ok('по умолчанию отдельной строки нет — доставка в ценах позиций',
   !str_contains($html, 'Доставка до склада'));
ok('и вошла в «Итого»', str_contains($html, 'Итого: 73 500 руб.'),
   (string)(preg_match('/Итого: [^<]+/u', $html, $m) ? $m[0] : ''));

Settings::set('KP_DELIVERY_MODE', 'line');
$html = PdfGenerator::html($proposalId);
ok('настройка «отдельной строкой» печатает её как раньше', str_contains($html, 'Доставка до склада'));
ok('со своей ценой', str_contains($html, '3 500 руб.'));
// 2 × 5000 + 5 × 12000 + 3500
ok('и «Итого» то же самое', str_contains($html, 'Итого: 73 500 руб.'),
   (string)(preg_match('/Итого: [^<]+/u', $html, $m) ? $m[0] : ''));
Settings::forget('KP_DELIVERY_MODE');

// =====================================================================  9

echo "\n== 9. Резерв под неоплаченный счёт ==\n";

Settings::set('MS_RESERVE_DAYS', '14');
$orderId = Db::insert('orders', ['counterparty_id' => $cpId, 'proposal_id' => $proposalId,
                                 'manager_id' => $mgr, 'moysklad_id' => 'ms-order-1',
                                 'name' => '00001', 'sum' => 73500, 'state_name' => 'Резерв',
                                 'applicable' => 1,
                                 'reserve_until' => date('Y-m-d H:i:s', time() + 14 * 86400)]);
$invId = Db::insert('invoices', ['order_id' => $orderId, 'counterparty_id' => $cpId,
                                 'moysklad_id' => 'ms-inv-1', 'name' => '00001',
                                 'sum' => 73500, 'payed_sum' => 0]);

$state = fn() => Reserves::state(Db::one("SELECT * FROM orders WHERE id=?", [$orderId]));
ok('резерв держится', $state()['held']);
ok('но срок ещё не вышел', !$state()['due']);
ok('и напоминать пока не о чем', Reserves::due() === []);

Db::update('orders', ['reserve_until' => date('Y-m-d H:i:s', time() - 3600)], 'id=?', [$orderId]);
ok('срок вышел — пора напоминать', $state()['due']);
ok('и заказ попал в список', count(Reserves::due()) === 1);
ok('в напоминании видно, сколько не заплачено', abs($state()['unpaid'] - 73500) < 0.01);

Db::update('invoices', ['payed_sum' => 73500], 'id=?', [$invId]);
ok('счёт оплатили — напоминать не о чем', !$state()['due'] && Reserves::due() === []);

Db::update('invoices', ['payed_sum' => 0], 'id=?', [$invId]);
Db::update('orders', ['applicable' => 0, 'reserve_released_at' => date('Y-m-d H:i:s')], 'id=?', [$orderId]);
ok('снятый резерв в список не возвращается', Reserves::due() === []);
ok('и помечен снятым', $state()['released'] && !$state()['held']);

// =====================================================================

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
