<?php
/**
 * Модуль 062: issues #116–#119.
 *
 *   — #118 «плита Бр3, размер XL»: сам товар, его модификация, не «нет в
 *     наличии» и не боковая плита; поиск по каталогу полнотекстовый;
 *   — #119 этапы карточки сами, документы МойСклад в ленте, счёт из подбора;
 *   — #116/#117 фокус карточки, возврат на доску, окна по экрану телефона.
 *
 * Запуск:  php tests/module_062.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-062-' . getmypid() . '.db';
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
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/fulfillment.php';
require_once ROOT . '/lib/crm.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');

// ===================================================================== 1
echo "1. #118 плита Бр3 размер XL — тот самый товар и его модификация\n";
$prod = fn(string $id, string $name, array $o = []) => Db::insert('products_cache', $o + [
    'moysklad_id' => $id, 'name' => $name, 'price' => 0, 'stock' => 0, 'reserved' => 0, 'product_type' => 'product']);
$prod('p3', 'Плита для бронежилета Бр3');
foreach (['S' => 0, 'M' => 0, 'L' => 55, 'XL' => 20] as $size => $stock) {
    $prod("p3-$size", "Плита для бронежилета Бр3 (Размер: $size)", ['price' => $size === 'XL' ? 25900 : 22400,
        'stock' => $stock, 'product_type' => 'variant', 'parent_id' => 'p3', 'characteristics' => "Размер: $size"]);
}
$prod('side', 'Боковая плита для бронежилета Бр3');
foreach (['S' => 10, 'M' => 82, 'L' => 47, 'XL' => 5] as $size => $stock) {
    $prod("side-$size", "Боковая плита для бронежилета Бр3 (Размер: $size)", ['price' => 10000,
        'stock' => $stock, 'product_type' => 'variant', 'parent_id' => 'side', 'characteristics' => "Размер: $size"]);
}
$prod('p2', 'Плита для бронежилета Бр2', ['price' => 15000, 'stock' => 9]);
ProductMatcher::forgetCatalog();

$c = ProductMatcher::findCandidates('плита для бронежилета Бр3', 5);
ok('первым — товар, чьё имя совпало с запросом', ($c[0]['moysklad_id'] ?? '') === 'p3', json_encode(array_column($c, 'name'), JSON_UNESCAPED_UNICODE));
$side = array_values(array_filter($c, fn($r) => str_starts_with($r['moysklad_id'], 'side')));
ok('«боковая» ниже самого товара и его модификаций', !$side || $side[0]['score'] < $c[0]['score'] - 0.05);
ok('Бр2 — не кандидат выше Бр3', ($c[0]['moysklad_id'] ?? '') !== 'p2');

ok('один размер со словом «размер» — метка', Variants::sizeLabel('5 плит для бронежилета Бр3, размер XL') === 'XL');
ok('без подсказки — не размер', Variants::sizeLabel('Рукав 5ELEM - 1 шт') === null && Variants::sizeLabel('Шлем Бр3 L') === null);
ok('два размера без количеств — не метка', Variants::sizeLabel('размер S или размер M') === null);

$letter = "Добрый день! Хотим приобрести у вас 5 плит для бронежилета Бр3, размер XL "
        . "(https://atlant-armour.ru/catalog/sredstva-zashchity/broneplity/plita-dlya-bronezhileta-br3/?oid=5950). "
        . "Прошу выставить счёт.";
$req = Db::insert('requests', ['source' => 'email', 'raw_text' => $letter, 'email_from' => 'x@mail.ru',
    'parsed_json' => json_encode(['items' => [['name' => 'плита для бронежилета Бр3', 'qty' => 5,
        'raw_text' => 'Хотим приобрести у вас 5 плит для бронежилета Бр3, размер XL']]], JSON_UNESCAPED_UNICODE)]);
$row = RequestItems::ensure($req)[0] ?? [];
ok('строка встала на модификацию XL', ($row['moysklad_product_id'] ?? '') === 'p3-XL', json_encode($row, JSON_UNESCAPED_UNICODE));
ok('не аналог, остаток XL', (int)($row['is_alternative'] ?? 1) === 0 && (int)$row['stock'] === 20);
ok('метка на строке', ($row['variant_label'] ?? '') === 'XL' && str_contains((string)$row['raw_name'], 'XL'));

// Без размера — сам товар, и он В НАЛИЧИИ: остаток — сумма модификаций
$req2 = Db::insert('requests', ['source' => 'email', 'raw_text' => 'Нужна плита для бронежилета Бр3, 2 шт.',
    'parsed_json' => json_encode(['items' => [['name' => 'плита для бронежилета Бр3', 'qty' => 2]]], JSON_UNESCAPED_UNICODE)]);
$row2 = RequestItems::ensure($req2)[0] ?? [];
ok('без размера — товар целиком', ($row2['moysklad_product_id'] ?? '') === 'p3', (string)($row2['product_name'] ?? ''));
ok('товар с модификациями — не «нет в наличии», аналога нет', (int)$row2['is_alternative'] === 0 && (int)$row2['stock'] === 75);
$rr = RequestItems::rematchReport($req2, false);
$row2 = RequestItems::all($req2)[0];
ok('перебор тоже не подменяет его аналогом', $row2['moysklad_product_id'] === 'p3' && (int)$row2['is_alternative'] === 0
   && (int)Db::val("SELECT stock FROM request_items WHERE id=?", [(int)$row2['id']]) === 75);

// Строка, заведённая до исправления: метки нет, перебор находит размер в письме
Db::update('request_items', ['variant_label' => null, 'moysklad_product_id' => 'side-M', 'is_confirmed' => 0,
                             'raw_name' => 'плита для бронежилета Бр3'], 'request_id=?', [$req]);
RequestItems::rematchReport($req, false);
$row = RequestItems::all($req)[0];
ok('перебор нашёл «размер XL» в письме', $row['variant_label'] === 'XL' && $row['moysklad_product_id'] === 'p3-XL',
   $row['moysklad_product_id'] . ' / ' . $row['variant_label']);

// Нет XL на складе — «под заказ», а не боковая плита и не другой размер
Db::update('products_cache', ['stock' => 0], 'moysklad_id=?', ['p3-XL']);
Db::update('request_items', ['is_confirmed' => 0], 'request_id=?', [$req]);
RequestItems::rematchReport($req, false);
$row = RequestItems::all($req)[0];
ok('XL кончился — строка остаётся на XL под заказ', $row['moysklad_product_id'] === 'p3-XL' && (int)$row['is_alternative'] === 0
   && (int)$row['is_backorder'] === 1, $row['moysklad_product_id']);

// Поле «начните печатать»: полнотекстово, без учёта регистра кириллицы
Db::update('products_cache', ['description' => '<p>Защищает от автомата АК</p>'], 'moysklad_id=?', ['p2']);
$s = array_column(ProductMatcher::search('плита бр3', 10), 'moysklad_id');
$firstSide = min(array_keys(array_filter($s, fn($id) => str_starts_with($id, 'side'))));
ok('поиск: «плита бр3» — семья самого товара вся выше боковой', str_starts_with($s[0] ?? '', 'p3')
   && max(array_keys(array_filter($s, fn($id) => str_starts_with($id, 'p3')))) < $firstSide, implode(',', $s));
ok('поиск: регистр кириллицы не важен', (ProductMatcher::search('ПЛИТА БР2', 5)[0]['moysklad_id'] ?? '') === 'p2');
ok('поиск: слово из описания', array_column(ProductMatcher::search('автомата плита', 5), 'moysklad_id') === ['p2']);
ok('поиск: все слова обязательны', ProductMatcher::search('плита шлем', 5) === []);
ok('API поиска идёт через ProductMatcher::search', str_contains(file_get_contents(ROOT . '/public/api/products.php'),
   '$items = ProductMatcher::search($q, $limit);'));

// ===================================================================== 2
echo "2. #119 этапы сами: письмо/подбор → работа, КП → отправлено, счёт → оплата, оплата → сборка, отгрузка → отправлено\n";
$board = Boards::singleton();
$bid = (int)$board['id'];
$colOf = fn(int $cp) => (string)Db::val("SELECT c.title FROM board_cards d JOIN board_columns c ON c.id=d.column_id
                                          WHERE d.counterparty_id=?", [$cp]);
MsSync::$fetchPdf = fn($id) => null;
$cp = Db::insert('counterparties', ['name' => 'ООО «Эксклюзив Строй»']);
Boards::addCard((int)Boards::inboxColumn($bid)['id'], ['counterparty_id' => $cp]);
$r = Db::insert('requests', ['source' => 'email', 'raw_text' => 'плита', 'counterparty_id' => $cp]);
Boards::workStarted($r);
ok('подбор начат — «В работе»', $colOf($cp) === 'В работе', $colOf($cp));
Boards::advance($cp, null, 'kp_sent');
ok('КП ушло — «КП отправлено»', $colOf($cp) === 'КП отправлено');
Boards::workStarted($r);
ok('подбор после КП назад не тянет', $colOf($cp) === 'КП отправлено');
MsSync::upsertInvoice(['id' => 'inv-1', 'name' => '00101', 'moment' => date('Y-m-d H:i:s'), 'sum' => 129500,
                       'payed_sum' => 0, 'state_name' => 'Новый'], null, $cp);
ok('новый счёт — «Ждём оплату»', $colOf($cp) === 'Ждём оплату', $colOf($cp));
$inv = (int)Db::val("SELECT id FROM invoices WHERE moysklad_id='inv-1'");
Fulfillment::invoicePaid($inv, 'bank');
ok('оплата — «Сборка»', $colOf($cp) === 'Сборка', $colOf($cp));

$ord = Db::insert('orders', ['counterparty_id' => $cp, 'moysklad_id' => 'ord-1', 'name' => '00077', 'sum' => 129500,
                             'moment' => date('Y-m-d H:i:s')]);
Fulfillment::$fetchOrder = fn($id) => ['id' => $id, 'attributes' => []];
Fulfillment::$fetchDemands = fn($id) => [['id' => 'dem-1', 'name' => '00031', 'moment' => date('Y-m-d H:i:s'), 'attributes' => []]];
Fulfillment::checkShipments(30, $cp);
ok('отгрузка — «Отправлено»', $colOf($cp) === 'Отправлено', $colOf($cp));
$names = array_column(Db::all("SELECT title FROM board_columns WHERE board_id=? ORDER BY position", [$bid]), 'title');
ok('«Отправлено» стоит после «Сборки» и до «Закрыто»',
   array_search('Отправлено', $names) === array_search('Сборка', $names) + 1
   && array_search('Отправлено', $names) < array_search('Закрыто', $names), implode(' | ', $names));
Db::update('invoices', ['paid_at' => null, 'payed_sum' => 0], 'id=?', [$inv]);
Fulfillment::invoicePaid($inv, 'bank');
ok('поздняя оплата отгруженную назад не тянет', $colOf($cp) === 'Отправлено');

// Руками — можно куда угодно, и следующее событие двигает дальше
$card = (int)Db::val("SELECT id FROM board_cards WHERE counterparty_id=?", [$cp]);
Boards::moveCard($card, (int)Boards::workColumn($bid)['id'], 0);
ok('ручной перенос назад работает', $colOf($cp) === 'В работе');
Boards::advance($cp, null, 'payment');
ok('после ручного переноса событие снова двигает вперёд', $colOf($cp) === 'Ждём оплату');

// Карточку перетащили в свою колонку — начатое письмо её не трогает
$cp2 = Db::insert('counterparties', ['name' => 'Своя колонка']);
$custom = Db::insert('board_columns', ['board_id' => $bid, 'title' => 'Тендеры', 'position' => 99]);
Boards::addCard($custom, ['counterparty_id' => $cp2]);
$r2 = Db::insert('requests', ['source' => 'email', 'raw_text' => 'x', 'counterparty_id' => $cp2]);
Boards::workStarted($r2);
ok('«В работе» не тянет из колонки менеджера', $colOf($cp2) === 'Тендеры');
// Старый счёт из истории первой синхронизации карточку не двигает
MsSync::upsertInvoice(['id' => 'inv-old', 'name' => '00001', 'moment' => '2024-01-10 10:00:00', 'sum' => 10,
                       'payed_sum' => 0, 'state_name' => 'Новый'], null, $cp2);
ok('счёт из истории не двигает', $colOf($cp2) === 'Тендеры');
ok('API: подбор начат → workStarted', substr_count(file_get_contents(ROOT . '/public/api/requests.php'), 'Boards::workStarted($id);') === 3
   && str_contains(file_get_contents(ROOT . '/public/api/mail.php'), "Boards::workStarted((int)\$res['request_id'])"));

// ===================================================================== 3
echo "3. #119 документы в ленте и печатные формы\n";
Db::update('orders', ['request_id' => $r], 'id=?', [$ord]);
$docs = Crm::documents($cp);
$kinds = array_count_values(array_column($docs, 'doc'));
ok('в ленте заказ, счёт и отгрузка', ($kinds['order'] ?? 0) === 1 && ($kinds['invoice'] ?? 0) === 1 && ($kinds['demand'] ?? 0) === 1,
   json_encode($kinds));
$dem = array_values(array_filter($docs, fn($d) => $d['doc'] === 'demand'))[0];
ok('отгрузка ведёт в МойСклад и знает свой запрос', str_contains($dem['url'], '#demand/edit?id=dem-1') && $dem['request_id'] === $r
   && str_contains($dem['pdf_url'], 'doc_pdf&doc=demand'));
ok('лента — по дате', array_column($docs, 'created_at') === (function ($a) { sort($a); return $a; })(array_column($docs, 'created_at')));
MsSync::$fetchPdf = fn($id) => "%PDF-1.4 $id";
$pdf = MsSync::docPdf('demand', (int)$dem['id']);
ok('печатная форма отгрузки — файлом в storage/docs', $pdf['path'] && is_file($pdf['path']) && $pdf['name'] === 'Отгрузка 00031.pdf');
@unlink((string)$pdf['path']);
ok('платёж печатной формы не имеет', MsSync::docPdf('payment', 1)['path'] === null);
ok('API: 👁 и «В письмо» для заказа и отгрузки', str_contains(file_get_contents(ROOT . '/public/api/counterparties.php'), "case 'doc_pdf':")
   && str_contains(file_get_contents(ROOT . '/public/api/mail.php'), "\$kind === 'order' || \$kind === 'demand'"));

// ===================================================================== 4
echo "4. Интерфейс: #116, #117, #119\n";
ok('#116 после удаления письма — на доску', str_contains($js, "this.goAfterDelete('mail/board');"));
ok('#116 первый раз — последнее письмо, повторно — поле ответа', str_contains($js, 'focusOnOpen(cpId, key)')
   && str_contains($js, "const target = again ? this.focusReply(key) : null;"));
ok('#116 фокус держится, пока дорисовываются блоки', str_contains($js, 'pinScroll(el, block'));
ok('#117 окно — по видимой части экрана', str_contains($js, 'fitModal()') && str_contains($js, "transform: `scale(\${1 / k})`"));
ok('#119 нет вкладок «Информация» и «Заметки, заказы и счета»', !str_contains($js, 'id="companySide"')
   && !str_contains($js, 'data-block="events"'));
ok('#119 «Информация» — по названию компании', str_contains($js, 'App.companyInfo(); return false'));
ok('#119 нет «Заметки на доске»', !str_contains($js, 'Заметка на доске') && !str_contains($js, 'boardCardNote'));
ok('#119 нет «Написать новое письмо» и подсказки под ним', !str_contains($js, 'Написать новое письмо</button>')
   && !str_contains($js, 'Переписка выше разворачивается нажатием'));
ok('#119 нет заголовка «Переписка»', !str_contains($js, "<span>Переписка\${this.hint('thread')}</span>"));
ok('#119 счёт рядом с «Сформировать КП»', str_contains($js, "Завести контрагента и выставить счёт")
   && str_contains($js, '${this.invoiceButton(requestId, kp)}'));
ok('#119 документы — между письмами, с 👁 и «В письмо»', str_contains($js, 'placeFeed()') && str_contains($js, "App.previewDoc('\${d.doc}'"));
ok('#119 запрос знает, заведён ли покупатель в МойСклад', str_contains(file_get_contents(ROOT . '/public/api/requests.php'), "\$req['ms'] = Crm::moyskladHint("));
echo $fail ? "\nFAILED: $fail\n" : "\nall ok\n";
exit($fail ? 1 : 0);
