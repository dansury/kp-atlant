<?php
/**
 * Issue #182: named models retain their revision through ranking and memory.
 * Run: php tests/issue_182.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-182-' . getmypid() . '.db';
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
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/alternatives.php';
require_once ROOT . '/lib/match_memory.php';
require_once ROOT . '/lib/mail_compose.php';
require_once ROOT . '/lib/cdek.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('MATCH_VECTOR_WEIGHT', 0);
function product182(string $id, string $name, array $extra = []): void {
    Db::insert('products_cache', $extra + ['moysklad_id' => $id, 'name' => $name,
        'product_type' => 'product', 'price' => 35000, 'stock' => 10, 'reserved' => 0]);
}
// Wrong revision first in catalog: the screenshot showed a false 97% exact hit.
product182('proton2', 'Баллистический шлем Протон-2 СВМПЭ');
product182('proton', 'Баллистический шлем Протон СВМПЭ');
product182('proton3', 'Баллистический шлем Протон-3 СВМПЭ');
product182('proton2-m', 'Баллистический шлем Протон-2 СВМПЭ (Размер: M(56-59); Цвет: Multicam)',
    ['product_type' => 'variant', 'parent_id' => 'proton2']);
product182('proton-l', 'Баллистический шлем Протон СВМПЭ (Размер: L(60-62); Цвет: Multicam)',
    ['product_type' => 'variant', 'parent_id' => 'proton']);
ProductMatcher::forgetCatalog();
$q = 'Баллистический шлем Протон СВМПЭ';
$ids = array_column(ProductMatcher::findCandidates($q, 20), 'moysklad_id');
ok('unnumbered model wins', ($ids[0] ?? '') === 'proton');
ok('numbered models and their variants excluded', !array_intersect($ids, ['proton2', 'proton3', 'proton2-m']));
ok('own size variant retained', in_array('proton-l', $ids, true));
$m = ProductMatcher::matchItems([['name' => $q, 'qty' => 4]], false)[0];
ok('automatic match is Proton, confirmed', ($m['match']['moysklad_id'] ?? '') === 'proton' && $m['is_confirmed']);
foreach (['-', '–', '—', '‑'] as $dash) {
    $ids = array_column(ProductMatcher::findCandidates("Баллистический шлем Протон{$dash}2 СВМПЭ", 20), 'moysklad_id');
    ok('revision 2 preserved with dash ' . $dash, ($ids[0] ?? '') === 'proton2' && !array_intersect($ids, ['proton', 'proton-l', 'proton3']));
}
ok('memory keys retain revision', MatchMemory::key($q) !== MatchMemory::key('Баллистический шлем Протон-2 СВМПЭ')
    && MatchMemory::key('Протон-2') !== MatchMemory::key('Протон-3'));
MatchMemory::remember($q, 'proton2', 'manual');
ok('historical wrong memory ignored', MatchMemory::recall($q) === null);
$m = ProductMatcher::matchItems([['name' => $q, 'qty' => 4]], false)[0];
ok('bad memory cannot override correct match', ($m['match']['moysklad_id'] ?? '') === 'proton');
MatchMemory::remember($q, 'proton', 'manual');
ok('valid memory retained', (MatchMemory::recall($q)['moysklad_id'] ?? '') === 'proton');
ok('size numbers are not model revisions', !ProductMatcher::modelConflict($q, $q . ' (Размер: M(56-59))'));
ok('protection class conflict still rejected', ProductMatcher::modelConflict('Плита Бр2', 'Плита Бр3'));
ok('parent without protection class remains usable', !ProductMatcher::modelConflict('Плита Бр3', 'Плита'));
Db::q("DELETE FROM products_cache WHERE moysklad_id IN ('proton', 'proton-l')");
ProductMatcher::forgetCatalog();
$m = ProductMatcher::matchItems([['name' => $q, 'qty' => 4]], false)[0];
ok('missing Proton is not replaced by Proton-2', $m['match'] === null);
echo $fail ? "FAIL: $fail\n" : "ALL OK\n";
exit($fail ? 1 : 0);
