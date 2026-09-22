<?php
/**
 * Модуль 027 — у запроса несколько КП, позиции между ними перетаскиваются:
 *
 *   — «Сформировать КП» кладёт в него все позиции запроса, пул пустеет;
 *   — «+ Ещё одно КП» заводит пустое КП того же запроса;
 *   — позиция переезжает из КП в КП одним движением и НЕ задваивается;
 *   — выброшенная из КП позиция возвращается в список позиций запроса;
 *   — каждое КП печатает свои позиции и свой итог;
 *   — КП можно назвать своими словами и убрать, пока оно не ушло клиенту;
 *   — счёт помнит, по какому КП он выставлен, и счетов может быть несколько.
 *
 * Запуск:  php tests/module_027.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-027-' . getmypid() . '.db';
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
require_once ROOT . '/lib/kp_set.php';
require_once ROOT . '/lib/request_items.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('ALT_ENABLED', '0');
Settings::set('KP_SHOW_SITE_LINK', '0');

$mgr = Db::insert('managers', ['login' => 'yana', 'name' => 'Яна',
                               'password_hash' => 'x', 'is_admin' => 1]);
$cpId = Db::insert('counterparties', ['name' => 'ООО «Завод»', 'email_domain' => 'zavod.ru']);

// Каталог: три позиции, все в наличии
$catalog = [
    ['p-helmet', 'Шлем Протон', 30000, 10],
    ['p-vest',   'Бронежилет 6Б45', 45000, 4],
    ['p-amp',    'Наушники AMP', 12000, 25],
];
foreach ($catalog as [$id, $name, $price, $stock]) {
    Db::insert('products_cache', ['moysklad_id' => $id, 'name' => $name,
                                  'name_normalized' => mb_strtolower($name), 'article' => $id,
                                  'price' => $price, 'stock' => $stock, 'reserved' => 0,
                                  'unit' => 'шт.', 'product_type' => 'product']);
}

$requestId = Db::insert('requests', ['source' => 'email', 'counterparty_id' => $cpId,
                                     'raw_text' => 'Шлемы, бронежилеты, наушники',
                                     'email_from' => 'client@zavod.ru', 'status' => 'new']);
foreach ($catalog as $i => [$id, $name, $price, $stock]) {
    Db::insert('request_items', ['request_id' => $requestId, 'position' => $i + 1,
                                 'raw_name' => $name, 'product_name' => $name,
                                 'moysklad_product_id' => $id, 'unit' => 'шт.',
                                 'quantity' => $i + 1, 'price' => $price, 'stock' => $stock,
                                 'is_confirmed' => 1]);
}

/** Как это делает «Сформировать КП»: шапка + все позиции запроса. */
function buildKp(int $requestId, int $mgr, string $label = ''): int {
    $id = KpSet::create($requestId, $mgr, $label);
    foreach (RequestItems::toProposalItems(RequestItems::all($requestId)) as $i => $m) {
        Db::insert('proposal_items', KpSet::itemRow($m, $i + 1) + ['proposal_id' => $id]);
    }
    return $id;
}

// =====================================================================  1

echo "\n== 1. Первое КП забирает все позиции запроса ==\n";

$kp1 = buildKp($requestId, $mgr, 'Основное');
$board = KpSet::board($requestId);
ok('КП одно', count($board['proposals']) === 1, (string)count($board['proposals']));
ok('в нём все три позиции', count($board['proposals'][0]['items']) === 3);
ok('пул позиций пуст', $board['pool'] === [], json_encode(array_column($board['pool'], 'raw_name'), JSON_UNESCAPED_UNICODE));
ok('имя КП — то, которое дали', $board['proposals'][0]['title'] === 'Основное',
   $board['proposals'][0]['title']);
// 1×30000 + 2×45000 + 3×12000
ok('итог посчитан', abs($board['proposals'][0]['total'] - 156000) < 0.01,
   (string)$board['proposals'][0]['total']);

// =====================================================================  2

echo "\n== 2. «+ Ещё одно КП» ==\n";

$kp2 = KpSet::create($requestId, $mgr, 'Бронежилеты');
$board = KpSet::board($requestId);
ok('КП стало два', count($board['proposals']) === 2);
ok('второе пустое', $board['proposals'][1]['items'] === []);
ok('и его можно убрать', $board['proposals'][1]['can_delete'] === true);

// =====================================================================  3

echo "\n== 3. Позиция переезжает из КП в КП ==\n";

$vest = null;
foreach ($board['proposals'][0]['items'] as $it) {
    if ($it['product_name'] === 'Бронежилет 6Б45') $vest = $it;
}
ok('строка бронежилета нашлась', $vest !== null);

KpSet::moveItem((int)$vest['id'], $kp2);
$board = KpSet::board($requestId);
ok('в первом КП осталось две позиции', count($board['proposals'][0]['items']) === 2,
   (string)count($board['proposals'][0]['items']));
ok('во втором — одна', count($board['proposals'][1]['items']) === 1);
ok('и это бронежилет', $board['proposals'][1]['items'][0]['product_name'] === 'Бронежилет 6Б45');
ok('позиция не задвоилась',
   (int)Db::val("SELECT COUNT(*) FROM proposal_items WHERE product_name='Бронежилет 6Б45'") === 1);
ok('пул по-прежнему пуст', $board['pool'] === []);
ok('итог первого КП пересчитан', abs($board['proposals'][0]['total'] - 66000) < 0.01,
   (string)$board['proposals'][0]['total']);
