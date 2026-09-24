<?php
/**
 * Модуль 053 — без сети и без Битрикса:
 *
 *   — atlant.kpsync.zip совпадает с исходниками модуля;
 *   — версия модуля одна: в репозитории, в ping и в карточке «Сайт (Битрикс)»;
 *   — «Откуда брать описание» — с подписями, в группе «Сайт (Битрикс)», работает;
 *   — экспорт в Excel есть в меню «Сервисы» и ставится установщиком.
 *
 * Запуск:  php tests/module_053.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-053-' . getmypid() . '.db';
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
require_once ROOT . '/lib/bitrix.php';
require_once ROOT . '/lib/kp_content.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

$mod = ROOT . '/bitrix-module/atlant.kpsync';

echo "Архив модуля\n";
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/tools/build_bitrix_zip.php') . ' --check 2>&1', $o, $rc);
ok('atlant.kpsync.zip совпадает с исходниками', $rc === 0, implode(' ', $o));
$z = new ZipArchive();
ok('в корне архива папка atlant.kpsync/', $z->open(Bitrix::moduleZip()) === true
    && $z->locateName('atlant.kpsync/install/index.php') !== false);
$z->close();

echo "Версия\n";
ok('версия в репозитории 1.2.0', Bitrix::bundledVersion() === '1.2.0', Bitrix::bundledVersion());
$config = file_get_contents($mod . '/lib/config.php');
ok('Config::version() читает install/version.php', str_contains($config, "install/version.php"));
ok('ping отдаёт версию', str_contains(file_get_contents($mod . '/lib/catalog.php'), "'version'       => Config::version()"));
ok('диагностика несёт версию репозитория', array_key_exists('bundled', Bitrix::diagnose()));
ok('настройки модуля показывают версию и папку', str_contains(file_get_contents($mod . '/options.php'), 'ATLANT_KPSYNC_VERSION'));

echo "Источник описания\n";
$spec = Settings::SPEC['KP_DESCRIPTION_SOURCE'];
ok('в группе «Сайт (Битрикс)»', $spec[0] === 'bitrix');
ok('варианты с подписями', str_contains($spec[2], 'moysklad_first=') && str_contains($spec[2], 'bitrix_first='));
ok('в подписях нет запятых (разделитель вариантов)', count(explode(',', substr($spec[2], 7))) === 2);
ok('по умолчанию МойСклад', KpContent::pickDescription('ms', 'site') === 'ms');
Settings::set('KP_DESCRIPTION_SOURCE', 'bitrix_first');
ok('bitrix_first — сайт первым', KpContent::pickDescription('ms', 'site') === 'site');
ok('bitrix_first — пустой сайт → МойСклад', KpContent::pickDescription('ms', '') === 'ms');
Settings::forget('KP_DESCRIPTION_SOURCE');

echo "Счётчики с сайта\n";
Db::q("INSERT INTO products_cache (moysklad_id, name, article, site_url, site_description) VALUES ('p53', 'Жилет', 'A-53', 'https://s/1', 'Описание')");
ok('ссылки с сайта считаются', (int)Db::val("SELECT COUNT(*) FROM products_cache WHERE site_url IS NOT NULL AND site_url<>''") === 1);
$api = file_get_contents(ROOT . '/public/api/products.php');
ok('stats отдаёт with_site_url и with_site_description', str_contains($api, "'with_site_url'") && str_contains($api, "'with_site_description'"));

echo "Интерфейс (по исходнику)\n";
$js = file_get_contents(ROOT . '/public/assets/js/app.js');
ok('select с подписями — общий помощник', str_contains($js, 'selectOptions(type, value)') && str_contains($js, 'this.selectOptions(it.type, it.value)'));
ok('карточка «Сайт (Битрикс)» в каталоге', str_contains($js, "id=\"bitrixCard\"") && str_contains($js, 'this.loadBitrixCard();'));
ok('выбор источника сохраняется сразу', str_contains($js, 'values: {KP_DESCRIPTION_SOURCE: sel.value}'));
ok('кнопка загрузки с сайта', str_contains($js, "admin.php?action=bitrix_sync_catalog"));
ok('кнопка проверки связи', str_contains($js, "admin.php?action=bitrix_diagnose"));
ok('ссылка на архив модуля', str_contains($js, 'api/admin.php?action=bitrix_module_zip'));
ok('архив отдаётся из admin.php', str_contains(file_get_contents(ROOT . '/public/api/admin.php'), "case 'bitrix_module_zip':"));

echo "Модуль Битрикс: экспорт в меню\n";
$menu = file_get_contents($mod . '/admin/menu.php');
ok('пункт в «Сервисах»', str_contains($menu, "'parent_menu' => 'global_menu_services'"));
ok('без страницы в /bitrix/admin ведёт в настройки', str_contains($menu, 'settings.php?mid=atlant.kpsync'));
ok('страница экспорта отдаёт файл по sessid', str_contains(file_get_contents($mod . '/admin/export.php'), "check_bitrix_sessid()"));
$inst = file_get_contents($mod . '/install/index.php');
ok('установщик копирует страницу в /bitrix/admin', str_contains($inst, "__DIR__ . '/admin', \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin'"));
ok('удаление убирает её', str_contains($inst, "DeleteDirFiles(__DIR__ . '/admin'"));
ok('заглушка ищет модуль в /local и /bitrix', str_contains(file_get_contents($mod . '/install/admin/atlant_kpsync_export.php'), '/local/modules/atlant.kpsync/admin/export.php'));
foreach (['admin/menu.php', 'admin/export.php'] as $f) {
    ok("есть перевод для $f", is_file($mod . '/lang/ru/' . $f));
}
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mod, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $lo, $lrc);
    if ($lrc !== 0) $bad[] = $f->getFilename();
}
ok('все файлы модуля без синтаксических ошибок', !$bad, implode(', ', $bad));

echo $fail ? "\nFAILED: $fail\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
