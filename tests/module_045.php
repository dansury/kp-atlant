<?php
/**
 * Модуль 045 (остаток issue #60) — на выбрасываемой базе и без сети:
 *
 *   — доставка раскладывается по цене за единицу и так же уходит в счёт;
 *   — «не наша номенклатура» печатается в КП серым с прочерками;
 *   — письмо не называет остаток цифрой, ставит «см. на сайте» и скидку;
 *   — печатная форма счёта выбирается по имени;
 *   — КП, поправленное на странице A4, собирается из правки, пока её не сняли.
 *
 * Запуск:  php tests/module_045.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-045-' . getmypid() . '.db';
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

Settings::set('KP_QR_CODE', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');

$mgr = Db::insert('managers', ['login' => 'yana', 'password_hash' => Auth::hashPassword('secret'),
                               'name' => 'Яна', 'is_admin' => 0]);
$cpId = Db::insert('counterparties', ['name' => 'ООО «Покупатель»']);

// =====================================================================  1
echo "\n== 1. Доля доставки: копейка в копейку ==\n";

$s = DeliveryShare::perUnit([['unit' => 1000, 'qty' => 1], ['unit' => 3000, 'qty' => 1]], 300);
ok('доли 75 и 225', $s === [75.0, 225.0], json_encode($s));

$s = DeliveryShare::perUnit([['unit' => 100, 'qty' => 3], ['unit' => 50, 'qty' => 1], ['unit' => 10, 'qty' => 7]], 100);
$spread = 0.0;
foreach ([3, 1, 7] as $i => $q) $spread += $s[$i] * $q;
ok('остаток уходит строке с количеством 1 — сумма точна', abs($spread - 100) < 0.001, (string)$spread);

$s = DeliveryShare::perUnit([['unit' => 100, 'qty' => 3], ['unit' => 100, 'qty' => 3]], 100);
$spread = ($s[0] + $s[1]) * 3;
ok('без строки на 1 шт. расхождение — меньше копейки на штуку', abs($spread - 100) <= 0.03, (string)$spread);
ok('нечего раскладывать — нули', DeliveryShare::perUnit([['unit' => 0, 'qty' => 1]], 100) === [0.0]);

// =====================================================================  2
echo "\n== 2. Счёт по КП: доставка в цене, скидка за ожидание не теряется ==\n";

$proposal = ['id' => 0, 'delivery_on' => 1, 'delivery_price' => 300, 'vat_rate' => 22];
$rows = [
    ['moysklad_product_id' => 'p-1', 'product_name' => 'А', 'quantity' => 1, 'price' => 1000, 'discount_percent' => 0],
    ['moysklad_product_id' => 'p-2', 'product_name' => 'Б', 'quantity' => 1, 'price' => 3000, 'discount_percent' => 10],
    ['moysklad_product_id' => null,  'product_name' => 'Без карточки', 'quantity' => 1, 'price' => 500],
];
$inv = DeliveryShare::invoicePositions($proposal, $rows);
ok('позиция без карточки МойСклад пропущена', $inv['skipped'] === ['Без карточки']);
$sum = 0.0;
foreach ($inv['positions'] as $p) $sum += $p['price'] * (1 - $p['discount'] / 100) * $p['quantity'];
// КП: 1000 + 2700 (скидка 10%) + 300 доставки
ok('сумма счёта = сумма КП с доставкой', abs($sum - 4000) < 0.05, (string)$sum);
ok('скидка позиции сохранена', (float)$inv['positions'][1]['discount'] === 10.0);
ok('доставка отдельной строкой не заведена', count($inv['positions']) === 2);

$waitRow = ['moysklad_product_id' => 'p-3', 'product_name' => 'В', 'quantity' => 2, 'price' => 1000,
            'discount_percent' => 0, 'wait_on' => 1, 'wait_discount' => 10, 'wait_months' => 3, 'wait_prepay' => 100];
$inv = DeliveryShare::invoicePositions(['delivery_on' => 0], [$waitRow]);
ok('скидка за ожидание доезжает до счёта', (float)$inv['positions'][0]['discount'] === 10.0,
   (string)$inv['positions'][0]['discount']);

Settings::set('KP_DELIVERY_MODE', 'line');
$inv = DeliveryShare::invoicePositions($proposal, $rows);
ok('строкой, услуга не указана — доставка названа пропавшей', $inv['delivery_missing'] === true);
Settings::set('MS_DELIVERY_SERVICE_ID', 'svc-1');
$inv = DeliveryShare::invoicePositions($proposal, $rows);
$last = end($inv['positions']);
ok('строкой, услуга указана — строка услуги', ($last['type'] ?? '') === 'service' && (float)$last['price'] === 300.0);
ok('и цены товаров без надбавки', (float)$inv['positions'][0]['price'] === 1000.0);
Settings::forget('MS_DELIVERY_SERVICE_ID');
Settings::forget('KP_DELIVERY_MODE');

// =====================================================================  3
echo "\n== 3. КП: доля в цене за единицу, «не наша номенклатура» с прочерками ==\n";

$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 'test', 'status' => 'processing',
                                 'counterparty_id' => $cpId]);
Db::insert('request_items', ['request_id' => $reqId, 'position' => 9, 'raw_name' => 'Топор пожарный',
                             'product_name' => '', 'quantity' => 1, 'unit' => 'шт.', 'is_out_of_scope' => 1]);
$proposalId = Db::insert('proposals', ['request_id' => $reqId, 'counterparty_id' => $cpId,
                                       'manager_id' => $mgr, 'delivery_on' => 1,
                                       'delivery_name' => 'Доставка', 'delivery_price' => 300,
                                       'terms_text' => KpTerms::defaultText()]);
Db::insert('proposal_items', ['proposal_id' => $proposalId, 'position' => 1, 'product_name' => 'Товар А',
                              'unit' => 'шт.', 'quantity' => 2, 'price' => 1000, 'is_confirmed' => 1,
                              'site_url' => 'https://shop.test/a']);
Db::insert('proposal_items', ['proposal_id' => $proposalId, 'position' => 2, 'product_name' => 'Товар Б',
                              'unit' => 'шт.', 'quantity' => 1, 'price' => 2000, 'is_confirmed' => 1,
                              'discount_percent' => 10]);
Requisites::freeze($proposalId);

Db::update('proposals', ['show_out_of_scope' => 1], 'id=?', [$proposalId]);
$html = PdfGenerator::html($proposalId);
// 2×1000 + 1800 = 3800; доля А: 300·2000/3800/2 = 78,95 за шт → 1 078,95; остаток Б: 300−157,90 = 142,10
ok('цена за единицу включает долю доставки', str_contains($html, '1 078,95'), 'А');
ok('сумма строки = цена × количество', str_contains($html, '2 157,90'));
ok('строка со скидкой: «Со скидкой» с долей', str_contains($html, '1 942,10'));
ok('«Итого» — товары и доставка', str_contains($html, 'Итого: 4 100 руб.'));
ok('строка «не наша номенклатура» напечатана', (bool)preg_match('#<tr class="out-of-scope">.*Топор пожарный.*</tr>#s', $html));
preg_match('#<tr class="out-of-scope">.*?</tr>#s', $html, $row);
ok('в ней прочерки', substr_count($row[0] ?? '', '>—<') === 5, (string)substr_count($row[0] ?? '', '>—<'));

$docx = DocxGenerator::generate($proposalId);
$zip = new ZipArchive();
$xml = $zip->open($docx) === true ? (string)$zip->getFromName('word/document.xml') : '';
$zip->close();
ok('в Word строка «не наша номенклатура» серая', str_contains($xml, 'Топор пожарный') && str_contains($xml, '8A8A8A'));
@unlink($docx);

$second = Db::insert('proposals', ['request_id' => $reqId, 'counterparty_id' => $cpId, 'manager_id' => $mgr]);
Requisites::freeze($second);
ok('во втором КП запроса её нет', !str_contains(PdfGenerator::html($second), 'Топор пожарный'));

$text = KpText::render($proposalId);
ok('в тексте КП цена с долей доставки', str_contains($text['text'], '1 078,95'));
ok('в тексте КП отдельной строки доставки нет', !str_contains($text['text'], 'Доставка —'));
ok('скидка названа словами', str_contains($text['text'], 'скидка 10%'));
ok('ссылка — «см. на сайте»', str_contains($text['text'], 'см. на сайте: https://shop.test/a'));
ok('в HTML это ссылка со словами', str_contains($text['html'], '<a href="https://shop.test/a">см. на сайте</a>'));
ok('«не наша номенклатура» — прочерком', str_contains($text['text'], '— Топор пожарный — не поставляем'));

// =====================================================================  4
echo "\n== 4. Письмо: наличие словами, скидка, «см. на сайте» ==\n";

Db::insert('products_cache', ['moysklad_id' => 'ms-1', 'name' => 'Шлем', 'price' => 1000, 'stock' => 57,
                              'unit' => 'шт.', 'site_url' => 'https://shop.test/helmet']);
$block = RequestItems::matchedBlock([
    ['product_name' => 'Шлем', 'moysklad_product_id' => 'ms-1', 'quantity' => 5, 'unit' => 'шт.',
     'price' => 1000, 'discount_percent' => 10, 'effective_price' => 900, 'stock' => 57],
    ['product_name' => 'Плита', 'quantity' => 10, 'unit' => 'шт.', 'price' => 500, 'stock' => 3],
]);
ok('остаток цифрой не назван', !str_contains($block, '57') && !str_contains($block, 'в наличии 3'));
ok('«в наличии» словами', str_contains($block, 'Шлем') && str_contains($block, ', в наличии'));
ok('нехватка — «не всё количество»', str_contains($block, 'в наличии не всё количество'));
ok('скидка названа', str_contains($block, 'скидка 10%'));
ok('ссылка «см. на сайте» под позицией', str_contains($block, 'см. на сайте: https://shop.test/helmet'));

$line = RequestParser::coverLetterLine(['product_name' => 'Шлем', 'quantity' => 2, 'unit' => 'шт.',
                                        'price' => 1000, 'discount_percent' => 5, 'site_url' => 'https://shop.test/h',
                                        'wait_on' => 1, 'wait_months' => 2, 'wait_discount' => 0, 'wait_prepay' => 50]);
ok('сопроводительное: скидка', str_contains($line, 'скидка 5%'));
ok('сопроводительное: условия ожидания', str_contains($line, 'срок ожидания'));
ok('сопроводительное: см. на сайте', str_contains($line, 'см. на сайте: https://shop.test/h'));

$h = MailText::textToHtml("Шлем\nсм. на сайте: https://a.test/x?y=1&z=2.\n\nЕщё https://b.test/p, всё");
ok('textToHtml: «см. на сайте» ссылкой', str_contains($h, '<a href="https://a.test/x?y=1&amp;z=2">см. на сайте</a>.'));
ok('textToHtml: голый адрес ссылкой', str_contains($h, '<a href="https://b.test/p">https://b.test/p</a>,'));
ok('textToHtml: абзацы', substr_count($h, '<p>') === 2);

// =====================================================================  5
echo "\n== 5. Печатная форма счёта — по имени ==\n";

$tpl = [['name' => 'Счет покупателю', 'meta' => ['href' => 'a']],
        ['name' => 'Счёт покупателю с печатью с QR и с подписью', 'meta' => ['href' => 'b']],
        ['name' => 'Счет покупателю с печатью и подписью', 'meta' => ['href' => 'c']]];
ok('точное имя (ё = е)', MoySklad::pickTemplate($tpl, 'Счет покупателю с печатью с QR и с подписью')['meta']['href'] === 'b');
ok('часть имени', MoySklad::pickTemplate($tpl, 'с печатью и подписью')['meta']['href'] === 'c');
ok('не нашлось — первый', MoySklad::pickTemplate($tpl, 'нет такого')['meta']['href'] === 'a');
ok('шаблонов нет — null', MoySklad::pickTemplate([], 'x') === null);

// =====================================================================  6
echo "\n== 6. Страница A4: правка сохраняется и собирает документ ==\n";

$png = base64_encode((string)base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$ext = KpEditor::externalize('<p><img class="x" src="data:image/png;base64,' . $png . '"></p>');
ok('фото уходит в редактор короткой ссылкой', (bool)preg_match('#action=kp_img&h=[0-9a-f]{40}#', $ext));
$back = KpEditor::internalize(str_replace('&h=', '&amp;h=', $ext));
ok('и возвращается data:-картинкой', str_contains($back, 'src="data:image/png;base64,' . $png . '"'));
ok('неизвестная картинка убирается', KpEditor::internalize('<img src="/api/proposals.php?action=kp_img&h=' . str_repeat('a', 40) . '">') === '');

$dirty = '<html><head><style data-kp-editor>x{}</style></head><body onload="evil()">'
       . '<p contenteditable="true" onclick="x()">Цена onclick=1</p><script>alert(1)</script>'
       . '<a href="javascript:alert(1)">a</a><iframe src="x"></iframe></body></html>';
$clean = KpEditor::sanitize($dirty);
ok('скрипты и рамки вырезаны', !str_contains($clean, '<script') && !str_contains($clean, '<iframe'));
ok('обработчики событий сняты', !str_contains($clean, 'onload=') && !str_contains($clean, 'onclick="x()"'));
ok('текст с похожим словом не тронут', str_contains($clean, 'Цена onclick=1'));
ok('javascript: ссылки обезврежены', !str_contains($clean, 'javascript:'));
ok('стиль редактора убран', !str_contains($clean, 'data-kp-editor'));

$page = KpEditor::page($proposalId);
$edited = str_replace('Товар А', 'Товар А (поправлено руками)', $page);
try {
    KpEditor::save($proposalId, $edited);
    $saved = true;
} catch (Throwable $e) {
    $saved = false;
    echo '      ' . $e->getMessage() . "\n";
}
ok('правка сохранена и PDF собран', $saved);
ok('документ собирается из правки', str_contains(PdfGenerator::html($proposalId), 'Товар А (поправлено руками)'));
ok('отправленное КП не правится', !KpEditor::editable(['status' => 'sent']));
KpEditor::reset($proposalId);
ok('сброс возвращает сборку из базы', !str_contains(PdfGenerator::html($proposalId), 'поправлено руками'));
$threw = false;
try { KpEditor::save($proposalId, '<p>без тела</p>'); } catch (InvalidArgumentException $e) { $threw = true; }
ok('страница без <body> не принимается', $threw);

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
