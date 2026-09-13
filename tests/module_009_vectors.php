<?php
/**
 * Vectors over the catalog and over the wiki (modules 009 + 005), against a
 * throwaway database and with NO network: the section index and its resume
 * cursor, the vector that survives a re-read of the wiki, the degradation to
 * words when Yandex is not configured, the masked secret, and the VAT the
 * catalog carries.
 *
 * Run:  php tests/module_009_vectors.php
 *
 * It builds its own database in the system temp directory — `data/kp.db` is
 * never opened, so running this on a server cannot touch live data.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-vec-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);

$savedConfig = $hadConfig ? file_get_contents($configPath) : null;
$existing = $hadConfig ? (array)(require $configPath) : [];
$effective = ['DB_PATH' => $tmpDb] + $existing;      // the override MUST come first
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
require_once ROOT . '/lib/embeddings.php';
require_once ROOT . '/lib/requisites.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

// Never call a model or the network in this test
Settings::set('YANDEX_API_KEY', '');
Settings::set('YANDEX_FOLDER_ID', '');
Settings::set('KNOWLEDGE_ENABLED', '1');

// ---------- the wiki ----------
echo "\n== База знаний: разделы и их хеш ==\n";

$docs = [
    ['GRAPH/wiki/delivery.md', "# Доставка\ntags: логистика\n\n## Сроки поставки\nОтгрузка со склада в Москве занимает один рабочий день. Позиции под заказ едут от производителя две недели.\n\n## Транспортные компании\nОтправляем СДЭК, ПЭК и Деловыми линиями. Забор груза бесплатный по Москве.\n"],
    ['GRAPH/wiki/warranty.md', "# Гарантия\n\n## Срок гарантии\nНа бронежилеты собственного производства гарантия пять лет со дня отгрузки. На шлемы — три года.\n"],
];
foreach ($docs as [$path, $content]) {
    Db::insert('knowledge_docs', [
        'path' => $path, 'title' => trim(explode("\n", $content)[0], "# "), 'tags' => '',
        'content' => $content, 'sha' => md5($content), 'size' => strlen($content),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

$n = Knowledge::reindex();
ok('reindex() разложил вики на разделы', $n >= 3, "разделов: $n");

$sections = Db::all("SELECT id, title, text_hash FROM knowledge_sections");
ok('у каждого раздела есть text_hash', count($sections) === $n
   && !array_filter($sections, fn($s) => strlen((string)$s['text_hash']) !== 32));

$hashBefore = array_column($sections, 'text_hash', 'title');

// ---------- the vector index ----------
echo "\n== Векторы базы знаний ==\n";

$stats = Embeddings::knowledgeStats();
ok('статистика считает разделы, а не документы', $stats['total'] === count(array_unique($hashBefore)),
   "total={$stats['total']}");
ok('без ключа Yandex векторизация выключена, но не сломана', $stats['enabled'] === false && $stats['indexed'] === 0);
ok('очередь = все разделы', $stats['pending'] === $stats['total'], "pending={$stats['pending']}");

// One vector, written the way the indexer writes it: float32, little-endian
$vec = array_map(fn($i) => sin($i / 7), range(0, 15));
$one = (string)array_values($hashBefore)[0];
$stmt = Db::pdo()->prepare("INSERT INTO knowledge_vectors (text_hash, model, dim, vec, updated_at) VALUES (?,?,?,?,?)");
$stmt->bindValue(1, $one);
$stmt->bindValue(2, (string)Settings::get('VECTOR_MODEL_DOC', 'text-search-doc'));
$stmt->bindValue(3, count($vec), PDO::PARAM_INT);
$stmt->bindValue(4, pack('g*', ...$vec), PDO::PARAM_LOB);
$stmt->bindValue(5, date('Y-m-d H:i:s'));
$stmt->execute();

$stats = Embeddings::knowledgeStats();
ok('векторизованный раздел уходит из очереди', $stats['indexed'] === 1 && $stats['pending'] === $stats['total'] - 1,
   "indexed={$stats['indexed']}, pending={$stats['pending']}");

// Re-reading the wiki rebuilds the sections with new ids — and must NOT cost
// the embeddings of sections whose text did not move. That is the whole reason
// a vector is keyed by the text hash and not by the section id.
Knowledge::reindex();
$hashAfter = array_column(Db::all("SELECT title, text_hash FROM knowledge_sections"), 'text_hash', 'title');
ok('хеши разделов стабильны между пересборками', $hashAfter == $hashBefore);
$stats = Embeddings::knowledgeStats();
ok('вектор пережил перечитывание вики', $stats['indexed'] === 1 && $stats['pending'] === $stats['total'] - 1,
   "indexed={$stats['indexed']}");

// A section whose text changed loses its vector — and only it
Db::q("UPDATE knowledge_docs SET content = content || '\nОтгрузка в субботу по договорённости.' WHERE path=?",
      ['GRAPH/wiki/delivery.md']);
Knowledge::reindex();
ok('изменённый раздел возвращается в очередь',
   Embeddings::knowledgeStats()['pending'] >= $stats['pending']);

// ---------- degradation ----------
echo "\n== Без Yandex всё работает, только хуже ==\n";

$report = Embeddings::indexKnowledge();
ok('шаг без ключа не падает, а объясняет', $report['indexed'] === 0 && $report['error'] !== null,
   (string)$report['error']);

$picked = Knowledge::search('какие сроки поставки со склада', 6000);
ok('подбор по словам работает без векторов', count($picked) > 0
   && str_contains(mb_strtolower($picked[0]['title']), 'сроки'),
   $picked ? $picked[0]['title'] : 'ничего не найдено');
ok('каждый раздел помечен источником', !array_filter($picked, fn($p) => ($p['source'] ?? '') !== 'text'));
ok('векторный поиск без ключа молчит', Embeddings::searchKnowledge('сроки поставки') === []);

// ---------- secrets on screen ----------
echo "\n== Как показывается секрет ==\n";

ok('видно первые и последние четыре знака',
   Settings::mask('github_pat_11ABCDEFGH_abcdefghijKLMNOP') === 'gith…MNOP',
   Settings::mask('github_pat_11ABCDEFGH_abcdefghijKLMNOP'));
ok('короткий секрет не показывается вовсе', Settings::mask('abc12') === '•••••');
ok('пустой секрет — пустая строка', Settings::mask('') === '');

Settings::set('MOYSKLAD_TOKEN', 'msk_012345678901234567890123456789');
$row = null;
foreach (Settings::describe() as $item) if ($item['key'] === 'MOYSKLAD_TOKEN') $row = $item;
ok('в панель уходит маска, а не токен', $row && $row['value'] === '' && $row['tail'] === 'msk_…6789',
   (string)($row['tail'] ?? ''));

// ---------- VAT the catalog carries ----------
echo "\n== НДС и реквизиты для «Оформления КП» ==\n";

foreach ([['v1', 5], ['v2', 5], ['v3', 20]] as [$id, $vat]) {
    Db::insert('products_cache', ['moysklad_id' => $id, 'name' => "Позиция $id", 'vat' => $vat, 'is_archived' => 0]);
}
$cv = Requisites::catalogVat();
ok('преобладающая ставка — из каталога МойСклад', $cv['rate'] === 5 && $cv['items'] === 3 && $cv['share'] === 67,
   json_encode($cv['rates'], JSON_UNESCAPED_UNICODE));

Settings::set('MOYSKLAD_ORG_ID', 'a1b2c3d4-0000-1111-2222-333344445555');
$kp = Requisites::kpDefaults();
ok('ID организации отдаётся целиком', $kp['org_id'] === 'a1b2c3d4-0000-1111-2222-333344445555');
ok('реквизиты продавца приходят из legal_entities', ($kp['seller']['inn'] ?? '') !== '');
ok('НДС организации известен', array_key_exists('pays_vat', $kp['seller']));

echo "\n" . ($fail ? "ПРОВАЛЕНО: $fail\n" : "Все проверки прошли\n");
exit($fail ? 1 : 0);
