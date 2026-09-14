<?php
/**
 * PNG без альфа-канала — тот единственный вид картинки, который mPDF печатает
 * на любом хостинге (модуль 022).
 *
 * Логотип в КП не печатался, и молча: mPDF умеет прозрачный PNG только через
 * GD («GD library with PNG support required for image»), а при
 * `showImageErrors = false` просто выбрасывает картинку из документа. Фотография
 * товара (JPEG) и QR (серый PNG) проходили мимо этой ветки и печатались — знак
 * с прозрачным фоном не печатался никогда. Снаружи это выглядело как «логотип
 * загружен, а в КП его нет».
 *
 * Поэтому прозрачность снимается ДО mPDF: картинка кладётся на белый лист и
 * отдаётся как PNG truecolor без альфы. Через GD — если она есть; если её нет —
 * тем же чистым PHP, что и всё остальное на этом хостинге: PNG распаковывается
 * zlib, строки разфильтровываются, альфа смешивается с белым, и файл
 * собирается обратно фильтром 0.
 *
 * Возвращаем null, если перед нами не PNG или формат нам не по зубам
 * (16 бит на канал, чересстрочный Adam7) — тогда исходный файл уходит в mPDF
 * как был: хуже, чем было, не станет.
 */
final class Png {

    /** Белый — фон листа КП; на нём и сводится прозрачность. */
    private const BG = [255, 255, 255];

    /**
     * Картинка без альфа-канала, готовая для mPDF.
     * @return string|null PNG-байты или null, если трогать нечего/нечем
     */
    public static function flatten(string $data): ?string {
        if (!self::isPng($data)) return null;
        if (!self::hasAlpha($data)) return null;          // печатается и так

        $viaGd = self::flattenWithGd($data);
        if ($viaGd !== null) return $viaGd;

        return self::flattenPure($data);
    }

    public static function isPng(string $data): bool {
        return str_starts_with($data, "\x89PNG\r\n\x1a\n");
    }

    /**
     * Есть ли в этом PNG прозрачность: цветовой тип 4/6 — альфа-канал,
     * кусок `tRNS` — прозрачность у палитры или у одного цвета.
     */
    public static function hasAlpha(string $data): bool {
        $ihdr = self::ihdr($data);
        if ($ihdr === null) return false;
        if (in_array($ihdr['color_type'], [4, 6], true)) return true;
        return self::chunk($data, 'tRNS') !== null;
    }

    /** IHDR: ширина, высота, глубина, цветовой тип, чересстрочность. */
    public static function ihdr(string $data): ?array {
        $chunk = self::chunk($data, 'IHDR');
        if ($chunk === null || strlen($chunk) < 13) return null;
        $p = unpack('Nw/Nh/Cbits/Ctype/Ccomp/Cfilter/Cinterlace', $chunk);
        return [
            'width'      => (int)$p['w'],
            'height'     => (int)$p['h'],
            'bits'       => (int)$p['bits'],
            'color_type' => (int)$p['type'],
            'interlace'  => (int)$p['interlace'],
        ];
    }

    // ------------------------------------------------------------------ GD

    private static function flattenWithGd(string $data): ?string {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefromstring')) return null;
        $gd = function_exists('gd_info') ? gd_info() : [];
        if (empty($gd['PNG Support'])) return null;

        $src = @imagecreatefromstring($data);
        if (!$src) return null;

        $w = imagesx($src);
        $h = imagesy($src);
        $canvas = imagecreatetruecolor($w, $h);
        if (!$canvas) { imagedestroy($src); return null; }

        imagealphablending($canvas, false);
        imagefilledrectangle($canvas, 0, 0, $w, $h,
            imagecolorallocate($canvas, self::BG[0], self::BG[1], self::BG[2]));
        imagealphablending($canvas, true);
        imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, false);          // альфы в файле быть не должно

