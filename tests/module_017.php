<?php
/**
 * Module 017: the QR printed beside the link, and the catalog export the site
 * module answers with.
 *
 * Run:  php tests/module_017.php
 *
 * No network and no live database: the QR is checked against golden symbols
 * that were verified once with a real camera decoder (OpenCV), and the export
 * is checked through `Bitrix::parseExport()`, which is pure by design exactly
 * so that this file can exist.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-017-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);

// Same guard as the other suites: the override key must come FIRST, so a
// server whose config.php names the real DB_PATH cannot be written to here.
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
require_once ROOT . '/lib/qr.php';
require_once ROOT . '/lib/bitrix.php';
require_once ROOT . '/lib/kp_content.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

/** The symbol as one string of 0/1, for comparing against a golden value. */
function flat(array $matrix): string {
    $s = '';
    foreach ($matrix as $row) $s .= implode('', $row);
    return $s;
}

echo "\n1. QR-код: символ совпадает с эталоном\n";

// These two were generated once and read back with an actual QR decoder
// (OpenCV QRCodeDetector). If a change here moves a single module, the hash
// moves with it — which is the point: a symbol that no longer decodes is not
// something a unit test can notice on its own.
$golden = [
    'https://atlant-armour.ru/catalog/bronezhilet-strazh-5/'
        => [33, '81dc7491167a31f9adfcf39c627a13df0a18ed8d'],
    'A' => [21, '444d3cd9b5a3fc15d7b2816edba96777efb85194'],
];
foreach ($golden as $text => [$size, $hash]) {
    $m = Qr::matrix($text);
    ok('символ построен: ' . mb_substr($text, 0, 28), is_array($m));
    ok('размер ' . $size . ' модулей', is_array($m) && count($m) === $size,
       is_array($m) ? (string)count($m) : 'null');
    ok('модули не сдвинулись', is_array($m) && sha1(flat($m)) === $hash);
}

echo "\n2. QR-код: обязательные элементы на местах\n";
$m = Qr::matrix('https://atlant-armour.ru/catalog/shlem/');
$size = count($m);
$finderOk = true;
foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r, $c]) {
    // Центр искателя — залитый квадрат 3x3, рамка вокруг него светлая
    for ($dr = 0; $dr < 7; $dr++) for ($dc = 0; $dc < 7; $dc++) {
        $expect = ($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6
                   || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4)) ? 1 : 0;
        if ($m[$r + $dr][$c + $dc] !== $expect) $finderOk = false;
    }
}
ok('три искателя целые', $finderOk);
ok('тайминг чередуется', $m[6][8] === 1 && $m[6][9] === 0 && $m[6][10] === 1);
ok('тёмный модуль стоит', $m[$size - 8][8] === 1);
ok('символ квадратный', count($m[0]) === $size);

echo "\n3. QR-код: картинка, которую примут и mPDF, и Word\n";
$png = Qr::png('https://atlant-armour.ru/catalog/bronezhilet-strazh-5/', 4, 2);
ok('PNG получен', is_string($png) && $png !== '');
ok('это действительно PNG', is_string($png) && str_starts_with($png, "\x89PNG\r\n\x1a\n"));
$info = is_string($png) ? @getimagesizefromstring($png) : false;
// Html2Docx::image() читает размеры именно так — если здесь false, в Word
// картинка молча не попадёт
ok('размеры читаются getimagesizefromstring', is_array($info));
ok('сторона = (модули + поля) x масштаб', is_array($info) && $info[0] === (33 + 4) * 4,
   is_array($info) ? (string)$info[0] : '-');
ok('картинка квадратная', is_array($info) && $info[0] === $info[1]);
ok('mime — image/png', is_array($info) && $info['mime'] === 'image/png');

echo "\n4. QR-код: что не кодируется, не ломает документ\n";
ok('пустая строка — не картинка, а пустота', Qr::png('') === null);
ok('слишком длинный текст отдаёт null', Qr::png(str_repeat('x', 400)) === null);
ok('dataUri для пустой строки — пустая строка', Qr::dataUri('') === '');
ok('dataUri начинается как надо',
   str_starts_with(Qr::dataUri('https://atlant-armour.ru/'), 'data:image/png;base64,'));

echo "\n5. Карточка КП: QR рядом со ссылкой\n";
Settings::set('KP_QR_CODE', '1');
$item = ['site_url' => 'https://atlant-armour.ru/catalog/bronezhilet-strazh-5/'];
ok('есть ссылка — есть QR', str_starts_with(KpContent::itemQr($item), 'data:image/png;base64,'));
ok('нет ссылки — нет QR', KpContent::itemQr(['site_url' => '']) === '');
ok('нет поля вовсе — нет QR', KpContent::itemQr([]) === '');

Settings::set('KP_QR_CODE', '0');
ok('настройка выключена — QR не печатается', KpContent::itemQr($item) === '');
Settings::set('KP_QR_CODE', '1');

echo "\n6. Выгрузка каталога с сайта: разбор ответа модуля\n";
$body = json_encode([
    'ok' => true,
    'result' => [
        ['ID' => 10, 'NAME' => 'Бронежилет Страж-5', 'ARTICLE' => 'BZ-5',
         'CODE' => 'bronezhilet-strazh-5',
         'DETAIL_PAGE_URL' => 'https://atlant-armour.ru/catalog/bronezhilet-strazh-5/'],
        // строчные ключи — так отвечает вебхук, написанный руками
        ['name' => 'Шлем АШ-1', 'article' => 'SH-1', 'url' => '/catalog/shlem-ash-1/'],
        // без адреса это не ответ, а строка каталога: её пропускаем
        ['NAME' => 'Без страницы', 'ARTICLE' => 'NOPE', 'DETAIL_PAGE_URL' => ''],
    ],
    'total' => 3,
    'offset' => 0,
    'next' => 2,
], JSON_UNESCAPED_UNICODE);

Settings::set('BITRIX_SITE_URL', 'https://atlant-armour.ru');
$page = Bitrix::parseExport($body);
ok('разобрано две строки из трёх', count($page['items']) === 2, (string)count($page['items']));
ok('артикул прочитан', ($page['items'][0]['article'] ?? '') === 'BZ-5');
ok('адрес прочитан из DETAIL_PAGE_URL',
   ($page['items'][0]['url'] ?? '') === 'https://atlant-armour.ru/catalog/bronezhilet-strazh-5/');
ok('относительный адрес достроен до абсолютного',
   ($page['items'][1]['url'] ?? '') === 'https://atlant-armour.ru/catalog/shlem-ash-1/');
ok('строка без страницы не попала в выгрузку',
   !in_array('NOPE', array_column($page['items'], 'article'), true));
ok('следующее смещение прочитано', $page['next'] === 2);

$last = Bitrix::parseExport(json_encode(['ok' => true, 'result' => [], 'next' => null]));
ok('пустая страница — конец обхода', $last['items'] === [] && $last['next'] === null);
ok('не JSON — не падаем', Bitrix::parseExport('<html>502</html>')['items'] === []);
ok('JSON без result — не падаем', Bitrix::parseExport('{"ok":false}')['items'] === []);

echo "\n7. Выключенная связь с сайтом ничего не делает\n";
Settings::set('BITRIX_ENABLED', '0');
$sync = Bitrix::syncFromSite();
ok('обход не начинался', $sync['pages'] === 0 && $sync['updated'] === 0);
ok('и считается завершённым', $sync['done'] === true);

echo "\n" . ($fail ? "$fail FAILED\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
