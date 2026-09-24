<?php
/**
 * Module 013 end to end, against a throwaway database and with no network:
 * an analogue for a position we cannot ship, the correspondence table of a
 * request that arrived as a table, the card with its description, first photo
 * and link, the requisites frozen onto the КП, and the discipline block.
 *
 * Run:  php tests/module_013.php
 *
 * It builds its own database in the system temp directory — `data/kp.db` is
 * never opened, so running this on a server cannot touch live data.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);

// bootstrap reads DB_PATH out of config.php, so the test hands it one pointing
// at a scratch file and puts the real config back on the way out.
//
// The order of the union matters and is the whole safety of this test: PHP's `+`
// keeps the LEFT operand's key, so the override has to come FIRST. The other way
// round, a server whose config.php names the real DB_PATH would have this suite
// writing test products into the live database.
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
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/alternatives.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/request_shape.php';
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/bitrix.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

// Never call a model or the network in this test
Settings::set('ALT_USE_LLM', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('KNOWLEDGE_ENABLED', '0');

// ---------- catalog ----------
$products = [
    // asked for, but nothing free on the shelf
    ['ms-1', 'Бронежилет Кираса-5 скрытого ношения', 'KR5', 0, 0, 24000,
     "Бронежилет скрытого ношения. Класс защиты Бр5. Площадь защиты 40 дм2. Цвет чёрный. Вес 3,8 кг."],
    // the analogue: in stock, same class, same area
    ['ms-2', 'Бронежилет Страж-5 скрытого ношения', 'STR5', 7, 2, 22500,
     "Бронежилет скрытого ношения собственного производства. Класс защиты Бр5. Площадь защиты 40 дм2. Вес 3,5 кг. Цвет чёрный."],
    // in stock but a different class — must not win against ms-2
    ['ms-3', 'Бронежилет Страж-2 скрытого ношения', 'STR2', 10, 0, 12000,
     "Бронежилет скрытого ношения. Класс защиты Бр2. Площадь защиты 30 дм2."],
    ['ms-4', 'Шлем Атлант АШ-1', 'ASH1', 4, 0, 31000,
     "Шлем баллистический. Класс защиты Бр2. Размер M. Вес 1,3 кг."],
];
foreach ($products as [$id, $name, $art, $stock, $reserved, $price, $desc]) {
    Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved, unit, description, vat, product_type, source, updated_at)
           VALUES (?,?,?,?,?,?,?,'шт.',?,5,'product','test',datetime('now'))",
          [$id, $name, mb_strtolower($name), $art, $price, $stock, $reserved, $desc]);
}
ProductMatcher::forgetCatalog();

echo "\n1. Синонимы\n";
ok('«броник» сводится к «бронежилет»', Synonyms::canonical('броник') === 'бронежилет', Synonyms::canonical('броник'));
ok('«бронежилеты» тоже (по основе)', Synonyms::canonical('бронежилеты') === 'бронежилет');
ok('«каску» переписывает в «шлем»', Synonyms::rewrite('каска баллистическая') === 'шлем баллистическая',
   Synonyms::rewrite('каска баллистическая'));
ok('неизвестное слово не трогает', Synonyms::canonical('редуктор') === 'редуктор');

echo "\n2. Требования из текста запроса\n";
$req = Alternatives::requirements('Бронежилет скрытого ношения, класс защиты Бр5, площадь 40 дм2, 10 шт');
ok('количество не считается требованием', !in_array('10 шт', $req, true), implode(' | ', $req));
ok('класс защиты распознан', (bool)array_filter($req, fn($r) => str_contains(mb_strtolower($r), 'бр5')), implode(' | ', $req));
ok('площадь распознана', (bool)array_filter($req, fn($r) => str_contains($r, '40')), implode(' | ', $req));

echo "\n3. Аналог для позиции не в наличии\n";
$alt = Alternatives::suggest([[
    'raw_name'   => 'броник скрытого ношения Кираса-5',
    'raw_text'   => 'броник скрытого ношения Кираса-5, класс защиты Бр5, площадь 40 дм2, 10 шт',
    'exclude_id' => 'ms-1',
]], null, false)[0];
ok('аналог найден', $alt !== null);
ok('это позиция из наличия', $alt && $alt['free'] > 0, $alt ? "free={$alt['free']}" : '');
ok('выбран Страж-5, а не Страж-2', $alt && $alt['moysklad_id'] === 'ms-2', $alt['moysklad_id'] ?? '—');
ok('названы совпавшие требования', $alt && count($alt['matched']) >= 2,
   $alt ? json_encode(array_column($alt['matched'], 'requirement'), JSON_UNESCAPED_UNICODE) : '');
ok('доказательство — цитата из НАШЕГО описания',
   $alt && (bool)array_filter($alt['matched'], fn($m) => str_contains(mb_strtolower($m['ours']), 'страж')
                                                       || str_contains($m['ours'], 'бр5')),
   $alt ? json_encode(array_column($alt['matched'], 'ours'), JSON_UNESCAPED_UNICODE) : '');
ok('позиция, которой нет на складе, в кандидаты не попала',
   !array_filter(Alternatives::candidates('Бронежилет Кираса-5', '', null), fn($c) => $c['moysklad_id'] === 'ms-1'));

echo "\n4. Форма запроса\n";
ok('текст письма — не таблица', !RequestShape::looksTabular("Здравствуйте! Прошу выставить КП на бронежилеты. С уважением, Иван"));
ok('строки с табуляциями — таблица', RequestShape::looksTabular("Бронежилет\tБр5\t10\nШлем\tM\t5"));
ok('шапка спецификации — таблица', RequestShape::looksTabular("№ п/п  Наименование  Кол-во\n1 Бронежилет 10"));
ok('нумерованный список из трёх позиций — таблица',
   RequestShape::looksTabular("1. Бронежилет Страж — 10 шт\n2. Шлем АШ-1 — 5 шт\n3. Аптечка — 20 шт"));

echo "\n5. Запрос → подходящие позиции → КП\n";
$cpId = Db::insert('counterparties', ['name' => 'ООО «Рубеж»', 'inn' => '7701234567', 'contact_email' => 'z@rubezh.ru']);
$letter = "Наименование\tКол-во\nБронежилет Кираса-5 скрытого ношения, класс защиты Бр5, площадь 40 дм2\t10\nШлем Атлант АШ-1, размер M\t5";
$requestId = Db::insert('requests', [
    'source' => 'email', 'raw_text' => $letter, 'counterparty_id' => $cpId, 'status' => 'new',
    'parsed_json' => json_encode(['items' => [
        ['name' => 'Бронежилет Кираса-5 скрытого ношения', 'qty' => 10,
         'raw_text' => 'Бронежилет Кираса-5 скрытого ношения, класс защиты Бр5, площадь 40 дм2'],
        ['name' => 'Шлем Атлант АШ-1', 'qty' => 5, 'raw_text' => 'Шлем Атлант АШ-1, размер M'],
    ]], JSON_UNESCAPED_UNICODE),
]);
ok('запрос таблицей распознан', RequestShape::of($requestId) === RequestShape::TABLE, RequestShape::of($requestId));

$rows = RequestItems::ensure($requestId, false);
ok('две строки', count($rows) === 2, (string)count($rows));
$kirasa = $rows[0];
ok('строка без наличия стала аналогом', (int)$kirasa['is_alternative'] === 1);
ok('аналог — Страж-5', $kirasa['moysklad_product_id'] === 'ms-2', (string)$kirasa['moysklad_product_id']);
ok('запомнено, что просили', str_contains((string)$kirasa['alt_of'], 'Кираса'), (string)$kirasa['alt_of']);
ok('доказательства сохранены', !empty($kirasa['alternative']['matched']));
ok('исходная позиция вернулась в варианты',
   (bool)array_filter($kirasa['variants'], fn($v) => $v['moysklad_id'] === 'ms-1'));
ok('позиция в наличии не тронута', (int)$rows[1]['is_alternative'] === 0);

// The КП, without the parts that need a model or the network
$proposalId = Db::insert('proposals', [
    'request_id' => $requestId, 'counterparty_id' => $cpId, 'vat_rate' => 5,
    'show_match_table' => RequestShape::of($requestId) === RequestShape::TABLE ? 1 : 0,
]);
foreach (RequestItems::toProposalItems($rows) as $i => $m) {
    $match = $m['match'];
    Db::insert('proposal_items', [
        'proposal_id' => $proposalId, 'position' => $i + 1,
        'product_name' => $match['name'], 'requested_name' => $m['raw_name'],
        'moysklad_product_id' => $match['moysklad_id'], 'unit' => $match['unit'],
        'quantity' => $m['quantity'], 'price' => $match['price'],
        'stock_available' => $match['stock'], 'notes' => $m['notes'],
        'is_alternative' => $m['is_alternative'] ? 1 : 0,
        'alt_reason' => $m['alt_specs']['reason'] ?? null,
        'alt_specs_json' => $m['alt_specs'] ? json_encode($m['alt_specs'], JSON_UNESCAPED_UNICODE) : null,
    ]);
}
KpContent::enrichItems($proposalId);

echo "\n6. Таблица соответствия\n";
$proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
ok('таблица включена для табличного запроса', KpContent::showMatchTable($proposal));
$table = KpContent::matchTableRows($proposalId);
ok('строк столько же, сколько позиций', count($table) === 2, (string)count($table));
ok('слева — как просил клиент', str_contains($table[0]['requested'], 'Кираса'), $table[0]['requested']);
ok('справа — что предлагаем', str_contains($table[0]['offered'], 'Страж'), $table[0]['offered']);
ok('строка помечена как аналог', $table[0]['is_alternative']);
ok('в таблице есть артикул', $table[0]['article'] === 'STR5', $table[0]['article']);
ok('наличие названо', $table[0]['availability'] === 'в наличии', $table[0]['availability']);
ok('перечислены совпавшие требования', count($table[0]['matched']) >= 2);

$proposal['show_match_table'] = null;
Settings::set('KP_MATCH_TABLE', 'auto');
Db::update('requests', ['shape' => 'text'], 'id=?', [$requestId]);
ok('текстовому запросу таблица не нужна', !KpContent::showMatchTable($proposal));
Db::update('requests', ['shape' => 'table'], 'id=?', [$requestId]);
$proposal['show_match_table'] = 0;
ok('явное «нет» менеджера сильнее автоопределения', !KpContent::showMatchTable($proposal));

echo "\n7. Описание и первая фотография\n";
$item = Db::one("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY position LIMIT 1", [$proposalId]);
// The card text is the ANALOGUE's own description, not the one we could not ship
ok('описание подтянулось со склада — от предложенной позиции',
   str_contains((string)$item['description_text'], 'собственного производства'),
   mb_substr((string)$item['description_text'], 0, 60));
// two photos on disk, no explicit pick → the card takes the first
$dir = ROOT . '/storage/product_images';
@mkdir($dir, 0755, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents("$dir/test-0.png", $png);
file_put_contents("$dir/test-1.png", $png);
Db::update('proposal_items', ['images_json' => json_encode(["$dir/test-0.png", "$dir/test-1.png"])], 'id=?', [$item['id']]);
Db::q("UPDATE products_cache SET images_json=? WHERE moysklad_id='ms-2'",
      [json_encode(["$dir/test-0.png", "$dir/test-1.png"])]);
$item = Db::one("SELECT * FROM proposal_items WHERE id=?", [$item['id']]);
// Без выбора — первые $max (модуль 060: одна настройка вместо KP_CARD_PHOTOS)
ok('без явного выбора в КП идут первые по лимиту',
   count(KpContent::itemGallery($item, 1)) === 1, (string)count(KpContent::itemGallery($item, 1)));
$item['selected_images'] = json_encode(['local:0', 'local:1']);
ok('явный выбор менеджера лимитом не режется', count(KpContent::itemGallery($item, 1)) === 2);

echo "\n8. НДС и реквизиты\n";
Db::q("UPDATE legal_entities SET pays_vat=1, kpp='770101001', legal_address='г. Москва, ул. Примерная, 1',
       bank_name='АО «Банк»', bank_bic='044525000', bank_account='40802810000000000001', bank_details='АО «Банк», БИК 044525000'");
$snap = Requisites::freeze($proposalId);
ok('ставка НДС взята из позиции каталога', (int)$snap['vat']['rate'] === 5, json_encode($snap['vat'], JSON_UNESCAPED_UNICODE));
ok('источник ставки назван', str_contains($snap['vat']['source'], 'каталог'), $snap['vat']['source']);
ok('реквизиты продавца в снимке', ($snap['seller']['kpp'] ?? '') === '770101001');
ok('банк в снимке', str_contains((string)($snap['seller']['bank']['line'] ?? ''), 'БИК'));
ok('покупатель в снимке', ($snap['buyer']['inn'] ?? '') === '7701234567');

Db::q("UPDATE legal_entities SET pays_vat=0");
Db::q("UPDATE proposals SET requisites_json=NULL WHERE id=?", [$proposalId]);
$snap2 = Requisites::snapshot($proposalId);
ok('не плательщик НДС — ставка 0', (int)$snap2['vat']['rate'] === 0);
ok('и формулировка «НДС не облагается»', $snap2['vat']['statement'] === 'НДС не облагается', $snap2['vat']['statement']);
ok('снимок КП не переписан задним числом',
   (int)(Requisites::forProposal($proposalId)['vat']['rate'] ?? -1) === 0);  // json was cleared above

Db::q("UPDATE legal_entities SET pays_vat=1");
Requisites::freeze($proposalId);
Db::q("UPDATE legal_entities SET inn='0000000000', pays_vat=0");
ok('замороженный снимок переживает изменение реквизитов',
   Requisites::forProposal($proposalId)['seller']['inn'] !== '0000000000',
   Requisites::forProposal($proposalId)['seller']['inn']);

echo "\n9. Дисциплина промпта\n";
$rendered = Prompts::render('reply_kp', ['catalog' => 'X']);
ok('блок дисциплины добавлен', str_contains($rendered, 'ДИСЦИПЛИНА ОТВЕТА'));
ok('и запрещает лишние вопросы', str_contains($rendered, 'Уточняющий вопрос допустим ТОЛЬКО'));
Settings::set('LLM_DISCIPLINE', '0');
ok('выключается настройкой', !str_contains(Prompts::render('reply_kp'), 'ДИСЦИПЛИНА ОТВЕТА'));
Settings::set('LLM_DISCIPLINE', '1');
Settings::set('LLM_DISCIPLINE_TEXT', 'СВОИ ПРАВИЛА');
ok('переписывается администратором', str_contains(Prompts::render('reply_kp'), 'СВОИ ПРАВИЛА'));
Settings::forget('LLM_DISCIPLINE_TEXT');
ok('промпт аналогов существует', str_contains(Prompts::text('kp_alternatives'), '"pick"'));

echo "\n10. Документ, который прочитает клиент\n";
try {
    Db::update('proposals', ['show_match_table' => 1], 'id=?', [$proposalId]);
    $html = PdfGenerator::html($proposalId);
    // Таблица соответствия запросу больше не печатается ни при каких настройках
    // (issue #60) — под нашей позицией и так стоит собственное название клиента
    ok('таблицы соответствия в документе больше нет', !str_contains($html, 'Таблица соответствия запросу'));
    ok('и что предлагаем', str_contains($html, 'Страж-5'));
    ok('описание товара печатается', str_contains($html, 'собственного производства'));
    ok('и фотография', str_contains($html, 'data:image/'));
    // Реквизиты поставщика стоят в шапке первой страницы и БОЛЬШЕ НИГДЕ:
    // до модуля 022 тот же ИНН, КПП и адрес печатались вторым блоком в конце
    // документа, и клиент читал одно и то же дважды.
    ok('реквизиты поставщика в шапке', str_contains($html, 'ИНН'));
    ok('и не продублированы в конце', !str_contains($html, 'Реквизиты поставщика'));
    // ИНН ПОКУПАТЕЛЯ в документе тоже есть, и это не дубль — считаем наш
    $sellerInn = (string)(Requisites::forProposal($proposalId)['seller']['inn'] ?? '');
    ok('ИНН поставщика напечатан один раз', substr_count($html, $sellerInn) === 1,
       $sellerInn . ' × ' . substr_count($html, $sellerInn));
    ok('НДС прописан', str_contains($html, 'НДС'));

    $path = PdfGenerator::generate($proposalId);
    ok('PDF собирается', file_exists($path) && filesize($path) > 1000,
       (string)(file_exists($path) ? filesize($path) : 0) . ' байт');
} catch (Throwable $e) {
    ok('документ собирается', false, $e->getMessage());
}

echo "\n11. Ссылка на товар на сайте\n";
Settings::set('BITRIX_ENABLED', '1');
Settings::set('BITRIX_SITE_URL', 'https://atlant-armour.ru');
Settings::set('BITRIX_URL_TEMPLATE', '/catalog/{article}/');
Settings::set('BITRIX_VERIFY_URL', '0');        // никакой сети в тесте
Settings::set('BITRIX_SEARCH_TEMPLATE', '');
$url = Bitrix::productUrl('ms-2');
ok('ссылка собрана по шаблону', $url === 'https://atlant-armour.ru/catalog/STR5/', (string)$url);
ok('ответ закэширован на товаре',
   (string)Db::val("SELECT site_url FROM products_cache WHERE moysklad_id='ms-2'") === (string)$url);
Settings::set('BITRIX_ENABLED', '0');
ok('выключенная интеграция ссылок не даёт', Bitrix::productUrl('ms-4') === null);

echo "\n12. Остаток, который весь в резерве, — это не наличие\n";
Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved, unit, description, vat, product_type, source, updated_at)
       VALUES ('ms-5','Аптечка Лазарь Multicam','аптечка лазарь multicam','APT1',4200,5,5,'шт.','Аптечка первой помощи. Цвет мультикам.',5,'product','test',datetime('now'))");
Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved, unit, description, vat, product_type, source, updated_at)
       VALUES ('ms-6','Аптечка Атлант Multicam','аптечка атлант multicam','APT2',3900,12,1,'шт.','Аптечка первой помощи собственной сборки. Цвет мультикам.',5,'product','test',datetime('now'))");
ProductMatcher::forgetCatalog();
ok('товар «5 на складе, 5 в резерве» в кандидаты не идёт',
   !array_filter(Alternatives::candidates('Аптечка Лазарь Multicam', '', null), fn($c) => $c['moysklad_id'] === 'ms-5'));

$r2 = Db::insert('requests', [
    'source' => 'manual', 'status' => 'new',
    'raw_text' => 'Здравствуйте! Нужна аптечка Лазарь Multicam, 20 штук. Сориентируйте по цене.',
    'parsed_json' => json_encode(['items' => [
        ['name' => 'Аптечка Лазарь Multicam', 'qty' => 20, 'raw_text' => 'аптечка Лазарь Multicam, цвет мультикам'],
    ]], JSON_UNESCAPED_UNICODE),
]);
$rows2 = RequestItems::ensure($r2, false);
ok('полностью зарезервированная позиция получила аналог', (int)$rows2[0]['is_alternative'] === 1);
ok('аналог — тот, что реально свободен', $rows2[0]['moysklad_product_id'] === 'ms-6', (string)$rows2[0]['moysklad_product_id']);
ok('остаток в строке — свободный, а не «сколько лежит»', (int)$rows2[0]['stock'] === 11, (string)$rows2[0]['stock']);

echo "\n13. Текстовый запрос таблицы соответствия не получает\n";
ok('письмо прозой опознано как текст', RequestShape::of($r2) === RequestShape::TEXT, RequestShape::of($r2));
$p2 = Db::insert('proposals', ['request_id' => $r2, 'vat_rate' => 5,
    'show_match_table' => RequestShape::of($r2) === RequestShape::TABLE ? 1 : 0]);
foreach (RequestItems::toProposalItems($rows2) as $i => $m) {
    Db::insert('proposal_items', [
        'proposal_id' => $p2, 'position' => $i + 1,
        'product_name' => $m['match']['name'], 'requested_name' => $m['raw_name'],
        'moysklad_product_id' => $m['match']['moysklad_id'], 'unit' => $m['match']['unit'],
        'quantity' => $m['quantity'], 'price' => $m['match']['price'],
        'stock_available' => $m['match']['stock'],
        'is_alternative' => $m['is_alternative'] ? 1 : 0,
        'alt_reason' => $m['alt_specs']['reason'] ?? null,
        'alt_specs_json' => $m['alt_specs'] ? json_encode($m['alt_specs'], JSON_UNESCAPED_UNICODE) : null,
    ]);
}
KpContent::enrichItems($p2);
Requisites::freeze($p2);
$html2 = PdfGenerator::html($p2);
ok('таблицы соответствия в документе нет', !str_contains($html2, 'Таблица соответствия запросу'));
ok('но замена всё равно объяснена', str_contains($html2, 'Предлагается вместо'));
ok('и позиция в документе есть', str_contains($html2, 'Атлант Multicam'));

echo "\n14. Тот же документ выгружается в Word (модуль 016)\n";
require_once ROOT . '/lib/docx.php';
$docxPath = DocxGenerator::generate($proposalId);
ok('файл .docx создан', is_file($docxPath) && filesize($docxPath) > 2000);
ok('путь записан в КП', (string)Db::val("SELECT docx_path FROM proposals WHERE id=?", [$proposalId]) === $docxPath);
$zip = new ZipArchive();
ok('пакет открывается', $zip->open($docxPath) === true);
$docXml = (string)$zip->getFromName('word/document.xml');
$zip->close();
ok('document.xml — корректный XML', simplexml_load_string($docXml) !== false);
ok('таблицы соответствия нет и в Word', !str_contains($docXml, 'Таблица соответствия запросу'));
ok('позиция в Word есть', str_contains($docXml, 'Страж-5'));
ok('реквизиты в Word не продублированы', !str_contains($docXml, 'Реквизиты поставщика'));
ok('стили шаблона в текст не просочились', !str_contains($docXml, 'border-collapse'));
@unlink($docxPath);

echo "\n" . ($fail ? "$fail FAILED\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