        ob_start();
        $ok = imagepng($canvas, null, 6);
        $out = (string)ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($src);
        return ($ok && $out !== '') ? $out : null;
    }

    // ------------------------------------------------------------ чистый PHP

    /**
     * То же самое без GD. Поддерживаем ровно то, чем бывает логотип:
     * 8 бит на канал, без чересстрочности — серый с альфой (4), RGBA (6) и
     * палитру с `tRNS` (3). Остальное честно отдаём как «не смогли».
     */
    private static function flattenPure(string $data): ?string {
        if (!function_exists('gzuncompress') || !function_exists('gzcompress')) return null;

        $ihdr = self::ihdr($data);
        if ($ihdr === null || $ihdr['bits'] !== 8 || $ihdr['interlace'] !== 0) return null;
        if (!in_array($ihdr['color_type'], [3, 4, 6], true)) return null;

        $channels = match ($ihdr['color_type']) {
            6 => 4,   // RGBA
            4 => 2,   // серый + альфа
            default => 1, // индекс в палитре
        };

        $raw = @gzuncompress(self::idat($data));
        if (!is_string($raw) || $raw === '') return null;

        $w = $ihdr['width'];
        $h = $ihdr['height'];
        $stride = $w * $channels;
        if (strlen($raw) < ($stride + 1) * $h) return null;

        // Палитра и её прозрачность — для цветового типа 3
        $palette = self::chunk($data, 'PLTE') ?? '';
        $trns    = self::chunk($data, 'tRNS') ?? '';
        if ($ihdr['color_type'] === 3 && $palette === '') return null;

        $out = '';                       // готовые строки RGB, каждая с фильтром 0
        $prev = str_repeat("\x00", $stride);
        $pos = 0;
        for ($y = 0; $y < $h; $y++) {
            $filter = ord($raw[$pos]);
            $pos++;
            $line = substr($raw, $pos, $stride);
            $pos += $stride;
            $line = self::unfilter($filter, $line, $prev, $channels);
            if ($line === null) return null;
            $prev = $line;

            $row = '';
            for ($x = 0; $x < $w; $x++) {
                $o = $x * $channels;
                [$r, $g, $b, $a] = match ($ihdr['color_type']) {
                    6 => [ord($line[$o]), ord($line[$o + 1]), ord($line[$o + 2]), ord($line[$o + 3])],
                    4 => [ord($line[$o]), ord($line[$o]), ord($line[$o]), ord($line[$o + 1])],
                    default => self::paletteColor(ord($line[$o]), $palette, $trns),
                };
                $row .= $a === 255
                    ? chr($r) . chr($g) . chr($b)
                    : chr(self::mix($r, $a, self::BG[0]))
                    . chr(self::mix($g, $a, self::BG[1]))
                    . chr(self::mix($b, $a, self::BG[2]));
            }
            $out .= "\x00" . $row;       // фильтр 0: строка как есть
        }

        return self::assemble($w, $h, $out);
    }

    /** Цвет пикселя палитры и его прозрачность из `tRNS`. */
    private static function paletteColor(int $index, string $palette, string $trns): array {
        $o = $index * 3;
        if ($o + 2 >= strlen($palette)) return [self::BG[0], self::BG[1], self::BG[2], 255];
        return [
            ord($palette[$o]), ord($palette[$o + 1]), ord($palette[$o + 2]),
            $index < strlen($trns) ? ord($trns[$index]) : 255,
        ];
    }

    /** Канал поверх фона: обычное альфа-смешивание, округление к ближайшему. */
    private static function mix(int $value, int $alpha, int $bg): int {
        return (int)round(($value * $alpha + $bg * (255 - $alpha)) / 255);
    }

    /**
     * Обратный фильтр строки PNG (спека, §9.2). `$bpp` — байт на пиксель:
     * фильтры Sub/Paeth смотрят на пиксель слева, а не на байт слева.
     */
    private static function unfilter(int $filter, string $line, string $prev, int $bpp): ?string {
        if ($filter === 0) return $line;
        $len = strlen($line);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $x = ord($line[$i]);
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $b = $i < strlen($prev) ? ord($prev[$i]) : 0;
            $c = ($i >= $bpp && $i - $bpp < strlen($prev)) ? ord($prev[$i - $bpp]) : 0;
            $value = match ($filter) {
                1 => $x + $a,
                2 => $x + $b,
                3 => $x + (int)(($a + $b) / 2),
                4 => $x + self::paeth($a, $b, $c),
                default => null,
            };
            if ($value === null) return null;
            $out .= chr($value & 0xFF);
        }
        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) return $a;
        return $pb <= $pc ? $b : $c;
    }

    /** Собрать PNG truecolor из готовых строк. */
    private static function assemble(int $w, int $h, string $rows): string {
        $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);   // 8 бит, RGB, без чересстрочности
        return "\x89PNG\r\n\x1a\n"
            . self::chunkOut('IHDR', $ihdr)
            . self::chunkOut('IDAT', (string)gzcompress($rows, 6))
            . self::chunkOut('IEND', '');
    }

    private static function chunkOut(string $type, string $body): string {
        return pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
    }

    /** Первый кусок с этим именем, или null. */
    private static function chunk(string $data, string $type): ?string {
        $p = 8;
        $len = strlen($data);
        while ($p + 8 <= $len) {
            $size = (int)unpack('N', substr($data, $p, 4))[1];
            $name = substr($data, $p + 4, 4);
            if ($name === $type) return substr($data, $p + 8, $size);
            if ($name === 'IEND') break;
            $p += 12 + $size;
        }
        return null;
    }

    /** IDAT может быть разбит на несколько кусков — их склеивают подряд. */
    private static function idat(string $data): string {
        $out = '';
        $p = 8;
        $len = strlen($data);
        while ($p + 8 <= $len) {
            $size = (int)unpack('N', substr($data, $p, 4))[1];
            $name = substr($data, $p + 4, 4);
            if ($name === 'IDAT') $out .= substr($data, $p + 8, $size);
            if ($name === 'IEND') break;
            $p += 12 + $size;
        }
        return $out;
    }
}
