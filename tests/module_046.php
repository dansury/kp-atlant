<?php
/**
 * Модуль 046 (issue #67) — на выбрасываемой базе и без сети:
 *
 *   — деньги в КП без «,00», вилка только у разных цен модификаций;
 *   — скидка: зачёркнута старая цена, процент серым, «Со скидкой N%»;
 *   — вступление с продавцом и покупателем, без блока «Покупатель»;
 *   — «не наша номенклатура» по галочке КП, на своём месте в порядке подбора;
 *   — НДС 0 у товара плательщика — ставка из настроек; effectiveVat;
 *   — Word: раскладки без рамок, зачёркивание;
 *   — описание с сайта по приоритету; выгрузка .xlsx; требования клиента к КП;
 *   — интерфейс: сброс фото, блоки, масштаб листа (по исходнику).
 *
 * Запуск:  php tests/module_046.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-046-' . getmypid() . '.db';
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
require_once ROOT . '/lib/terms.php';
require_once ROOT . '/lib/kp_terms.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/docx.php';
require_once ROOT . '/lib/kp_text.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/delivery_share.php';
require_once ROOT . '/lib/mail_text.php';
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/kp_editor.php';
require_once ROOT . '/lib/parser.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL " ) . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

require_once ROOT . '/lib/bitrix.php';
require_once ROOT . '/lib/kp_requirements.php';
require_once ROOT . '/bitrix-module/atlant.kpsync/lib/xlsx.php';

Settings::set('KP_QR_CODE', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');

$mgr = Db::insert('managers', ['login' => 'yana', 'password_hash' => Auth::hashPassword('secret'),
                               'name' => 'Яна', 'is_admin' => 0]);
$cpId = Db::insert('counterparties', ['name' => 'ООО «Покупатель»']);

// =====================================================================  1
echo "\n== 1. Деньги и вилка ==\n";
ok('целые рубли без «,00»', KpContent::rub(1800) === '1 800');
ok('копейки — двумя цифрами', KpContent::rub(500.5) === '500,50');
ok('почти целое — округлено', KpContent::rub(599.999) === '600');
ok('одинаковые цены — не вилка', !KpContent::hasRange(600, 600));
ok('разные — вилка', KpContent::hasRange(600, 700));

// =====================================================================  2
echo "\n== 2. Таблица КП: скидка, вилка, прочерки, вступление ==\n";
$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 't', 'status' => 'processing',
                                 'counterparty_id' => $cpId,
                                 'parsed_json' => json_encode(['kp_requirements' => ['страна производства', ' ', 'Страна производства']], JSON_UNESCAPED_UNICODE)]);
$ri1 = Db::insert('request_items', ['request_id' => $reqId, 'position' => 1, 'raw_name' => 'шлем', 'quantity' => 2, 'unit' => 'шт.']);
Db::insert('request_items', ['request_id' => $reqId, 'position' => 2, 'raw_name' => 'Топор пожарный', 'quantity' => 1,
                             'unit' => 'шт.', 'is_out_of_scope' => 1]);
$ri3 = Db::insert('request_items', ['request_id' => $reqId, 'position' => 3, 'raw_name' => 'жилет', 'quantity' => 1, 'unit' => 'шт.']);
$pid = Db::insert('proposals', ['request_id' => $reqId, 'counterparty_id' => $cpId, 'manager_id' => $mgr,
                                'terms_text' => KpTerms::defaultText()]);
Db::insert('proposal_items', ['proposal_id' => $pid, 'position' => 1, 'product_name' => 'Шлем', 'unit' => 'шт.',
                              'quantity' => 2, 'price' => 1000, 'discount_percent' => 10, 'is_confirmed' => 1,
                              'request_item_id' => $ri1]);
Db::insert('proposal_items', ['proposal_id' => $pid, 'position' => 2, 'product_name' => 'Жилет', 'unit' => 'шт.',
                              'quantity' => 1, 'price' => 600, 'price_max' => 600, 'discount_percent' => 10,
                              'is_confirmed' => 1, 'request_item_id' => $ri3]);
Db::insert('proposal_items', ['proposal_id' => $pid, 'position' => 3, 'product_name' => 'Плита', 'unit' => 'шт.',
                              'quantity' => 1, 'price' => 500, 'price_max' => 700, 'is_confirmed' => 1]);
Requisites::freeze($pid);
Db::q("UPDATE proposals SET requisites_json=json_set(requisites_json, '$.buyer.legal_title', 'ООО «Покупатель»') WHERE id=?", [$pid]);

$html = PdfGenerator::html($pid);
ok('«,00» в документе нет', !str_contains($html, ',00 руб'));
ok('одинаковые min и max — одна цена', !str_contains($html, 'от 600 до 600'));
ok('разные — вилка', str_contains($html, 'от 500 до 700 руб.'));
ok('одна скидка — процент в заголовке', str_contains($html, '<th>Со скидкой 10%</th>'));
ok('старая цена зачёркнута', str_contains($html, '<span class="was">1 000 руб.</span>'));
ok('ячейка со скидкой — без процента при общей скидке', !str_contains($html, 'class="disc"'));
ok('блока «Покупатель» нет', !str_contains($html, '>Покупатель<'));
ok('вступление: продавец по запросу покупателя', (bool)preg_match('/по запросу ООО «Покупатель» имеет возможность/u', $html));
ok('«не наша номенклатура» по умолчанию не печатается', !str_contains($html, 'Топор пожарный'));

Db::update('proposal_items', ['discount_percent' => 15], 'proposal_id=? AND position=2', [$pid]);
$html = PdfGenerator::html($pid);
ok('разные скидки — заголовок без процента', str_contains($html, '<th>Со скидкой</th>'));
ok('и процент в ячейке серым', str_contains($html, '<span class="disc">-15%</span><br>'));

Db::update('proposal_items', ['discount_percent' => 0], 'proposal_id=?', [$pid]);
ok('без скидок столбца нет', !str_contains(PdfGenerator::html($pid), 'Со скидкой'));

Db::update('proposals', ['show_out_of_scope' => 1], 'id=?', [$pid]);
$html = PdfGenerator::html($pid);
$posHelmet = strpos($html, 'Шлем'); $posAxe = strpos($html, 'Топор пожарный'); $posVest = strpos($html, 'Жилет');
ok('галочка КП включает строку', $posAxe !== false);
ok('строка на своём месте: между шлемом и жилетом', $posHelmet < $posAxe && $posAxe < $posVest);
ok('название курсивом', str_contains($html, '<em class="out-of-scope__name">Топор пожарный</em>'));
ok('в «Итого» не вошла', str_contains($html, 'Итого: от 3 100 руб.'), 'итог');

$docx = DocxGenerator::generate($pid);
$zip = new ZipArchive();
$xml = $zip->open($docx) === true ? (string)$zip->getFromName('word/document.xml') : '';
$zip->close(); @unlink($docx);
ok('Word: шапка — таблица без рамок', str_contains($xml, '<w:top w:val="nil"/>'));
ok('Word: «не наша номенклатура» курсивом серым', (bool)preg_match('#<w:i/><w:color w:val="8A8A8A"/>.{0,200}Топор#su', $xml));
Db::update('proposal_items', ['discount_percent' => 10], 'proposal_id=? AND position=1', [$pid]);
$docx = DocxGenerator::generate($pid);
$xml = $zip->open($docx) === true ? (string)$zip->getFromName('word/document.xml') : '';
$zip->close(); @unlink($docx);
ok('Word: старая цена зачёркнута', str_contains($xml, '<w:strike/>'));

// =====================================================================  3
echo "\n== 3. НДС ==\n";
Db::insert('products_cache', ['moysklad_id' => 'v0', 'name' => 'Без ставки', 'price' => 1, 'vat' => 0]);
Db::update('proposal_items', ['moysklad_product_id' => 'v0'], 'proposal_id=?', [$pid]);
$vat = Requisites::vatFor($pid, ['pays_vat' => 1], 0);
ok('ставка 0 у товара плательщика — из настроек', $vat['rate'] > 0, (string)$vat['rate']);
ok('effectiveVat берётся первым', MoySklad::vatOf(['vat' => 0, 'vatEnabled' => false, 'effectiveVat' => 20, 'effectiveVatEnabled' => true]) === 20);
ok('без effectiveVat — собственная', MoySklad::vatOf(['vat' => 10]) === 10);
ok('ставки нет — null', MoySklad::vatOf([]) === null);

// =====================================================================  4
echo "\n== 4. Описание: МойСклад или сайт ==\n";
Db::insert('products_cache', ['moysklad_id' => 'd1', 'name' => 'Товар', 'price' => 1, 'description' => '',
                              'site_description' => 'С сайта']);
Db::insert('products_cache', ['moysklad_id' => 'd2', 'name' => 'Товар 2', 'price' => 1, 'description' => 'Из МойСклад',
                              'site_description' => 'С сайта 2']);
$d = RequestItems::catalogDescriptions(['d1', 'd2']);
ok('в МойСклад пусто — с сайта', ($d['d1'] ?? '') === 'С сайта');
ok('по умолчанию МойСклад первым', ($d['d2'] ?? '') === 'Из МойСклад');
Settings::set('KP_DESCRIPTION_SOURCE', 'bitrix_first');
ok('bitrix_first — сайт первым', (RequestItems::catalogDescriptions(['d2'])['d2'] ?? '') === 'С сайта 2');
Settings::forget('KP_DESCRIPTION_SOURCE');
$page = Bitrix::parseExport(json_encode(['result' => [['ARTICLE' => 'a', 'url' => 'https://s.test/a', 'DESCRIPTION' => '<p>Текст</p>']]]));
ok('выгрузка сайта несёт описание', ($page['items'][0]['description'] ?? '') === '<p>Текст</p>');

// =====================================================================  5
echo "\n== 5. Excel из модуля Битрикс ==\n";
$path = \Atlant\KpSync\Xlsx::write(['Код', 'Название'], [['x1', "Шлем <A&B>\nL"], ["x\x01", '']]);
$zip = new ZipArchive();
$ok = $zip->open($path) === true;
$sheet = $ok ? (string)$zip->getFromName('xl/worksheets/sheet1.xml') : '';
$zip->close(); @unlink($path);
ok('файл открывается как zip с листом', $ok && $sheet !== '');
ok('текст экранирован', str_contains($sheet, 'Шлем &lt;A&amp;B&gt;'));
ok('управляющие символы вырезаны', !str_contains($sheet, "\x01"));
ok('лист — корректный XML', (bool)@simplexml_load_string($sheet));
ok('столбцы A, Z, AA', \Atlant\KpSync\Xlsx::col(0) . \Atlant\KpSync\Xlsx::col(25) . \Atlant\KpSync\Xlsx::col(26) === 'AZAA');
$src = file_get_contents(ROOT . '/bitrix-module/atlant.kpsync/lib/export.php');
ok('в выгрузке пять столбцов из issue', str_contains($src, "['Внешний код', 'Название товара', 'Модификации и характеристики', 'Описание', 'Ссылка на сайте']"));

// =====================================================================  6
echo "\n== 6. Требования клиента к КП ==\n";
ok('требования прочитаны, пустые и повторы убраны', KpRequirements::of($reqId) === ['страна производства']);
ok('нет разбора — пусто', KpRequirements::of(999) === []);
$prompts = Prompts::registry();
ok('разбор письма спрашивает kp_requirements', str_contains($prompts['parse_request'][3], 'kp_requirements')
   && str_contains($prompts['classify_request'][3], 'kp_requirements'));
ok('промпт абзаца запрещает выдумывать', str_contains($prompts['kp_requirements'][3], 'Ничего не выдумывай'));

// =====================================================================  7
echo "\n== 7. Интерфейс (по исходнику) ==\n";
$js = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');
ok('«Сбросить выбор» у фото', str_contains($js, 'resetPhotos(btn, itemId)') && str_contains($js, 'Сбросить выбор'));
ok('«Информация» на телефоне свёрнута всегда', str_contains($js, "if (name === 'info' && this.isPhone()) return true;"));
ok('положение блоков — у каждого письма', str_contains($js, "'kp.fold.' + (this.foldKey || '')"));
ok('масштаб листа: колесо с Ctrl и щипок', str_contains($js, "if (!e.ctrlKey && !e.metaKey) return;") && str_contains($js, "'touchmove'"));
ok('масштаб не попадает в сохранённую правку', str_contains($js, "root.style.removeProperty('zoom')"));
ok('на телефоне КП под блоком', str_contains($js, "(this.isPhone() && host && host.querySelector('[data-kp-slot]'))"));
ok('«Этап» внизу на телефоне', str_contains($css, '#app:has(> .letter) > #threadPlacement'));
ok('у блоков свой оттенок', str_contains($css, '[data-block="items"]    { --tint: var(--tint-items); }'));

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
