<?php
/**
 * Module 034: КП как в образце, доставка строкой подбора и ошибки, которые
 * больше не ошибки.
 *
 * Проверяется ровно то, ради чего модуль появился:
 *   — «под заказ» печатается в строке КП ОДИН раз, а не дважды;
 *   — срок исполнения — месяцы ожидания, если что-то под заказ, и дни, если нет;
 *   — «доставка считается отдельно», а не «отдельной строкой»;
 *   — описания товаров уехали в приложение №1, а банк — в шапку;
 *   — доставка живёт строкой подбора, включена сразу и убирается крестиком;
 *   — оборванный и обёрнутый ответ модели всё-таки разбирается как JSON;
 *   — второй вебхук МойСклад по тому же заказу не падает на UNIQUE;
 *   — поисковая ссылка на сайт уступает место странице товара;
 *   — подпись в Word печатается подписью, а не картинкой на полстраницы.
 *
 * Run:  php tests/module_034.php
 *
 * База создаётся в системном временном каталоге — `data/kp.db` не открывается,
 * так что запуск на сервере не может задеть живые данные.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-034-' . getmypid() . '.db';
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
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/kp_set.php';
require_once ROOT . '/lib/kp_terms.php';
require_once ROOT . '/lib/terms.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/docx.php';
require_once ROOT . '/lib/llm.php';
require_once ROOT . '/lib/bitrix.php';

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

// Организация — та, что уже стоит активной после первого запуска
Db::q("UPDATE legal_entities SET short_name='ООО \"АТЛАНТ АРМОР\"',
              full_name='ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ \"АТЛАНТ АРМОР\"',
              inn='9731154370', ogrn='1257700368595', city='г. Москва',
              address='ул. Крылатские холмы, 37', signatory_name='Сурков К.А.'
        WHERE is_active=1");

Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved,
                                   unit, description, vat, product_type, source, updated_at)
       VALUES ('p-helmet', 'Баллистический шлем Протон СВМПЭ', 'баллистический шлем протон свмпэ', 'ProtonPE',
               35000.0, 0, 0, 'шт.', '<p>Класс защиты Бр1.</p>', 22, 'product', 'api', datetime('now'))");

$cpId = Db::insert('counterparties', ['name' => "ООО 'Воевода'", 'contact_email' => 'logist@voevoda.pro']);
$requestId = Db::insert('requests', [
    'source' => 'email', 'raw_text' => 'Шлем Протон — 25 шт', 'counterparty_id' => $cpId, 'status' => 'new',
    'parsed_json' => json_encode(['items' => [['name' => 'Баллистический шлем Протон СВМПЭ', 'qty' => 25]]],
                                 JSON_UNESCAPED_UNICODE),
]);

echo "\n1. Доставка — строка подбора, включённая с самого начала\n";

$d = RequestItems::delivery($requestId);
ok('доставка включена сразу', (int)$d['on'] === 1, json_encode($d, JSON_UNESCAPED_UNICODE));
ok('и названа по-человечески', $d['name'] === 'Доставка');
ok('цена — ноль, пока её не поставили', (float)$d['price'] === 0.0);

RequestItems::saveDelivery($requestId, ['name' => 'Доставка до ТК', 'price' => 8500]);
$d = RequestItems::delivery($requestId);
ok('цена доставки сохранилась', (float)$d['price'] === 8500.0, json_encode($d, JSON_UNESCAPED_UNICODE));

RequestItems::saveDelivery($requestId, null);
ok('крестик убирает доставку', (int)RequestItems::delivery($requestId)['on'] === 0);
RequestItems::saveDelivery($requestId, ['name' => 'Доставка до ТК', 'price' => 8500]);

echo "\n2. Доставка из подбора едет в КП сама\n";

RequestItems::ensure($requestId);
$proposalId = KpSet::create($requestId, null);
foreach (RequestItems::toProposalItems(RequestItems::all($requestId)) as $i => $m) {
    Db::insert('proposal_items', KpSet::itemRow($m, $i + 1) + ['proposal_id' => $proposalId]);
}
$proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
ok('КП собралось с доставкой', (int)$proposal['delivery_on'] === 1);
ok('той же ценой', (float)$proposal['delivery_price'] === 8500.0, (string)$proposal['delivery_price']);
ok('и тем же названием', $proposal['delivery_name'] === 'Доставка до ТК');

echo "\n3. «Под заказ» печатается один раз\n";

// Описание товара — то, ради чего в документе появилось приложение №1
Db::update('proposal_items', ['description_text' => 'Класс защиты Бр1. Материал — СВМПЭ.'],
           'proposal_id=?', [$proposalId]);
$item = Db::one("SELECT * FROM proposal_items WHERE proposal_id=?", [$proposalId]);
Db::update('proposal_items', ['notes' => Terms::AUTO_NOTE, 'wait_on' => 1, 'wait_months' => 3,
                              'wait_discount' => 10, 'wait_prepay' => 100], 'id=?', [$item['id']]);
$item = Db::one("SELECT * FROM proposal_items WHERE id=?", [$item['id']]);

ok('условия ожидания сложились в фразу', str_starts_with(Terms::note($item), 'под заказ, срок ожидания 3 месяца'),
   Terms::note($item));
ok('а авто-примечание при них молчит', Terms::itemNote($item) === '', Terms::itemNote($item));

Db::update('proposal_items', ['notes' => 'Цвет — олива'], 'id=?', [$item['id']]);
$mine = Db::one("SELECT * FROM proposal_items WHERE id=?", [$item['id']]);
ok('примечание менеджера не трогается', Terms::itemNote($mine) === 'Цвет — олива');
Db::update('proposal_items', ['notes' => Terms::AUTO_NOTE], 'id=?', [$item['id']]);

$html = PdfGenerator::html($proposalId);
ok('в документе «под заказ» ровно один раз', substr_count($html, 'под заказ') === 1,
   (string)substr_count($html, 'под заказ'));

echo "\n4. Срок исполнения — тот, что в таблице подбора\n";

$proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
$terms = KpTerms::forProposal($proposal);
ok('под заказ — срок в месяцах', str_contains($terms, 'условий договора 3 месяца'), $terms);
ok('и никаких 30 календарных дней', !str_contains($terms, 'календарных дней'), $terms);
// По умолчанию доставка включена в цену товара — оговорки про отдельную
// оплату в тексте условий нет вовсе (issue #60)
ok('доставка по умолчанию в цене товара, а не отдельной строкой',
   str_contains($terms, 'хранение, доставку, подготовку')
   && !str_contains($terms, 'не включена') && !str_contains($terms, 'отдельной строкой'), $terms);

Db::update('proposal_items', ['wait_on' => 0], 'proposal_id=?', [$proposalId]);
$terms = KpTerms::forProposal(Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]));
ok('весь товар в наличии — срок в днях', str_contains($terms, 'условий договора 30 календарных дней'), $terms);
Db::update('proposal_items', ['wait_on' => 1], 'proposal_id=?', [$proposalId]);

echo "\n5. Документ собран как образец\n";

Db::update('proposals', ['intro_text' => null], 'id=?', [$proposalId]);
$html = PdfGenerator::html($proposalId);
ok('во вступлении — короткое имя компании',
   str_contains($html, 'ООО &quot;АТЛАНТ АРМОР&quot; по Вашему запросу имеет возможность')
   || str_contains($html, 'ООО &quot;АТЛАНТ АРМОР&quot; по запросу '),
   substr($html, strpos($html, 'имеет возможность') ?: 0, 80));
// По умолчанию (issue #60) доставка распределена по позициям; настройка
// возвращает прежнее поведение — отдельной строкой таблицы
Settings::set('KP_DELIVERY_MODE', 'line');
$htmlLine = PdfGenerator::html($proposalId);
ok('доставка стоит строкой таблицы', str_contains($htmlLine, 'Доставка до ТК'));
Settings::forget('KP_DELIVERY_MODE');
ok('описание товара — в приложении №1', str_contains($html, 'Приложение №1'));
ok('и на него есть ссылка под таблицей',
   str_contains($html, 'Более подробное описание товаров приведено в приложении №1.'));
ok('приложение идёт ПОСЛЕ условий поставки',
   strpos($html, 'Приложение №1') > strpos($html, 'Предлагаемая цена продукции'));
ok('подпись — отдельной картинкой с классом', !str_contains($html, '<img src="" class="sign-img"'));

// Банк из замороженных реквизитов печатается в шапке, а не блоком внизу
Db::update('proposals', ['requisites_json' => json_encode([
    'seller' => ['short_name' => 'ООО "АТЛАНТ АРМОР"', 'inn' => '9731154370', 'kpp' => '773101001',
                 'bank' => ['line' => 'АО "ТБанк", р/с 40702810610001953607']],
    'buyer'  => ['name' => "ООО 'Воевода'"],
], JSON_UNESCAPED_UNICODE)], 'id=?', [$proposalId]);
$html = PdfGenerator::html($proposalId);
$header = substr($html, 0, strpos($html, 'Коммерческое предложение') ?: 0);
ok('банк напечатан в шапке', str_contains($header, 'ТБанк'), $header === '' ? 'шапка не найдена' : '');
ok('и второй раз внизу не печатается', substr_count($html, 'ТБанк') === 1,
   (string)substr_count($html, 'ТБанк'));

echo "\n6. Word: подпись размером с подпись, приложение — с новой страницы\n";

// Подпись — сканированная картинка: раньше она печаталась в Word шириной 430 px,
// то есть на полстраницы. Кладём заведомо большую и смотрим, какой она выйдет.
$big = imagecreatetruecolor(900, 400);
ob_start(); imagepng($big); $bigPng = (string)ob_get_clean();
unset($big);   // imagedestroy() в PHP 8 ничего не делает и вычищен из кода (модуль 031)
$signDir = ROOT . '/storage/signatures';
if (!is_dir($signDir)) mkdir($signDir, 0755, true);
$signPath = $signDir . '/test-034.png';
file_put_contents($signPath, $bigPng);
register_shutdown_function(fn() => @unlink($signPath));
Db::q("UPDATE legal_entities SET signature_path=? WHERE is_active=1", [$signPath]);
// Подпись организации — по выбору менеджера КП (модуль 051)
$signer = Db::insert('managers', ['login' => 'signer034', 'name' => 'Подписант', 'password_hash' => 'x',
                                  'is_admin' => 0, 'kp_signature_mode' => 'company']);
Db::update('proposals', ['manager_id' => $signer], 'id=?', [$proposalId]);

$path = DocxGenerator::generate($proposalId);
ok('файл .docx собрался', is_file($path) && filesize($path) > 0);
$zip = new ZipArchive();
$zip->open($path);
$docXml = (string)$zip->getFromName('word/document.xml');
$zipStyles = (string)$zip->getFromName('word/styles.xml');
$zipFonts = (string)$zip->getFromName('word/fontTable.xml');
$zip->close();
ok('в пакете есть таблица шрифтов', str_contains($zipFonts, '<w:fonts'));
ok('и document.xml — правильный XML', simplexml_load_string($docXml) !== false);
ok('в документе есть разрыв страницы перед приложением', str_contains($docXml, '<w:br w:type="page"/>'));
ok('имя файла .docx есть чем назвать', DocxGenerator::filename($proposalId) !== '');

// Ни одна картинка в Word не шире 430 px, а подпись — не шире 170
$widths = [];
if (preg_match_all('/<wp:extent cx="(\d+)"/', $docXml, $m)) {
    foreach ($m[1] as $cx) $widths[] = (int)round($cx / 9525);
}
ok('картинки не вылезают за страницу', !$widths || max($widths) <= 430, implode(', ', $widths));

// Подпись — последняя картинка документа и единственная не шире 170 px
$heights = [];
if (preg_match_all('/<wp:extent cx="(\d+)" cy="(\d+)"/', $docXml, $m)) {
    foreach ($m[2] as $cy) $heights[] = (int)round($cy / 9525);
}
$signW = $widths ? end($widths) : 0;
$signH = $heights ? end($heights) : 0;
ok('подпись в Word — подпись, а не картинка на полстраницы',
   $signW > 0 && $signW <= 170 && $signH <= 60, $signW . '×' . $signH . ' px');

// В PDF подпись ограничена стилем — он должен остаться на месте
$css = file_get_contents(ROOT . '/templates/kp.html');
ok('и в PDF она тоже ограничена', str_contains($css, '.signature img.sign-img { max-height: 46px; max-width: 115px;'));

// Шрифт и обтекание — то, чем документ отличался от образца (модуль 035)
ok('документ набран шрифтом из настроек',
   str_contains((string)$zipStyles, 'Microsoft Sans Serif'), Html2Docx::font());
ok('кегль основного текста — 10 пт', Html2Docx::bodyHalfPoints() === 20);
// Знак — в левой ячейке шапки-раскладки, реквизиты — в правой (модуль 046)
ok('знак стоит слева, в своей ячейке шапки',
   (bool)preg_match('/<w:tbl>.*?<wp:inline.*?<\/w:tc><w:tc>.*?ИНН/su', $docXml));
ok('подпись лежит поверх строки, не раздвигая текст',
   str_contains($docXml, '<wp:wrapNone/>') && str_contains($docXml, 'behindDoc="1"'));
ok('фотографии товара не «в строке» — плавают', substr_count($docXml, '<wp:inline') <= 2);
ok('фотография в PDF обтекается текстом', str_contains($css, '.card .gallery { float: right;'));

echo "\n7. Ответ модели разбирается, даже если оборвался\n";

ok('ограда ```json снимается', LLM::decodeJson('```json{"a":1}```') === ['a' => 1]);
ok('фраза вокруг объекта не мешает',
   LLM::decodeJson('вот результат: {"inn":"7701234567"} — всё') === ['inn' => '7701234567']);
ok('рассуждение <think> выбрасывается', LLM::decodeJson('<think>ну</think>{"x":[1,2]}') === ['x' => [1, 2]]);
ok('хвостовая запятая прощается', LLM::decodeJson('{"a":1,}') === ['a' => 1]);
ok('оборванный на полуслове ответ чинится',
   LLM::decodeJson('{"items":[{"name":"шлем","qty":2},{"name":"броне') === ['items' => [['name' => 'шлем', 'qty' => 2]]]);
ok('незакрытая скобка дописывается', LLM::decodeJson('{"a":1') === ['a' => 1]);
ok('не-JSON так и остаётся не-JSON', LLM::decodeJson('совсем не json') === null);

echo "\n8. Второй вебхук МойСклад по тому же заказу не падает\n";

$orderId = Db::insert('orders', ['moysklad_id' => 'ms-order-1', 'counterparty_id' => $cpId,
                                 'name' => '00001', 'sum' => 787500]);
$duplicated = false;
try {
    Db::insert('orders', ['moysklad_id' => 'ms-order-1', 'counterparty_id' => $cpId, 'name' => '00001']);
} catch (PDOException $e) {
    $duplicated = str_contains($e->getMessage(), 'UNIQUE constraint failed');
}
ok('база по-прежнему держит уникальность moysklad_id', $duplicated);
ok('и заказ в базе один', (int)Db::val("SELECT COUNT(*) FROM orders WHERE moysklad_id='ms-order-1'") === 1);
ok('upsertOrder ловит дубль сам',
   str_contains(file_get_contents(ROOT . '/lib/sync.php'), 'if (!self::isDuplicate($e)) throw $e;'));

echo "\n9. Ссылка на поиск уступает место странице товара\n";

ok('поисковая ссылка узнаётся', Bitrix::isSearchUrl('https://atlant-armour.ru/search/?q=ProtonPE'));
ok('страница товара — не поиск', !Bitrix::isSearchUrl('https://atlant-armour.ru/catalog/protonpe/'));
ok('источник ссылки хранится на товаре',
   Db::val("SELECT COUNT(*) FROM pragma_table_info('products_cache') WHERE name='site_url_source'") > 0);

echo "\n10. Экран: этап переключается у каждого письма, кнопки не двоятся\n";

$js = file_get_contents(ROOT . '/public/assets/js/app.js');
ok('у переписки есть переключатель этапа', str_contains($js, 'App.moveThreadCard('));
ok('надписи «На доске:» больше нет', !str_contains($js, '<span class="muted">На доске:</span>'));
ok('и кнопки «В доску» тоже', !str_contains($js, 'App.boardPick('));
ok('«завести в МойСклад» стоит на строке организации', str_contains($js, '➕ Завести в МойСклад'));
// В карточке компании отдельного предложения завести контрагента больше нет:
// оно жило над списком организаций и делало то же самое
ok('отдельной кнопки над списком организаций нет',
   !str_contains(substr($js, strpos($js, 'companySide(cp) {') ?: 0,
                        strpos($js, 'orgListHtml(cpId, orgs)') - (strpos($js, 'companySide(cp) {') ?: 0)),
                 'msCreateLink'));
ok('ИНН ищется по переписке', str_contains($js, 'App.msFindInn()'));
ok('скачивание проверяет ответ сервера', str_contains($js, 'if (!res.ok) throw new Error(await this.errorTextOf(res))'));
ok('«собрать КП в файл» раскрывает предпросмотр', str_contains($js, 'this.openKpUnderLetter(proposalId, btn)'));

echo "\n11. Имя и номер документа\n";

ok('имя файла — информативное',
   (bool)preg_match('/^КП_Атлант_Армор_для_ООО_Воевода_от_\d{2}\.\d{2}\.\d{4}\.docx$/u',
                    DocxGenerator::filename($proposalId)), DocxGenerator::filename($proposalId));
ok('и латиницей для заголовка — тоже',
   (bool)preg_match('/^KP_Atlant_Armor_dlya_OOO_Voevoda_ot_\d{2}\.\d{2}\.\d{4}\.docx$/', PdfGenerator::asciiFileName($proposalId, 'docx')),
   PdfGenerator::asciiFileName($proposalId, 'docx'));

// Номер не должен повторяться: убрали КП — следующее не берёт его номер
$firstNumber = (string)Db::val("SELECT number FROM proposals WHERE id=?", [$proposalId]);
$extra = KpSet::create($requestId, null);
PdfGenerator::generate($extra);
$extraNumber = (string)Db::val("SELECT number FROM proposals WHERE id=?", [$extra]);
ok('у второго КП свой номер', $extraNumber !== '' && $extraNumber !== $firstNumber,
   $firstNumber . ' / ' . $extraNumber);
KpSet::delete($extra);
$third = KpSet::create($requestId, null);
PdfGenerator::generate($third);
$thirdNumber = (string)Db::val("SELECT number FROM proposals WHERE id=?", [$third]);
ok('убранное КП не отдаёт свой номер следующему',
   $thirdNumber !== $extraNumber && $thirdNumber !== $firstNumber,
   $firstNumber . ' / ' . $extraNumber . ' / ' . $thirdNumber);
KpSet::delete($third);

echo "\n12. Старые условия переписываются, подписанные — нет\n";

// Ровно тот текст, который лежит в живых базах с модуля 026
$oldTerms = "Стоимость включает расходы на упаковку, маркировку, хранение, погрузку, "
          . "подготовку и передачу документов. Доставка в стоимость не включена и считается отдельной строкой.\n"
          . "Сроки выполнения условий договора {execution_days} календарных дней с момента получения предоплаты.\n"
          . "Предлагаемая цена продукции является твёрдой и не подлежит изменению в течение "
          . "{validity_days} дней с даты настоящего предложения.";

Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '32')");
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('default_terms_text', ?)", [$oldTerms]);
$draftId = Db::insert('proposals', ['request_id' => $requestId, 'terms_text' => $oldTerms, 'status' => 'draft']);
$sentId  = Db::insert('proposals', ['request_id' => $requestId, 'terms_text' => $oldTerms, 'status' => 'sent']);
$ownId   = Db::insert('proposals', ['request_id' => $requestId, 'terms_text' => 'Свои условия', 'status' => 'draft']);
runMigrations();

// Цепочка миграций проводит старый текст через ВСЕ версии подряд — от
// «отдельной строкой» (v32) через «считается отдельно» (v33) до сегодняшних
// плейсхолдеров доставки (issue #60, v38) — и останавливается на последней
$setting = (string)Db::val("SELECT value FROM settings WHERE key='default_terms_text'");
ok('заготовка для новых КП обновилась до сегодняшнего вида', $setting === KpTerms::FACTORY_TEXT, $setting);
ok('нетронутый черновик обновился так же',
   (string)Db::val("SELECT terms_text FROM proposals WHERE id=?", [$draftId]) === KpTerms::FACTORY_TEXT);
ok('отправленное КП осталось как подписывали',
   str_contains((string)Db::val("SELECT terms_text FROM proposals WHERE id=?", [$sentId]), 'отдельной строкой'));
ok('свой текст менеджера не тронут',
   Db::val("SELECT terms_text FROM proposals WHERE id=?", [$ownId]) === 'Свои условия');

// Даже со старым текстом срок исполнения печатается настоящий
$legacy = KpTerms::fill($oldTerms, ['id' => $proposalId, 'execution_days' => 30, 'validity_days' => 14]);
ok('в старом тексте «30 календарных дней» уступает сроку ожидания',
   str_contains($legacy, 'условий договора 3 месяца'), $legacy);

echo "\n" . ($fail ? "ПРОВАЛОВ: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
