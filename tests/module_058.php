<?php
/**
 * Модуль 058 — на выбрасываемой базе и без сети:
 *
 *   — расшифровка вилки: только характеристики, от которых зависит цена;
 *   — в документе — под ценой, со скидкой строки; ручная цена — без неё;
 *   — модули только к товару из «Модули подходят к товарам»;
 *   — остаток модуля — по модификациям и на день печати;
 *   — таблица модулей без «Ед. изм.».
 *
 * Запуск:  php tests/module_058.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-058-' . getmypid() . '.db';
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
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('KP_QR_CODE', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');
$tpl = file_get_contents(ROOT . '/templates/kp.html');
$js = file_get_contents(ROOT . '/public/assets/js/app.js');

// ====================================================================== 1
echo "\n== 1. Характеристики парами ==\n";
ok('API через «;»', Variants::characteristicPairs(['characteristics' => 'Цвет: Multicam; Размер: L']) === ['Цвет' => 'Multicam', 'Размер' => 'L']);
ok('Excel через «,»', Variants::characteristicPairs(['characteristics' => 'Цвет: Олива, Размер: M(56-59)']) === ['Цвет' => 'Олива', 'Размер' => 'M(56-59)']);
ok('без колонки — из скобок имени', Variants::characteristicPairs(['name' => 'Плита (Размер: S)']) === ['Размер' => 'S']);

// ====================================================================== 2
echo "\n== 2. Вилка расшифровывается по тому, от чего зависит цена ==\n";
Db::insert('products_cache', ['moysklad_id' => 'vest', 'name' => 'Жилет', 'price' => 0, 'stock' => 0, 'reserved' => 0]);
$n = 0;
foreach (['Черный', 'Multicam'] as $color) {
    foreach (['S' => 25000, 'M' => 25000, 'L' => 39000, 'XL' => 39000] as $size => $price) {
        Db::insert('products_cache', ['moysklad_id' => 'vest-' . (++$n), 'name' => "Жилет (Цвет: $color; Размер: $size)",
            'price' => $price, 'stock' => 1, 'reserved' => 0, 'product_type' => 'variant', 'parent_id' => 'vest',
            'characteristics' => "Цвет: $color; Размер: $size"]);
    }
}
$rows = Catalog::rangeBreakdown(['moysklad_id' => 'vest', 'product_type' => 'product']);
ok('цвет цену не меняет — одни размеры', $rows === [['label' => 'Размер: S, M', 'price' => 25000.0],
                                                  ['label' => 'Размер: L, XL', 'price' => 39000.0]], json_encode($rows, JSON_UNESCAPED_UNICODE));
ok('модификация вилки не имеет', Catalog::rangeBreakdown(['moysklad_id' => 'vest-1', 'product_type' => 'variant']) === []);

Db::insert('products_cache', ['moysklad_id' => 'helm', 'name' => 'Шлем', 'price' => 0]);
foreach ([['Черный', 'M', 1000], ['Черный', 'L', 1000], ['Олива', 'M', 1200], ['Олива', 'L', 1200]] as $i => [$c, $s, $p]) {
    Db::insert('products_cache', ['moysklad_id' => "helm-$i", 'name' => "Шлем ($c, $s)", 'price' => $p,
        'product_type' => 'variant', 'parent_id' => 'helm', 'characteristics' => "Цвет: $c, Размер: $s"]);
}
$rows = Catalog::rangeBreakdown(['moysklad_id' => 'helm', 'product_type' => 'product']);
ok('размер цену не меняет — одни цвета', array_column($rows, 'label') === ['Цвет: Черный', 'Цвет: Олива'], json_encode($rows, JSON_UNESCAPED_UNICODE));

Db::insert('products_cache', ['moysklad_id' => 'same', 'name' => 'Плита', 'price' => 0]);
Db::insert('products_cache', ['moysklad_id' => 'same-1', 'name' => 'Плита (Размер: S)', 'price' => 500, 'product_type' => 'variant', 'parent_id' => 'same', 'characteristics' => 'Размер: S']);
Db::insert('products_cache', ['moysklad_id' => 'same-2', 'name' => 'Плита (Размер: L)', 'price' => 500, 'product_type' => 'variant', 'parent_id' => 'same', 'characteristics' => 'Размер: L']);
ok('одна цена — расшифровки нет', Catalog::rangeBreakdown(['moysklad_id' => 'same', 'product_type' => 'product']) === []);

// ====================================================================== 3
echo "\n== 3. Расшифровка в документе ==\n";
$cpId = Db::insert('counterparties', ['name' => 'ООО «Покупатель»']);
$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 't', 'status' => 'processing', 'counterparty_id' => $cpId]);
$pid = Db::insert('proposals', ['request_id' => $reqId, 'counterparty_id' => $cpId, 'terms_text' => KpTerms::defaultText()]);
Db::insert('proposal_items', ['proposal_id' => $pid, 'position' => 1, 'product_name' => 'Жилет', 'unit' => 'шт.',
    'quantity' => 1, 'price' => 25000, 'price_max' => 39000, 'is_confirmed' => 1, 'moysklad_product_id' => 'vest']);
Requisites::freeze($pid);
$html = PdfGenerator::html($pid);
ok('вилка печатается', str_contains($html, 'от 25 000 до 39 000 руб.'));
ok('под ней — размеры с ценами', str_contains($html, 'Размер: S, M — 25 000 руб.') && str_contains($html, 'Размер: L, XL — 39 000 руб.'));
ok('цвет в расшифровке не назван', !str_contains($html, 'Цвет: Черный'));

$text = KpText::render($pid)["text"];
ok('в тексте письма — та же расшифровка', str_contains($text, 'Размер: S, M — 25 000 руб.') && str_contains($text, 'Размер: L, XL — 39 000 руб.'));

Db::q("UPDATE proposal_items SET discount_percent=10 WHERE proposal_id=?", [$pid]);
$html = PdfGenerator::html($pid);
ok('со скидкой — цены расшифровки со скидкой', str_contains($html, 'Размер: S, M — 22 500 руб.') && str_contains($html, 'Размер: L, XL — 35 100 руб.'));

Db::q("UPDATE proposal_items SET discount_percent=0, price=27000, price_max=0 WHERE proposal_id=?", [$pid]);
ok('ручная цена — расшифровки нет', !str_contains(PdfGenerator::html($pid), 'Размер: S, M'));

// ====================================================================== 4
echo "\n== 4. Модули — только к своему товару, остаток — по модификациям ==\n";
Db::q("UPDATE settings SET value='Модули' WHERE key='addon_category'");
ok('настройка засеяна базовым Бр2', str_contains((string)Db::val("SELECT value FROM settings WHERE key='addon_hosts'"), 'Бронежилет Атлант базовый Бр2'));
Db::insert('products_cache', ['moysklad_id' => 'base', 'name' => 'Бронежилет Атлант базовый Бр2 (без доп.модулей, без бронеплит)', 'price' => 50000]);
Db::insert('products_cache', ['moysklad_id' => 'base-L', 'name' => 'Бронежилет Атлант базовый Бр2 (без доп.модулей, без бронеплит) (Размер: L)',
    'price' => 50000, 'product_type' => 'variant', 'parent_id' => 'base', 'characteristics' => 'Размер: L']);
Db::insert('products_cache', ['moysklad_id' => 'mod', 'name' => 'Модуль защиты шеи', 'price' => 3000, 'stock' => 0, 'reserved' => 0,
    'is_addon' => 1, 'category' => 'Модули']);
Db::insert('products_cache', ['moysklad_id' => 'mod-1', 'name' => 'Модуль защиты шеи (Цвет: Multicam)', 'price' => 3000,
    'stock' => 7, 'reserved' => 0, 'product_type' => 'variant', 'parent_id' => 'mod', 'characteristics' => 'Цвет: Multicam']);
Db::insert('products_cache', ['moysklad_id' => 'mod2', 'name' => 'Модуль паха', 'price' => 2000, 'stock' => 0, 'reserved' => 0,
    'is_addon' => 1, 'category' => 'Модули']);

$other = Db::insert('proposals', ['request_id' => $reqId, 'counterparty_id' => $cpId, 'terms_text' => KpTerms::defaultText(), 'show_upsell' => 1]);
Db::insert('proposal_items', ['proposal_id' => $other, 'position' => 1, 'product_name' => 'Бронежилет штурмовой Атлант Бр2 (без бронеплит)',
    'unit' => 'шт.', 'quantity' => 1, 'price' => 100000, 'is_confirmed' => 1]);
Requisites::freeze($other);
ok('другой бронежилет — не хозяин модулей', !KpContent::addonHostIn($other));
KpContent::seedAddons($other);
ok('модули в такое КП не засеваются', (int)Db::val("SELECT COUNT(*) FROM proposal_addons WHERE proposal_id=?", [$other]) === 0);
KpContent::setAddons($other, [['product_name' => 'Модуль паха', 'moysklad_product_id' => 'mod2', 'price' => 2000]]);
ok('засеянное раньше не печатается', !str_contains(PdfGenerator::html($other), 'Дополнительные модули'));

$host = Db::insert('proposals', ['request_id' => $reqId, 'counterparty_id' => $cpId, 'terms_text' => KpTerms::defaultText(), 'show_upsell' => 1]);
Db::insert('proposal_items', ['proposal_id' => $host, 'position' => 1, 'product_name' => 'Бронежилет Атлант базовый Бр2 (без доп.модулей, без бронеплит) (Размер: L)',
    'unit' => 'шт.', 'quantity' => 1, 'price' => 50000, 'is_confirmed' => 1, 'moysklad_product_id' => 'base-L']);
Requisites::freeze($host);
ok('модификация базового Бр2 — хозяин модулей', KpContent::addonHostIn($host));
KpContent::seedAddons($host);
$notes = Db::all("SELECT moysklad_product_id, notes FROM proposal_addons WHERE proposal_id=? ORDER BY position", [$host]);
$byId = array_column($notes, 'notes', 'moysklad_product_id');
ok('модуль с остатком на модификации — не «под заказ»', array_key_exists('mod', $byId) && $byId['mod'] === null, json_encode($byId, JSON_UNESCAPED_UNICODE));
ok('модуль без остатка — «под заказ»', ($byId['mod2'] ?? '') === Terms::AUTO_NOTE);

// Замороженное «под заказ» пересчитывается при печати, своё менеджера остаётся
Db::q("UPDATE proposal_addons SET notes=? WHERE proposal_id=? AND moysklad_product_id='mod'", [Terms::AUTO_NOTE, $host]);
ok('старое авто-«под заказ» пересчитано', KpContent::addonNote(['moysklad_product_id' => 'mod', 'notes' => Terms::AUTO_NOTE]) === null);
ok('своё примечание осталось', KpContent::addonNote(['moysklad_product_id' => 'mod2', 'notes' => 'через неделю']) === 'через неделю');
$html = PdfGenerator::html($host);
ok('блок модулей печатается', str_contains($html, 'Дополнительные модули и доукомплектование'));
preg_match('~<table class="addons">.*?</table>~s', $html, $m);
$table = $m[0] ?? '';
ok('в таблице модулей нет «Ед. изм.»', $table !== '' && !str_contains($table, 'Ед.изм') && !str_contains($table, 'Ед. изм'));
ok('«под заказ» только у модуля без остатка', substr_count($table, 'под заказ') === 1, (string)substr_count($table, 'под заказ'));

Db::q("UPDATE settings SET value='' WHERE key='addon_hosts'");
ok('пустая настройка — модули к любому КП', KpContent::addonHostIn($other));

// ====================================================================== 5
echo "\n== 5. Интерфейс ==\n";
ok('поле «Модули подходят к товарам» в настройках', str_contains($js, 'id="kpAddonHosts"') && str_contains($js, "addon_hosts: document.getElementById('kpAddonHosts').value"));
ok('в редакторе модуля нет поля единицы', !str_contains($js, 'data-field="unit" value="${this.esc(a.unit'));
ok('редактор говорит, что блок не печатается', str_contains($js, 'proposal.addon_host === false'));

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