ok('итог второго — тоже', abs($board['proposals'][1]['total'] - 90000) < 0.01,
   (string)$board['proposals'][1]['total']);
ok('нумерация в первом КП без дыр',
   array_column($board['proposals'][0]['items'], 'position') === [1, 2],
   json_encode(array_column($board['proposals'][0]['items'], 'position')));

// =====================================================================  4

echo "\n== 4. Выброшенная из КП позиция возвращается в запрос ==\n";

$amp = null;
foreach ($board['proposals'][0]['items'] as $it) {
    if ($it['product_name'] === 'Наушники AMP') $amp = $it;
}
KpSet::removeItem((int)$amp['id']);
$board = KpSet::board($requestId);
ok('в первом КП одна позиция', count($board['proposals'][0]['items']) === 1);
ok('наушники вернулись в пул', count($board['pool']) === 1 && $board['pool'][0]['raw_name'] === 'Наушники AMP',
   json_encode(array_column($board['pool'], 'raw_name'), JSON_UNESCAPED_UNICODE));

$poolId = (int)$board['pool'][0]['id'];
KpSet::addFromRequest($kp2, $poolId);
$board = KpSet::board($requestId);
ok('и уехали во второе КП', count($board['proposals'][1]['items']) === 2);
ok('пул снова пуст', $board['pool'] === []);

KpSet::addFromRequest($kp2, $poolId);
ok('повторное добавление не задваивает',
   (int)Db::val("SELECT COUNT(*) FROM proposal_items WHERE proposal_id=? AND request_item_id=?",
                [$kp2, $poolId]) === 1);

// =====================================================================  5

echo "\n== 5. Каждое КП печатает свои позиции ==\n";

Requisites::freeze($kp1);
Requisites::freeze($kp2);
$html1 = PdfGenerator::html($kp1);
$html2 = PdfGenerator::html($kp2);
ok('в первом КП — шлем', str_contains($html1, 'Шлем Протон'));
ok('и нет бронежилета', !str_contains($html1, '6Б45'));
ok('во втором — бронежилет и наушники',
   str_contains($html2, '6Б45') && str_contains($html2, 'Наушники AMP'));
ok('и нет шлема', !str_contains($html2, 'Шлем Протон'));
ok('итог первого КП в документе', str_contains($html1, 'Итого: 30 000 руб.'),
   (string)(preg_match('/Итого: [^<]+/u', $html1, $m) ? $m[0] : ''));
ok('итог второго — свой', str_contains($html2, 'Итого: 126 000 руб.'),
   (string)(preg_match('/Итого: [^<]+/u', $html2, $m) ? $m[0] : ''));

// =====================================================================  6

echo "\n== 6. Имя и удаление КП ==\n";

KpSet::rename($kp2, 'Вторая партия');
$board = KpSet::board($requestId);
ok('КП переименовано', $board['proposals'][1]['title'] === 'Вторая партия');

KpSet::rename($kp2, '');
$board = KpSet::board($requestId);
ok('пустое имя — зовём по номеру или счёту',
   str_starts_with($board['proposals'][1]['title'], 'КП '), $board['proposals'][1]['title']);

$kp3 = KpSet::create($requestId, $mgr);
KpSet::delete($kp3);
ok('пустое КП убирается', (int)Db::val("SELECT COUNT(*) FROM proposals WHERE id=?", [$kp3]) === 0);

Db::update('proposals', ['status' => 'sent'], 'id=?', [$kp1]);
$err = '';
try { KpSet::delete($kp1); } catch (Throwable $e) { $err = $e->getMessage(); }
ok('отправленное КП не удаляется', $err !== '', $err);
ok('и доска это показывает', KpSet::board($requestId)['proposals'][0]['can_delete'] === false);
Db::update('proposals', ['status' => 'draft'], 'id=?', [$kp1]);

// =====================================================================  7

echo "\n== 7. Счёт помнит своё КП, и счетов может быть несколько ==\n";

$inv1 = Db::insert('invoices', ['counterparty_id' => $cpId, 'proposal_id' => $kp2,
                                'moysklad_id' => 'ms-1', 'name' => '00001', 'sum' => 90000]);
$inv2 = Db::insert('invoices', ['counterparty_id' => $cpId, 'proposal_id' => $kp2,
                                'moysklad_id' => 'ms-2', 'name' => '00002', 'sum' => 36000]);
Db::insert('invoices', ['counterparty_id' => $cpId, 'proposal_id' => $kp1,
                        'moysklad_id' => 'ms-3', 'name' => '00003', 'sum' => 30000]);

$board = KpSet::board($requestId);
ok('у второго КП два счёта', count($board['proposals'][1]['invoices']) === 2,
   (string)count($board['proposals'][1]['invoices']));
ok('у первого — один', count($board['proposals'][0]['invoices']) === 1);
ok('счета не перемешались',
   array_column($board['proposals'][1]['invoices'], 'name') === ['00001', '00002'],
   json_encode(array_column($board['proposals'][1]['invoices'], 'name')));

$err = '';
try { KpSet::delete($kp2); } catch (Throwable $e) { $err = $e->getMessage(); }
ok('КП со счётом не удаляется', $err !== '', $err);
ok('и доска это показывает', $board['proposals'][1]['can_delete'] === false);

// =====================================================================

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
