<?php
/**
 * Архив модуля сайта: bitrix-module/atlant.kpsync → bitrix-module/atlant.kpsync.zip
 * (папка atlant.kpsync/ в корне — распаковывается прямо в /local/modules/).
 *
 * Одинаковые исходники дают одинаковые байты: файлы по порядку, время фиксировано.
 *
 *   php tools/build_bitrix_zip.php           собрать
 *   php tools/build_bitrix_zip.php --check   exit 1, если архив отстал от исходников
 */
const MODULE = 'atlant.kpsync';
const MTIME  = 1767225600;   // 2026-01-01 00:00 UTC

$src = dirname(__DIR__) . '/bitrix-module/' . MODULE;
$zip = dirname(__DIR__) . '/bitrix-module/' . MODULE . '.zip';

/** @return array<string,string> путь в архиве → путь на диске */
function sources(string $src): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
        $out[MODULE . '/' . $rel] = $f->getPathname();
    }
    ksort($out, SORT_STRING);
    return $out;
}

$files = sources($src);

if (in_array('--check', $argv, true)) {
    $z = new ZipArchive();
    if ($z->open($zip) !== true) { fwrite(STDERR, "нет архива $zip\n"); exit(1); }
    $stale = [];
    $inZip = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = $z->getNameIndex($i);
        if (str_ends_with($name, '/')) continue;
        $inZip[$name] = true;
        if (!isset($files[$name])) $stale[] = "лишний: $name";
        elseif ($z->getFromIndex($i) !== file_get_contents($files[$name])) $stale[] = "изменён: $name";
    }
    foreach ($files as $name => $_) if (!isset($inZip[$name])) $stale[] = "нет в архиве: $name";
    $z->close();
    if ($stale) { fwrite(STDERR, implode("\n", $stale) . "\nпересоберите: php tools/build_bitrix_zip.php\n"); exit(1); }
    echo "архив актуален (" . count($files) . " файлов)\n";
    exit(0);
}

$tmp = $zip . '.tmp';
@unlink($tmp);
$z = new ZipArchive();
if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "не создать $tmp\n"); exit(1); }
// Папки — отдельными записями: так архив открывают и старые распаковщики
$dirs = [];
foreach ($files as $name => $_) {
    for ($d = dirname($name); $d !== '.' && !isset($dirs[$d]); $d = dirname($d)) $dirs[$d] = true;
}
ksort($dirs, SORT_STRING);
foreach (array_keys($dirs) as $d) {
    $z->addEmptyDir($d);
    $z->setMtimeName($d . '/', MTIME);
}
foreach ($files as $name => $path) {
    $z->addFromString($name, file_get_contents($path));
    $z->setMtimeName($name, MTIME);
    $z->setCompressionName($name, ZipArchive::CM_DEFLATE);
}
$z->close();
rename($tmp, $zip);
echo "собран $zip (" . count($files) . " файлов)\n";
