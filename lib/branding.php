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

    /** Что реально будет показано: загруженное, иначе встроенное. */
    public static function resolve(string $kind): string {
        $uploaded = self::uploaded($kind);
        if ($uploaded) return $uploaded;
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

    /**
     * Квадратная иконка нужного размера для манифеста PWA.
     *
     * Логотип магазина — широкий, а иконка на телефоне квадратная: картинка
     * вписывается в квадрат целиком (никогда не обрезается) и ставится на
     * фон манифеста. Результат кладётся рядом, в `storage/logo/cache/`, —
     * пересчитывать его на каждый запрос значка вкладки незачем.
     * Без расширения GD отдаётся исходный файл: браузер отмасштабирует сам.
     */
    public static function icon(int $size, bool $maskable = false): string {
        $source = self::resolve('app');
        $size = max(16, min(1024, $size));
        if ($source === '' || !function_exists('imagecreatetruecolor')) return $source;
        if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'svg') return $source;

        $cacheDir = self::dir() . '/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        $cache = $cacheDir . '/icon-' . $size . ($maskable ? '-m' : '') . '-' . self::version('app') . '.png';
        if (is_file($cache)) return $cache;

        $src = @imagecreatefromstring((string)file_get_contents($source));
        if (!$src) return $source;

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        // Maskable: система обрезает иконку по своей маске, поэтому знак живёт
        // в безопасной зоне — 80% квадрата, остальное поля
        $scale = $maskable ? 0.8 : 0.92;
        $sw = imagesx($src);
        $sh = imagesy($src);
        $ratio = min($size * $scale / $sw, $size * $scale / $sh);
        $dw = max(1, (int)round($sw * $ratio));
        $dh = max(1, (int)round($sh * $ratio));
        imagecopyresampled($canvas, $src, (int)(($size - $dw) / 2), (int)(($size - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);

        imagepng($canvas, $cache);
        imagedestroy($canvas);
        imagedestroy($src);
        return is_file($cache) ? $cache : $source;
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

    /** Состояние для панели: что загружено, что встроено, каким адресом отдаётся. */
    public static function describe(): array {
        $out = [];
        foreach (self::KINDS as $kind) {
            $uploaded = self::uploaded($kind);
            $resolved = self::resolve($kind);
            $out[] = [
                'kind'     => $kind,
                'title'    => self::LABELS[$kind][0] ?? $kind,
                'hint'     => self::LABELS[$kind][1] ?? '',
                'uploaded' => $uploaded !== null,
                'filename' => $uploaded ? basename($uploaded) : ($resolved !== '' ? basename($resolved) : ''),
                'size'     => $resolved !== '' ? (int)@filesize($resolved) : 0,
                'url'      => 'api/branding.php?kind=' . $kind . '&v=' . self::version($kind),
            ];
        }
        return $out;
    }
}
