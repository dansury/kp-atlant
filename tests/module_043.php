<?php
/**
 * Модуль 043 — на выбрасываемой базе и без сети:
 *
 *   — модель Yandex, которая отвечает только по OpenAI-совместимому маршруту,
 *     запоминается вместе с маршрутом и подписана в списке;
 *   — ответ модели разбирается, даже если в строке живой перевод строки или
 *     в байтах мусор; повторная попытка знает, чем кончилась предыдущая;
 *   — обрыв по пределу длины виден (`LLM::lastTruncated`), предел настраивается;
 *   — пауза перед повтором к МойСклад берётся из заголовков самого МойСклада;
 *   — событие вебхука, которое не прошло, остаётся в журнале и повторяется.
 *
 * Запуск:  php tests/module_043.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-043-' . getmypid() . '.db';
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
require_once ROOT . '/lib/sync.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

$llmSrc = file_get_contents(ROOT . '/lib/llm.php');

// =====================================================================  1
echo "\n1. Модель Yandex отвечает по OpenAI-совместимому маршруту\n";

LLM::init(['YANDEX_API_KEY' => 'test', 'YANDEX_FOLDER_ID' => 'b1test',
           'LLM_PROVIDER_PRIORITY' => 'yandex', 'YANDEX_MODEL' => 'gemma-3-27b-it']);

ok('непроверенный слаг идёт обычным маршрутом', LLM::yandexRoute('yandexgpt') === 'fm');

Db::q("INSERT INTO settings (key, value) VALUES ('yandex_models', ?)
       ON CONFLICT(key) DO UPDATE SET value=excluded.value",
      [json_encode(['checked' => ['gemma-3-27b-it' => 'ok'],
                    'routes'  => ['gemma-3-27b-it' => 'openai'],
                    'synced_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)]);

ok('выясненный маршрут запоминается', LLM::yandexRoute('gemma-3-27b-it') === 'openai');
ok('слаг с версией в кэше тот же', LLM::yandexRoute(' gemma-3-27b-it/') === 'openai');

$row = null;
foreach (LLM::catalog('yandex') as $m) if ($m['id'] === 'gemma-3-27b-it') $row = $m;
ok('в списке моделей маршрут подписан', $row !== null && str_contains($row['label'], 'OpenAI-совместимому'),
   $row['label'] ?? '—');
ok('и такая модель не считается отсутствующей', LLM::isKnown('yandex', 'gemma-3-27b-it'));

ok('400 «via gRPC» узнаётся и переводит запрос на другой адрес',
   str_contains($llmSrc, 'isOpenAiOnlyModel($e->getMessage())')
   && str_contains($llmSrc, "markYandexRoute(\$model, 'openai')"));
ok('у OpenAI-совместимого адреса свой URL',
   str_contains($llmSrc, "YX_URL_OPENAI = 'https://llm.api.cloud.yandex.net/v1/chat/completions'"));
ok('проба каталога засчитывает такую модель как рабочую',
   str_contains($llmSrc, "if (self::isOpenAiOnlyModel(\$e->getMessage())) {\n                    \$checked[\$slug] = 'ok';"));
ok('«Забыть проверку» стирает и маршруты', (function () {
    LLM::forgetYandexModels();
    return LLM::yandexRoute('gemma-3-27b-it') === 'fm';
})());

// =====================================================================  2
echo "\n2. Ответ модели разбирается, даже если модель сломала свой JSON\n";

ok('живой перевод строки внутри строки экранируется',
   LLM::decodeJson("{\"comment\":\"первая\nвторая\"}") === ['comment' => "первая\nвторая"]);
ok('табуляция внутри строки не мешает',
   LLM::decodeJson("{\"a\":\"один\tдва\"}") === ['a' => "один\tдва"]);
ok('битый байт не превращает ответ в «не JSON»',
   LLM::decodeJson("{\"a\":\"\xC3(\"}") === ['a' => '(']);
// Разбор модуля 034 по-прежнему на месте
ok('ограда ```json снимается', LLM::decodeJson('```json{"a":1}```') === ['a' => 1]);
ok('оборванный на полуслове ответ чинится',
   LLM::decodeJson('{"items":[{"name":"шлем","qty":2},{"name":"броне') === ['items' => [['name' => 'шлем', 'qty' => 2]]]);
ok('не-JSON так и остаётся не-JSON', LLM::decodeJson('совсем не json') === null);

// =====================================================================  3
echo "\n3. Повторная попытка знает, чем кончилась предыдущая\n";

ok('к системному промпту дописывается разбор неудачи',
   str_contains($llmSrc, '===== ПОВТОРНАЯ ПОПЫТКА ====='));
ok('следующая попытка уходит уже с этой припиской',
   str_contains($llmSrc, 'self::call($system . $hint, $user, $temp, true)'));
ok('обрыв по длине виден провайдеру и нам', !LLM::lastTruncated()
   && str_contains($llmSrc, "\$f === 'length'") && str_contains($llmSrc, "stripos(\$f, 'TRUNCATED')"));
ok('в журнал уходит и голова, и хвост ответа, и модель',
   str_contains($llmSrc, "'head'      => mb_substr(\$raw, 0, 400)")
   && str_contains($llmSrc, "'tail'      => mb_substr(\$raw, -400)")
   && str_contains($llmSrc, "'model'     => \$call['model'] ?? ''"));
ok('предел длины ответа — настройка, а не число в коде',
   isset(Settings::SPEC['LLM_MAX_TOKENS'])
   && !str_contains($llmSrc, "'maxTokens' => 4096")
   && str_contains($llmSrc, "self::\$cfg['LLM_MAX_TOKENS']"));
ok('поле предела есть на экране «Нейросети»',
   str_contains(file_get_contents(ROOT . '/public/assets/js/app.js'), 'id="set_LLM_MAX_TOKENS"'));

// =====================================================================  4
echo "\n4. МойСклад: пауза перед повтором берётся у самого МойСклада\n";

ok('X-RateLimit-Retry-After (мс) слушается',
   abs(MoySklad::retryPause(['x-ratelimit-retry-after' => '2500'], 1.0) - 2.5) < 0.001);
ok('Retry-After (сек) тоже', abs(MoySklad::retryPause(['retry-after' => '3'], 1.0) - 3.0) < 0.001);
ok('слишком длинная пауза обрезается',
   MoySklad::retryPause(['x-ratelimit-retry-after' => '600000'], 1.0) === 10.0);
$noHeader = MoySklad::retryPause([], 2.0);
ok('без заголовка — выдержка с разбросом', $noHeader >= 2.0 && $noHeader <= 2.25, (string)$noHeader);

$msSrc = file_get_contents(ROOT . '/lib/moysklad.php');
ok('попыток стало пять', str_contains($msSrc, 'private const RETRIES = 5'));
ok('не достучались (code 0) — тоже повод повторить',
   str_contains($msSrc, 'if (!($code === 0 || $code === 429 || $code >= 500)) return null;'));
ok('ошибка называет код и ответ API',
   str_contains($msSrc, 'attempts: $method $path"') && str_contains($msSrc, 'self::lastErrorSuffix()'));
ok('весь цикл ограничен по времени', str_contains($msSrc, 'RETRY_BUDGET_SEC = 45.0'));

// =====================================================================  5
echo "\n5. Событие вебхука, которое не прошло, повторяется\n";

foreach (['status', 'attempts', 'event_json'] as $col) {
    ok("в журнале вебхуков есть колонка $col",
       (bool)Db::val("SELECT COUNT(*) FROM pragma_table_info('webhook_log') WHERE name=?", [$col]));
}

$event = ['meta' => ['type' => 'demand', 'href' => 'https://api.moysklad.ru/entity/demand/abc-1'],
          'action' => 'CREATE'];
$result = MsSync::runWebhookEvent($event, json_encode(['events' => [$event]], JSON_UNESCAPED_UNICODE));
ok('событие записывается в журнал с разбором', str_contains($result, 'unsupported entity'));
$logged = Db::one("SELECT * FROM webhook_log ORDER BY id DESC LIMIT 1");
ok('и помечено как обработанное', ($logged['status'] ?? '') === 'ok' && (int)($logged['attempts'] ?? 0) === 1);
ok('событие сохранено целиком — есть что повторять',
   ($logged['event_json'] ?? '') !== '' && json_decode((string)$logged['event_json'], true)['action'] === 'CREATE');

// Упавшее событие ждёт крона и на второй попытке проходит
$failedId = Db::insert('webhook_log', [
    'entity_type' => 'demand', 'action' => 'CREATE', 'moysklad_id' => 'abc-2',
    'payload' => '{}', 'event_json' => json_encode($event, JSON_UNESCAPED_UNICODE),
    'status' => 'error', 'attempts' => 1,
    'result' => 'error: MoySklad request failed after 5 attempts (HTTP 429)',
]);
$res = MsSync::retryFailedWebhooks();
$after = Db::one("SELECT * FROM webhook_log WHERE id=?", [$failedId]);
ok('крон повторяет упавшее событие', $res['done'] === 1 && ($after['status'] ?? '') === 'ok');
ok('и считает попытки', (int)($after['attempts'] ?? 0) === 2);

$res2 = MsSync::retryFailedWebhooks();
ok('успешное второй раз не переигрывается', $res2['done'] === 0 && $res2['failed'] === 0);

Db::update('webhook_log', ['status' => 'error', 'attempts' => 5], 'id=?', [$failedId]);
ok('после пяти попыток событие оставляют в покое', MsSync::retryFailedWebhooks()['done'] === 0);

Db::insert('webhook_log', ['entity_type' => 'demand', 'action' => 'CREATE', 'moysklad_id' => 'abc-3',
                           'payload' => '{}', 'event_json' => 'не json', 'status' => 'error', 'attempts' => 1]);
MsSync::retryFailedWebhooks();
ok('испорченное событие не крутится вечно',
   Db::val("SELECT status FROM webhook_log WHERE moysklad_id='abc-3'") === 'skipped');

ok('приёмник вебхуков зовёт общий разбор',
   str_contains(file_get_contents(ROOT . '/public/api/moysklad_hook.php'), 'MsSync::runWebhookEvent($event, $raw)'));
ok('крон повторяет события каждые пять минут',
   str_contains(file_get_contents(ROOT . '/cron/sync_moysklad.php'), 'MsSync::retryFailedWebhooks()'));

echo "\n" . ($fail ? "ПРОВАЛЕНО: $fail\n" : "Все проверки прошли\n");
exit($fail ? 1 : 0);
