<?php
/**
 * Модуль 054 — на выбрасываемой базе и без сети:
 *
 *   — доп. поле «СОТРУДНИК» строится по типу поля: строка, сотрудник, справочник;
 *   — сотрудник находится по ФИО в любом порядке и с инициалом;
 *   — количество в подборе — целое;
 *   — приложенный файл отдаётся только из папки своего менеджера;
 *   — интерфейс (по исходнику): кнопки под КП, чип со скачиванием, «+ Позиция»
 *     внизу, сворачивание строк, закреплённые «?», стрелки вкладок.
 *
 * Запуск:  php tests/module_054.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-054-' . getmypid() . '.db';
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
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/outbox.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');
$inv = file_get_contents(ROOT . '/public/api/invoices.php');
$ms  = file_get_contents(ROOT . '/lib/moysklad.php');

echo "Свёрнутый подбор\n";
ok('свёрнутый блок не прячет .card__keep', str_contains($css, '.card--folded > :not(.card__title):not(.card__keep) { display: none !important; }'));
ok('кнопки КП — .card__keep', (bool)preg_match('/class="flex flex--wrap card__keep"[^>]*>\s*<!--[^>]*-->\s*<span class="muted" data-match-saved><\/span>\s*<span data-kp-buttons/s', $js));
ok('счета под кнопками — .card__keep', str_contains($js, '<div data-kp-invoices class="muted card__keep"'));
ok('КП на телефоне — .card__keep', str_contains($js, '<div data-kp-slot class="card__keep"></div>'));

echo "Статус по имени\n";
$states = ['Новый' => 's1', 'Резерв' => 's2', 'Отгружен' => 's3'];
ok('точное имя', MoySklad::stateId($states, 'Резерв') === 's2');
ok('регистр и пробелы', MoySklad::stateId($states, '  резерв ') === 's2');
ok('нет такого — null', MoySklad::stateId($states, 'Архив') === null);
ok('пустое — null', MoySklad::stateId($states, '') === null);

echo "Склад\n";
$stores = [
    ['id' => 'a', 'name' => 'Архивный', 'archived' => true],
    ['id' => 'b', 'name' => 'Основной', 'archived' => false],
    ['id' => 'c', 'name' => 'Витрина', 'archived' => false],
];
ok('выбранный живой склад', MoySklad::pickStore($stores, 'c', []) === 'c');
ok('архивный не выбирается', MoySklad::pickStore($stores, 'a', []) === 'b');
ok('затем первый из складов остатков', MoySklad::pickStore($stores, '', ['x', 'c']) === 'c');
ok('затем первый неархивный', MoySklad::pickStore($stores, '', []) === 'b');
ok('складов нет — null', MoySklad::pickStore([], 'b', ['b']) === null);
ok('настройка склада есть', (Settings::SPEC['MS_ORDER_STORE'][2] ?? '') === 'store');

echo "Заказ и счёт (по исходнику)\n";
ok('позиции товара встают в резерв', str_contains($ms, "'reserve' => (\$storeId !== '' && \$meta['meta']['type'] !== 'service') ? \$p['quantity'] : null"));
ok('склад в заказе и счёте', substr_count($ms, "self::storeMeta(") >= 2);
ok('сотрудник (owner) в заказе и счёте', str_contains($ms, "postWithOwner('/entity/customerorder'") && str_contains($ms, "postWithOwner('/entity/invoiceout'"));
ok('склад передаётся из запроса', str_contains($inv, "MoySklad::defaultStoreId(trim((string)(\$_GET['store_id'] ?? '')))"));
ok('список складов для выбора', str_contains($inv, "case 'stores':"));
ok('клиент шлёт склад', str_contains($js, '&store_id=${encodeURIComponent(picked.store || \'\')}'));
ok('окно выбора склада', str_contains($js, 'data-pick-store') && str_contains($js, 'pickInvoiceTarget()'));

echo "Документы в письме\n";
// kpAttachFiles ушёл вместе с «Отправкой» на странице КП (модуль 060)
ok('kpAttach объявлен один раз', substr_count($js, '    async kpAttach(') === 1);
ok('у файла кнопка ✕ «Убрать из письма»', str_contains($js, 'onclick="App.removeFileChip(this)"'));
ok('тот же документ не дублируется', str_contains($js, 'addFileChip(composer, f)'));
ok('документ идёт в видимое письмо', str_contains($js, 'const composer = this.activeComposer();'));
$methods = [];
preg_match_all('/^    (?:async )?([a-zA-Z_]+)\(/m', $js, $m);
$dups = array_keys(array_filter(array_count_values($m[1]), fn($n) => $n > 1));
ok('в App нет одноимённых методов', !$dups, implode(', ', $dups));

echo "Печатная форма счёта\n";
ok('ссылка хранилища — без токена', str_contains($ms, "self::requestRawUrl('GET', \$url, null, \$ownHost)")
    && str_contains($ms, "\$auth ? self::headers(\$body !== null) : []"));
ok('причина отказа — в ответе 502', str_contains($inv, 'MoySklad::lastExportError()'));
ok('просмотр счёта без JSON в рамке', str_contains($js, "URL.createObjectURL(await res.blob())"));

echo "Подпись в поле письма\n";
ok('галочка правит поле', str_contains($js, 'this.insertSignature(box, sign)') && str_contains($js, 'this.removeSignature(box, sign)'));
ok('подпись после черновика, не наперегонки', str_contains($js, 'await this.syncSignature(c, restored);'));
ok('черновик нейросети отмечает галочку', str_contains($js, "await this.syncSignature(c, true);\n            this.composerChanged(key);"));

echo $fail ? "\nFAILED: $fail\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
