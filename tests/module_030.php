<?php
/**
 * Module 030: НДС в КП, на своей отдельной базе и без сети.
 *
 * Проверяется ровно то, ради чего модуль появился:
 *   — налог печатается в КП ВСЕГДА, без всяких выключателей;
 *   — печатается он в одном из двух видов, и вид выбирается в настройках:
 *     цена уже с НДС либо цена + НДС сверху;
 *   — документ, письмо и Word говорят одни и те же цифры;
 *   — счёт и заказ в МойСклад выставляются тем же способом, что и КП.
 *
 * Run:  php tests/module_030.php
 *
 * База создаётся в системном временном каталоге — `data/kp.db` не открывается,
 * так что запуск на сервере не может задеть живые данные.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-' . getmypid() . '.db';
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
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/kp_text.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/docx.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

// Ни модели, ни сети
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('KNOWLEDGE_ENABLED', '0');
Settings::set('KP_QR_CODE', '0');

echo "\n1. КП из двух позиций на 2 500 руб.\n";
foreach ([['ms-1', 'Бронежилет Страж-5', 'STR5', 1000.0], ['ms-2', 'Шлем Атлант АШ-1', 'ASH1', 500.0]] as $p) {
    Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved,
                                       unit, vat, product_type, source, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime('now'))",
        [$p[0], $p[1], mb_strtolower($p[1]), $p[2], $p[3], 10, 0, 'шт', 5, 'product', 'api']);
}
$cpId = Db::insert('counterparties', ['name' => 'ООО «Рубеж»', 'inn' => '7701234567', 'contact_email' => 'z@rubezh.ru']);
$requestId = Db::insert('requests', ['source' => 'email', 'raw_text' => 'бронежилет 2, шлем 1',
                                     'counterparty_id' => $cpId, 'status' => 'new']);
$proposalId = Db::insert('proposals', ['request_id' => $requestId, 'counterparty_id' => $cpId, 'vat_rate' => 5]);
Db::insert('proposal_items', ['proposal_id' => $proposalId, 'position' => 1, 'product_name' => 'Бронежилет Страж-5',
    'moysklad_product_id' => 'ms-1', 'unit' => 'шт', 'quantity' => 2, 'price' => 1000.0, 'stock_available' => 10]);
Db::insert('proposal_items', ['proposal_id' => $proposalId, 'position' => 2, 'product_name' => 'Шлем Атлант АШ-1',
    'moysklad_product_id' => 'ms-2', 'unit' => 'шт', 'quantity' => 1, 'price' => 500.0, 'stock_available' => 10]);

$snap = Requisites::freeze($proposalId);
ok('ставка из каталога', (int)$snap['vat']['rate'] === 5, json_encode($snap['vat'], JSON_UNESCAPED_UNICODE));
ok('организация — плательщик НДС', (bool)$snap['vat']['pays_vat']);

echo "\n2. Цены с НДС внутри: налог выделяется из итога\n";
ok('по умолчанию печатаем цену с НДС', Requisites::vatMode() === 'included', Requisites::vatMode());
$t = Requisites::vatTotals(2500.0, $snap['vat'], 'included');
ok('итог не изменился', abs($t['total'] - 2500.0) < 0.005, (string)$t['total']);
ok('налог выделен из суммы', abs($t['amount'] - 119.05) < 0.005, (string)$t['amount']);
ok('и цена без него названа', abs($t['net'] - 2380.95) < 0.005, (string)$t['net']);
ok('колонка говорит «в т.ч. НДС 5%»', $t['column'] === 'в т.ч. НДС 5%', $t['column']);

$html = PdfGenerator::html($proposalId);
ok('в шапке колонки — «в т.ч. НДС 5%»', str_contains($html, 'Цена за ед., в т.ч. НДС 5%'));
ok('итог в документе', str_contains($html, 'Итого: 2 500 руб.'));
// Раньше эта строка печаталась только по галочке `show_vat_total`, и КП
// уходило клиенту вообще без суммы налога — ради этого модуль и заведён
ok('НДС под итогом напечатан без всяких галочек',
   str_contains($html, 'в т.ч. НДС 5%: 119,05 руб.'));

$text = KpText::render($proposalId)['text'];
ok('в письме тот же итог', str_contains($text, 'Итого: 2 500 руб.'));
ok('и тот же налог', str_contains($text, 'в т.ч. НДС 5%: 119,05 руб.'));

echo "\n3. Цена + НДС: налог прибавляется к итогу\n";
Settings::set('KP_VAT_MODE', 'added');
ok('настройка прочиталась', Requisites::vatMode() === 'added', Requisites::vatMode());
$t = Requisites::vatTotals(2500.0, $snap['vat'], 'added');
ok('цены в таблице остались прежними', abs($t['net'] - 2500.0) < 0.005, (string)$t['net']);
ok('налог посчитан сверху', abs($t['amount'] - 125.0) < 0.005, (string)$t['amount']);
ok('и клиент платит больше', abs($t['total'] - 2625.0) < 0.005, (string)$t['total']);

$html = PdfGenerator::html($proposalId);
ok('в шапке колонки — «без НДС»', str_contains($html, 'Цена за ед., без НДС'));
ok('«в т.ч. НДС» из документа ушло', !str_contains($html, 'в т.ч. НДС'));
ok('итог без налога', str_contains($html, 'Итого без НДС: 2 500 руб.'));
ok('сам налог', str_contains($html, 'НДС 5%: 125 руб.'));
ok('и итог с налогом', str_contains($html, 'Итого с НДС: 2 625 руб.'));
ok('цена позиции не переписана', str_contains($html, '1 000 руб.'));

$text = KpText::render($proposalId)['text'];
ok('письмо повторяет документ', str_contains($text, 'Итого без НДС: 2 500 руб.')
   && str_contains($text, 'НДС 5%: 125 руб.') && str_contains($text, 'Итого с НДС: 2 625 руб.'));

echo "\n4. Word говорит то же самое (модуль 016)\n";
$docxPath = DocxGenerator::generate($proposalId);
$zip = new ZipArchive();
ok('пакет открывается', $zip->open($docxPath) === true);
$xml = $zip->getFromName('word/document.xml');
$zip->close();
@unlink($docxPath);
$plain = preg_replace('/<[^>]+>/', '', (string)$xml);
ok('итог с НДС попал в Word', str_contains((string)$plain, 'Итого с НДС'));
ok('и сам налог отдельной строкой', str_contains((string)$plain, 'НДС 5%: 125 руб.'));

echo "\n5. У отдельного КП может быть свой вид цены\n";
Db::update('proposals', ['vat_mode' => 'included'], 'id=?', [$proposalId]);
$proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
ok('своё значение важнее настройки', Requisites::vatMode($proposal) === 'included', Requisites::vatMode($proposal));
$html = PdfGenerator::html($proposalId);
ok('и документ печатается по нему', str_contains($html, 'в т.ч. НДС 5%: 119,05 руб.'));
Db::update('proposals', ['vat_mode' => null], 'id=?', [$proposalId]);
$proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
ok('пусто — снова как в настройках', Requisites::vatMode($proposal) === 'added');

echo "\n6. Не плательщик НДС: налог не прибавляется ни в каком виде\n";
Db::q("UPDATE legal_entities SET pays_vat=0");
$snapExempt = Requisites::freeze($proposalId);
ok('снимок это запомнил', (bool)$snapExempt['vat']['pays_vat'] === false);
$t = Requisites::vatTotals(2500.0, $snapExempt['vat'], 'added');
ok('итог не вырос', abs($t['total'] - 2500.0) < 0.005, (string)$t['total']);
ok('и налога нет', abs($t['amount']) < 0.005, (string)$t['amount']);
$html = PdfGenerator::html($proposalId);
ok('в документе — формулировка организации', str_contains($html, 'НДС не облагается'));
ok('и никакого «Итого с НДС»', !str_contains($html, 'Итого с НДС'));
ok('в письме — она же', str_contains(KpText::render($proposalId)['text'], 'НДС не облагается'));

echo "\n7. Плательщик, но ставка 0: «без НДС», а не выдуманная фраза\n";
Db::q("UPDATE legal_entities SET pays_vat=1");
$t = Requisites::vatTotals(2500.0, ['pays_vat' => true, 'rate' => 0], 'included');
ok('колонка — «без НДС»', $t['column'] === 'без НДС', $t['column']);
ok('«не облагается» за организацию не сказано',
   !str_contains(json_encode($t['lines'], JSON_UNESCAPED_UNICODE), 'не облагается'));

echo "\n8. Счёт и заказ в МойСклад — тем же способом, что и КП\n";
Settings::set('KP_VAT_MODE', 'added');
$proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
Requisites::freeze($proposalId);                      // организация снова плательщик
$flags = Requisites::msVatFlags($proposal);
ok('налог в документе МойСклад включён', $flags['vat_enabled'] === true);
ok('но в цену не входит', $flags['vat_included'] === false, json_encode($flags));

Settings::set('KP_VAT_MODE', 'included');
$flags = Requisites::msVatFlags($proposal);
ok('«цена с НДС» — налог внутри цены', $flags['vat_included'] === true);

Db::q("UPDATE legal_entities SET pays_vat=0");
Requisites::freeze($proposalId);
$flags = Requisites::msVatFlags(Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]));
ok('не плательщик — документ без НДС вовсе', $flags['vat_enabled'] === false && $flags['vat_included'] === false,
   json_encode($flags));

echo "\n" . ($fail ? "ПРОВАЛОВ: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
