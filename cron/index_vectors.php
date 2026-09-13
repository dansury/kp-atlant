<?php
/**
 * Cron: build the vector indexes (modules 009 and 005).
 * Usage: php cron/index_vectors.php [--budget=60] [--batch=20] [--once]
 *                                   [--only=catalog|knowledge]
 *
 * The run is made of resumable steps, so it never has to finish inside one PHP
 * process: each step embeds what has changed since the last one, stops at its
 * time budget and leaves the rest to the next call. `--once` does a single step
 * per index (that is what the panel button does); without it the script keeps
 * stepping until nothing is left or a step brings no progress.
 *
 * Both the catalog and the wiki are indexed by default — they are searched by
 * the same embeddings and go stale for the same reasons.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/embeddings.php';

$opt = static function (string $name, $default) use ($argv) {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) return substr($a, strlen($name) + 3);
    }
    return $default;
};

if (!Embeddings::enabled()) {
    echo "Векторизация выключена или не задан ключ Yandex — нечего делать\n";
    exit(0);
}

$budget = (int)$opt('budget', (int)Settings::get('VECTOR_BUDGET_SEC', 20));
$batch  = (int)$opt('batch', (int)Settings::get('VECTOR_BATCH', 20));
$once   = in_array('--once', $argv, true);
$only   = (string)$opt('only', '');

$targets = [
    'catalog'   => ['Каталог', fn(array $o) => Embeddings::indexCatalog($o)],
    'knowledge' => ['База знаний', fn(array $o) => Embeddings::indexKnowledge($o)],
];
if ($only !== '') {
    if (!isset($targets[$only])) {
        fwrite(STDERR, "неизвестный индекс: $only (catalog|knowledge)\n");
        exit(2);
    }
    $targets = [$only => $targets[$only]];
}
// The wiki is split into sections at sync time; with no sections there is
// nothing to embed, so build them first rather than reporting «готово»
if (isset($targets['knowledge']) && !(int)Db::val("SELECT COUNT(*) FROM knowledge_sections")) {
    Knowledge::reindex();
}

$failed = 0;
foreach ($targets as [$label, $step]) {
    echo "$label:\n";
    $total = 0;
    try {
        while (true) {
            $r = $step(['budget' => $budget, 'batch' => $batch]);
            $total += $r['indexed'];
            echo "  шаг: +{$r['indexed']}, неудач {$r['failed']}, осталось {$r['left']}, {$r['elapsed']} с\n";

            // A step that indexed nothing will not index anything next time
            // either — stopping beats looping on a broken API until the cron
            // slot runs out, and the reason is printed, not guessed
            if ($r['indexed'] === 0 && !$r['done']) {
                fwrite(STDERR, '  ' . ($r['error'] ?: "шаг без прогресса, осталось {$r['left']}") . "\n");
                $failed++;
                break;
            }
            if ($r['error']) fwrite(STDERR, "  часть не векторизована: {$r['error']}\n");
            if ($once || $r['done']) break;
        }
    } catch (Throwable $e) {
        Logger::exception('catalog', $e, ['source' => 'cron', 'index' => $label]);
        fwrite(STDERR, '  Ошибка векторизации: ' . $e->getMessage() . "\n");
        $failed++;
        continue;
    }
    echo "  готово: векторизовано $total\n";
}

exit($failed ? 1 : 0);
