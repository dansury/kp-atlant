<?php
/**
 * Логотипы, которые загружают через интерфейс (модуль 021).
 *
 * Три разных знака, потому что это три разных места:
 *   kp      — логотип в шапке коммерческого предложения (PDF и Word);
 *   app     — знак веб-приложения: иконка на телефоне и в шапке панели;
 *   favicon — значок вкладки браузера.
 *
 * Файл всегда ложится в `storage/logo/` — вне репозитория, потому что деплой
 * перезаписывает `public/assets/`, и загруженный знак пропадал бы при каждом
 * обновлении кода. Ничего не загрузили — отдаётся встроенный: КП без логотипа
 * читается как черновик, а вкладка без значка — как чужая страница.
 */
final class Branding {
    public const KINDS = ['kp', 'app', 'favicon'];
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'ico'];

    /**
     * Чьи ЗАГРУЖЕННЫЕ знаки подходят этому виду — до встроенного. Знак
     * приложения — это логотип компании: загрузили только логотип КП — он и
     * на иконке, и во вкладке. КП чужой квадратный знак не берёт.
     */
    public const FALLBACK = [
        'kp'      => ['kp'],
        'app'     => ['app', 'favicon', 'kp'],
        'favicon' => ['favicon', 'app', 'kp'],
    ];

    /** Подписи для интерфейса: что это и где видно. */
    public const LABELS = [
        'kp'      => ['Логотип в КП', 'Печатается слева вверху коммерческого предложения — в PDF и в Word. Лучше PNG с прозрачным фоном, шириной от 600 px.'],
        'app'     => ['Знак приложения', 'Иконка на домашнем экране телефона и знак в шапке панели. Квадратная картинка от 512×512.'],
        'favicon' => ['Значок вкладки', 'Показывается во вкладке браузера и в закладках. Квадратный PNG 32×32 или больше.'],
    ];

    public static function dir(): string {
        $dir = ROOT . '/storage/logo';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Загруженный файл этого вида — или null.
     * `kp` хранится под историческим именем `logo.*`: там уже лежат логотипы,
     * загруженные до этого модуля, и переименование стёрло бы их.
     */
    public static function uploaded(string $kind): ?string {
        $base = $kind === 'kp' ? 'logo' : $kind;
        foreach (glob(self::dir() . '/' . $base . '.*') ?: [] as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, self::EXTENSIONS, true) && is_file($path)) return $path;
        }
        return null;
    }

    /** Встроенные запасные знаки, по очереди — берётся первый существующий. */
    public static function bundled(string $kind): array {
        return match ($kind) {
            'kp' => [
                ROOT . '/public/assets/img/logo.png',
                ROOT . '/public/assets/img/logo.jpg',
                ROOT . '/public/assets/img/logo.svg',
                ROOT . '/public/assets/img/logo-default.png',
            ],
            'app' => [
                ROOT . '/public/assets/icons/icon-512.png',
                ROOT . '/public/assets/icons/icon-192.png',
            ],
            'favicon' => [
                ROOT . '/public/assets/icons/icon-192.png',
                ROOT . '/public/assets/icons/icon-512.png',
            ],
            default => [],
        };
    }

    /**
     * Знак в шапке панели (issue #107): загруженная картинка, а не рисованный
     * SVG. Квадратные знаки (приложение, вкладка) — рядом с надписью; логотип
     * КП — широкий, надпись в нём уже есть. '' — ничего не загружено.
     */
    public static function headerKind(): string {
        foreach (['app', 'favicon', 'kp'] as $kind) {
            if (self::uploaded($kind)) return $kind;
        }
        return '';
    }

    /** Какой загруженный вид отдаётся за этот, или '' — загруженного нет. */
    public static function source(string $kind): string {
        foreach (self::FALLBACK[$kind] ?? [$kind] as $from) {
            if (self::uploaded($from)) return $from;
        }
        return '';
    }

    /** Что реально будет показано: загруженное (своё или по FALLBACK), иначе встроенное. */
    public static function resolve(string $kind): string {
        $from = self::source($kind);
        if ($from !== '') return (string)self::uploaded($from);
        foreach (self::bundled($kind) as $path) {
            if (is_file($path)) return $path;
        }
        return '';
    }

    /**
     * Метка версии для адреса картинки. Браузер и PWA держат иконку в кэше
     * неделями — без неё загруженный знак появился бы у менеджера «когда-нибудь».
     */
    public static function version(string $kind): string {
        $path = self::resolve($kind);
        return $path !== '' ? (string)@filemtime($path) : '0';
    }

    /** Общая версия всех знаков — ею помечаются адреса в `<head>`. */
    public static function stamp(): string {
        $parts = array_map(fn($kind) => self::version($kind), self::KINDS);
        $parts[] = 'icon' . self::ICON_REV;   // иконка перерисована — адрес новый
        return substr(md5(implode('-', $parts)), 0, 8);
    }

    /**
     * Знак для ДОКУМЕНТА — data:URI, который mPDF и Word напечатают на любом
     * хостинге (модуль 022).
     *
     * mPDF рисует прозрачный PNG только через GD, а без неё выбрасывает
     * картинку молча (`showImageErrors` выключен): КП уходило клиенту без
     * логотипа, хотя знак был загружен и лежал на диске. Поэтому прозрачность
     * снимается здесь — один раз, с кэшем рядом с файлом, — и в документ
     * уходит PNG без альфы.
     *
     * Пустая строка означает «знака нет вовсе»; вызывающий это уже отличает от
     * «знак есть, но не читается».
     */
    public static function documentImage(string $kind = 'kp'): string {
        $path = self::resolve($kind);
        return $path === '' ? '' : self::fileAsDocumentImage($path);
    }

    /** Тот же знак, но из названного файла: путь из `legal_entities.logo_path`. */
    public static function fileAsDocumentImage(string $path): string {
        if ($path === '' || !is_file($path)) return '';

        $flat = self::flatCopy($path);
        $file = $flat !== '' ? $flat : $path;
        $bytes = @file_get_contents($file);
        if ($bytes === false || $bytes === '') return '';

        return 'data:' . self::mime($file) . ';base64,' . base64_encode($bytes);
    }

    /**
     * Копия файла без прозрачности, или '' — если снимать нечего или нечем.
     * Кэш живёт рядом с загруженным знаком и помечен временем файла: заменили
     * логотип — старая копия больше не подойдёт по имени.
     */
    private static function flatCopy(string $path): string {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== 'png') return '';       // JPEG и SVG mPDF печатает и без GD

        $cacheDir = self::dir() . '/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        $cache = $cacheDir . '/flat-' . md5($path) . '-' . (int)@filemtime($path) . '.png';
        if (is_file($cache)) return $cache;

        require_once __DIR__ . '/png.php';
        $data = (string)@file_get_contents($path);
        if ($data === '') return '';
        $flat = Png::flatten($data);
        // null — либо альфы нет (печатается как есть), либо формат нам не по зубам
        if ($flat === null) return '';
        return @file_put_contents($cache, $flat) !== false ? $cache : '';
    }

    public static function mime(string $path): string {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico'  => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }

    /**
     * Сохранить загруженный файл. `$opts['move'] = false` — копировать, а не
     * `move_uploaded_file()`: так этим пользуются тесты и CLI.
     * @return array{path:string,kind:string}
     */
    public static function store(string $kind, array $file, array $opts = []): array {
        if (!in_array($kind, self::KINDS, true)) throw new RuntimeException('Неизвестный вид логотипа');

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = $kind === 'favicon' ? self::EXTENSIONS : ['png', 'jpg', 'jpeg', 'svg', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            throw new RuntimeException('Поддерживаются файлы: ' . implode(', ', $allowed));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('Файл не загрузился');

        // Картинка, а не переименованный PDF: `<img>` с чужим содержимым — это
        // битая шапка в подписанном документе
        if ($ext !== 'svg' && @getimagesize($tmp) === false) throw new RuntimeException('Это не картинка');
        if ($ext === 'svg' && preg_match('/<\s*script|javascript:/i', (string)file_get_contents($tmp))) {
            throw new RuntimeException('В SVG есть скрипт — такой файл не принимаем');
        }

        $base = $kind === 'kp' ? 'logo' : $kind;
        foreach (glob(self::dir() . '/' . $base . '.*') ?: [] as $old) @unlink($old);
        $dest = self::dir() . '/' . $base . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);

        $moved = ($opts['move'] ?? true) ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        if (!$moved && !@copy($tmp, $dest)) throw new RuntimeException('Файл не сохранился в storage/logo');
        @chmod($dest, 0644);
        self::clearCache();

        // КП читает логотип через `legal_entities.logo_path` — путь должен
        // указывать на новый файл, иначе документ печатает предыдущий
        if ($kind === 'kp') Db::q("UPDATE legal_entities SET logo_path=? WHERE is_active=1", [$dest]);

        Logger::info('settings', 'Загружен логотип: ' . $kind, ['path' => $dest]);
        return ['path' => $dest, 'kind' => $kind];
    }

    /** Убрать загруженный знак и вернуться к встроенному. */
    public static function remove(string $kind): void {
        $base = $kind === 'kp' ? 'logo' : $kind;
        foreach (glob(self::dir() . '/' . $base . '.*') ?: [] as $path) @unlink($path);
        if ($kind === 'kp') Db::q("UPDATE legal_entities SET logo_path='' WHERE is_active=1");
        self::clearCache();
        Logger::info('settings', 'Логотип сброшен к встроенному: ' . $kind);
    }

    /** Меняется вместе с правилами рисования иконки: старый кэш с рамкой не отдаётся. */
    public const ICON_REV = 2;

    /**
     * Квадратная иконка нужного размера для манифеста PWA (модуль 021).
     *
     * Иконка НЕПРОЗРАЧНАЯ и без рамки: прозрачное лаунчер закрашивает своей
     * белой подложкой, и любой вписанный знак приезжал на телефон в белой
     * рамке. Поэтому: срезаются собственные однотонные поля картинки,
     * квадрат заливается цветом её края, знак — во весь квадрат (`any`) или
     * в безопасную зону 80% (`maskable`). Кэш — в `storage/logo/cache/`.
     * Без GD отдаётся исходный файл (панель предупреждает: `iconWarning()`).
     */
    public static function icon(int $size, bool $maskable = false): string {
        $source = self::resolve('app');
        $size = max(16, min(1024, $size));
        if ($source === '' || !function_exists('imagecreatetruecolor')) return $source;
        if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'svg') return $source;

        $cacheDir = self::dir() . '/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        $cache = $cacheDir . '/icon' . self::ICON_REV . '-' . $size . ($maskable ? '-m' : '') . '-' . self::version('app') . '.png';
        if (is_file($cache)) return $cache;

        $src = @imagecreatefromstring((string)file_get_contents($source));
        if (!$src) return $source;
        if (!imageistruecolor($src)) imagepalettetotruecolor($src);

        [$bx, $by, $bw, $bh, $cut, $clear] = self::trimBox($src);
        [$edge, $share] = self::edgeColor($src, $bx, $by, $bw, $bh);

        // Однотонный край (плашка, фон) — им и заливаем, знак во весь квадрат.
        // Прозрачное поле — автор фона не хотел: белая заливка с воздухом.
        // Край режет рисунок (срезали поле вокруг логотипа) — заливаем цветом
        // срезанного поля, воздух того же цвета
        if (!$clear && $edge !== null && $share >= 0.6) {
            $fill = $edge;
            // Край того же цвета, что срезанное поле, — это воздух вокруг знака,
            // а не плашка: возвращаем его, того же цвета
            $scale = ($cut !== null && self::near([...$edge, 0], [...$cut, 0], 40)) ? 0.88 : 1.0;
        } else {
            $fill = $cut ?? [255, 255, 255];
            $scale = 0.88;
        }
        if ($maskable) $scale = min($scale, 0.8);

        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $fill[0], $fill[1], $fill[2]));
        imagealphablending($canvas, true);   // прозрачное в знаке ложится на заливку

        $ratio = min($size * $scale / $bw, $size * $scale / $bh);
        $dw = max(1, (int)round($bw * $ratio));
        $dh = max(1, (int)round($bh * $ratio));
        imagecopyresampled($canvas, $src, (int)(($size - $dw) / 2), (int)(($size - $dh) / 2), $bx, $by, $dw, $dh, $bw, $bh);

        imagepng($canvas, $cache);
        unset($canvas, $src);   // imagedestroy() deprecated с PHP 8.5 и бесполезен с 8.0
        return is_file($cache) ? $cache : $source;
    }

    /** Пиксель как [r, g, b, a]; a — GD-шные 0 (непрозрачно) … 127 (прозрачно). */
    private static function px(\GdImage $im, int $x, int $y): array {
        $c = imagecolorat($im, $x, $y);
        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, ($c >> 24) & 0x7F];
    }

    /** Один цвет с поправкой на шум JPEG; все почти прозрачные — один цвет. */
    private static function near(array $p, array $q, int $tol = 28): bool {
        if ($p[3] >= 120 && $q[3] >= 120) return true;
        return abs($p[0] - $q[0]) <= $tol && abs($p[1] - $q[1]) <= $tol
            && abs($p[2] - $q[2]) <= $tol && abs($p[3] - $q[3]) <= 20;
    }

    /**
     * Рамка картинки без её собственных однотонных полей — полосы скриншота,
     * пустое поле вокруг логотипа. Один слой: цвет угла, строки и столбцы,
     * целиком (98% точек) этого цвета.
     * @return array{0:int,1:int,2:int,3:int,4:?array,5:bool} x, y, w, h,
     *         срезанный цвет [r,g,b] (null — ничего не срезано или срезана
     *         прозрачность) и «поле было прозрачным»
     */
    public static function trimBox(\GdImage $im): array {
        $w = imagesx($im);
        $h = imagesy($im);
        $ref = self::px($im, 0, 0);
        $line = function (bool $row, int $i, int $from, int $to) use ($im, $ref): bool {
            $step = max(1, intdiv($to - $from, 160));
            $n = $ok = 0;
            for ($j = $from; $j < $to; $j += $step) {
                $n++;
                if (self::near($row ? self::px($im, $j, $i) : self::px($im, $i, $j), $ref)) $ok++;
            }
            return $n > 0 && $ok / $n >= 0.98;
        };

        $top = 0;
        while ($top < $h - 1 && $line(true, $top, 0, $w)) $top++;
        $bottom = $h - 1;
        while ($bottom > $top && $line(true, $bottom, 0, $w)) $bottom--;
        $left = 0;
        while ($left < $w - 1 && $line(false, $left, $top, $bottom + 1)) $left++;
        $right = $w - 1;
        while ($right > $left && $line(false, $right, $top, $bottom + 1)) $right--;

        // Картинка одного цвета целиком — резать нечего
        if ($right - $left < 2 || $bottom - $top < 2) return [0, 0, $w, $h, null, false];
        $trimmed = $top > 0 || $left > 0 || $bottom < $h - 1 || $right < $w - 1;
        $clear = $trimmed && $ref[3] >= 120;
        $cut = ($trimmed && !$clear) ? [$ref[0], $ref[1], $ref[2]] : null;
        return [$left, $top, $right - $left + 1, $bottom - $top + 1, $cut, $clear];
    }

    /**
     * Самый частый цвет по краю рамки и его доля: [[r,g,b]|null, share].
     * null — край прозрачный.
     */
    public static function edgeColor(\GdImage $im, int $x, int $y, int $w, int $h): array {
        $pts = [];
        $step = max(1, intdiv(max($w, $h), 200));
        for ($i = 0; $i < $w; $i += $step) { $pts[] = [$x + $i, $y]; $pts[] = [$x + $i, $y + $h - 1]; }
        for ($j = 0; $j < $h; $j += $step) { $pts[] = [$x, $y + $j]; $pts[] = [$x + $w - 1, $y + $j]; }

        $buckets = [];
        foreach ($pts as [$px, $py]) {
            $p = self::px($im, $px, $py);
            $key = $p[3] >= 120 ? 'clear' : (($p[0] >> 4) . '.' . ($p[1] >> 4) . '.' . ($p[2] >> 4));
            $buckets[$key][] = $p;
        }
        uasort($buckets, fn($a, $b) => count($b) <=> count($a));
        $key = array_key_first($buckets);
        $share = count($buckets[$key]) / max(1, count($pts));
        if ($key === 'clear') return [null, $share];

        // Среднее по корзине — ровный цвет, а не её угол
        $sum = [0, 0, 0];
        foreach ($buckets[$key] as $p) { $sum[0] += $p[0]; $sum[1] += $p[1]; $sum[2] += $p[2]; }
        $n = count($buckets[$key]);
        return [[intdiv($sum[0], $n), intdiv($sum[1], $n), intdiv($sum[2], $n)], $share];
    }

    /** Почему иконка приложения может прийти с рамкой. '' — всё хорошо. */
    public static function iconWarning(): string {
        if (function_exists('imagecreatetruecolor')) return '';
        return 'На сервере нет расширения GD: иконка приложения отдаётся как есть, без подгонки под квадрат — '
             . 'телефон может показать её в белой рамке. Загрузите квадратный непрозрачный PNG или JPG.';
    }

    private static function clearCache(): void {
        foreach (glob(self::dir() . '/cache/*.png') ?: [] as $file) @unlink($file);
    }

    /**
     * Почему знак этого вида может не напечататься. Пустая строка — всё хорошо.
     * Панель показывает это рядом с загрузкой, чтобы «логотип не вставляется»
     * было видно ДО того, как КП уйдёт клиенту.
     */
    public static function documentWarning(string $kind = 'kp'): string {
        $path = self::resolve($kind);
        if ($path === '') return 'Знак не найден: ни загруженного, ни встроенного файла нет.';

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== 'png') return '';

        require_once __DIR__ . '/png.php';
        $data = (string)@file_get_contents($path);
        if ($data === '') return 'Файл знака не читается с диска.';
        if (!Png::isPng($data) || !Png::hasAlpha($data)) return '';
        if (self::flatCopy($path) !== '') return '';

        return 'PNG с прозрачным фоном, и снять её не удалось: в КП знак может не напечататься. '
             . 'Загрузите PNG без прозрачности или JPG.';
    }

    /** Что панели нужно, чтобы заменить знаки в шапке и `<head>` без перезагрузки. */
    public static function live(): array {
        return ['stamp' => self::stamp(), 'header' => self::headerKind()];
    }

    /** Состояние для панели: что загружено, что встроено, каким адресом отдаётся. */
    public static function describe(): array {
        $out = [];
        foreach (self::KINDS as $kind) {
            $uploaded = self::uploaded($kind);
            $resolved = self::resolve($kind);
            $from = self::source($kind);
            $out[] = [
                'kind'     => $kind,
                'title'    => self::LABELS[$kind][0] ?? $kind,
                'hint'     => self::LABELS[$kind][1] ?? '',
                'uploaded' => $uploaded !== null,
                // Заимствован у другого загруженного вида — не «встроенный»
                'from'     => ($from !== '' && $from !== $kind) ? (self::LABELS[$from][0] ?? $from) : '',
                'filename' => basename($resolved),
                'size'     => $resolved !== '' ? (int)@filesize($resolved) : 0,
                'url'      => 'api/branding.php?kind=' . $kind . '&v=' . self::version($kind),
            ];
        }
        return $out;
    }
}
