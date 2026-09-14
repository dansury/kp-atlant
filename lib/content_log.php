<?php
/**
 * Кто и что поменял в текстах сервиса (модуль 022).
 *
 * Промпты, база знаний, оформление КП и Tone of Voice перестали быть
 * админской вкладкой: менеджер работает с текстами каждый день и правит их
 * быстрее всех. Но текст, который правят вдвоём и молча, однажды уезжает не
 * туда — и никто не помнит, когда.
 *
 * Поэтому у каждой правки есть след: что за раздел, какой ключ, кто трогал и
 * что было до. Админ видит ленту на первой странице панели — не как
 * уведомление, которое нужно закрыть, а как список того, что изменилось с его
 * прошлого захода.
 */
final class ContentLog {

    public const AREAS = [
        'prompt'    => 'Промпты',
        'knowledge' => 'База знаний',
        'tov'       => 'Tone of Voice',
        'email'     => 'Правила писем',
        'kp'        => 'Оформление КП',
        'settings'  => 'Настройки',
    ];

    public static function label(string $area): string {
        return self::AREAS[$area] ?? $area;
    }

    /**
     * Записать правку. `$before` хранится обрезанным: лента — это «что
     * менялось», а не система контроля версий. У промптов своя история
     * (`prompt_history`), и дублировать её здесь незачем.
     */
    public static function record(string $area, string $key, string $title,
                                  ?int $managerId, string $before = '', string $after = ''): int {
        if (!isset(self::AREAS[$area])) return 0;
        if ($before !== '' && $before === $after) return 0;   // нажали «Сохранить», ничего не изменив

        return (int)Db::insert('content_changes', [
            'area'       => $area,
            'item_key'   => mb_substr($key, 0, 200),
            'title'      => mb_substr($title, 0, 300),
            'manager_id' => $managerId ?: null,
            'before_len' => mb_strlen($before),
            'after_len'  => mb_strlen($after),
            'excerpt'    => mb_substr(trim($after) !== '' ? $after : $before, 0, 400),
        ]);
    }

    /** Лента для первой страницы панели. */
    public static function recent(int $limit = 15): array {
        $rows = Db::all(
            "SELECT c.*, m.name AS manager_name, COALESCE(m.is_admin, 0) AS by_admin
             FROM content_changes c LEFT JOIN managers m ON m.id = c.manager_id
             ORDER BY c.id DESC LIMIT ?", [max(1, $limit)]
        );
        foreach ($rows as &$row) {
            $row['area_label'] = self::label((string)$row['area']);
            $row['delta'] = (int)$row['after_len'] - (int)$row['before_len'];
        }
        return $rows;
    }

    /** Сколько правок сделали не-администраторы за сутки — цифра для карточки. */
    public static function countSince(string $since = '-1 day'): int {
        return (int)Db::val(
            "SELECT COUNT(*) FROM content_changes WHERE created_at >= datetime('now', ?)", [$since]
        );
    }
}
