<?php
/**
 * Tone of Voice — как компания разговаривает с клиентом (модуль 022).
 *
 * Текст жил файлом `reference/tov.md` В РЕПОЗИТОРИИ, и это была ошибка: деплой
 * перезаписывает файлы репозитория, так что любая правка исчезала на следующем
 * обновлении кода. Теперь он лежит в `storage/` — там же, где подписи,
 * логотипы и всё остальное, что сервис узнал от людей, — и `pull.php` его не
 * трогает.
 *
 * Править его может и менеджер: он разговаривает с клиентами каждый день и
 * замечает раньше всех, что фраза звучит не так. Каждая правка попадает в
 * ленту изменений, и админ видит её на первой странице.
 */
final class Tov {

    private const STORED = '/storage/tov.md';
    private const BUNDLED = '/reference/tov.md';

    /** Текст, который уходит в промпты. Пусто — значит правил нет, и это норма. */
    public static function read(): string {
        foreach ([ROOT . self::STORED, ROOT . self::BUNDLED] as $path) {
            if (is_file($path)) return (string)file_get_contents($path);
        }
        return '';
    }

    /** Свой ли это текст или ещё встроенный — панель это показывает. */
    public static function isCustom(): bool {
        return is_file(ROOT . self::STORED);
    }

    public static function updatedAt(): ?string {
        $path = ROOT . self::STORED;
        return is_file($path) ? date('Y-m-d H:i:s', (int)filemtime($path)) : null;
    }

    public static function save(string $text, ?int $managerId = null): void {
        require_once __DIR__ . '/content_log.php';
        $before = self::read();

        $dir = ROOT . '/storage';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (@file_put_contents(ROOT . self::STORED, $text) === false) {
            throw new RuntimeException('Не удалось сохранить storage/tov.md — проверьте права на папку');
        }

        ContentLog::record('tov', 'tov', 'Tone of Voice', $managerId, $before, $text);
        Logger::info('settings', 'Tone of Voice изменён', ['manager_id' => $managerId]);
    }

    /** Вернуться к встроенному тексту из репозитория. */
    public static function reset(?int $managerId = null): void {
        require_once __DIR__ . '/content_log.php';
        $before = self::read();
        @unlink(ROOT . self::STORED);
        ContentLog::record('tov', 'tov', 'Tone of Voice сброшен к встроенному', $managerId, $before, self::read());
    }
}
