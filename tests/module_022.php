<?php
/**
 * Модуль 022 целиком, на выбрасываемой базе и без сети:
 *
 *   — один товар в трёх размерах становится тремя строками со своими
 *     количествами и своими карточками каталога;
 *   — «не наша номенклатура» не становится позицией КП (печатается строкой
 *     с прочерками — модуль 045) и не уходит в ответ клиенту;
 *   — КП печатается с логотипом, со строкой подписи под картинкой подписи
 *     (модуль 035 — по образцу заказчика) и без второго
 *     блока реквизитов поставщика;
 *   — имя файла КП называет адресата и дату;
 *   — разметка из МойСклад печатается разметкой, даже если в поле лёг HTML;
 *   — правка классификатора запоминается и возвращается в промпт;
 *   — письмо выключенного ящика уходит с экрана вместе с ящиком.
 *
 * Запуск:  php tests/module_022.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе,
 * так что прогон на сервере не может задеть живые данные.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-022-' . getmypid() . '.db';
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
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/mail_threads.php';
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/markup.php';
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/variants.php';
require_once ROOT . '/lib/scope.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/docx.php';
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/signatures.php';
require_once ROOT . '/lib/png.php';
require_once ROOT . '/lib/branding.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('KP_QR_CODE', '0');
Settings::set('MATCH_VECTOR_WEIGHT', '0');
Settings::set('ALTERNATIVES_ENABLED', '0');

// ===================================================================== 1

echo "\n== 1. Один товар в трёх размерах — это три строки ==\n";

$parts = Variants::split('Баллистический шлем Протон СВМПЭ (р.S-5шт, р.M- 13шт, р.L - 7шт)');
ok('модификаций найдено три', count($parts) === 3, (string)count($parts));
ok('размеры прочитаны', implode(',', array_column($parts, 'label')) === 'S,M,L',
   implode(',', array_column($parts, 'label')));
ok('количества прочитаны', array_sum(array_column($parts, 'quantity')) == 25,
   (string)array_sum(array_column($parts, 'quantity')));

$expanded = Variants::expand([
    ['name' => 'Баллистический шлем Протон СВМПЭ (р.S-5шт, р.M- 13шт, р.L - 7шт)', 'qty' => 25],
    ['name' => 'Топор пожарный поясной', 'qty' => 1],
]);
ok('строк стало четыре', count($expanded) === 4, (string)count($expanded));
ok('каталог ищется по товару-родителю', $expanded[0]['name'] === 'Баллистический шлем Протон СВМПЭ',
   $expanded[0]['name']);
ok('а называется строка так, как просил клиент',
   str_contains($expanded[1]['raw_name'], 'размер M'), $expanded[1]['raw_name']);
ok('строка без модификаций не тронута', $expanded[3]['name'] === 'Топор пожарный поясной');

// Числа, которые размером не являются, строку не делят
ok('«Рукав 5ELEM - 1 шт» модификациями не считается', Variants::split('Рукав пожарный 5ELEM - 1 шт') === []);
ok('«Шлем 6Б47 - 5 шт» тоже', Variants::split('Шлем 6Б47 - 5 шт') === []);

// ===================================================================== 2

echo "\n== 2. Модификация получает свою карточку каталога ==\n";

Db::insert('legal_entities', [
    'entity_type' => 'ООО', 'full_name' => 'ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ "АТЛАНТ АРМОР"',
    'short_name' => 'ООО «АТЛАНТ АРМОР»', 'inn' => '9731154370', 'ogrn' => '1257700368595',
    'city' => 'Москва', 'address' => 'ул. Крылатские Холмы, 37', 'is_active' => 1,
    'signatory_name' => 'Сурков Кирилл Александрович', 'logo_path' => '',
    'phone' => '+7 495 000-00-00', 'email' => 'info@atlant-armour.ru',
]);

$product = ['moysklad_id' => 'ms-helmet', 'name' => 'Баллистический шлем Протон СВМПЭ',
            'name_normalized' => 'баллистический шлем протон свмпэ', 'article' => 'ProtonPE',
            'price' => 35000, 'stock' => 0, 'reserved' => 0, 'unit' => 'шт.',
            'description' => '<ul><li>Класс защиты Бр1.</li><li>Материал — СВМПЭ.</li></ul>',
            'product_type' => 'product'];
Db::insert('products_cache', $product);
foreach ([['S', 4], ['M', 20], ['L', 9]] as [$size, $stock]) {
    Db::insert('products_cache', [
        'moysklad_id' => 'ms-helmet-' . $size, 'name' => 'Баллистический шлем Протон СВМПЭ (Размер: ' . $size . ')',
        'name_normalized' => 'баллистический шлем протон свмпэ размер ' . mb_strtolower($size),
        'article' => 'ProtonPE-' . $size, 'price' => 35000, 'stock' => $stock, 'reserved' => 0,
        'unit' => 'шт.', 'product_type' => 'variant', 'parent_id' => 'ms-helmet',
        'characteristics' => 'Размер: ' . $size,
    ]);
}

ok('у товара есть модификации', Variants::has('ms-helmet'));
$picked = Variants::pick(Variants::forProduct('ms-helmet'), 'M');
ok('модификация M находится', ($picked['moysklad_id'] ?? '') === 'ms-helmet-M', (string)($picked['name'] ?? ''));

$resolved = Variants::resolveRow(['moysklad_product_id' => 'ms-helmet', 'variant_label' => 'L']);
ok('строка переезжает на свою карточку', ($resolved['moysklad_product_id'] ?? '') === 'ms-helmet-L');
ok('и берёт её артикул', ($resolved['article'] ?? '') === 'ProtonPE-L', (string)($resolved['article'] ?? ''));
ok('и её остаток, а не общий на товар', (int)($resolved['stock'] ?? -1) === 9,
   (string)($resolved['stock'] ?? ''));

$missing = Variants::resolveRow(['moysklad_product_id' => 'ms-helmet', 'variant_label' => 'XXL']);
ok('которой нет — строка об этом говорит', str_contains((string)($missing['notes'] ?? ''), 'XXL'),
   (string)($missing['notes'] ?? ''));

// ---------------------------------------------------------------------
// Подсказка каталога: выбирают размер и цвет, а не «товар вообще»

Db::insert('products_cache', ['moysklad_id' => 'ms-ptt', 'name' => 'Кнопка PTT под наушники',
                              'name_normalized' => 'кнопка ptt под наушники', 'article' => 'PTT-1',
                              'price' => 4500, 'stock' => 6, 'reserved' => 2, 'unit' => 'шт.',
                              'product_type' => 'product']);
Db::insert('products_cache', ['moysklad_id' => 'ms-old', 'name' => 'Подсумок под рацию',
                              'name_normalized' => 'подсумок под рацию', 'article' => 'PU-9',
                              'price' => 1200, 'stock' => 0, 'reserved' => 0, 'unit' => 'шт.',
                              'product_type' => 'product']);
Db::insert('products_cache', ['moysklad_id' => 'ms-ear', 'name' => 'Наушники Earmor M32',
                              'name_normalized' => 'наушники earmor m32', 'article' => 'M32',
                              'price' => 8500, 'stock' => 0, 'reserved' => 0, 'unit' => 'шт.',
                              'product_type' => 'product']);
foreach ([['coyote', 'Цвет: Coyote Brown; Вид рельсы: arc', 0],
          ['oliva',  'Цвет: Олива; Вид рельсы: arc',        3]] as [$id, $ch, $stock]) {
    Db::insert('products_cache', [
        'moysklad_id' => 'ms-ear-' . $id, 'name' => 'Наушники Earmor M32 (' . $ch . ')',
        'name_normalized' => 'наушники earmor m32 ' . $id, 'article' => 'M32-' . $id,
        'price' => 8500, 'stock' => $stock, 'reserved' => 0, 'unit' => 'шт.',
        'product_type' => 'variant', 'parent_id' => 'ms-ear', 'characteristics' => $ch,
    ]);
}

$row = fn(string $id) => Db::one("SELECT * FROM products_cache WHERE moysklad_id=?", [$id]);
$suggest = Variants::expandSuggest([$row('ms-old'), $row('ms-helmet'), $row('ms-ptt'), $row('ms-ear')]);
$ids = array_column($suggest, 'moysklad_id');

// Модуль 040 развернул это правило: сам товар СТОИТ в подсказке первой
// строкой семьи и выбирается — КП на «шлем» пишут без размера, с вилкой цен,
// а размеры уточняют в заказе. Модификации при этом никуда не делись.
$helmet = array_values(array_filter($suggest, fn($s) => $s['moysklad_id'] === 'ms-helmet'))[0] ?? [];
ok('сам товар с модификациями тоже можно выбрать', !empty($helmet['is_group']), implode(', ', $ids));
ok('и у него вилка цен по модификациям',
   (float)($helmet['price'] ?? 0) > 0 && (float)($helmet['price_max'] ?? 0) >= (float)($helmet['price'] ?? 0),
   json_encode([$helmet['price'] ?? null, $helmet['price_max'] ?? null]));
ok('а остаток — сумма по размерам', (int)($helmet['stock'] ?? -1) === 33, (string)($helmet['stock'] ?? ''));

$sizes = [];
foreach ($suggest as $s) {
    if (!empty($s['is_group'])) continue;   // строка всего товара — не размер
    if ($s['group_name'] === 'Баллистический шлем Протон СВМПЭ') $sizes[$s['variant_label']] = $s['stock'];
}
ok('у каждого размера своё количество', $sizes === ['L' => 9, 'M' => 20, 'S' => 4],
   json_encode($sizes, JSON_UNESCAPED_UNICODE));
$articles = array_column(array_filter($suggest, fn($s) => $s['variant_label'] === 'M'), 'article');
ok('и свой артикул', $articles === ['ProtonPE-M'], json_encode($articles));

$ptt = array_values(array_filter($suggest, fn($s) => $s['moysklad_id'] === 'ms-ptt'))[0] ?? [];
ok('товар без модификаций стоит сам', ($ptt['variant_label'] ?? 'x') === '' && ($ptt['group_name'] ?? 'x') === '');
ok('и с количеством за вычетом резерва', ($ptt['stock'] ?? -1) === 4, (string)($ptt['stock'] ?? ''));

$colors = [];
foreach ($suggest as $s) {
    if (!empty($s['is_group'])) continue;
    if (($s['group_name'] ?? '') === 'Наушники Earmor M32') $colors[$s['variant_label']] = $s['stock'];
}
ok('метка модификации — её характеристики, а не имя товара целиком',
   $colors === ['Coyote Brown · arc' => 0, 'Олива · arc' => 3],
   json_encode($colors, JSON_UNESCAPED_UNICODE));

ok('пустая полка уходит вниз списка', end($ids) === 'ms-old', implode(', ', $ids));

$one = Variants::expandSuggest([$row('ms-helmet-L')]);
// Нашлась одна модификация — показываем её и её товар (модуль 040): выбрать
// можно и размер, и шлем целиком, а найденной остаётся ровно одна строка размера
ok('нашлась одна модификация — она и её товар',
   array_column($one, 'moysklad_id') === ['ms-helmet', 'ms-helmet-L'],
   json_encode(array_column($one, 'moysklad_id')));
$sizesOnly = array_values(array_filter($one, fn($r) => empty($r['is_group'])));
ok('размер в списке ровно один', count($sizesOnly) === 1, (string)count($sizesOnly));
ok('и подписан он своим товаром',
   ($sizesOnly[0]['group_name'] ?? '') === 'Баллистический шлем Протон СВМПЭ',
   (string)($sizesOnly[0]['group_name'] ?? ''));

ok('характеристики из скобок имени тоже читаются меткой',
   Variants::label(['name' => 'Наушники Earmor M32 (Цвет: Олива; Вид рельсы: arc)']) === 'Олива · arc',
   Variants::label(['name' => 'Наушники Earmor M32 (Цвет: Олива; Вид рельсы: arc)']));

// ===================================================================== 3

echo "\n== 3. Запрос целиком: три размера и четыре чужие позиции ==\n";

$cpId = Db::insert('counterparties', ['name' => 'ООО «Воевода»', 'inn' => '7701234567',
                                      'contact_email' => 'z@voevoda.ru']);
$letter = "просьба выставить счет на:\n"
        . "Баллистический шлем Протон СВМПЭ (р.S-5шт, р.M- 13шт, р.L - 7шт)\n"
        . "Топор пожарный поясной - 1 шт\n"
        . "Рукав пожарный 5ELEM - 1 шт\n"
        . "Водопенное оборудование - 1 шт\n"
        . "Ящики для песка и инвентаря стеклопластиковые «Рапан» - 1 шт\n";
$requestId = Db::insert('requests', [
    'source' => 'email', 'status' => 'new', 'type' => 'order', 'counterparty_id' => $cpId,
    'raw_text' => $letter, 'email_from' => 'Елизавета Черниченкова <z@voevoda.ru>',
    'parsed_json' => json_encode(['items' => [
        ['name' => 'Баллистический шлем Протон СВМПЭ (р.S-5шт, р.M- 13шт, р.L - 7шт)', 'qty' => 25],
        ['name' => 'Топор пожарный поясной', 'qty' => 1],
        ['name' => 'Рукав пожарный 5ELEM', 'qty' => 1],
        ['name' => 'Водопенное оборудование', 'qty' => 1],
        ['name' => 'Ящики для песка и инвентаря стеклопластиковые «Рапан»', 'qty' => 1],
    ]], JSON_UNESCAPED_UNICODE),
]);

$items = RequestItems::ensure($requestId);
ok('строк стало семь', count($items) === 7, (string)count($items));

$byLabel = [];
foreach ($items as $row) if (($row['variant_label'] ?? '') !== '') $byLabel[$row['variant_label']] = $row;
ok('три модификации на месте', count($byLabel) === 3, implode(',', array_keys($byLabel)));
ok('у размера M — 13 штук', (float)($byLabel['M']['quantity'] ?? 0) == 13.0,
   (string)($byLabel['M']['quantity'] ?? ''));
ok('и его собственная карточка', ($byLabel['M']['moysklad_product_id'] ?? '') === 'ms-helmet-M',
   (string)($byLabel['M']['product_name'] ?? ''));
ok('размер S в наличии, значит не «под заказ»', ($byLabel['S']['notes'] ?? null) === null,
   (string)($byLabel['S']['notes'] ?? 'null'));

$out = RequestItems::outOfScope($requestId);
ok('пожарное снаряжение отсеяно', count($out) === 4, (string)count($out));
ok('и названо правилом', ($out[0]['reason'] ?? '') !== '', (string)($out[0]['reason'] ?? ''));
ok('в «уточняем» этих строк нет',
   !array_filter(RequestItems::unmatched($requestId), fn($u) => str_contains($u['requested'], 'пожарн')));

$block = RequestItems::outOfScopeBlock($out);
ok('промпту сказано молчать о них', str_contains($block, 'НЕ упоминай'), mb_substr($block, 0, 60));
Settings::set('SCOPE_REPLY_MODE', 'decline');
ok('режим «отказать» говорит иначе', str_contains(RequestItems::outOfScopeBlock($out), 'не поставляем'));
Settings::set('SCOPE_REPLY_MODE', 'silent');

// Кнопка «не наш профиль» пополняет список правил
$helmetRow = $byLabel['S'];
$before = count(Scope::rules());
RequestItems::setScope($requestId, (int)$helmetRow['id'], true);
ok('строка отмечена руками',
   (int)Db::val("SELECT is_out_of_scope FROM request_items WHERE id=?", [$helmetRow['id']]) === 1);
ok('и правило запомнено', count(Scope::rules()) === $before + 1,
   (string)count(Scope::rules()));
RequestItems::setScope($requestId, (int)$helmetRow['id'], false);
ok('возврат в работу возможен',
   (int)Db::val("SELECT is_out_of_scope FROM request_items WHERE id=?", [$helmetRow['id']]) === 0);

// ===================================================================== 4

echo "\n== 4. КП: чужие позиции не становятся позициями документа ==\n";

$matched = RequestItems::toProposalItems(RequestItems::all($requestId));
ok('в КП уходят только наши строки', count($matched) === 3, (string)count($matched));

$proposalId = Db::insert('proposals', ['request_id' => $requestId, 'counterparty_id' => $cpId,
                                       'vat_rate' => 22, 'execution_days' => 30, 'validity_days' => 14]);
foreach ($matched as $i => $m) {
    $match = $m['match'];
    Db::insert('proposal_items', [
        'proposal_id' => $proposalId, 'position' => $i + 1,
        'product_name' => $match ? $match['name'] : $m['raw_name'],
        'requested_name' => $m['raw_name'],
        'moysklad_product_id' => $match['moysklad_id'] ?? null,
        'unit' => $match['unit'] ?? 'шт.', 'quantity' => $m['quantity'],
        'price' => $match['price'] ?? 0, 'is_confirmed' => 1,
        // Поле нарочно заполняем СЫРЫМ HTML: так оно и лежало в рабочей базе,
        // и именно так теги уезжали в подписанный документ
        'description_text' => '<ul><li>Класс защиты Бр1.</li><li>Материал — СВМПЭ.</li></ul>',
    ]);
}
Requisites::freeze($proposalId);

// Строки печатаются по галочке КП «Показать в КП отсутствующую номенклатуру» (модуль 046)
Settings::set('KP_SHOW_OUT_OF_SCOPE', 1);
$html = PdfGenerator::html($proposalId);
ok('шлем в документе есть', str_contains($html, 'Протон СВМПЭ'));
// «Не наша номенклатура» печатается только строкой с прочерками, серым
// курсивом названием клиента (issue #60, #67) — не позицией и не
// «нужно уточнение»
preg_match_all('#<tr class="out-of-scope">.*?</tr>#s', $html, $scopeRows);
$scopeHtml = implode('', $scopeRows[0]);
$rest = str_replace($scopeRows[0], '', $html);
ok('топор — только строкой «не наша номенклатура»', str_contains($scopeHtml, 'Топор пожарный') && !str_contains($rest, 'Топор пожарный'));
ok('рукава тоже', !str_contains($rest, '5ELEM'));
ok('и ящики для песка', !str_contains($rest, 'Рапан'));
ok('блока «нужно уточнение» про них нет', !str_contains($rest, 'Водопенное'));
Settings::set('KP_SHOW_OUT_OF_SCOPE', 0);
ok('настройка выключает эти строки', !str_contains(PdfGenerator::html($proposalId), 'Топор пожарный'));
Settings::forget('KP_SHOW_OUT_OF_SCOPE');

echo "\n== 5. Документ: логотип, подпись, реквизиты, разметка ==\n";

ok('теги описания не напечатаны буквой', !str_contains($html, '&lt;ul&gt;') && !str_contains($html, '<li>Класс защиты Бр1.</li>')
   || str_contains($html, '<li>Класс защиты Бр1.</li>'));
ok('список напечатан списком', str_contains($html, '<li>Класс защиты Бр1.</li>'));
ok('экранированных тегов в документе нет', !str_contains($html, '&lt;ul&gt;'));

ok('логотип вставлен', str_contains($html, '<img src="data:image/') && str_contains($html, 'class="logo"'));
// Модуль 051: прочерка «_____» нет, а КП без менеджера не подписывается —
// подписи организации по умолчанию больше нет
ok('прочерка под подпись нет', !str_contains($html, '_______________'));
ok('картинка подписи стоит в строке, а не отдельным абзацем',
   str_contains(file_get_contents(ROOT . '/templates/kp.html'), '<img class="sign-img"'));
ok('КП без менеджера не подписано', !str_contains(substr($html, (int)strrpos($html, 'class="signature"')), 'Сурков'));
ok('второго блока реквизитов поставщика нет', !str_contains($html, 'Реквизиты поставщика'));

// Своя подпись менеджера перебивает подписанта организации
$managerId = Db::insert('managers', ['login' => 'ivanov', 'name' => 'Иванов И.И.',
                                     'password_hash' => 'x', 'is_admin' => 0,
                                     'signatory_name' => 'Иванов Иван Иванович', 'kp_signature_mode' => 'own']);
Db::update('proposals', ['manager_id' => $managerId], 'id=?', [$proposalId]);
$html2 = PdfGenerator::html($proposalId);
ok('КП подписывает тот, кто его отправляет', str_contains($html2, 'Иванов Иван Иванович'));
ok('и подписанта организации в подписи уже нет',
   !str_contains(substr($html2, (int)strrpos($html2, 'class="signature"')), 'Сурков'));

echo "\n== 6. Имя файла называет адресата и дату ==\n";

$name = PdfGenerator::fileName($proposalId, 'pdf');
ok('в имени наш бренд', str_starts_with($name, 'КП_Атлант_Армор_'), $name);
ok('и адресат', str_contains($name, 'Воевода'), $name);
ok('и дата', str_contains($name, date('d.m.Y')), $name);
ok('расширение на месте', str_ends_with($name, '.pdf'), $name);
ok('Word называется так же', PdfGenerator::fileName($proposalId, 'docx')
   === substr($name, 0, -3) . 'docx', DocxGenerator::filename($proposalId));
ok('в имени нет символов, ломающих файловую систему',
   !preg_match('#[\\\\/:*?"<>|]#', $name), $name);

echo "\n== 7. Разметка печатается разметкой, откуда бы ни пришла ==\n";

$raw = '<ul><li>Класс защиты Бр1.</li><li>Материал — СВМПЭ.</li></ul>';
ok('HTML, попавший в поле, печатается списком',
   Markup::markdownToHtml($raw) === '<ul><li>Класс защиты Бр1.</li><li>Материал — СВМПЭ.</li></ul>',
   Markup::markdownToHtml($raw));
ok('обычный текст не трогается', Markup::markdownToHtml('ширина < 40 мм') === '<p>ширина &lt; 40 мм</p>',
   Markup::markdownToHtml('ширина < 40 мм'));

echo "\n== 8. Логотип без GD: прозрачность снимается до mPDF ==\n";

$src = (string)file_get_contents(ROOT . '/public/assets/img/logo.png');
ok('встроенный знак — PNG с прозрачностью', Png::isPng($src) && Png::hasAlpha($src));
$flat = Png::flatten($src);
ok('плоская копия получилась', is_string($flat) && $flat !== '');
ok('и в ней альфы уже нет', is_string($flat) && !Png::hasAlpha($flat));
$size = @getimagesizefromstring((string)$flat);
ok('размер не изменился', ($size[0] ?? 0) === 900 && ($size[1] ?? 0) === 387,
   implode('×', [$size[0] ?? 0, $size[1] ?? 0]));
ok('знак для документа отдаётся data:URI',
   str_starts_with(Branding::documentImage('kp'), 'data:image/'));
ok('и претензий к нему нет', Branding::documentWarning('kp') === '', Branding::documentWarning('kp'));

echo "\n== 9. Классификатор учится на правках менеджера ==\n";

$msgId = Db::insert('mail_messages', [
    'direction' => 'in', 'from_email' => 'tagir@example.ru', 'to_emails' => 'info@atlant-armour.ru',
    'subject' => 'спальный мешок', 'body_text' => 'подскажите пожалуйста спальный мешок envelope двойной, '
        . 'т.е. на теплую погоду есть возможность отстегнуть слой ?',
    'date_at' => date('Y-m-d H:i:s'), 'category' => 'kp_request', 'is_read' => 0,
]);
$msg = Db::one("SELECT * FROM mail_messages WHERE id=?", [$msgId]);

Triage::correct($msg, 'product_question', $managerId, 'Спрашивают про конструкцию, а не про цену');
$samples = Learning::fewShot('category');
ok('правка записана', count($samples) === 1, (string)count($samples));
ok('в ней видно, что было', ($samples[0]['auto_answer'] ?? '') === 'kp_request');
ok('и как правильно', ($samples[0]['correct_answer'] ?? '') === 'product_question');

$learned = Triage::learned();
ok('правка вернулась в промпт классификатора', str_contains($learned, 'product_question'), mb_substr($learned, 0, 80));
ok('и с пояснением менеджера', str_contains($learned, 'не про цену'));

Triage::correct($msg, 'product_question', $managerId);
ok('та же категория второй раз не записывается', count(Learning::fewShot('category')) === 1);

Settings::set('TRIAGE_LEARN', '0');
ok('обучение выключается', Triage::learned() === '');
Settings::set('TRIAGE_LEARN', '1');

echo "\n== 10. Правки собираются и помечаются выгруженными ==\n";

Learning::record('answer', [
    'subject' => 'Проверка подбора', 'question' => 'можно ли отстегнуть слой?',
    'auto_answer' => 'Уточняем наличие и цену', 'correct_answer' => 'Да, верхний слой отстёгивается',
    'manager_id' => $managerId,
]);
$list = Learning::query([]);
ok('в панели видны обе правки', $list['total'] === 2, (string)$list['total']);
ok('и обе ещё не выгружены', $list['pending'] === 2, (string)$list['pending']);

Learning::update((int)$list['items'][0]['id'], ['correct_answer' => 'Да, верхний слой отстёгивается полностью']);
ok('правку можно переписать',
   str_contains((string)Db::val("SELECT correct_answer FROM learning_samples WHERE id=?",
                                [$list['items'][0]['id']]), 'полностью'));

// Выгрузку в GitHub здесь не делаем — сети нет; проверяем ровно то, что после
// неё правки перестают попадать в следующий архив
Db::q("UPDATE learning_samples SET exported_at=datetime('now'), export_batch='test'");
ok('после выгрузки новых правок нет', Learning::query([])['pending'] === 0);
Learning::record('reply', ['question' => 'ещё письмо', 'auto_answer' => 'а', 'correct_answer' => 'б',
                           'manager_id' => $managerId]);
ok('а следующая правка снова новая', Learning::query([])['pending'] === 1);
ok('и в выгруженные она не попала',
   (int)Db::val("SELECT COUNT(*) FROM learning_samples WHERE export_batch='test'") === 2);

echo "\n== 11. Что правили менеджеры — видно админу ==\n";

ContentLog::record('tov', 'tov', 'Tone of Voice', $managerId, 'было', 'стало подлиннее');
Prompts::save('mail_reply', 'Отвечай коротко.', $managerId);
$recent = ContentLog::recent();
ok('лента изменений не пуста', count($recent) >= 2, (string)count($recent));
ok('правка промпта в ленте', (bool)array_filter($recent, fn($r) => $r['area'] === 'prompt'));
ok('и видно, кто правил', ($recent[0]['manager_name'] ?? '') === 'Иванов И.И.',
   (string)($recent[0]['manager_name'] ?? ''));
ok('правка без изменений не пишется',
   ContentLog::record('tov', 'tov', 'Tone of Voice', $managerId, 'одно и то же', 'одно и то же') === 0);

echo "\n== 12. Tone of Voice переживает обновление кода ==\n";

Tov::save('Обращаемся на «вы», без восклицательных знаков.', $managerId);
ok('текст сохранён в storage/', Tov::isCustom() && is_file(ROOT . '/storage/tov.md'));
ok('и читается', str_contains(Tov::read(), 'восклицательных'));
ok('это не файл репозитория', !str_contains((string)@file_get_contents(ROOT . '/reference/tov.md') ?: '',
                                            'восклицательных'));
Tov::reset($managerId);
ok('сброс возвращает встроенный', !Tov::isCustom());

echo "\n== 13. Выключенный ящик уходит с экрана вместе с письмами ==\n";

$boxA = Db::insert('mailboxes', ['name' => 'info@atlant-armour.ru', 'email' => 'info@atlant-armour.ru',
                                 'is_active' => 1, 'is_default' => 1]);
$boxB = Db::insert('mailboxes', ['name' => 'atlant.armour@yandex.ru', 'email' => 'atlant.armour@yandex.ru',
                                 'is_active' => 1]);
$key = 's:' . md5('test-thread');
foreach ([[$boxA, 'info'], [$boxB, 'yandex']] as [$box, $tag]) {
    Db::insert('mail_messages', [
        'mailbox_id' => $box, 'direction' => 'in', 'from_email' => 'z@voevoda.ru',
        'to_emails' => 'info@atlant-armour.ru', 'subject' => 'запрос на стоимость и сроки',
        'body_text' => 'просьба выставить счет на: ' . $tag, 'date_at' => date('Y-m-d H:i:s'),
        'thread_key' => $key, 'thread_subject' => 'запрос на стоимость и сроки', 'is_read' => 1,
    ]);
}
ok('в переписке два письма', count(MailThreads::messages($key)) === 2);

MailSync::setMailboxMessagesHidden($boxB, true);
ok('после выключения ящика — одно', count(MailThreads::messages($key)) === 1,
   (string)count(MailThreads::messages($key)));
ok('осталось письмо рабочего ящика',
   str_contains((string)MailThreads::messages($key)[0]['body_text'], 'info'));
ok('но в архиве видны оба', count(MailThreads::messages($key, true)) === 2);

MailSync::setMailboxMessagesHidden($boxB, false);
ok('ящик включили обратно — письмо вернулось', count(MailThreads::messages($key)) === 2);

echo "\n" . ($fail ? "$fail FAILED\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
