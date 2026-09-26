<?php
/**
 * Модуль 065: issues #131–#132 и автоподбор в одну кнопку.
 *
 *   — «шлем Атом Арамид (размер Л, М) - количество по 2 штуки каждого» —
 *     две строки, L и M, на своих модификациях, без «аналога»;
 *   — нейросеть сама решает строки, которые каталог не решил;
 *   — закрытая карточка возвращается в «В работе», когда клиент написал;
 *   — у каждого уведомления есть крестик.
 *
 * Запуск:  php tests/module_065.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-065-' . getmypid() . '.db';
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
require_once ROOT . '/lib/item_lines.php';
require_once ROOT . '/lib/variants.php';
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/alternatives.php';
require_once ROOT . '/lib/autopick.php';
require_once ROOT . '/lib/boards.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');
$api = file_get_contents(ROOT . '/public/api/requests.php');
$j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);

// ===================================================================== 1
echo "1. Строка письма: «количество по 2 штуки каждого»\n";
$line = 'Баллистический шлем Атом Арамид (размер Л, М) - количество по 2 штуки каждого';
$items = ItemLines::extract("Добрый день!\nПрошу выставить КП:\n$line\nС уважением, Иван");
ok('позиция одна, имя без «- количество по»', count($items) === 1
   && $items[0]['name'] === 'Баллистический шлем Атом Арамид (размер Л, М)', $j($items));
ok('количество 2 и оно на каждый размер', ($items[0]['qty'] ?? 0) == 2 && !empty($items[0]['each']));
$plain = ItemLines::extract('Тактические наушники AMP в количестве 5 шт. Или аналог.');
ok('обычная строка — как была, без «каждого»', ($plain[0]['name'] ?? '') === 'Тактические наушники AMP'
   && ($plain[0]['qty'] ?? 0) == 5 && empty($plain[0]['each']), $j($plain));
ok('имя, оканчивающееся на «р», не бьётся тире в trim', (ItemLines::extract('Монокуляр — 2 шт')[0]['name'] ?? '') === 'Монокуляр');

// ===================================================================== 2
echo "2. Размеры кириллицей и списком после одного «размер»\n";
ok('Л, М → L, M', Variants::sizeList('шлем (размер Л, М)') === ['L', 'M']);
ok('размеры S/M/L', Variants::sizeList('размеры S/M/L') === ['S', 'M', 'L']);
ok('ХЛ и ХХЛ', Variants::sizeList('размер ХЛ и ХХЛ') === ['XL', 'XXL']);
ok('ростовки через запятую', Variants::sizeList('р. 52-54, 56/58') === ['52-54', '56-58']);
ok('«размер S или размер M» — выбор, не список', Variants::sizeList('размер S или размер M') === []
   && Variants::sizeLabel('размер S или размер M') === null);
ok('один размер кириллицей — метка', Variants::sizeLabel('шлем, размер Л') === 'L');
ok('без подсказки «с» и «м» — не размеры', Variants::sizeList('кабель 10 м с разъёмом') === []
   && Variants::sizeLabel('Шлем Бр3 L') === null);
ok('название без списка размеров', Variants::stripSize('Баллистический шлем Атом Арамид (размер Л, М)') === 'Баллистический шлем Атом Арамид');
ok('«р.Л-5шт, р.М-3шт» — латиницей', array_column(Variants::split('шлем (р.Л-5шт, р.М-3шт)'), 'label') === ['L', 'M']);

$each = Variants::expand([['name' => 'Баллистический шлем Атом Арамид (размер Л, М)', 'qty' => 2, 'each' => true]]);
ok('по 2 каждого — две строки по 2', count($each) === 2 && $each[0]['qty'] == 2 && $each[1]['qty'] == 2
   && $each[0]['variant_label'] === 'L' && $each[1]['variant_label'] === 'M', $j($each));
ok('строки ищут товар без размера', $each[0]['name'] === 'Баллистический шлем Атом Арамид'
   && $each[0]['raw_name'] === 'Баллистический шлем Атом Арамид (размер L)');
$total = Variants::expand([['name' => 'Шлем Атом (размер L, M)', 'qty' => 5]]);
ok('итог без «по» — поровну, остаток первым: 3 + 2', array_column($total, 'qty') === [3, 2], $j(array_column($total, 'qty')));
ok('и строка говорит, что делили мы', str_contains((string)($total[0]['qty_note'] ?? ''), 'разделено поровну'));
ok('«по N» в тексте строки — тоже на каждый', array_column(Variants::expand([['name' => 'Шлем Атом (размер L, M)',
   'qty' => 3, 'raw_text' => 'Шлем Атом (размер L, M) по 3 шт']]), 'qty') === [3, 3]);
$tailOnly = Variants::expand([['name' => 'Плита (Бр4)', 'qty' => 2, 'raw_text' => 'Плита (Бр4), размеры L, XL — по 2 шт']]);
ok('размеры в хвосте письма — имя со скобкой класса не теряет', ($tailOnly[0]['name'] ?? '') === 'Плита (Бр4)'
   && array_column($tailOnly, 'variant_label') === ['L', 'XL'], $j($tailOnly));
ok('поиск строки с размером — со скобкой класса', Variants::searchName('Плита (Бр4) (размер L)') === 'Плита (Бр4)'
   && Variants::searchName('Шлем Протон СВМПЭ (цвет: олива)') === 'Шлем Протон СВМПЭ'
   && Variants::searchName('Шлем (р.S-5шт, р.M-13шт)') === 'Шлем');
ok('одна штука на два размера — это «L или M», не делим', count(Variants::expand([['name' => 'Шлем (размер L, M)', 'qty' => 1]])) === 1);

// ===================================================================== 3
echo "3. Каталог: товар, его размер и цвет, который есть\n";
$prod = fn(string $id, string $name, array $o = []) => Db::insert('products_cache', $o + [
    'moysklad_id' => $id, 'name' => $name, 'price' => 0, 'stock' => 0, 'reserved' => 0, 'product_type' => 'product']);
$prod('atom', 'Баллистический шлем Атом Арамид');
$atom = [['S(54-56)', 'Coyote', 0, 50000], ['S(54-56)', 'Multicam', 1, 50000], ['S(54-56)', 'Multicam Alpine', 1, 30000],
         ['M(56-58)', 'Coyote', 0, 50000], ['M(56-58)', 'Multicam', 3, 50000], ['L(60-62)', 'Coyote', 2, 50000],
         ['L(60-62)', 'Multicam', 0, 50000]];
foreach ($atom as $i => [$size, $color, $stock, $price]) {
    $prod("atom-$i", "Баллистический шлем Атом Арамид (Размер шлема: $size; Цвет: $color)", ['price' => $price,
        'stock' => $stock, 'product_type' => 'variant', 'parent_id' => 'atom', 'characteristics' => "Размер шлема: $size; Цвет: $color"]);
}
$prod('atom2', 'Баллистический Шлем Атом-2 Арамид');
foreach ([['XL(62-64)', 'Мох', 1], ['L(60-62)', 'Coyote Brown', 1]] as $i => [$size, $color, $stock]) {
    $prod("atom2-$i", "Баллистический Шлем Атом-2 Арамид (Размер шлема: $size; Цвет: $color)", ['price' => 50000,
        'stock' => $stock, 'product_type' => 'variant', 'parent_id' => 'atom2', 'characteristics' => "Размер шлема: $size; Цвет: $color"]);
}
ProductMatcher::forgetCatalog();

$c = ProductMatcher::findCandidates('Баллистический шлем Атом Арамид (размер Л, М) - количество по', 5);
ok('«размер» и «количество» не тянут вверх модификации — первым сам шлем', ($c[0]['moysklad_id'] ?? '') === 'atom',
   $j(array_column($c, 'name')));
ok('Атом-2 — не первый', ($c[0]['moysklad_id'] ?? '') !== 'atom2');

$letter = "Добрый день!\nПрошу выставить КП:\n$line\nС уважением, Иван";
$req = Db::insert('requests', ['source' => 'email', 'raw_text' => $letter, 'email_from' => 'x@mail.ru',
                               'parsed_json' => json_encode(['items' => []])]);
$rows = RequestItems::ensure($req);
ok('две строки: L и M', count($rows) === 2 && $rows[0]['variant_label'] === 'L' && $rows[1]['variant_label'] === 'M',
   $j(array_column($rows, 'raw_name')));
ok('L — Coyote, единственный L на складе', ($rows[0]['moysklad_product_id'] ?? '') === 'atom-5', (string)$rows[0]['product_name']);
ok('M — Multicam, единственный M на складе', ($rows[1]['moysklad_product_id'] ?? '') === 'atom-4', (string)$rows[1]['product_name']);
ok('по 2 штуки каждого', (int)$rows[0]['quantity'] === 2 && (int)$rows[1]['quantity'] === 2);
ok('не аналог и не вопрос', !$rows[0]['is_alternative'] && !$rows[1]['is_alternative']
   && !$rows[0]['needs_choice'] && !$rows[1]['needs_choice']);

// Модель разобрала письмо — имя со скобкой, «каждого» в куске письма
$req2 = Db::insert('requests', ['source' => 'email', 'raw_text' => $letter, 'email_from' => 'x@mail.ru',
    'parsed_json' => $j(['items' => [['name' => 'Баллистический шлем Атом Арамид (размер Л, М)', 'qty' => 2, 'raw_text' => $line]]])]);
$rows2 = RequestItems::ensure($req2);
ok('разбор моделью — те же две строки', array_column($rows2, 'moysklad_product_id') === ['atom-5', 'atom-4']
   && array_column($rows2, 'quantity') == [2, 2], $j(array_column($rows2, 'product_name')));

// Цвет назван в письме — берётся он, хоть его и меньше
Db::update('products_cache', ['stock' => 1], 'moysklad_id=?', ['atom-6']);
$req3 = Db::insert('requests', ['source' => 'email', 'raw_text' => 'Шлем Атом Арамид мультикам, размер L — 1 шт',
    'parsed_json' => $j(['items' => [['name' => 'Шлем Атом Арамид мультикам, размер L', 'qty' => 1,
                                      'raw_text' => 'Шлем Атом Арамид мультикам, размер L — 1 шт']]])]);
$r3 = RequestItems::ensure($req3)[0] ?? [];
ok('цвет из письма: мультикам', ($r3['moysklad_product_id'] ?? '') === 'atom-6', (string)($r3['product_name'] ?? ''));
// Цвет не назван, на складе два — выбран тот, где больше, и строка это говорит
$req4 = Db::insert('requests', ['source' => 'email', 'raw_text' => 'Шлем Атом Арамид, размер L — 1 шт',
    'parsed_json' => $j(['items' => [['name' => 'Шлем Атом Арамид, размер L', 'qty' => 1]]])]);
$r4 = RequestItems::ensure($req4)[0] ?? [];
ok('цвет не назван — больше на складе (Coyote)', ($r4['moysklad_product_id'] ?? '') === 'atom-5');
ok('подсказка менеджеру: выбрали мы, есть и другой', str_contains((string)($r4['match_hint'] ?? ''), 'есть также')
   && str_contains((string)$r4['match_hint'], 'Multicam'), (string)($r4['match_hint'] ?? ''));
ok('в КП подсказка не печатается: `notes` пустое', trim((string)($r4['notes'] ?? '')) === '');
$alts = array_column($r4['variants'] ?? [], 'moysklad_id');
ok('«ещё похожие» — другой цвет этого размера, без чужих размеров', in_array('atom-6', $alts, true)
   && !array_intersect(['atom-0', 'atom-1', 'atom-2', 'atom-3', 'atom-4', 'atom'], $alts), $j($alts));
Db::update('products_cache', ['stock' => 0], 'moysklad_id=?', ['atom-6']);

// Итог «5 шт на L, M» — поровну, и подсказка на строке, а не в `notes`
$req5 = Db::insert('requests', ['source' => 'email', 'raw_text' => 'Шлем Атом Арамид (размер L, M) — 5 шт',
    'parsed_json' => $j(['items' => [['name' => 'Шлем Атом Арамид (размер L, M)', 'qty' => 5]]])]);
$r5 = RequestItems::ensure($req5);
ok('5 на два размера — 3 и 2', array_column($r5, 'quantity') == [3, 2], $j(array_column($r5, 'quantity')));
ok('«разделено поровну» — подсказкой, не в КП', str_contains((string)$r5[0]['match_hint'], 'разделено поровну')
   && !str_contains((string)$r5[0]['notes'], 'разделено'));

// ===================================================================== 4
echo "4. Аналог — это другой товар, а не другой размер того же\n";
$pool = Alternatives::candidates('Баллистический шлем Атом Арамид', 'atom-0');
ok('кандидаты аналога — без семьи самого шлема', !array_filter($pool, fn($r) => $r['moysklad_id'] === 'atom'
   || str_starts_with($r['moysklad_id'], 'atom-')), $j(array_column($pool, 'moysklad_id')));

// Строка стоит на пустой модификации без размера из письма — сам товар
$req6 = Db::insert('requests', ['source' => 'email', 'raw_text' => 'Шлем Атом Арамид — 1 шт', 'parsed_json' => $j(['items' => []])]);
$id6 = Db::insert('request_items', ['request_id' => $req6, 'position' => 1, 'raw_name' => 'Шлем Атом Арамид', 'quantity' => 1,
    'moysklad_product_id' => 'atom-0', 'product_name' => 'Баллистический шлем Атом Арамид (Размер шлема: S(54-56); Цвет: Coyote)',
    'stock' => 0, 'is_confirmed' => 0, 'match_source' => 'words']);
RequestItems::fillAlternatives($req6, false, [$id6]);
$r6 = Db::one("SELECT * FROM request_items WHERE id=?", [$id6]);
ok('без размера — сам шлем, остаток его модификаций, не аналог', $r6['moysklad_product_id'] === 'atom'
   && (int)$r6['is_alternative'] === 0 && (int)$r6['stock'] > 0, $r6['moysklad_product_id'] . ' / alt ' . $r6['is_alternative']);

// Размер назван, строка на пустом цвете — на цвет этого размера, что есть
$id7 = Db::insert('request_items', ['request_id' => $req6, 'position' => 2, 'raw_name' => 'Шлем Атом Арамид (размер L)',
    'quantity' => 1, 'variant_label' => 'L', 'moysklad_product_id' => 'atom-6',
    'product_name' => 'Баллистический шлем Атом Арамид (Размер шлема: L(60-62); Цвет: Multicam)', 'stock' => 0, 'is_confirmed' => 0]);
RequestItems::fillAlternatives($req6, false, [$id7]);
$r7 = Db::one("SELECT * FROM request_items WHERE id=?", [$id7]);
ok('размер L — на L Coyote, не аналог', $r7['moysklad_product_id'] === 'atom-5' && (int)$r7['is_alternative'] === 0,
   $r7['moysklad_product_id']);

// Модификации одного товара, равные по оценке, — это сам товар, а не вопрос
$m = ProductMatcher::matchItems([['name' => 'Баллистический шлем Атом Арамид Multicam', 'qty' => 1]], false);
ok('равные модификации одного товара — не «выберите один»', !$m[0]['needs_choice'], $j([$m[0]['match']['name'] ?? '', $m[0]['needs_choice']]));

// Старая строка «… (размер Л, М) - количество по» делится кнопкой «Подобрать заново»
$req8 = Db::insert('requests', ['source' => 'email', 'raw_text' => $letter, 'parsed_json' => $j(['items' => []])]);
Db::insert('request_items', ['request_id' => $req8, 'position' => 1, 'quantity' => 2,
    'raw_name' => 'Баллистический шлем Атом Арамид (размер Л, М) - количество по', 'moysklad_product_id' => 'atom-5',
    'product_name' => 'Баллистический шлем Атом Арамид (Размер шлема: L(60-62); Цвет: Coyote)', 'is_alternative' => 1,
    'alt_of' => 'Баллистический шлем Атом Арамид (Размер шлема: S(54-56); Цвет: Coyote)', 'needs_choice' => 1, 'is_confirmed' => 0,
    'notes' => 'аналог: соответствует запросу по 2 из 3 указанных требований, есть на складе',
    'alt_specs_json' => $j(['matched' => [], 'differs' => [], 'reason' => 'x', 'source' => 'words'])]);
// Карточка открылась — строка делится сама, без кнопки
$r8 = RequestItems::ensure($req8);
ok('при открытии старая строка разделена сама', count($r8) === 2 && array_column($r8, 'variant_label') === ['L', 'M']
   && array_column($r8, 'moysklad_product_id') === ['atom-5', 'atom-4'] && !array_sum(array_map('intval', array_column($r8, 'is_alternative'))),
   $j(array_column($r8, 'product_name')));
ok('машинное «аналог: …» не осталось в примечании для КП', !array_filter(array_column($r8, 'notes'),
   fn($n) => str_contains((string)$n, 'аналог')));
Db::q("DELETE FROM request_items WHERE request_id=?", [$req8]);
Db::insert('request_items', ['request_id' => $req8, 'position' => 1, 'quantity' => 2,
    'raw_name' => 'Баллистический шлем Атом Арамид (размер Л, М) - количество по', 'moysklad_product_id' => 'atom-5',
    'product_name' => 'Баллистический шлем Атом Арамид (Размер шлема: L(60-62); Цвет: Coyote)', 'is_alternative' => 1,
    'alt_of' => 'Баллистический шлем Атом Арамид (Размер шлема: S(54-56); Цвет: Coyote)', 'needs_choice' => 1, 'is_confirmed' => 0]);
$rep = RequestItems::rematchReport($req8, false, true);
$r8 = $rep['items'];
ok('старая строка разделена на L и M', count($r8) === 2 && array_column($r8, 'variant_label') === ['L', 'M'],
   $j(array_column($r8, 'raw_name')));
ok('и стоит на своих модификациях, без аналога', array_column($r8, 'moysklad_product_id') === ['atom-5', 'atom-4']
   && !array_sum(array_map('intval', array_column($r8, 'is_alternative'))), $j(array_column($r8, 'product_name')));
ok('без ключа нейросети отчёт — без её раздела', !isset($rep['llm']));

// ===================================================================== 5
echo "5. Нейросеть сама — для того, что каталог не решил\n";
$prod('vest-a', 'Бронежилет Страж скрытого ношения', ['price' => 40000, 'stock' => 3]);
$prod('vest-b', 'Бронежилет Страж-М', ['price' => 45000, 'stock' => 2]);
$prod('kit', 'Аптечка индивидуальная АИ-4', ['price' => 3000, 'stock' => 10]);
ProductMatcher::forgetCatalog();
$req9 = Db::insert('requests', ['source' => 'email', 'raw_text' => "броник страж скрытый 1 шт\nшлем Атом Арамид 1 шт",
                                'parsed_json' => $j(['items' => []])]);
$a = Db::insert('request_items', ['request_id' => $req9, 'position' => 1, 'raw_name' => 'броник страж скрытый', 'quantity' => 1,
    'moysklad_product_id' => 'vest-b', 'product_name' => 'Бронежилет Страж-М', 'match_confidence' => 0.62, 'needs_choice' => 1,
    'match_variants' => $j([['moysklad_id' => 'vest-a', 'name' => 'Бронежилет Страж скрытого ношения', 'score' => 0.61]]),
    'is_confirmed' => 0, 'match_source' => 'words']);
$b = Db::insert('request_items', ['request_id' => $req9, 'position' => 2, 'raw_name' => 'шлем Атом Арамид', 'quantity' => 1,
    'moysklad_product_id' => 'atom', 'product_name' => 'Баллистический шлем Атом Арамид', 'match_confidence' => 0.97,
    'is_confirmed' => 1, 'match_source' => 'words']);
$pending = Autopick::pendingIds($req9);
ok('нерешённая строка ждёт модели, уверенная — нет', $pending === [$a], $j($pending));

$poolA = Autopick::pool(Db::one("SELECT * FROM request_items WHERE id=?", [$a]));
ok('кандидаты — найденное кодом, оба «Стража»', count(array_intersect(['vest-a', 'vest-b'], array_column($poolA, 'moysklad_id'))) === 2,
   $j(array_column($poolA, 'moysklad_id')));
$poolH = Autopick::pool(['raw_name' => 'шлем Атом Арамид', 'moysklad_product_id' => 'atom-3', 'match_variants' => $j([
    ['moysklad_id' => 'atom-4'], ['moysklad_id' => 'atom-5']])]);
ok('модификации стоят своим товаром, по одному разу', count(array_keys(array_column($poolH, 'moysklad_id'), 'atom')) === 1
   && !array_filter($poolH, fn($c) => str_starts_with($c['moysklad_id'], 'atom-')), $j(array_column($poolH, 'moysklad_id')));

// Модель не ответила — строки как были, и спросят в следующий раз
$res = Autopick::run($req9, null, function () { throw new RuntimeException('timeout'); });
ok('упала модель — ничего не тронуто', ($res['error'] ?? '') === 'timeout'
   && Db::val("SELECT moysklad_product_id FROM request_items WHERE id=?", [$a]) === 'vest-b'
   && Autopick::pendingIds($req9) === [$a]);

// Модель выбирает — только из данного, и строку больше не спрашивают
$seen = null;
$res = Autopick::run($req9, null, function (string $system, string $user) use (&$seen) {
    $seen = json_decode($user, true);
    return ['lines' => [['line' => 0, 'pick' => 'vest-a', 'reason' => 'скрытого ношения, как просили']]];
});
$ra = Db::one("SELECT * FROM request_items WHERE id=?", [$a]);
ok('модели ушла одна строка и её кандидаты', count($seen['lines'] ?? []) === 1 && ($seen['lines'][0]['asked'] ?? '') === 'броник страж скрытый');
ok('выбор модели встал на строку', $ra['moysklad_product_id'] === 'vest-a' && (int)$ra['needs_choice'] === 0
   && $ra['match_source'] === 'нейросеть' && (int)$res['picked'] === 1, $j($ra));
ok('цена из каталога, причина — подсказкой', (float)$ra['price'] === 40000.0 && str_contains((string)$ra['match_hint'], 'скрытого'));
ok('спросили — больше не ждёт', Autopick::pendingIds($req9) === [] && $ra['llm_checked_at'] !== null);
ok('подтверждённую строку модель не трогала', Db::val("SELECT moysklad_product_id FROM request_items WHERE id=?", [$b]) === 'atom');

// Придуманный id — мимо
$c9 = Db::insert('request_items', ['request_id' => $req9, 'position' => 3, 'raw_name' => 'аптечка', 'quantity' => 1,
    'is_confirmed' => 0]);
$res = Autopick::run($req9, null, fn() => ['lines' => [['line' => 0, 'pick' => 'invented-id']]]);
ok('id, которого не давали, игнорируется', (int)$res['picked'] === 0
   && Db::val("SELECT moysklad_product_id FROM request_items WHERE id=?", [$c9]) !== 'invented-id');
ok('но строку спросили — второй раз не спросят', Autopick::pendingIds($req9) === []);
ok('без ключа модель недоступна', Autopick::available() === false);

// ===================================================================== 6
echo "6. #131 закрытая карточка возвращается в «В работе»\n";
$board = Boards::singleton();
$closed = (int)Db::val("SELECT id FROM board_columns WHERE board_id=? AND kind='closed'", [(int)$board['id']]);
$work = (int)Boards::workColumn((int)$board['id'])['id'];
$cp = Db::insert('counterparties', ['name' => 'ООО Ромашка']);
$card = Boards::addCard($closed, ['counterparty_id' => $cp]);
Db::update('board_cards', ['moved_at' => '2026-09-20 10:00:00'], 'id=?', [$card]);
$msg = fn(array $o) => Db::insert('mail_messages', $o + ['direction' => 'in', 'subject' => 'Re: КП', 'from_email' => 'a@romashka.ru',
    'body_text' => 'текст', 'counterparty_id' => $cp, 'thread_key' => 'romashka-kp']);
$msg(['date_at' => '2026-09-19 10:00:00']);
ok('старое письмо не открывает закрытое', Boards::reopenClosed((int)$board['id']) === 0
   && (int)Db::val("SELECT column_id FROM board_cards WHERE id=?", [$card]) === $closed);
$msg(['date_at' => '2026-09-21 10:00:00', 'direction' => 'out']);
ok('наше письмо — тоже нет', Boards::reopenClosed((int)$board['id']) === 0);
$msg(['date_at' => '2026-09-21 11:00:00', 'category' => 'spam']);
ok('спам — тоже нет', Boards::reopenClosed((int)$board['id']) === 0);
$msg(['date_at' => '2026-09-22 10:00:00']);
ok('клиент написал после закрытия — карточка в «В работе»', Boards::reopenClosed((int)$board['id']) === 1
   && (int)Db::val("SELECT column_id FROM board_cards WHERE id=?", [$card]) === $work);
ok('и встала наверх', (int)Db::val("SELECT position FROM board_cards WHERE id=?", [$card]) === 0);
ok('sync() делает это сам', str_contains(file_get_contents(ROOT . '/lib/boards.php'), '$revived += self::reopenClosed($boardId);'));
// Карточка переписки без компании — по своей цепочке
$card2 = Boards::addCard($closed, ['thread_key' => 'orphan-thread']);
Db::update('board_cards', ['moved_at' => '2026-09-20 10:00:00', 'counterparty_id' => null], 'id=?', [$card2]);
Db::insert('mail_messages', ['direction' => 'in', 'subject' => 'Вопрос', 'from_email' => 'z@gmail.com', 'body_text' => 'x',
                             'thread_key' => 'orphan-thread', 'date_at' => '2026-09-23 10:00:00']);
ok('карточка переписки — по своей цепочке', Boards::reopenClosed((int)$board['id']) === 1
   && (int)Db::val("SELECT column_id FROM board_cards WHERE id=?", [$card2]) === $work);

// ===================================================================== 7
echo "7. Настройка, промпт и интерфейс\n";
ok('настройка MATCH_AUTO_LLM объявлена', isset(Settings::SPEC['MATCH_AUTO_LLM']) && Settings::SPEC['MATCH_AUTO_LLM'][4] === 1);
ok('промпт match_pick — в реестре и в задачах базы знаний', isset(Prompts::registry()['match_pick'])
   && isset(Knowledge::TASKS['match_pick']) && str_contains(Prompts::registry()['match_pick'][3], '{{knowledge}}'));
ok('API: статус и фоновое уточнение', str_contains($api, "case 'items_autopick':")
   && substr_count($api, 'Autopick::status($id)') >= 3);
ok('одна кнопка «↻ Подобрать заново», второй нет', str_contains($js, '↻ Подобрать заново')
   && !str_contains($js, '>Подобрать нейросетью<') && !str_contains($js, '>Подобрать по каталогу<'));
ok('карточка сама зовёт нейросеть фоном', str_contains($js, "items_autopick&id=") && str_contains($js, 'if (opts.autopick) this.autopick('));
ok('автосохранение ждёт ответа нейросети', str_contains($js, 'host._autosaveAfterPick = true;'));
ok('подсказка подбора на строке', str_contains($js, '${this.matchHintNote(i)}') && str_contains($css, '.match-row__hint'));
ok('у каждого тоста крестик', str_contains($js, 'this.toastClose(el, close);') && str_contains($js, "x.setAttribute('aria-label', 'Закрыть');"));
ok('и у отсчёта отправки, и у «Обновляем из МойСклад»', substr_count($js, 'this.toastClose(') >= 3
   && str_contains($js, "this.toastClose(wait);"));
ok('крестик оформлен', str_contains($css, '.toast__close {'));

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
