<?php
/**
 * Модуль 036: письмо самому себе, вилка цен, общие условия КП, аналог и
 * групповой перенос карточек.
 *
 * Проверяется ровно то, ради чего модуль появился:
 *   — письмо с нашего адреса на наш же появляется во «Входящих», а не
 *     съедается копией из «Отправленных»;
 *   — копия себе в «Копию» письма клиенту при этом по-прежнему одна;
 *   — товар без своей цены отвечает вилкой по модификациям, а одинаковые цены
 *     модификаций — одной ценой;
 *   — модификация без цены берёт цену товара, и по нужному ТИПУ цены;
 *   — вилка печатается в КП, в тексте письма и делает «Итого» словом «от»;
 *   — общие условия КП применяются ко всем строкам и запоминаются за
 *     менеджером, а руками вписанную цену не трогают;
 *   — галочка «аналог» кладёт в КП слова клиента, и они печатаются над
 *     названием нашего товара;
 *   — отмеченные карточки переезжают группой, своим порядком и насовсем.
 *
 * Run:  php tests/module_036.php
 *
 * База создаётся в системном временном каталоге — `data/kp.db` не открывается,
 * так что запуск на сервере не может задеть живые данные.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-036-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);

// Порядок слагаемых здесь — это вся безопасность теста: `+` оставляет ключ
// ЛЕВОГО операнда, поэтому подмена DB_PATH должна идти первой.
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
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/catalog.php';
require_once ROOT . '/lib/variants.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/kp_set.php';
require_once ROOT . '/lib/kp_text.php';
require_once ROOT . '/lib/terms.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/boards.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

// Ни модели, ни сети
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('KNOWLEDGE_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('KP_QR_CODE', '0');
Settings::set('KP_SHOW_SITE_LINK', '0');
Settings::set('ALTERNATIVES_ENABLED', '0');

Db::q("UPDATE legal_entities SET short_name='ООО \"АТЛАНТ АРМОР\"',
              full_name='ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ \"АТЛАНТ АРМОР\"',
              inn='9731154370', city='г. Москва', signatory_name='Сурков К.А.'
        WHERE is_active=1");

$managerId = Db::insert('managers', ['login' => 'yana', 'name' => 'Яна',
                                    'email' => 'yana@atlant-armour.ru',
                                    'password_hash' => 'x', 'is_admin' => 1]);

$boxId = Db::insert('mailboxes', [
    'name' => 'Основной', 'email' => 'info@atlant-armour.ru', 'is_active' => 1, 'is_default' => 1,
    'create_requests' => 1, 'sync_sent' => 1, 'imap_folder_in' => 'INBOX', 'imap_folder_sent' => 'Sent',
]);
$box = Mailboxes::get($boxId);

echo "\n1. Письмо самому себе видно во «Входящих»\n";

// Так менеджер проверяет почту: отправляет письмо себе. Сервис сам положил
// копию в архив отправленным, потом её же вернула синхронизация «Отправленных».
$self = [
    'message_id' => '<self-test-1@atlant-armour.ru>',
    'subject'    => 'Проверка почты',
    'from'       => 'info@atlant-armour.ru',
    'from_name'  => 'Атлант Армор',
    'to'         => 'info@atlant-armour.ru',
    'body'       => 'Проверяю, доходят ли письма до входящих. Это тестовое письмо самому себе.',
    'date'       => date('Y-m-d H:i:s'),
];
$outId = MailArchive::storeIncoming($box, $self + ['folder' => 'Sent', 'uid' => 11], 'out');
ok('отправленная копия легла в архив', $outId > 0);

$inId = MailArchive::storeIncoming($box, $self + ['folder' => 'INBOX', 'uid' => 22], 'in');
ok('входящая копия тоже легла — это ДВА факта, а не один', $inId > 0, "in=$inId out=$outId");
ok('и она действительно входящая',
   (string)Db::val("SELECT direction FROM mail_messages WHERE id=?", [$inId]) === 'in');
ok('второй раз то же письмо во «Входящие» не попадает',
   MailArchive::storeIncoming($box, $self + ['folder' => 'INBOX', 'uid' => 33], 'in') === 0);
ok('и в «Отправленных» оно по-прежнему одно',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE direction='out' AND message_id=?",
                [$self['message_id']]) === 1);

// Копия себе в «Копию» письма КЛИЕНТУ — не письмо самому себе: в «Кому» клиент,
// и ответ обязан остаться в переписке один
$cc = [
    'message_id' => '<answer-to-client@atlant-armour.ru>',
    'subject'    => 'Re: Запрос КП',
    'from'       => 'info@atlant-armour.ru',
    'to'         => 'zakupki@zavod.ru',
    'cc'         => 'info@atlant-armour.ru',
    'body'       => 'Добрый день! Направляем коммерческое предложение по Вашему запросу.',
    'date'       => date('Y-m-d H:i:s'),
];
ok('ответ клиенту лёг отправленным',
   MailArchive::storeIncoming($box, $cc + ['folder' => 'Sent', 'uid' => 44], 'out') > 0);
ok('а его копия «себе в копию» второй строкой не становится',
   MailArchive::storeIncoming($box, $cc + ['folder' => 'INBOX', 'uid' => 55], 'in') === 0);

// Письмо клиента остаётся письмом клиента и дедуплицируется как раньше
$fromClient = [
    'message_id' => '<from-client@zavod.ru>', 'subject' => 'Запрос КП',
    'from' => 'zakupki@zavod.ru', 'to' => 'info@atlant-armour.ru',
    'body' => 'Добрый день, просим направить коммерческое предложение на шлемы.',
    'date' => date('Y-m-d H:i:s'),
];
ok('письмо клиента архивируется', MailArchive::storeIncoming($box, $fromClient + ['folder' => 'INBOX', 'uid' => 66], 'in') > 0);
ok('и второй раз не задваивается',
   MailArchive::storeIncoming($box, $fromClient + ['folder' => 'INBOX', 'uid' => 77], 'in') === 0);

echo "\n2. Вилка цен: у товара цены нет, у модификаций она разная\n";

// Товар-родитель без цены и три размера: два по 12 000, один по 15 000
Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, prices_json,
                                   stock, reserved, unit, product_type, source, updated_at)
       VALUES ('p-suit', 'Костюм тактический Ратник', 'костюм тактический ратник', 'RATNIK',
               0, NULL, 0, 0, 'шт.', 'product', 'api', datetime('now'))");
foreach ([['v-s', 'Костюм Ратник S', 12000.0], ['v-m', 'Костюм Ратник M', 12000.0], ['v-l', 'Костюм Ратник L', 15000.0]] as [$id, $name, $price]) {
    Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, prices_json,
                                       stock, reserved, unit, product_type, parent_id, source, updated_at)
           VALUES (?, ?, ?, 'RATNIK', ?, ?, 4, 0, 'шт.', 'variant', 'p-suit', 'api', datetime('now'))",
          [$id, $name, mb_strtolower($name), $price, json_encode(['Розница' => $price], JSON_UNESCAPED_UNICODE)]);
}

$suit = Db::one("SELECT * FROM products_cache WHERE moysklad_id='p-suit'");
$range = Catalog::priceRange($suit, null, 'Розница');
ok('низ вилки — самая дешёвая модификация', $range['min'] === 12000.0, json_encode($range));
ok('верх вилки — самая дорогая', $range['max'] === 15000.0, json_encode($range));
ok('цена товара — низ вилки, а не ноль', Catalog::priceFor($suit, null, 'Розница') === 12000.0);

// Все модификации по одной цене — вилки нет, есть одна цена
Db::q("UPDATE products_cache SET price=12000, prices_json=? WHERE moysklad_id='v-l'",
      [json_encode(['Розница' => 12000], JSON_UNESCAPED_UNICODE)]);
$flat = Catalog::priceRange($suit, null, 'Розница');
ok('одинаковые цены модификаций — вилки нет', $flat['min'] === $flat['max'] && $flat['min'] === 12000.0,
   json_encode($flat));
Db::q("UPDATE products_cache SET price=15000, prices_json=? WHERE moysklad_id='v-l'",
      [json_encode(['Розница' => 15000], JSON_UNESCAPED_UNICODE)]);

echo "\n3. И наоборот: модификация без цены берёт её у товара\n";

Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, prices_json,
                                   stock, reserved, unit, product_type, source, updated_at)
       VALUES ('p-helmet', 'Шлем Протон', 'шлем протон', 'PROTON', 35000,
               ?, 0, 0, 'шт.', 'product', 'api', datetime('now'))",
      [json_encode(['Розница' => 35000, 'Опт безнал' => 31000], JSON_UNESCAPED_UNICODE)]);
Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, prices_json,
                                   stock, reserved, unit, product_type, parent_id, source, updated_at)
       VALUES ('h-l', 'Шлем Протон L', 'шлем протон l', 'PROTON-L', 0,
               ?, 3, 0, 'шт.', 'variant', 'p-helmet', 'api', datetime('now'))",
      [json_encode(['Розница' => 36000], JSON_UNESCAPED_UNICODE)]);

$hl = Db::one("SELECT * FROM products_cache WHERE moysklad_id='h-l'");
ok('своя розница модификации сильнее родительской', Catalog::priceFor($hl, null, 'Розница') === 36000.0);
ok('опт без своей цены берётся у товара — ПО ТИПУ цены',
   Catalog::priceFor($hl, null, 'Опт безнал') === 31000.0, (string)Catalog::priceFor($hl, null, 'Опт безнал'));
ok('и вилки у модификации нет — она одна', Catalog::priceRange($hl, null, 'Опт безнал')['max'] === 31000.0);

echo "\n4. Вилка доходит до строки подбора и до документа\n";

Settings::set('CATALOG_DEFAULT_PRICE_TYPE', 'Розница');
$cpId = Db::insert('counterparties', ['name' => "ООО 'Воевода'", 'contact_email' => 'logist@voevoda.pro']);
$requestId = Db::insert('requests', [
    'source' => 'email', 'raw_text' => 'Костюм Ратник — 10 шт', 'counterparty_id' => $cpId, 'status' => 'new',
    'parsed_json' => json_encode(['items' => []], JSON_UNESCAPED_UNICODE),
]);
$itemId = Db::insert('request_items', [
    'request_id' => $requestId, 'position' => 1,
    'raw_name' => 'Костюмы тактические летние, 10 комплектов',
    'quantity' => 10, 'moysklad_product_id' => 'p-suit', 'product_name' => 'Костюм тактический Ратник',
    'article' => 'RATNIK', 'unit' => 'шт.', 'price' => 12000, 'stock' => 12,
]);

$rows = RequestItems::all($requestId);
ok('строка подбора знает верх вилки', (float)$rows[0]['price_max'] === 15000.0, json_encode($rows[0]['price_max']));

$proposalId = KpSet::create($requestId, null);
foreach (RequestItems::toProposalItems($rows) as $i => $m) {
    Db::insert('proposal_items', KpSet::itemRow($m, $i + 1) + ['proposal_id' => $proposalId]);
}
$pi = Db::one("SELECT * FROM proposal_items WHERE proposal_id=?", [$proposalId]);
ok('вилка доехала до позиции КП', (float)$pi['price_max'] === 15000.0, (string)$pi['price_max']);
ok('скидка режет оба конца вилки одинаково',
   Terms::priceTop(['price' => 12000, 'price_max' => 15000, 'discount_percent' => 10]) === 13500.0,
   (string)Terms::priceTop(['price' => 12000, 'price_max' => 15000, 'discount_percent' => 10]));
ok('совпавшие концы вилкой не считаются',
   Terms::priceTop(['price' => 12000, 'price_max' => 12000]) === 0.0);

$html = PdfGenerator::html($proposalId);
ok('документ печатает вилку', str_contains($html, 'от 12 000,00 до 15 000,00 руб.'),
   (string)(strstr($html, 'от 12 000,00') ? 'нашлось' : 'нет'));
ok('и «Итого» при этом называется «от»', str_contains($html, 'Итого') && str_contains($html, 'от 120 000,00'));

$letter = KpText::render($proposalId)['text'];
ok('письмо называет ту же вилку', str_contains($letter, 'от 12 000,00 руб. до 15 000,00 руб.'), $letter);
ok('и тот же итог «от»', str_contains($letter, ': от '), $letter);

echo "\n5. Общие условия КП: один выбор на все позиции, и он запоминается\n";

$was = Terms::conditions($managerId);
ok('без выбора условия — из настроек', $was['price_type'] === 'Розница', json_encode($was, JSON_UNESCAPED_UNICODE));

Terms::remember($managerId, ['price_type' => 'Опт безнал', 'discount' => 5,
                             'wait_on' => 1, 'wait_months' => 2, 'wait_discount' => 12, 'wait_prepay' => 50]);
$now = Terms::conditions($managerId);
ok('выбор запомнен за менеджером', $now['price_type'] === 'Опт безнал' && $now['discount'] === 5.0,
   json_encode($now, JSON_UNESCAPED_UNICODE));
ok('и скидка за ожидание тоже', $now['wait_discount'] === 12.0);

// Следующее КП: строка с ценой руками и строка без — общий выбор трогает вторую
$nextRequest = Db::insert('requests', [
    'source' => 'email', 'raw_text' => 'Шлемы', 'counterparty_id' => $cpId, 'status' => 'new',
    'parsed_json' => json_encode(['items' => []], JSON_UNESCAPED_UNICODE),
]);
$auto = Db::insert('request_items', [
    'request_id' => $nextRequest, 'position' => 1, 'raw_name' => 'Шлем L — 3 шт', 'quantity' => 3,
    'moysklad_product_id' => 'h-l', 'product_name' => 'Шлем Протон L', 'unit' => 'шт.',
    'price' => 36000, 'stock' => 3,
]);
$manual = Db::insert('request_items', [
    'request_id' => $nextRequest, 'position' => 2, 'raw_name' => 'Шлем под заказ — 2 шт', 'quantity' => 2,
    'moysklad_product_id' => 'p-helmet', 'product_name' => 'Шлем Протон', 'unit' => 'шт.',
    'price' => 33000, 'price_is_manual' => 1, 'stock' => 0,
]);

RequestItems::applyConditions($nextRequest, Terms::conditions($managerId));
$after = array_column(RequestItems::all($nextRequest), null, 'id');
ok('тип цены проставился строке', (float)$after[$auto]['price'] === 31000.0, (string)$after[$auto]['price']);
ok('цену, вписанную руками, общий выбор не тронул', (float)$after[$manual]['price'] === 33000.0,
   (string)$after[$manual]['price']);
ok('скидка легла на обе строки',
   (float)$after[$auto]['discount_percent'] === 5.0 && (float)$after[$manual]['discount_percent'] === 5.0);
ok('условия ожидания — только тому, чего нет на складе',
   (int)$after[$manual]['wait_on'] === 1 && (int)$after[$auto]['wait_on'] === 0,
   "auto={$after[$auto]['wait_on']} manual={$after[$manual]['wait_on']}");
ok('и с запомненной скидкой за ожидание', (float)$after[$manual]['wait_discount'] === 12.0);

echo "\n6. Галочка «аналог» кладёт в КП слова клиента\n";

RequestItems::save($requestId, [[
    'id' => $itemId, 'raw_name' => 'Костюмы тактические летние, 10 комплектов', 'quantity' => 10,
    'moysklad_product_id' => 'p-suit', 'product_name' => 'Костюм тактический Ратник',
    'unit' => 'шт.', 'price' => 12000, 'is_alternative' => 1, 'alt_of' => '',
]]);
$saved = RequestItems::all($requestId)[0];
ok('пустое поле берёт формулировку из письма',
   $saved['alt_of'] === 'Костюмы тактические летние, 10 комплектов', (string)$saved['alt_of']);

RequestItems::save($requestId, [[
    'id' => $itemId, 'raw_name' => 'Костюмы тактические летние, 10 комплектов', 'quantity' => 10,
    'moysklad_product_id' => 'p-suit', 'product_name' => 'Костюм тактический Ратник',
    'unit' => 'шт.', 'price' => 12000, 'is_alternative' => 1,
    'alt_of' => 'Костюм летний полевой, обр. 2020',
]]);
$edited = RequestItems::all($requestId)[0];
ok('правка менеджера сильнее письма', $edited['alt_of'] === 'Костюм летний полевой, обр. 2020');
ok('а само письмо она не переписывает',
   $edited['raw_name'] === 'Костюмы тактические летние, 10 комплектов');

$kp2 = KpSet::create($requestId, null);
foreach (RequestItems::toProposalItems(RequestItems::all($requestId)) as $i => $m) {
    Db::insert('proposal_items', KpSet::itemRow($m, $i + 1) + ['proposal_id' => $kp2]);
}
$row = Db::one("SELECT * FROM proposal_items WHERE proposal_id=?", [$kp2]);
ok('слова клиента доехали до КП', (string)$row['alt_of'] === 'Костюм летний полевой, обр. 2020');

$html2 = PdfGenerator::html($kp2);
ok('и печатаются жирным серым над нашим названием (issue #60)',
   str_contains($html2, '<div class="analog-of">Костюм летний полевой, обр. 2020</div>'));
ok('именно над названием, а не после него',
   strpos($html2, 'Костюм летний полевой, обр. 2020') < strpos($html2, 'Костюм тактический Ратник'));

// Галочку сняли — в документе снова только наше название
Db::update('proposal_items', ['is_alternative' => 0], 'proposal_id=?', [$kp2]);
ok('без галочки строки клиента в документе нет',
   !str_contains(PdfGenerator::html($kp2), '<div class="analog-of">'));

echo "\n7. Доска: отмеченные карточки переезжают группой и насовсем\n";

$boardId = (int)Boards::singleton()['id'];
$inbox = Boards::inboxColumn($boardId);
$work  = Boards::workColumn($boardId) ?: ['id' => Boards::saveColumn($boardId, null, 'В работе', null, 'work')];

$cards = [];
foreach (['Первая', 'Вторая', 'Третья', 'Четвёртая'] as $title) {
    $cards[$title] = Boards::addCard((int)$inbox['id'], ['title' => $title]);
}
// Три из четырёх отмечены галочками и уезжают вместе
$moving = [$cards['Первая'], $cards['Вторая'], $cards['Третья']];
$done = Boards::moveCards($moving, (int)$work['id'], 0);
ok('переехали все отмеченные, а не одна', $done === 3, (string)$done);

$in = implode(',', array_fill(0, count($moving), '?'));
$placed = Db::all("SELECT id, column_id, position FROM board_cards WHERE id IN ($in) ORDER BY position", $moving);
ok('и переезд записан в базу — обновление страницы его не отменит',
   count(array_filter($placed, fn($r) => (int)$r['column_id'] === (int)$work['id'])) === 3,
   json_encode($placed));
ok('порядок внутри группы сохранён', array_column($placed, 'id') === $moving, json_encode(array_column($placed, 'id')));
ok('неотмеченная осталась на месте',
   (int)Db::val("SELECT column_id FROM board_cards WHERE id=?", [$cards['Четвёртая']]) === (int)$inbox['id']);

// Ни одной задвоенной строки: переезд — это перенос, а не копия
ok('карточек на доске по-прежнему четыре',
   (int)Db::val("SELECT COUNT(*) FROM board_cards d JOIN board_columns c ON c.id=d.column_id
                 WHERE c.board_id=? AND d.dismissed_at IS NULL", [$boardId]) === 4);

// Группа встаёт ТУДА, куда её положили, а не всегда в конец
Boards::moveCards([$cards['Четвёртая']], (int)$work['id'], 1);
$order = array_map(fn($r) => (int)$r['id'],
    Db::all("SELECT id FROM board_cards WHERE column_id=? ORDER BY position, id", [(int)$work['id']]));
ok('карточка легла на указанное место', $order[1] === $cards['Четвёртая'], json_encode($order));

// Групповая операция «в колонку» ходит той же дорогой
$res = Boards::bulk([$cards['Первая'], $cards['Вторая']], 'move', ['column_id' => (int)$inbox['id']]);
ok('групповое «в колонку» вернуло обеих', (int)$res['done'] === 2, json_encode($res, JSON_UNESCAPED_UNICODE));
ok('и обе действительно там',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE column_id=? AND id IN (?,?)",
                [(int)$inbox['id'], $cards['Первая'], $cards['Вторая']]) === 2);

// Карточка компании, уехавшая на ДРУГУЮ доску, не заводится интейком заново
$other = Boards::createBoard('Проекты');
$otherCol = (int)Db::val("SELECT id FROM board_columns WHERE board_id=? ORDER BY position, id LIMIT 1", [$other]);
$live = Db::insert('counterparties', ['name' => "ООО 'Редут'", 'contact_email' => 'zakaz@redut.ru']);
Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 900,
    'message_id' => '<redut@redut.ru>', 'thread_key' => 's:redut', 'subject' => 'Запрос',
    'from_email' => 'zakaz@redut.ru', 'to_emails' => 'info@atlant-armour.ru',
    'body_text' => 'Просим направить предложение на бронежилеты для нашего подразделения.',
    'counterparty_id' => $live, 'date_at' => date('Y-m-d H:i:s'),
]);
Boards::sync($boardId, true);
$redutCard = (int)Db::val("SELECT d.id FROM board_cards d JOIN board_columns c ON c.id=d.column_id
                           WHERE c.board_id=? AND d.counterparty_id=?", [$boardId, $live]);
ok('интейк завёл карточку компании', $redutCard > 0);

Boards::moveCards([$redutCard], $otherCol, 0);
Boards::sync($boardId, true);
ok('уехавшая на другую доску карточка не задваивается',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE counterparty_id=?", [$live]) === 1,
   (string)Db::val("SELECT COUNT(*) FROM board_cards WHERE counterparty_id=?", [$live]));
ok('и лежит она там, куда её перенесли',
   (int)Db::val("SELECT column_id FROM board_cards WHERE counterparty_id=?", [$live]) === $otherCol);

echo "\n8. Экран доски и подбора собран под это\n";

$js = file_get_contents(ROOT . '/public/assets/js/app.js');
ok('перетаскивание знает про группу отмеченных', str_contains($js, 'boardPicked') && str_contains($js, 'group.forEach'));
ok('и перерисовывает доску ответом сервера', str_contains($js, 'boardRedraw(r.board)'));
ok('бросок, которого не было, возвращает экран как был',
   str_contains($js, 'if (dragged && !dropped) this.boardRedraw();'));
ok('карточки отмечаются по статусу', str_contains($js, 'boardPickByState'));
ok('панель условий стоит над таблицей подбора', str_contains($js, 'conditionsPanel'));
ok('галочка «аналог» открывает поле', str_contains($js, 'toggleAnalog'));

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
