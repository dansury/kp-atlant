<?php
/**
 * Встроенный логотип КП (модуль 020).
 *
 * Знак рисуется кодом, а не лежит в репозитории картинкой из неизвестного
 * места: так видно, из чего он состоит, и так его можно перерисовать под
 * другой размер, не ища исходник. Это ЗАПАСНОЙ знак — настоящий файл
 * загружают в «Настройки → Реквизиты», и он побеждает этот.
 *
 * Запуск:  php tools/make_logo.php
 * Результат: public/assets/img/logo-default.png
 */
$scale  = 4;                       // рисуем крупно и уменьшаем — так чище края
$width  = 460 * $scale;
$height = 132 * $scale;

$img = imagecreatetruecolor($width, $height);
imagealphablending($img, false);
imagesavealpha($img, true);
imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
imagealphablending($img, true);
imageantialias($img, true);

$red   = imagecolorallocate($img, 0xB0, 0x12, 0x18);
$ink   = imagecolorallocate($img, 0x1B, 0x1B, 0x1B);
$paper = imagecolorallocate($img, 0xFF, 0xFF, 0xFF);

// Щит: плечи сверху, сходящиеся грани, острие снизу
$sx = 8 * $scale;
$sy = 8 * $scale;
$sw = 96 * $scale;
$sh = 116 * $scale;
$shield = [
    $sx,             $sy,
    $sx + $sw,       $sy,
    $sx + $sw,       $sy + (int)($sh * 0.52),
    $sx + (int)($sw * 0.5), $sy + $sh,
    $sx,             $sy + (int)($sh * 0.52),
];
imagefilledpolygon($img, $shield, $red);

// Шеврон внутри щита — две белые полосы под углом
$cw = (int)(11 * $scale);
foreach ([0.30, 0.52] as $at) {
    $y = $sy + (int)($sh * $at);
    imagefilledpolygon($img, [
        $sx + (int)($sw * 0.18), $y,
        $sx + (int)($sw * 0.50), $y + (int)($sh * 0.17),
        $sx + (int)($sw * 0.82), $y,
        $sx + (int)($sw * 0.82), $y + $cw,
        $sx + (int)($sw * 0.50), $y + (int)($sh * 0.17) + $cw,
        $sx + (int)($sw * 0.18), $y + $cw,
    ], $paper);
}

// Слово: ATLANT тёмным, ARMOUR красным, с разрядкой
$font = __DIR__ . '/../vendor/mpdf/mpdf/ttfonts/DejaVuSans-Bold.ttf';
if (!is_file($font)) { fwrite(STDERR, "нет шрифта: $font\n"); exit(1); }

$write = function (string $text, int $x, int $y, int $size, int $color, float $tracking) use ($img, $font) {
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $box = imagettftext($img, $size, 0, $x, $y, $color, $font, $ch);
        $x = $box[2] + (int)round($tracking);
    }
    return $x;
};

$textX = $sx + $sw + 26 * $scale;
$write('ATLANT', $textX, 58 * $scale, 33 * $scale, $ink, 3.4 * $scale);
$write('ARMOUR', $textX, 104 * $scale, 33 * $scale, $red, 3.4 * $scale);

// Уменьшение до рабочего размера: края текста и щита сглаживаются здесь
$out = imagecreatetruecolor((int)($width / $scale), (int)($height / $scale));
imagealphablending($out, false);
imagesavealpha($out, true);
imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
imagecopyresampled($out, $img, 0, 0, 0, 0,
                   (int)($width / $scale), (int)($height / $scale), $width, $height);

$path = __DIR__ . '/../public/assets/img/logo-default.png';
if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
imagepng($out, $path, 9);
echo "записано: $path (" . imagesx($out) . '×' . imagesy($out) . ", " . filesize($path) . " байт)\n";
