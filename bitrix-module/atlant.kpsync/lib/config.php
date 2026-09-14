<?php
namespace Atlant\KpSync;

use Bitrix\Main\Config\Option;

/**
 * Настройки модуля, прочитанные в одном месте.
 *
 * Без этого файла автозагрузчик (`include.php`) обещает класс, которого нет, и
 * страница «Настройки модуля» падает с `Failed opening required
 * .../lib/config.php` — а вместе с ней перестаёт отвечать и сам эндпоинт:
 * `kp.php` спрашивает `Config::enabled()` первой же строкой.
 *
 * Значения живут в `b_option` и правятся в админке. Здесь — только их разбор:
 * список инфоблоков строкой превращается в числа, адрес сайта — в основу для
 * абсолютных ссылок, токен сравнивается за постоянное время.
 */
final class Config
{
    public const MODULE_ID = 'atlant.kpsync';

    /** Чем поле заполнено, пока администратор его не трогал. */
    public const DEFAULTS = [
        'ENABLED'        => 'Y',
        'TOKEN'          => '',
        'IBLOCK_IDS'     => '',
        'ARTICLE_PROP'   => 'CML2_ARTICLE',
        'SEARCH_BY_NAME' => 'Y',
        'ACTIVE_ONLY'    => 'Y',
        'SITE_URL'       => '',
        'EXPORT_LIMIT'   => '500',
    ];

    public static function get(string $name): string
    {
        return (string)Option::get(self::MODULE_ID, $name, self::DEFAULTS[$name] ?? '');
    }

    public static function enabled(): bool
    {
        return self::get('ENABLED') === 'Y';
    }

    /**
     * Токен запроса. Пустой токен в настройках — сознательное решение
     * администратора открыть адрес всем, кто его знает; заполненный
     * сравнивается за постоянное время, чтобы его нельзя было подобрать по
     * времени ответа.
     */
    public static function tokenOk(string $given): bool
    {
        $expected = self::get('TOKEN');
        if ($expected === '') return true;
        return hash_equals($expected, $given);
    }

    /** ID инфоблоков каталога. Пусто — пусть решает вызывающий (все каталоги). */
    public static function iblockIds(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', self::get('IBLOCK_IDS')) ?: [] as $part) {
            $id = (int)trim($part);
            if ($id > 0) $out[$id] = true;
        }
        return array_keys($out);
    }

    public static function exportLimit(): int
    {
        return max(1, min(1000, (int)self::get('EXPORT_LIMIT') ?: 500));
    }

    /**
     * Основа для абсолютных ссылок. Задана в настройках — берём её; пусто —
     * собираем из самого запроса, чтобы ссылка всё равно открывалась.
     */
    public static function base(): string
    {
        $configured = rtrim(trim(self::get('SITE_URL')), '/');
        if ($configured !== '') return $configured;

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') return '';
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        return ($https ? 'https://' : 'http://') . $host;
    }
}
