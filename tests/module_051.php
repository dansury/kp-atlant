<?php
/**
 * Модуль 051 — на выбрасываемой базе и без сети:
 *
 *   — тексты КП помечены только на странице редактора, PDF печатается как раньше;
 *   — подстановки переживают правку, изменённый текст идёт в КП и в заготовку;
 *   — разделители страниц редактора и пустые поля в PDF и Word не попадают;
 *   — Word: разрыв страницы из редактора, выравнивание по ширине, цвет;
 *   — подпись: по выбору менеджера, по умолчанию без подписи, без прочерка;
 *   — нейросеть: долгий ответ ≠ фильтр, таймаут 90 с, /no_think для Qwen3;
 *   — интерфейс (по исходнику): жирные неотвеченные, «Редактировать вручную», окно КП.
 *
 * Запуск:  php tests/module_051.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-051-' . getmypid() . '.db';
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
require_once ROOT . '/lib/kp_fields.php';
require_once ROOT . '/lib/kp_set.php';
require_once ROOT . '/lib/kp_editor.php';
require_once ROOT . '/lib/signatures.php';
require_once ROOT . '/lib/docx.php';
require_once ROOT . '/lib/llm.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');
$api = file_get_contents(ROOT . '/public/api/proposals.php');
$tpl = file_get_contents(ROOT . '/templates/kp.html');

Settings::set('KP_QR_CODE', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('KP_DELIVERY_MODE', 'line');
$mgr = Db::insert('managers', ['login' => 'yana', 'password_hash' => 'x', 'name' => 'Яна Петрова', 'is_admin' => 0]);

$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 't', 'status' => 'processing']);
Db::insert('request_items', ['request_id' => $reqId, 'position' => 1, 'raw_name' => 'шлем', 'quantity' => 1,
                             'unit' => 'шт.', 'product_name' => 'Шлем', 'price' => 1000]);
$pid = KpSet::create($reqId, $mgr);
foreach (RequestItems::toProposalItems(RequestItems::all($reqId)) as $i => $m) {
    Db::insert('proposal_items', ['proposal_id' => $pid, 'comment_text' => 'Описание ' . $i] + KpSet::itemRow($m, $i + 1));
}

// ===================================================================== 1
echo "\n1. Метки только в редакторе, PDF — как раньше\n";

$plain = PdfGenerator::html($pid);
$page  = PdfGenerator::html($pid, true);
ok('в PDF меток нет', !str_contains($plain, 'data-kp-field') && !str_contains($plain, 'data-kp-var'));
ok('в редакторе помечены вступление и условия',
   str_contains($page, 'data-kp-field="intro_text"') && str_contains($page, 'data-kp-field="terms_text"'));
ok('пустые «перед таблицей» и «после таблицы» — местом для текста',
   str_contains($page, 'data-kp-field="pre_table_text"') && str_contains($page, 'data-kp-field="post_table_text"'));
ok('подстановки — в своих span', str_contains($page, 'data-kp-var="execution_term"') && str_contains($page, 'data-kp-var="seller"'));
ok('пустая подстановка держит место', str_contains($page, 'data-kp-var="delivery_in_price" data-kp-val="">' . KpFields::ZWSP . '</span>'));
ok('вступление по умолчанию — прежним текстом', str_contains($plain, 'по Вашему запросу имеет возможность поставить следующее вещевое имущество:'));

// ===================================================================== 2
echo "\n2. Правка возвращается в текст с подстановками\n";

$raw = "Срок {execution_term}, цена {validity_days} дней.\n{delivery_separate_clause}Конец.";
$vars = ['{execution_term}' => '30 календарных дней', '{validity_days}' => '14',
         '{delivery_separate_clause}' => "Доставка отдельно.\n"];
$html = '<div data-kp-field="terms_text">' . KpFields::html($raw, $vars, true) . '</div>';
ok('нетронутый текст читается обратно тем же', KpFields::extract($html)['terms_text'][0] === KpFields::normalize($raw),
   KpFields::extract($html)['terms_text'][0]);
$edited = str_replace('>14<', '>21<', $html);
ok('правленая подстановка остаётся текстом', str_contains(KpFields::extract($edited)['terms_text'][0], 'цена 21 дней'));
$chrome = str_replace('Конец.', '<div>Новая строка</div>', $html);
ok('новый абзац из редактора — новая строка', str_ends_with(KpFields::extract($chrome)['terms_text'][0], "\nНовая строка"));

// ===================================================================== 3
echo "\n3. Сохранение: КП и заготовка следующих КП\n";

$page = KpEditor::page($pid);
$untouchedLearned = KpEditor::save($pid, $page, $mgr);
ok('без правок ничего не стало заготовкой', $untouchedLearned === [], implode(', ', $untouchedLearned));
$termsBefore = KpTerms::defaultText();

$ed = $page;
$ed = preg_replace('#(<div class="conditions" data-kp-field="terms_text"[^>]*>)#', '$1Гарантия 12 месяцев.<br>', $ed, 1);
$ed = preg_replace('#(<div class="block" data-kp-field="pre_table_text"[^>]*>)(</div>)#', '$1Цены действительны при заказе от 2 шт.$2', $ed, 1);
$ed = preg_replace('#(имеет возможность поставить)#u', 'рада предложить и $1', $ed, 1);
$learned = KpEditor::save($pid, $ed, $mgr);
$p = Db::one("SELECT * FROM proposals WHERE id=?", [$pid]);
ok('названы ставшие заготовкой', in_array('Условия поставки', $learned, true) && in_array('Текст перед таблицей', $learned, true)
   && in_array('Вступление', $learned, true), implode(', ', $learned));
ok('условия КП — с правкой и с подстановками', str_starts_with((string)$p['terms_text'], "Гарантия 12 месяцев.\n")
   && str_contains((string)$p['terms_text'], '{execution_term}') && str_contains((string)$p['terms_text'], '{delivery_separate_clause}'),
   (string)$p['terms_text']);
ok('и заготовка условий — она же', KpTerms::defaultText() === $p['terms_text'] && $termsBefore !== KpTerms::defaultText());
ok('вступление — шаблоном с {seller} и {by_request}',
   KpFields::introTemplate() === '{seller} {by_request} рада предложить и имеет возможность поставить следующее вещевое имущество:',
   KpFields::introTemplate());
ok('текст перед таблицей — в КП', $p['pre_table_text'] === 'Цены действительны при заказе от 2 шт.');
$next = KpSet::create($reqId, $mgr);
ok('следующее КП начинается с него', Db::val("SELECT pre_table_text FROM proposals WHERE id=?", [$next]) === 'Цены действительны при заказе от 2 шт.');
ok('и с новыми условиями', str_starts_with((string)Db::val("SELECT terms_text FROM proposals WHERE id=?", [$next]), 'Гарантия 12 месяцев.'));
Db::q("DELETE FROM proposals WHERE id=?", [$next]);

// ===================================================================== 4
echo "\n4. Служебное редактора в документ не попадает\n";

$withUi = str_replace('<div class="intro"', '<div class="kp-pgap" data-kp-editor-ui="1" contenteditable="false"><span class="kp-pgap__band">стр. 2 из 2</span></div><div class="intro"', $ed);
$withUi = preg_replace('#(<tr)#', '<tr class="kp-pgap-row" data-kp-editor-ui="1"><td colspan="5"><div class="kp-pgap"><span>стр. 3</span></div></td></tr>$1', $withUi, 1);
$clean = KpEditor::sanitize($withUi);
ok('разделители страниц вырезаны', !str_contains($clean, 'data-kp-editor-ui') && !str_contains($clean, 'стр. 2 из 2') && !str_contains($clean, 'стр. 3'));
KpEditor::save($pid, $withUi, $mgr);
$printed = PdfGenerator::html($pid);
ok('в PDF нет ни ZWSP, ни пустых полей', !str_contains($printed, KpFields::ZWSP)
   && !preg_match('#data-kp-field="post_table_text"[^>]*>\s*</div>#', $printed));
ok('правленое напечатано', str_contains($printed, 'Гарантия 12 месяцев.') && str_contains($printed, 'Цены действительны'));
KpEditor::reset($pid);
ok('«Вернуть автоматическую сборку» — с правленными текстами', str_contains(PdfGenerator::html($pid), 'Гарантия 12 месяцев.'));

// ===================================================================== 5
echo "\n5. Word: разрыв страницы, по ширине, цвет\n";

$docx = new Html2Docx();
$tmp = sys_get_temp_dir() . '/kp051-' . getmypid() . '.docx';
$docx->write('<html><body><p>Первая</p><div class="kp-page-break" style="page-break-before:always"></div>'
    . '<p style="text-align: justify;">Широко</p><p><font color="#c00000">Красный</font> <span style="color: rgb(119, 119, 119)">серый</span></p></body></html>', $tmp);
$zip = new ZipArchive(); $zip->open($tmp);
$xml = (string)$zip->getFromName('word/document.xml'); $zip->close(); @unlink($tmp);
ok('разрыв страницы', str_contains($xml, 'w:type="page"'));
ok('по ширине', str_contains($xml, '<w:jc w:val="both"/>'));
ok('цвет из <font> и из style', str_contains($xml, 'w:val="C00000"') && str_contains($xml, 'w:val="777777"'));
ok('mPDF тоже рвёт страницу', str_contains($tpl, '.kp-page-break { page-break-before: always;')
   && str_contains($js, 'class="kp-page-break" style="page-break-before:always"'));

// ===================================================================== 6
echo "\n6. Подпись — по выбору, по умолчанию без неё\n";

$legal = Db::one("SELECT * FROM legal_entities WHERE is_active=1");
ok('по умолчанию — без подписи', Signatures::forManager($mgr, $legal)['name'] === '' && Signatures::forManager($mgr, $legal)['mode'] === 'none');
$sign = substr(PdfGenerator::html($pid), (int)strrpos(PdfGenerator::html($pid), '<div class="signature">'));
ok('строка — одна дата', str_contains($sign, 'signature__date') && !str_contains($sign, 'sign-name') && !str_contains($sign, 'sign-img'));
ok('прочерка нет нигде', !str_contains($tpl, '_____') && !str_contains($js, '_____________'));
Signatures::setMode($mgr, 'own');
ok('«Моя подпись» без расшифровки — имя менеджера', Signatures::forManager($mgr, $legal)['name'] === 'Яна Петрова');
Signatures::setMode($mgr, 'company');
ok('«Подпись организации»', Signatures::forManager($mgr, $legal)['name'] === Signatures::companyName($legal));
$thrown = false;
try { Signatures::setMode($mgr, 'всё'); } catch (InvalidArgumentException) { $thrown = true; }
ok('чужой вид не принимается', $thrown);
ok('КП без менеджера не подписано', Signatures::forManager(0, $legal)['mode'] === 'none');

$withOwn = Db::insert('managers', ['login' => 'old', 'password_hash' => 'x', 'name' => 'Старый', 'signatory_name' => 'Старый С.С.']);
$without = Db::insert('managers', ['login' => 'new', 'password_hash' => 'x', 'name' => 'Новый']);
Db::q("UPDATE managers SET kp_signature_mode=NULL WHERE id IN (?, ?)", [$withOwn, $without]);
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('cfg.LLM_TIMEOUT_SEC', '30')");
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '45')");
runMigrations();
ok('миграция: у кого была своя — «Моя подпись»', Db::val("SELECT kp_signature_mode FROM managers WHERE id=?", [$withOwn]) === 'own');
ok('миграция: остальные — без подписи', Db::val("SELECT kp_signature_mode FROM managers WHERE id=?", [$without]) === 'none');

// ===================================================================== 7
echo "\n7. Нейросеть: долгий ответ — не фильтр\n";

ok('миграция: сохранённые 30 с → 90 с', Db::val("SELECT value FROM settings WHERE key='cfg.LLM_TIMEOUT_SEC'") === '90');
ok('по умолчанию 90 с', (int)Settings::SPEC['LLM_TIMEOUT_SEC'][4] === 90);
$slow = LLM::slowAnswer('yandex', 'Operation timed out after 30002 milliseconds with 0 bytes received');
ok('объяснение — про модель и таймаут, не про фильтр', str_contains($slow, 'модель не успела ответить')
   && !str_contains($slow, 'фильтр на пути') && str_contains($slow, 'Таймаут запроса'));
ok('Qwen3 — без рассуждений', str_ends_with(LLM::noThink('qwen3-235b-a22b-fp8/latest', 'Система.'), '/no_think'));
ok('другим моделям не дописывается', LLM::noThink('yandexgpt/latest', 'Система.') === 'Система.');
$llmSrc = file_get_contents(ROOT . '/lib/llm.php');
ok('соединение — со своим коротким таймаутом', substr_count($llmSrc, 'CURLOPT_CONNECTTIMEOUT => self::connectTimeout()') === 2);
ok('таймаут после отправки узнаётся', str_contains($llmSrc, 'CURLINFO_PRETRANSFER_TIME'));

// ===================================================================== 8
echo "\n8. Интерфейс (по исходнику)\n";

ok('список: жирным и новое, и неотвеченное', str_contains($js, "if (card.unread || card.unanswered) cls.push('grow--unread');"));
ok('«Редактировать вручную» вместо «Страница A4»', str_contains($js, '✎ Редактировать вручную') && !str_contains($js, '📝 Страница A4'));
ok('«Текст по полям» убран вместе с API', !str_contains($js, 'Текст по полям') && !str_contains($api, "case 'doc_text'"));
ok('окно КП — не flex-строка', str_contains($js, '<div class="card kp-open" data-block="kp">') && !str_contains($js, 'card--inline kp-open'));
ok('высота — за полосу под листом', str_contains($js, 'kpGripStart') && str_contains($css, '.kp-open--drag iframe { pointer-events: none; }'));
ok('PDF в масштабе листа', str_contains($js, '#zoom=${z}'));
ok('панель редактора', str_contains($js, 'role="toolbar" aria-label="Оформление текста КП"') && str_contains($js, "doc.execCommand('styleWithCSS', false, false)"));
ok('вставка чистится', str_contains($js, 'kpCleanPaste(html)'));
ok('страницы на листе', str_contains($js, 'kpRepaginate(card)') && str_contains($js, "setAttribute('data-kp-editor-ui', '1')"));
ok('сохранение без служебного', str_contains($js, "root.querySelectorAll('style[data-kp-editor], [data-kp-editor-ui]')"));
ok('шрифт редактора — только из списка', str_contains($api, "case 'font':") && str_contains($api, "'r' => 'DejaVuSans.ttf'"));
ok('выбор подписи в настройках', str_contains($js, "onchange=\"App.saveSignatureMode(this.value)\"") && str_contains($js, 'Без подписи'));

echo $fail ? "\n$fail FAILED\n" : "\nВСЁ ЗЕЛЁНОЕ\n";
exit($fail ? 1 : 0);
