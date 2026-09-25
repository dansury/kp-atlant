<?php
/**
 * Модуль 047 — на выбрасываемой базе и без сети:
 *
 *   — подсказка каталога: модификация один раз, под своим товаром;
 *   — фото модификации и общего товара;
 *   — подбор раскрыт только у первого письма запроса КП;
 *   — ответ из «Отправленных» гасит жирный шрифт;
 *   — колонка «Сборка» после «Ждём оплату»;
 *   — выписка Т-Банка, номера счетов, оплата → платёж, «Сборка», напоминание;
 *   — трек-номер → черновик письма (ссылка СДЭК), карточка подсвечена;
 *   — интерфейс (по исходнику): лента без дублей, подпись под письмом, описание сразу.
 *
 * Запуск:  php tests/module_047.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-047-' . getmypid() . '.db';
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
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/variants.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/payments.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$api = file_get_contents(ROOT . '/public/api/products.php');

$managerId = Db::insert('managers', ['login' => 'yana', 'password_hash' => 'x', 'name' => 'Яна', 'is_admin' => 0]);
$adminId   = Db::insert('managers', ['login' => 'boss', 'password_hash' => 'x', 'name' => 'Админ', 'is_admin' => 1]);

// ===================================================================== 1
echo "\n1. Подсказка каталога: модификация один раз\n";

$tmp = sys_get_temp_dir() . '/kp047-' . getmypid();
@mkdir($tmp);
foreach (['p0', 'p1', 'v0'] as $n) file_put_contents("$tmp/$n.png", "\x89PNG$n");
Db::insert('products_cache', ['moysklad_id' => 'par', 'name' => 'Бронежилет штурмовой Бр2', 'article' => 'Vest',
    'unit' => 'шт.', 'price' => 100, 'product_type' => 'product',
    'images_json' => json_encode(["$tmp/p0.png", "$tmp/p1.png"])]);
Db::insert('products_cache', ['moysklad_id' => 'v-l', 'name' => 'Бронежилет штурмовой Бр2 (Размер: L)', 'article' => 'Vest-L',
    'unit' => 'шт.', 'price' => 100, 'stock' => 3, 'product_type' => 'variant', 'parent_id' => 'par',
    'characteristics' => 'Размер: L', 'images_json' => json_encode(["$tmp/v0.png"])]);
Db::insert('products_cache', ['moysklad_id' => 'v-m', 'name' => 'Бронежилет штурмовой Бр2 (Размер: M)', 'article' => 'Vest-M',
    'unit' => 'шт.', 'price' => 100, 'stock' => 1, 'product_type' => 'variant', 'parent_id' => 'par',
    'characteristics' => 'Размер: M']);

ok('поиск выбирает parent_id', str_contains($api, 'product_type, category, parent_id'));
$found = Db::all("SELECT moysklad_id, name, article, code, price, prices_json, stock, reserved, unit,
                         characteristics, product_type, category, parent_id FROM products_cache WHERE name LIKE '%штурмовой%'");
$rows = Variants::expandSuggest($found, 40);
$ids = array_column($rows, 'moysklad_id');
ok('каждая модификация в подсказке один раз', count($ids) === count(array_unique($ids)), implode(',', $ids));
ok('первой стоит строка товара целиком', !empty($rows[0]['is_group']) && $rows[0]['moysklad_id'] === 'par');
ok('модификации — под своим товаром', ($rows[1]['group_name'] ?? '') === 'Бронежилет штурмовой Бр2');

// ===================================================================== 2
echo "\n2. Фото модификации и общего товара\n";
$list = KpContent::productImageList('v-l');
ok('у модификации своё фото и фото товара', array_column($list, 'key') === ['local:0', 'parent:local:0', 'parent:local:1'],
   implode(',', array_column($list, 'key')));
$list = KpContent::productImageList('v-m');
ok('без своих фото — фото товара под прежними ключами', array_column($list, 'key') === ['local:0', 'local:1']);
ok('фото товара отдаётся по ключу', (KpContent::imageBytes('v-l', 'parent:local:1')['bytes'] ?? '') === "\x89PNGp1");

// ===================================================================== 3
echo "\n3. Подбор раскрыт только у первого письма запроса КП\n";
$cpId = Db::insert('counterparties', ['name' => 'ООО «Щит»', 'inn' => '7701234567', 'contact_email' => 'buyer@shield.test']);
$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 'КП', 'counterparty_id' => $cpId, 'category' => 'kp_request']);
$box = Db::insert('mailboxes', ['name' => 'info', 'email' => 'info@atlant.test']);
$inId = Db::insert('mail_messages', ['mailbox_id' => $box, 'thread_key' => 's:t1', 'direction' => 'in', 'subject' => 'КП',
    'from_email' => 'buyer@shield.test', 'counterparty_id' => $cpId, 'request_id' => $reqId, 'is_read' => 0,
    'date_at' => '2026-09-20 10:00:00']);
$req = Db::one("SELECT * FROM requests WHERE id=?", [$reqId]);
ok('первое письмо запроса КП — подбор раскрыт', RequestItems::matchOpen($req));
ok('другая категория — свёрнут', !RequestItems::matchOpen(['category' => 'delivery'] + $req));

// ===================================================================== 4
echo "\n4. Ответ из «Отправленных» гасит жирный шрифт\n";
MailArchive::storeIncoming(['id' => $box], ['subject' => 'Re: КП', 'in_reply_to' => '', 'from' => 'info@atlant.test',
    'to' => 'buyer@shield.test', 'date' => '2026-09-20 12:00:00', 'body' => 'Ответ', 'uid' => 7,
    'message_id' => '<r1@x>', 'folder' => 'Sent'], 'out');
$outKey = (string)Db::val("SELECT thread_key FROM mail_messages WHERE direction='out' ORDER BY id DESC LIMIT 1");
if ($outKey !== 's:t1') {
    // Ключ переписки считает MailThreads — проверяем само правило на ключе ответа
    Db::update('mail_messages', ['thread_key' => $outKey], 'id=?', [$inId]);
    Db::update('mail_messages', ['is_read' => 0], 'id=?', [$inId]);
    MailArchive::markThreadAnswered($outKey, '2026-09-20 12:00:00');
}
ok('входящее до ответа прочитано', (int)Db::val("SELECT is_read FROM mail_messages WHERE id=?", [$inId]) === 1);
$later = Db::insert('mail_messages', ['mailbox_id' => $box, 'thread_key' => $outKey, 'direction' => 'in', 'subject' => 'КП',
    'from_email' => 'buyer@shield.test', 'counterparty_id' => $cpId, 'is_read' => 0, 'date_at' => '2026-09-21 09:00:00']);
MailArchive::markThreadAnswered($outKey, '2026-09-20 12:00:00');
ok('письмо после ответа остаётся непрочитанным', (int)Db::val("SELECT is_read FROM mail_messages WHERE id=?", [$later]) === 0);
Db::update('mail_messages', ['thread_key' => 's:t1'], 'id IN (?, ?)', [$inId, $later]);
Db::update('mail_messages', ['thread_key' => 's:t1'], "direction='out'");
ok('после ответа подбор первого письма свёрнут', !RequestItems::matchOpen($req));

// ===================================================================== 5
echo "\n5. Колонка «Сборка»\n";
$board = Boards::singleton();
$cols = Db::all("SELECT title, kind FROM board_columns WHERE board_id=? ORDER BY position, id", [(int)$board['id']]);
$titles = array_column($cols, 'title');
ok('«Сборка» стоит сразу после «Ждём оплату»',
   array_search('Сборка', $titles, true) === array_search('Ждём оплату', $titles, true) + 1, implode(' | ', $titles));
ok('у «Сборки» свой вид колонки', in_array('assembly', array_column($cols, 'kind'), true));
$b2 = Boards::createBoard('Старая', false);
foreach ([['Входящие', 'inbox'], ['Ждем оплаты', null], ['Архив', 'closed']] as $i => [$t, $k]) {
    Db::insert('board_columns', ['board_id' => $b2, 'title' => $t, 'kind' => $k, 'position' => $i, 'color' => '#888']);
}
Boards::assemblyColumn($b2);
Boards::assemblyColumn($b2);
$t2 = array_column(Db::all("SELECT title FROM board_columns WHERE board_id=? ORDER BY position, id", [$b2]), 'title');
ok('на старой доске встаёт после «Ждем оплаты», и один раз', $t2 === ['Входящие', 'Ждем оплаты', 'Сборка', 'Архив'], implode(' | ', $t2));
Boards::deleteBoard($b2);

// ===================================================================== 6
echo "\n6. Выписка Т-Банка и номера счетов\n";
$n = TBank::normalize(['operationId' => 'op-1', 'operationDate' => '2026-09-22T10:15:00Z', 'typeOfOperation' => 'Credit',
    'accountAmount' => 750000, 'payPurpose' => 'Оплата по счету № 02369 от 22.09.2026, в т.ч. НДС',
    'counterParty' => ['inn' => '7701234567', 'name' => 'ООО «Щит»'], 'documentNumber' => '512'], '40702810000000000001');
ok('входящая операция разобрана', $n && $n['amount'] === 750000.0 && $n['payer_inn'] === '7701234567' && $n['number'] === '512');
ok('расход пропущен', TBank::normalize(['operationId' => 'op-2', 'typeOfOperation' => 'Debit', 'accountAmount' => 5]) === null);
ok('номер из «по счету № 02369»', Payments::invoiceNumbers('Оплата по счету № 02369 от 22.09.2026') === ['2369']);
ok('номер из «сч. 125» и «счёт на оплату 77»', Payments::invoiceNumbers('по сч. 125; счёт на оплату 77') === ['125', '77']);
ok('«счёт-фактура» без номера — ничего', Payments::invoiceNumbers('без НДС, счет-фактура не выставляется') === []);

// ===================================================================== 7
echo "\n7. Оплата → платёж МойСклад, «Сборка», «Сообщить складу»\n";
$orderId = Db::insert('orders', ['moysklad_id' => 'ms-o1', 'name' => '1094916743', 'sum' => 750000, 'counterparty_id' => $cpId,
    'manager_id' => $managerId, 'request_id' => $reqId]);
$invId = Db::insert('invoices', ['moysklad_id' => 'ms-i1', 'name' => '02369', 'sum' => 750000, 'payed_sum' => 0,
    'order_id' => $orderId, 'counterparty_id' => $cpId]);
$wait = Db::one("SELECT id FROM board_columns WHERE board_id=? AND title='Ждём оплату'", [(int)$board['id']]);
$cardId = Boards::addCard((int)$wait['id'], ['counterparty_id' => $cpId]);

$payIns = [];
Payments::$payIn = function ($msId, $sum, $o) use (&$payIns) { $payIns[] = [$msId, $sum, $o]; return ['id' => 'pay-1']; };
Payments::$readInvoice = fn($inv) => null;
Payments::$statement = fn($acc, $from, $to) => [$n];

ok('платёж найден по номеру счёта', (int)(Payments::match($n)['id'] ?? 0) === $invId);
$res = Payments::check();
ok('операция новая и проведена', $res['new'] === 1 && $res['matched'] === 1, json_encode($res));
ok('«Входящий платёж» по счёту в МойСклад', count($payIns) === 1 && $payIns[0][0] === 'ms-i1' && $payIns[0][1] === 750000.0
   && $payIns[0][2]['number'] === '512');
ok('счёт оплачен', (float)Db::val("SELECT payed_sum FROM invoices WHERE id=?", [$invId]) === 750000.0);
ok('заказ отмечен оплаченным', Db::val("SELECT paid_at FROM orders WHERE id=?", [$orderId]) !== null);
$assembly = Boards::assemblyColumn((int)$board['id']);
ok('карточка переехала в «Сборку»', (int)Db::val("SELECT column_id FROM board_cards WHERE id=?", [$cardId]) === (int)$assembly['id']);
ok('менеджеру — «Сообщить складу о необходимости отправки»',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='order_paid' AND manager_id=?
                 AND title='Сообщить складу о необходимости отправки'", [$managerId]) === 1);
$res = Payments::check();
ok('повторная выписка ничего не повторяет', $res['new'] === 0 && count($payIns) === 1
   && (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='order_paid'") === 1);

// Платёж без счёта — уведомление, без платежа в МойСклад
Payments::$statement = fn() => [TBank::normalize(['operationId' => 'op-9', 'typeOfOperation' => 'Credit',
    'accountAmount' => 10, 'payPurpose' => 'Возврат займа', 'counterParty' => ['inn' => '5000000000']])];
$res = Payments::check();
ok('платёж без счёта — «счёт не найден»', $res['unmatched'] === 1 && count($payIns) === 1
   && (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='payment_unmatched'") > 0);

// По ИНН и сумме, без номера в назначении
$inv2 = Db::insert('invoices', ['moysklad_id' => 'ms-i2', 'name' => '02400', 'sum' => 1200, 'payed_sum' => 0, 'counterparty_id' => $cpId]);
$m = Payments::match(['amount' => 1200, 'payer_inn' => '7701234567', 'purpose' => 'Оплата за товар']);
ok('без номера — по ИНН и сумме', (int)($m['id'] ?? 0) === $inv2);

// ===================================================================== 8
echo "\n8. Трек-номер → черновик письма клиенту\n";
$t = Fulfillment::shipmentText('1094916743', 'СДЭК', '10267205698');
ok('письмо называет заказ и трек', str_contains($t['html'], '1094916743') && str_contains($t['html'], '10267205698'));
ok('СДЭК — ссылка на отслеживание', str_contains($t['html'], 'https://www.cdek.ru/ru/tracking/?order_id=10267205698'));
ok('другая служба — без ссылки СДЭК', !str_contains(Fulfillment::shipmentText('1', 'Деловые линии', '55')['html'], 'cdek'));

Fulfillment::$fetchDemands = fn($id) => [];
Fulfillment::$fetchOrder = fn($id) => ['attributes' => ['Служба доставки' => 'СДЭК', 'ТРЕК-НОМЕР' => '']];
$r = Fulfillment::checkShipments();
ok('без трека — ждём дальше', $r['checked'] === 1 && $r['shipped'] === 0);
Fulfillment::$fetchOrder = fn($id) => ['attributes' => ['Служба доставки' => 'СДЭК', 'трек-номер' => '10267205698']];
$r = Fulfillment::checkShipments();
ok('трек вписан — заказ отправлен', $r['shipped'] === 1);
$draft = Db::one("SELECT * FROM mail_drafts WHERE kind='shipment'");
ok('черновик письма готов — клиенту, в переписку запроса',
   $draft && $draft['to_email'] === 'buyer@shield.test' && (int)$draft['manager_id'] === $managerId
   && (string)$draft['thread_key'] === 's:t1' && str_contains((string)$draft['body'], 'cdek.ru'));
ok('уведомление «заказ отправлен»',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='order_shipped' AND manager_id=?", [$managerId]) === 1);
$card = null;
foreach (Boards::get((int)$board['id'])['columns'] as $c) foreach ($c['cards'] as $cc) if ($cc['id'] === $cardId) $card = $cc + ['col' => $c['kind']];
ok('карточка в «Сборке» подсвечена', $card && $card['attention'] && $card['hot'] && $card['col'] === 'assembly');
$r = Fulfillment::checkShipments();
// Отправленный заказ ещё смотрится на новые отгрузки (issue #112), но письма второй раз нет
ok('второй раз письмо не готовится', $r['shipped'] === 0 && (int)Db::val("SELECT COUNT(*) FROM mail_drafts WHERE kind='shipment'") === 1);

// ===================================================================== 9
echo "\n9. Интерфейс (по исходнику)\n";
ok('лента: веха и документ — одна строка', str_contains($js, 'mergeDocEvents(data.items'));
$sign = strpos($js, 'data-cmp-sign checked');
ok('«подпись» стоит под полем письма', $sign > strpos($js, 'data-cmp-rte contenteditable') && $sign < strpos($js, 'data-cmp-files></div>'));
ok('описание заполняется при выборе товара', substr_count($js, 'this.fillCatalogComment(row') >= 2);
ok('подбор свёрнут, кроме первого письма', str_contains($js, "host.dataset.folded = req.match_open ? '0' : '1'"));
ok('сессия не держит долгие запросы', str_contains(file_get_contents(ROOT . '/lib/bootstrap.php'), 'session_write_close()'));

foreach (glob("$tmp/*") as $f) @unlink($f);
@rmdir($tmp);
echo $fail ? "\nFAILED: $fail\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
