<?php
/**
 * QR codes for the КП (module 017).
 *
 * A КП carries a link to the product's page on atlant-armour.ru; on paper and
 * in a printed Word file a link is not clickable, so the same URL is also
 * printed as a QR code the client photographs. That picture has to survive
 * three renderers — the browser preview, mPDF and Word — and the only format
 * all three read is a base64 PNG inside the `src` of an `<img>`
 * (`Html2Docx::image()` decodes exactly that).
 *
 * Hence: no external service, no GD, no new composer package. Byte mode,
 * correction level M, versions 1..10 (213 bytes — an order of magnitude more
 * than a catalog URL needs), and a PNG written by hand from zlib. A QR that
 * cannot be built returns null and the КП simply prints the link alone —
 * nothing in the document may depend on this file succeeding.
 */
final class Qr {

    /** Error correction level M: 15% of the symbol may be lost and still read. */
    private const EC_BITS = 0b00;

    /**
     * [ec codewords per block, blocks in group 1, data codewords in each,
     *  blocks in group 2, data codewords in each] for level M, versions 1..10.
     */
    private const BLOCKS = [
        1  => [10, 1, 16, 0, 0],
        2  => [16, 1, 28, 0, 0],
        3  => [26, 1, 44, 0, 0],
        4  => [18, 2, 32, 0, 0],
        5  => [24, 2, 43, 0, 0],
        6  => [16, 4, 27, 0, 0],
        7  => [18, 4, 31, 0, 0],
        8  => [22, 2, 38, 2, 39],
        9  => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],
    ];

    /** Centres of the alignment patterns, per version. */
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Bits the symbol carries past the last codeword, per version. */
    private const REMAINDER = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
                               7 => 0, 8 => 0, 9 => 0, 10 => 0];

    private static array $exp = [];
    private static array $log = [];
    private static array $memo = [];

    /**
     * The QR as a `data:image/png;base64,…` URI, ready for the `src` of an
     * `<img>` in the КП template. Empty string when the text cannot be encoded —
     * the caller prints no picture and says nothing about it.
     */
    public static function dataUri(string $text, int $scale = 4, int $quiet = 2): string
    {
        $key = $text . '|' . $scale . '|' . $quiet;
        if (isset(self::$memo[$key])) return self::$memo[$key];

        $png = self::png($text, $scale, $quiet);
        $uri = $png === null ? '' : 'data:image/png;base64,' . base64_encode($png);

        // One КП repeats the same link in the card and nowhere else, but a
        // reprint of the whole document goes through here again
        if (count(self::$memo) > 64) self::$memo = [];
        return self::$memo[$key] = $uri;
    }

    /** Raw PNG bytes, or null when the text does not fit versions 1..10. */
    public static function png(string $text, int $scale = 4, int $quiet = 2): ?string
    {
        $matrix = self::matrix($text);
        if ($matrix === null) return null;
        return self::encodePng($matrix, max(1, $scale), max(0, $quiet));
    }

    /**
     * The symbol itself: a square of 0/1 rows, no quiet zone.
     * Public because that is what a test can assert on.
     */
    public static function matrix(string $text): ?array
    {
        $len = strlen($text);
        if ($len === 0) return null;

        $version = self::pickVersion($len);
        if ($version === null) return null;

        $codewords = self::codewords($text, $version);
        [$matrix, $function] = self::skeleton($version);
        self::placeData($matrix, $function, $codewords);

        // Every mask is a legal symbol; the one that scores lowest is the one
        // a phone camera reads fastest
        $best = null; $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::applyMask($matrix, $function, $mask);
            self::placeFormat($candidate, $function, $mask);
            $penalty = self::penalty($candidate);
            if ($penalty < $bestPenalty) { $bestPenalty = $penalty; $best = $candidate; }
        }
        return $best;
    }

    // ------------------------------------------------------------------ data

    private static function pickVersion(int $bytes): ?int
    {
        foreach (self::BLOCKS as $version => $spec) {
            [, $g1, $d1, $g2, $d2] = $spec;
            $capacity = $g1 * $d1 + $g2 * $d2;
            // 4 bits of mode + the character count + the data itself
            $countBits = $version < 10 ? 8 : 16;
            if (4 + $countBits + $bytes * 8 <= $capacity * 8) return $version;
        }
        return null;
    }

    /** Mode, length, payload, padding, Reed-Solomon, interleaving. */
    private static function codewords(string $text, int $version): array
    {
        [$ecPerBlock, $g1, $d1, $g2, $d2] = self::BLOCKS[$version];
        $total = $g1 * $d1 + $g2 * $d2;
        $countBits = $version < 10 ? 8 : 16;

        $bits = '0100' . str_pad(decbin(strlen($text)), $countBits, '0', STR_PAD_LEFT);
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }
        // Terminator, then up to a whole byte
        $bits .= str_repeat('0', min(4, $total * 8 - strlen($bits)));
        if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);

        $data = [];
        foreach (str_split($bits, 8) as $byte) $data[] = bindec($byte);
        // The standard's own filler, alternating, until the version is full
        $pad = [0xEC, 0x11]; $p = 0;
        while (count($data) < $total) $data[] = $pad[$p++ % 2];

        // Split into blocks, each with its own correction codewords
        $blocks = []; $ecBlocks = []; $offset = 0;
        foreach ([[$g1, $d1], [$g2, $d2]] as [$count, $size]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($data, $offset, $size);
                $offset += $size;
                $blocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
            }
        }

        // Interleaved: the first codeword of every block, then the second…
        $out = [];
        $longest = max(array_map('count', $blocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) if (isset($block[$i])) $out[] = $block[$i];
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) $out[] = $block[$i];
        }
        return $out;
    }

    // -------------------------------------------------------- Reed-Solomon

    private static function gf(): void
    {
        if (self::$exp) return;
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;   // the QR field's primitive polynomial
        }
        for ($i = 255; $i < 512; $i++) self::$exp[$i] = self::$exp[$i - 255];
    }

    private static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    private static function reedSolomon(array $data, int $count): array
    {
        self::gf();

        // The generator polynomial (x - a^0)(x - a^1)…, highest degree first
        $gen = [1];
        for ($i = 0; $i < $count; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                $next[$j]     ^= $c;
                $next[$j + 1] ^= self::mul($c, self::$exp[$i]);
            }
            $gen = $next;
        }

        $rest = array_merge($data, array_fill(0, $count, 0));
        for ($i = 0, $n = count($data); $i < $n; $i++) {
            $lead = $rest[$i];
            if ($lead === 0) continue;
            for ($j = 1; $j <= $count; $j++) {
                $rest[$i + $j] ^= self::mul($gen[$j], $lead);
            }
        }
        return array_slice($rest, count($data), $count);
    }

    // --------------------------------------------------------------- layout

    /** Finders, separators, timing, alignment, and the areas reserved for later. */
    private static function skeleton(int $version): array
    {
        $size = 17 + 4 * $version;
        $m = array_fill(0, $size, array_fill(0, $size, 0));
        $f = array_fill(0, $size, array_fill(0, $size, false));

        $finder = function (int $row, int $col) use (&$m, &$f, $size) {
            for ($dr = -1; $dr <= 7; $dr++) {
                for ($dc = -1; $dc <= 7; $dc++) {
                    $r = $row + $dr; $c = $col + $dc;
                    if ($r < 0 || $r >= $size || $c < 0 || $c >= $size) continue;
                    $on = ($dr >= 0 && $dr <= 6 && ($dc === 0 || $dc === 6))
                       || ($dc >= 0 && $dc <= 6 && ($dr === 0 || $dr === 6))
                       || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4);
                    $m[$r][$c] = $on ? 1 : 0;
                    $f[$r][$c] = true;
                }
            }
        };
        $finder(0, 0);
        $finder(0, $size - 7);
        $finder($size - 7, 0);

        // Timing: the alternating row and column that tell a reader the scale
        for ($i = 8; $i < $size - 8; $i++) {
            $m[6][$i] = $m[$i][6] = ($i % 2 === 0) ? 1 : 0;
            $f[6][$i] = $f[$i][6] = true;
        }

        $centres = self::ALIGN[$version];
        $last = count($centres) - 1;
        foreach ($centres as $ri => $row) {
            foreach ($centres as $ci => $col) {
                // The three corners already carry a finder
                if (($ri === 0 && $ci === 0) || ($ri === 0 && $ci === $last)
                    || ($ri === $last && $ci === 0)) continue;
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $m[$row + $dr][$col + $dc] =
                            (max(abs($dr), abs($dc)) !== 1) ? 1 : 0;
                        $f[$row + $dr][$col + $dc] = true;
                    }
                }
            }
        }

        // Format areas, reserved now and filled once the mask is chosen
        for ($i = 0; $i < 9; $i++) {
            if (!$f[8][$i]) { $f[8][$i] = true; $m[8][$i] = 0; }
            if (!$f[$i][8]) { $f[$i][8] = true; $m[$i][8] = 0; }
        }
        for ($i = 0; $i < 8; $i++) {
            $f[8][$size - 1 - $i] = true; $m[8][$size - 1 - $i] = 0;
            $f[$size - 1 - $i][8] = true; $m[$size - 1 - $i][8] = 0;
        }
        $m[$size - 8][8] = 1; $f[$size - 8][8] = true;   // the dark module

        // From version 7 the symbol states its own version, twice
        if ($version >= 7) {
            $bits = self::bch($version << 12, 0x1F25, 18);
            for ($i = 0; $i < 18; $i++) {
                $bit = ($bits >> $i) & 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $m[$b][$a] = $bit; $f[$b][$a] = true;
                $m[$a][$b] = $bit; $f[$a][$b] = true;
            }
        }
        return [$m, $f];
    }

    /** Two bits at a time, up the rightmost free column and down the next. */
    private static function placeData(array &$m, array $f, array $codewords): void
    {
        $size = count($m);
        $bitCount = count($codewords) * 8;
        $i = 0;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;          // the timing column is not data
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $col = $right - $j;
                    $upward = ((($right + 1) & 2) === 0);
                    $row = $upward ? $size - 1 - $vert : $vert;
                    if ($f[$row][$col] || $i >= $bitCount) continue;
                    $m[$row][$col] = ($codewords[$i >> 3] >> (7 - ($i & 7))) & 1;
                    $i++;
                }
            }
        }
    }

    private static function applyMask(array $m, array $f, int $mask): array
    {
        $size = count($m);
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($f[$r][$c]) continue;
                $flip = match ($mask) {
                    0 => ($r + $c) % 2 === 0,
                    1 => $r % 2 === 0,
                    2 => $c % 3 === 0,
                    3 => ($r + $c) % 3 === 0,
                    4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                    5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
                    6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
                    7 => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                };
                if ($flip) $m[$r][$c] ^= 1;
            }
        }
        return $m;
    }

    private static function placeFormat(array &$m, array $f, int $mask): void
    {
        $size = count($m);
        $bits = self::bch(((self::EC_BITS << 3) | $mask) << 10, 0x537, 15)
              ^ 0x5412;   // the standard's mask, so an all-zero format is not blank

        for ($i = 0; $i <= 5; $i++)  $m[$i][8] = ($bits >> $i) & 1;
        $m[7][8] = ($bits >> 6) & 1;
        $m[8][8] = ($bits >> 7) & 1;
        $m[8][7] = ($bits >> 8) & 1;
        for ($i = 9; $i < 15; $i++) $m[8][14 - $i] = ($bits >> $i) & 1;

        for ($i = 0; $i < 8; $i++)   $m[8][$size - 1 - $i] = ($bits >> $i) & 1;
        for ($i = 8; $i < 15; $i++)  $m[$size - 15 + $i][8] = ($bits >> $i) & 1;
        $m[$size - 8][8] = 1;
    }

    /** Bose-Chaudhuri-Hocquenghem remainder, for the format and version fields. */
    private static function bch(int $value, int $generator, int $width): int
    {
        $degree = $width === 15 ? 10 : 12;
        $rest = $value;
        for ($i = $width - 1; $i >= $degree; $i--) {
            if ($rest & (1 << $i)) $rest ^= $generator << ($i - $degree);
        }
        return $value | $rest;
    }

    // -------------------------------------------------------------- penalty

    /** The standard's four rules; the mask with the lowest total wins. */
    private static function penalty(array $m): int
    {
        $size = count($m);
        $score = 0;

        // 1. Runs of five or more of the same colour, in both directions
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1; $prev = -1;
                for ($b = 0; $b < $size; $b++) {
                    $v = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    if ($v === $prev) {
                        $run++;
                        if ($run === 5) $score += 3;
                        elseif ($run > 5) $score++;
                    } else { $prev = $v; $run = 1; }
                }
            }
        }

        // 2. Every 2x2 block of one colour
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }

        // 3. The finder-like pattern 1:1:3:1:1 with four light modules beside it
        $needles = ['10111010000', '00001011101'];
        for ($r = 0; $r < $size; $r++) {
            $row = implode('', $m[$r]);
            $col = '';
            for ($k = 0; $k < $size; $k++) $col .= $m[$k][$r];
            foreach ($needles as $needle) {
                $score += 40 * substr_count($row, $needle);
                $score += 40 * substr_count($col, $needle);
            }
        }

        // 4. How far the symbol is from half dark
        $dark = 0;
        foreach ($m as $row) $dark += array_sum($row);
        $ratio = (int)floor(abs($dark * 100 / ($size * $size) - 50) / 5);
        $score += $ratio * 10;

        return $score;
    }

    // ------------------------------------------------------------------ PNG

    /**
     * An 8-bit greyscale PNG written by hand. GD is not installed on every
     * shared host and a QR is two colours — zlib is all this needs.
     */
    private static function encodePng(array $m, int $scale, int $quiet): string
    {
        $size  = count($m);
        $width = ($size + $quiet * 2) * $scale;

        $blank = str_repeat("\xFF", $width);
        $raw = str_repeat("\x00" . $blank, $quiet * $scale);
        foreach ($m as $row) {
            $line = str_repeat("\xFF", $quiet * $scale);
            foreach ($row as $cell) {
                $line .= str_repeat($cell ? "\x00" : "\xFF", $scale);
            }
            $line .= str_repeat("\xFF", $quiet * $scale);
            $raw .= str_repeat("\x00" . $line, $scale);   // filter byte 0 per row
        }
        $raw .= str_repeat("\x00" . $blank, $quiet * $scale);

        $chunk = function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data
                 . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n"
             . $chunk('IHDR', pack('NNCCCCC', $width, $width, 8, 0, 0, 0, 0))
             . $chunk('IDAT', gzcompress($raw, 9))
             . $chunk('IEND', '');
    }
}
