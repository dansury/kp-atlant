<?php
/**
 * Каталог моделей Yandex: список моделей берётся у облака, а не из кода.
 *
 *   — ответ Models API читается в любой из его форм, чужой каталог и
 *     не-gpt:// адреса отбрасываются;
 *   — модель, которой облако не назвало, помечается «нет в этом облаке»;
 *   — модель, которой нет в списке кандидатов, а в облаке есть, попадает
 *     в выбор;
 *   — список кандидатов — тот, что перечисляет каталог AI Studio.
 *
 * Запуск:  php tests/yandex_catalog.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-yxcat-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);

$savedConfig = $hadConfig ? file_get_contents($configPath) : null;
$existing = $hadConfig ? (array)(require $configPath) : [];
$effective = ['DB_PATH' => $tmpDb] + $existing;
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

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

LLM::init(['YANDEX_API_KEY' => 'k', 'YANDEX_FOLDER_ID' => 'b1folder', 'YANDEX_MODEL' => 'yandexgpt-5-lite',
           'LLM_PROVIDER_PRIORITY' => 'yandex']);

// =====================================================================  1

echo "\n== 1. Ответ Models API читается ==\n";

$answer = ['models' => [
    ['modelUri' => 'gpt://b1folder/yandexgpt-5-lite/latest'],
    ['modelUri' => 'gpt://b1folder/gemma-3-27b-it/latest'],
    ['modelUri' => 'gpt://b1other/yandexgpt-5-pro/latest'],     // чужой каталог
    ['modelUri' => 'art://b1folder/yandex-art-2.0/latest'],     // не текстовая модель
    ['id' => 'gpt-oss-120b'],
    'qwen3.6-35b-a3b',
    ['modelUri' => 'gpt://b1folder/yandexgpt-5-lite/latest'],   // повтор
]];
$slugs = LLM::yandexSlugsFrom($answer, 'b1folder');
ok('слаги этого каталога, без повторов и чужого',
   $slugs === ['yandexgpt-5-lite', 'gemma-3-27b-it', 'gpt-oss-120b', 'qwen3.6-35b-a3b'],
   implode(',', $slugs));
ok('форма {data:[…]} тоже читается',
   LLM::yandexSlugsFrom(['data' => [['id' => 'yandexgpt-5.1']]], 'b1folder') === ['yandexgpt-5.1']);
ok('не разобранный ответ — пустой список',
   LLM::yandexSlugsFrom(['что-то другое' => 1], 'b1folder') === []
   && LLM::yandexSlugsFrom('не json', 'b1folder') === []);

// =====================================================================  2

echo "\n== 2. Список облака решает, что показывать ==\n";

$cache = ['checked' => ['yandexgpt-5-lite' => 'ok', 'gemma-3-4b-it' => 'missing'],
          'models'  => [['id' => 'своя-дообученная', 'label' => 'Своя дообученная', 'group' => 'Yandex · каталог облака']],
          'synced_at' => date('Y-m-d H:i:s')];
Db::q("INSERT INTO settings (key, value) VALUES ('yandex_models', ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
      [json_encode($cache, JSON_UNESCAPED_UNICODE)]);

$rows = LLM::catalog('yandex');
$byId = array_column($rows, null, 'id');
ok('подтверждённая модель помечена как рабочая', ($byId['yandexgpt-5-lite']['state'] ?? '') === 'ok');
ok('модель, которой облако не назвало, зачёркнута',
   ($byId['gemma-3-4b-it']['state'] ?? '') === 'missing'
   && str_contains($byId['gemma-3-4b-it']['label'], 'нет в этом облаке'));
ok('непроверенная модель остаётся кандидатом', ($byId['gpt-oss-20b']['state'] ?? '') === 'unknown');
ok('модель из облака, которой нет в коде, попала в выбор',
   isset($byId['своя-дообученная']) && $byId['своя-дообученная']['group'] === 'Yandex · каталог облака');
ok('вычеркнутый слаг в запрос не уходит', !LLM::isKnown('yandex', 'gemma-3-4b-it'));
ok('подтверждённый — уходит', LLM::isKnown('yandex', 'yandexgpt-5-lite'));

LLM::forgetYandexModels();
ok('после «забыть» никто не осуждён', LLM::isKnown('yandex', 'gemma-3-4b-it'));

// =====================================================================  3

echo "\n== 3. Кандидаты — те, что перечисляет AI Studio ==\n";

$ids = array_column(LLM::CATALOG['yandex'], 'id');
foreach (['yandexgpt-5.1', 'yandexgpt-5-pro', 'yandexgpt-5-lite', 'aliceai-llm', 'aliceai-llm-flash',
          'deepseek-v4-flash', 'gpt-oss-120b', 'gpt-oss-20b', 'qwen3-235b-a22b-fp8', 'qwen3.6-35b-a3b',
          'llama-3.3-70b-instruct', 'gemma-3-27b-it'] as $slug) {
    ok("в списке есть {$slug}", in_array($slug, $ids, true));
}
foreach (['deepseek-r1', 'deepseek-v3', 'qwen3-30b-a3b', 'llama', 'llama-lite', 'yandexgpt-32k'] as $gone) {
    ok("снятого с раздачи {$gone} в списке нет", !in_array($gone, $ids, true));
}

echo "\n" . ($fail ? "ПРОВАЛЕНО: {$fail}\n" : "Всё зелено\n");
exit($fail ? 1 : 0);
