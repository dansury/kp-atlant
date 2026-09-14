<?php
/**
 * Что в запросе — не наша номенклатура (модуль 022).
 *
 * В запросе на пожарную часть рядом со шлемами стояли топор пожарный, рукав
 * 5ELEM, водопенное оборудование и ящики для песка. Мы этим не занимаемся — но
 * КП уходило клиенту с четырьмя строками по 0,00 руб., а ответ обещал «уточним
 * наличие, сроки и цену». Обещание, которое никто не собирался выполнять, хуже
 * честного молчания, а строка за ноль рублей в подписанном документе — это
 * предложение поставить то, чего у нас нет.
 *
 * Поэтому у строки запроса есть третье состояние. Раньше их было два:
 *   — нашли в каталоге → позиция КП;
 *   — не нашли → «уточняем» (модуль 018).
 * Теперь есть и «это не к нам»: такая строка не попадает НИ в КП, ни в ответ.
 * Менеджер её видит на карточке — молча выкинуть строку клиента нельзя, — но
 * клиент о ней от нас не услышит.
 *
 * Решают это два источника, и оба видны человеку:
 *   — список слов `CATALOG_OUT_OF_SCOPE` в «Настройках» — работает сразу,
 *     без модели и без сети;
 *   — кнопка «не наш профиль» на строке: нажали — слова этой строки попадают
 *     в тот же список, и в следующем письме то же самое отсеется само.
 */
final class Scope {

    /**
     * Чем мы точно не занимаемся. Начальный список — пожарно-техническое
     * снаряжение: именно оно приходит в одном письме с бронезащитой, потому
     * что закупает его тот же отдел снабжения.
     */
    public const DEFAULT_RULES = [
        'топор пожарный', 'рукав пожарный', 'пожарный рукав', 'пожарный ствол',
        'водопенное оборудование', 'ящик для песка', 'ящики для песка',
        'огнетушитель', 'пожарный шкаф', 'пожарный щит', 'мотопомпа',
        'гидрант', 'кран пожарный', 'самоспасатель',
    ];

    /** Правила как список строк: свои из настроек, иначе начальные. */
    public static function rules(): array {
        $raw = trim((string)Settings::get('CATALOG_OUT_OF_SCOPE', ''));
        $list = $raw === ''
            ? self::DEFAULT_RULES
            : ((array)preg_split('/\R|;/u', $raw) ?: []);

        $out = [];
        foreach ($list as $rule) {
            $rule = self::normalize((string)$rule);
            if ($rule !== '' && !in_array($rule, $out, true)) $out[] = $rule;
        }
        return $out;
    }

    public static function enabled(): bool {
        return (int)Settings::get('CATALOG_SCOPE_FILTER', 1) === 1;
    }

    /**
     * Правило, под которое подходит это название, или null.
     *
     * Совпадение — по вхождению нормализованной фразы: «Рукав пожарный 5ELEM»
     * ловится правилом «рукав пожарный», а «Ящики для песка и инвентаря
     * стеклопластиковые „Рапан“» — правилом «ящики для песка». Отдельные слова
     * в правиле мы не ищем нарочно: одно «пожарный» выкинуло бы и огнестойкий
     * подшлемник, который мы как раз продаём.
     */
    public static function match(string $name): ?string {
        if (!self::enabled()) return null;
        $name = self::normalize($name);
        if ($name === '') return null;

        foreach (self::rules() as $rule) {
            if (mb_strlen($rule) < 4) continue;      // слишком коротко, чтобы быть правилом
            if (str_contains($name, $rule)) return $rule;
        }
        return null;
    }

    /**
     * Запомнить строку, которую менеджер отметил как не нашу.
     *
     * В список уходит не всё название, а его «ядро» — первые два значащих
     * слова: «Топор пожарный поясной» даёт правило «топор пожарный», и в
     * следующем письме отсеется «Топор пожарный ТПП». Возвращает добавленное
     * правило или null, если такое уже есть.
     */
    public static function remember(string $name): ?string {
        $core = self::core($name);
        if ($core === '') return null;

        $rules = self::rules();
        foreach ($rules as $rule) {
            if (str_contains($core, $rule) || str_contains($rule, $core)) return null;
        }
        $rules[] = $core;
        Settings::set('CATALOG_OUT_OF_SCOPE', implode("\n", $rules));
        Logger::info('catalog', 'Не наша номенклатура: правило добавлено', ['rule' => $core]);
        return $core;
    }

    /** Убрать правило из списка — менеджер ошибся, и мы этим всё-таки торгуем. */
    public static function forget(string $rule): void {
        $rule = self::normalize($rule);
        $rules = array_values(array_filter(self::rules(), fn($r) => $r !== $rule));
        Settings::set('CATALOG_OUT_OF_SCOPE', implode("\n", $rules));
        Logger::info('catalog', 'Не наша номенклатура: правило убрано', ['rule' => $rule]);
    }

    /** Первые два значащих слова названия — то, чем строка узнаётся снова. */
    private static function core(string $name): string {
        $words = array_values(array_filter(
            explode(' ', self::normalize($name)),
            fn($w) => mb_strlen($w) >= 3
        ));
        if (!$words) return '';
        return implode(' ', array_slice($words, 0, 2));
    }

    /**
     * Название к виду, в котором его можно сравнивать: нижний регистр, «ё» = «е»,
     * кавычки и знаки — пробелы. Без `/u` байт 0xBB из «»» съедает букву «л».
     */
    private static function normalize(string $value): string {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);
        $value = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }
}
