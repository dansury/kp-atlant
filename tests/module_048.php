<?php
/**
 * Модуль 048 — на выбрасываемой базе и без сети:
 *
 *   — старые условия получают подстановки доставки, отправленное КП не трогается;
 *   — конец КП: условия, ссылка на приложение, дата и подпись — в этом порядке;
 *   — «не наша номенклатура» без курсива в PDF и Word, каждая карточка с новой страницы;
 *   — подпись: своя → организации (настройка) → МойСклад → по умолчанию;
 *   — одно КП на запрос: «Пересобрать» из подбора, отправленное — новым КП;
 *   — интерфейс (по исходнику): вкладка «Подпись», кнопки под подбором, доски КП нет.
 *
 * Запуск:  php tests/module_048.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-048-' . getmypid() . '.db';
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
require_once ROOT . '/lib/kp_terms.php';
require_once ROOT . '/lib/kp_set.php';
require_once ROOT . '/lib/kp_editor.php';
require_once ROOT . '/lib/signatures.php';
require_once ROOT . '/lib/docx.php';


$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js   = file_get_contents(ROOT . '/public/assets/js/app.js');
$api  = file_get_contents(ROOT . '/public/api/proposals.php');
$tpl  = file_get_contents(ROOT . '/templates/kp.html');

Settings::set('KP_QR_CODE', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');
$mgr = Db::insert('managers', ['login' => 'yana', 'password_hash' => 'x', 'name' => 'Яна', 'is_admin' => 0]);

// ===================================================================== 1
echo "\n1. Старые условия получают подстановки доставки\n";

$split = "Стоимость включает расходы на упаковку, маркировку, хранение, погрузку, подготовку и передачу документов.\n"
       . "Доставка в стоимость не включена и считается отдельно.\n"
       . "Сроки выполнения условий договора {execution_term} с момента получения предоплаты.";
$up = KpTerms::upgradeLegacy($split);
ok('«погрузку» → {delivery_in_price}', str_contains($up, 'хранение, {delivery_in_price}подготовку'), $up);
ok('фраза о доставке → {delivery_separate_clause}', str_contains($up, "документов.\n{delivery_separate_clause}Сроки"), $up);
$oneLine = "…хранение, погрузку, подготовку и передачу документов. Доставка в стоимость не включена и считается отдельной строкой.\nСроки";
ok('и в одну строку', str_contains(KpTerms::upgradeLegacy($oneLine), "документов.\n{delivery_separate_clause}Сроки"));
ok('заводской текст не меняется', KpTerms::upgradeLegacy(KpTerms::FACTORY_TEXT) === KpTerms::FACTORY_TEXT);

Settings::set('KP_DELIVERY_MODE', 'included');
$in = KpTerms::fill($up, ['execution_days' => 30]);
ok('включена: «доставку, » и без фразы', str_contains($in, 'хранение, доставку, подготовку') && !str_contains($in, 'Доставка в стоимость'));
Settings::set('KP_DELIVERY_MODE', 'line');
$line = KpTerms::fill($up, ['execution_days' => 30]);
ok('не включена: фраза СДЭК своей строкой',
   str_contains($line, "хранение, подготовку") && str_contains($line, "документов.\nДоставка в стоимость не включена и оплачивается при получении по тарифам СДЭК.\nСроки"), $line);
Settings::set('KP_DELIVERY_MODE', 'included');

// Миграция v44: черновик переписан, отправленное — нет
$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 't', 'status' => 'processing']);
$draft = Db::insert('proposals', ['request_id' => $reqId, 'terms_text' => $split, 'status' => 'draft']);
$sent  = Db::insert('proposals', ['request_id' => $reqId, 'terms_text' => $split, 'status' => 'sent']);
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('default_terms_text', ?)", [$split]);
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '43')");
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('cfg.KP_PAGE_BREAK', '0')");
runMigrations();
ok('миграция: черновик с подстановками',
   str_contains((string)Db::val("SELECT terms_text FROM proposals WHERE id=?", [$draft]), '{delivery_separate_clause}'));
ok('миграция: отправленное КП не тронуто', Db::val("SELECT terms_text FROM proposals WHERE id=?", [$sent]) === $split);
ok('миграция: заготовка для новых КП', str_contains(KpTerms::defaultText(), '{delivery_in_price}'));
ok('настройки разрыва страниц больше нет', (int)Db::val("SELECT COUNT(*) FROM settings WHERE key='cfg.KP_PAGE_BREAK'") === 0);
Db::q("DELETE FROM proposals");

// ===================================================================== 2
echo "\n2. Конец КП: условия → приложение → дата и подпись\n";

$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 't', 'status' => 'processing']);
Db::insert('request_items', ['request_id' => $reqId, 'position' => 1, 'raw_name' => 'шлем', 'quantity' => 1,
                             'unit' => 'шт.', 'product_name' => 'Шлем', 'price' => 1000]);
Db::insert('request_items', ['request_id' => $reqId, 'position' => 2, 'raw_name' => 'Топор пожарный', 'quantity' => 1,
                             'unit' => 'шт.', 'is_out_of_scope' => 1]);
Db::insert('request_items', ['request_id' => $reqId, 'position' => 3, 'raw_name' => 'жилет', 'quantity' => 2,
                             'unit' => 'шт.', 'product_name' => 'Жилет', 'price' => 500]);
$pid = KpSet::create($reqId, $mgr);
foreach (RequestItems::toProposalItems(RequestItems::all($reqId)) as $i => $m) {
    Db::insert('proposal_items', ['proposal_id' => $pid, 'comment_text' => 'Описание ' . $i] + KpSet::itemRow($m, $i + 1));
}
Db::update('proposals', ['show_out_of_scope' => 1], 'id=?', [$pid]);
$html = PdfGenerator::html($pid);
$pTerms = strpos($html, 'class="conditions"');
$pRef   = strpos($html, 'приведено в приложении №1');
$pSign  = strpos($html, '<div class="signature">');
$pApp   = strpos($html, '<div class="appendix">');
ok('условия раньше ссылки на приложение', $pTerms !== false && $pRef !== false && $pTerms < $pRef);
ok('ссылка раньше подписи', $pSign !== false && $pRef < $pSign);
ok('подпись — последняя перед приложением', $pApp !== false && $pSign < $pApp);
ok('в условиях «доставку, »', str_contains($html, 'хранение, доставку, подготовку'));

// ===================================================================== 3
echo "\n3. Word и PDF одного вида\n";

ok('«не наша» — без курсива', str_contains($html, '<span class="out-of-scope__name">Топор пожарный</span>')
   && !str_contains($tpl, '<em class="out-of-scope__name">') && !preg_match('/out-of-scope__name[^}]*italic/', $tpl));
ok('Word: цвет без курсива',
   str_contains(file_get_contents(ROOT . '/lib/docx.php'), "'out-of-scope__name' => ['color' => '8A8A8A'] + \$style"));
ok('вторая карточка — с новой страницы, первая — нет',
   substr_count($html, 'class="card card--break"') === 1 && str_contains($html, '<div class="card">'));
ok('настройки KP_PAGE_BREAK нет', !array_key_exists('KP_PAGE_BREAK', Settings::SPEC));
$docx = DocxGenerator::generate($pid);
$zip = new ZipArchive(); $zip->open($docx);
$xml = (string)$zip->getFromName('word/document.xml'); $zip->close();
ok('Word: разрывы — приложение и вторая карточка', substr_count($xml, 'w:type="page"') === 2,
   (string)substr_count($xml, 'w:type="page"'));

// ===================================================================== 4
echo "\n4. Подпись: своя → организации → МойСклад → по умолчанию\n";

Db::q("UPDATE legal_entities SET signatory_name='Иванов Иван Иванович' WHERE is_active=1");
ok('без своей — подписант МойСклад', Signatures::forManager($mgr, Db::one("SELECT * FROM legal_entities WHERE is_active=1"))['name'] === 'Иванов Иван Иванович');
Signatures::saveCompanyName('Сурков Кирилл Александрович');
$legal = Db::one("SELECT * FROM legal_entities WHERE is_active=1");
ok('подпись организации из настроек важнее МойСклад', Signatures::forManager($mgr, $legal)['name'] === 'Сурков Кирилл Александрович');
Db::update('managers', ['signatory_name' => 'Яна Петрова'], 'id=?', [$mgr]);
ok('своя расшифровка важнее всех', Signatures::forManager($mgr, $legal)['name'] === 'Яна Петрова');
ok('в документе — она же', str_contains(PdfGenerator::html($pid), 'Яна Петрова'));

$png = sys_get_temp_dir() . '/kp048-sig-' . getmypid() . '.png';
$im = imagecreatetruecolor(20, 10); imagepng($im, $png);
Signatures::storeCompany(['name' => 'sig.png', 'tmp_name' => $png], ['move' => false]);
$d = Signatures::describeCompany();
ok('картинка организации сохранена', $d['has_image'] === true && $d['signatory_name'] === 'Сурков Кирилл Александрович');
ok('и печатается у менеджера без своей', Signatures::forManager($mgr, Db::one("SELECT * FROM legal_entities WHERE is_active=1"))['image'] !== '');
Signatures::forgetCompany();
ok('убрана', Signatures::describeCompany()['has_image'] === false);
@unlink($png);

// ===================================================================== 5
echo "\n5. Одно КП на запрос: «Пересобрать»\n";

Db::q("UPDATE request_items SET quantity=5 WHERE request_id=? AND product_name='Шлем'", [$reqId]);
Db::update('proposals', ['html_override' => '<html><body>правка</body></html>'], 'id=?', [$pid]);
ok('сводка знает о правке листа', KpSet::summary($pid)['edited'] === true);
KpSet::rebuildItems($pid);
$qty = (float)Db::val("SELECT quantity FROM proposal_items WHERE proposal_id=? AND product_name='Шлем'", [$pid]);
ok('позиции — из подбора', $qty === 5.0, (string)$qty);
ok('«не наша» в КП не попала', !Db::val("SELECT COUNT(*) FROM proposal_items WHERE proposal_id=? AND product_name LIKE 'Топор%'", [$pid]));
ok('правка листа сброшена', KpSet::summary($pid)['edited'] === false);
ok('КП можно убрать', KpSet::summary($pid)['can_delete'] === true);

Db::update('proposals', ['status' => 'sent'], 'id=?', [$pid]);
$err = '';
try { KpSet::rebuildItems($pid); } catch (Throwable $e) { $err = $e->getMessage(); }
ok('отправленное КП не переписывается', $err !== '');
ok('и его не убрать', KpSet::summary($pid)['can_delete'] === false);
ok('API: по отправленному собирается новое КП',
   (bool)preg_match("/case 'rebuild':.*?KpEditor::editable\\(\\\$proposal\\).*?rebuildItems.*?buildProposal\\(/s", $api));

Settings::set('KP_SHOW_OUT_OF_SCOPE', '1');
$second = KpSet::create($reqId, $mgr);
ok('«не наша номенклатура» — и в новом КП запроса',
   count(KpContent::outOfScopeRows(['id' => $second, 'request_id' => $reqId, 'show_out_of_scope' => null])) === 1);

// ===================================================================== 6
echo "\n6. Интерфейс\n";

ok('доски КП больше нет', !str_contains($js, 'drawKpBoard') && !str_contains($js, 'Ещё одно КП')
   && !str_contains($api, "case 'move_item'") && !str_contains($api, "case 'board'"));
ok('кнопки под подбором', (bool)preg_match('/🔄 Пересобрать.*?>Открыть<.*?⬇ Word.*?⬇ PDF.*?🧾 Счёт.*?data-kp-delete/s', $js));
ok('«Убрать» — только когда можно', str_contains($js, 'del.innerHTML = s.can_delete'));
ok('вкладка «Подпись» открыта всем', str_contains($js, "['signature',  'Подпись',         false]"));
ok('подпись организации — админу', str_contains($js, "if (admin) this.loadCompanySignature();"));
ok('звук — в «Это устройство»', (bool)preg_match('/settingsDevice\(\) \{.*?loadMySound\(\)/s', $js));
ok('в условиях подсказаны подстановки доставки', str_contains($js, '<code>{delivery_separate_clause}</code>'));

echo "\n" . ($fail ? "ПРОВАЛОВ: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
