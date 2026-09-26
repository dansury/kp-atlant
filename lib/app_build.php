<?php
/**
 * Отпечаток сборки интерфейса — ключ кэша для `app.js` и `app.css` (модуль 066).
 *
 * Время изменения И размер: один `mtime` совпадал у файла, который деплой ещё
 * дописывал, и у дописанного в ту же секунду, — и половина `app.js` жила в кэше
 * телефона под адресом целого. Страница, собранная посреди записи, теперь
 * называет адрес, который больше никто не спросит.
 */
final class AppBuild {
    /** Файлы, по которым браузер узнаёт новую сборку. */
    public const ASSETS = ['assets/js/app.js', 'assets/css/app.css'];

    /** $public — корень `public/` (по умолчанию — рядом с `lib/`). */
    public static function stamp(?string $public = null): string {
        $public = rtrim($public ?? dirname(__DIR__) . '/public', '/');
        $parts = [];
        foreach (self::ASSETS as $rel) {
            $path = $public . '/' . $rel;
            clearstatcache(true, $path);
            $parts[] = (int)@filemtime($path) . ':' . (int)@filesize($path);
        }
        return substr(sha1(implode('|', $parts)), 0, 12);
    }
}
