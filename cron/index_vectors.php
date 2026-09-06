<?php
/**
 * Cron: build the catalog vector index (module 009).
 * Usage: php cron/index_vectors.php [--budget=60] [--batch=20] [--once]
 *
 * The run is made of resumable steps, so it never has to finish inside one PHP
 * process: each step embeds what has changed since the last one, stops at its
 * time budget and leaves the rest to the next call. `--once` does a single step
 * (that is what the panel button does); without it the script keeps stepping
 * until nothing is left or a step brings no progress.
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

$total = 0;
try {
    while (true) {
        $r = Embeddings::indexCatalog(['budget' => $budget, 'batch' => $batch]);
        if ($r['error']) {
            fwrite(STDERR, $r['error'] . "\n");
            exit(1);
        }
        $total += $r['indexed'];
        echo "шаг: +{$r['indexed']}, неудач {$r['failed']}, осталось {$r['left']}, {$r['elapsed']} с\n";

        if ($once || $r['done']) break;
        // A step that indexed nothing will not index anything next time either —
        // stopping beats looping on a broken API until the cron slot runs out
        if ($r['indexed'] === 0) {
            fwrite(STDERR, "шаг без прогресса — останавливаемся, осталось {$r['left']}\n");
            exit(1);
        }
    }
} catch (Throwable $e) {
    Logger::exception('catalog', $e, ['source' => 'cron']);
    fwrite(STDERR, 'Ошибка векторизации: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "готово: векторизовано $total позиций\n";
