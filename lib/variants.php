<?php
/**
 * Модификации: один товар, несколько размеров и цветов (модуль 022).
 *
 * Письмо просило «Баллистический шлем Протон СВМПЭ (р.S-5шт, р.M-13шт,
 * р.L-7шт)». В «Подходящих позициях» появлялась ОДНА строка — шлем, 25 штук, —
 * и КП уходило с одной строкой на 25 штук. Это не то, что просили: размеры
 * закупаются в разной пропорции, лежат на складе по отдельности и стоят порой
 * по-разному. Склеенные в одну строку, они превращаются в заказ, который потом
 * переписывают руками.
 *
 * Здесь строка письма разбирается на модификации ДО каталога и без модели:
 * «S — 5», «M — 13», «L — 7» — это чистый разбор текста, и он обязан работать
 * одинаково с ключом нейросети и без него. Модель тут лишняя ещё и потому, что
 * ошибка в количестве — это ошибка в деньгах.
 *
 * Дальше каждая строка ищет СВОЮ карточку в каталоге: в `products_cache` у
 * модификации МойСклад стоит `product_type = 'variant'`, `parent_id` товара и
 * характеристики («Размер: S»). Нашли — строка получает артикул, цену и
 * остаток именно этого размера; не нашли — остаётся товар-родитель, но строк
 * всё равно столько, сколько размеров просили.
 */
final class Variants {

    /** Размеры, которые можно узнать без подсказки «размер». Набор закрытый. */
    private const SIZE_TOKENS = ['xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl', '2xl', '3xl', '4xl'];

    /** Цвета снаряжения — то, чем отличаются модификации одного изделия. */
    private const COLORS = [
        'чёрный', 'черный', 'олива', 'оливковый', 'койот', 'coyote', 'хаки', 'khaki',
        'мультикам', 'multicam', 'песок', 'песочный', 'серый', 'зелёный', 'зеленый',
        'белый', 'синий', 'бежевый', 'коричневый', 'ранger', 'ranger', 'мох', 'цифра',
    ];

    /** Слова, после которых идёт размер: «р.S», «р-р 52», «размер L». */
    private const SIZE_PREFIX = '(?:разм(?:ер[аы]?)?|р\s*[-\/\.]\s*р|рост|р\.)';

    // ------------------------------------------------------------- разбор

    /**
     * Разложить позиции запроса на модификации.
     *
     * Строка, в которой модификаций не нашлось, возвращается как была — это
     * подавляющее большинство строк, и трогать их незачем.
     *
     * @param array $items позиции из разбора письма: name, quantity, unit, raw_text
     * @return array тот же список, где строка с модификациями стала несколькими
     */
    public static function expand(array $items): array {
        if ((int)Settings::get('CATALOG_SPLIT_VARIANTS', 1) !== 1) return $items;

        $out = [];
        foreach ($items as $item) {
            $name = trim((string)($item['name'] ?? $item['raw_name'] ?? ''));
            // Искать модификации есть смысл и в названии, и в куске письма, из
            // которого строка получилась: «(р.S-5шт…)» нередко остаётся там
            $source = $name;
            $tail = trim((string)($item['raw_text'] ?? ''));
            if (self::split($source) === [] && $tail !== '' && mb_stripos($tail, mb_substr($name, 0, 10)) !== false) {
                $source = $tail;
            }

            $parts = self::split($source);
            if (count($parts) < 2) { $out[] = $item; continue; }

            $base = self::baseName($name);
            foreach ($parts as $part) {
                $line = $item;
                // Каталог ищется по названию БЕЗ модификации — товар-родитель
                // один, а модификация выбирается у него уже по метке
                $line['name']          = $base;
                $line['raw_name']      = $base . ' ' . self::labelSuffix($part);
                $line['base_name']     = $base;
                $line['quantity']      = $part['quantity'];
                $line['qty']           = $part['quantity'];
                $line['variant_label'] = $part['label'];
                $line['variant_kind']  = $part['kind'];
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * Модификации, перечисленные в тексте: [[label, kind, quantity], …].
     *
     * Возвращает пустой массив, если модификаций нет, — и это нормальный,
     * самый частый ответ. Одна найденная модификация тоже не повод делить
     * строку: «шлем размера L — 7 шт» это одна позиция, а не список.
     */
    public static function split(string $text): array {
        $text = self::tidy($text);
        if ($text === '') return [];

        // Перечисление почти всегда стоит в скобках — там и ищем в первую
        // очередь: за скобками лежат класс защиты, ГОСТ и прочие числа,
        // которые легко принять за количество
        $scope = $text;
        if (preg_match_all('/\(([^)]{3,200})\)/u', $text, $m)) {
            foreach ($m[1] as $inside) {
                $found = self::scan($inside);
                if (count($found) >= 2) return $found;
            }
        }
        return self::scan($scope);
    }

    /** Найти пары «модификация — количество» в одном куске текста. */
    private static function scan(string $text): array {
        $out = [];

        // 1. С явной подсказкой размера: «р.S-5шт», «размер 52-54 — 10 шт»
        $re = '/' . self::SIZE_PREFIX . '\s*[:№]?\s*([A-Za-zА-Яа-я0-9]{1,4}(?:\s*[-–—\/]\s*\d{2,3})?)'
            . '\s*[-–—:=]+\s*(\d+(?:[.,]\d+)?)\s*(?:шт|штук[аи]?|компл|пар[аы]?|ед)?\.?/iu';
        foreach (self::matchAll($re, $text) as $hit) {
            self::push($out, $hit[1], 'size', $hit[2]);
        }

        // 2. Без подсказки, но размер из закрытого набора: «S - 5 шт, M - 13 шт»
        if (count($out) < 2) {
            $re = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(fn($t) => preg_quote($t, '/'), self::SIZE_TOKENS))
                . ')\s*[-–—:=]+\s*(\d+(?:[.,]\d+)?)\s*(?:шт|штук[аи]?|компл|пар[аы]?|ед)?\.?/iu';
            foreach (self::matchAll($re, $text) as $hit) {
                self::push($out, $hit[1], 'size', $hit[2]);
            }
        }

        // 3. Цвета: «чёрный — 5 шт, олива — 3 шт»
        if (count($out) < 2) {
            $colors = [];
            $re = '/(?:цвет\s*[:№]?\s*)?(' . implode('|', array_map(fn($c) => preg_quote($c, '/'), self::COLORS))
                . ')\w{0,3}\s*[-–—:=]+\s*(\d+(?:[.,]\d+)?)\s*(?:шт|штук[аи]?|компл|пар[аы]?|ед)?\.?/iu';
            foreach (self::matchAll($re, $text) as $hit) {
                self::push($colors, $hit[1], 'color', $hit[2]);
            }
            if (count($colors) >= 2) return $colors;
        }

        return $out;
    }

    /** @return array<int,array{1:string,2:string}> */
    private static function matchAll(string $re, string $text): array {
        if (!preg_match_all($re, $text, $m, PREG_SET_ORDER)) return [];
        return $m;
    }

    /** Добавить модификацию, не задваивая одну и ту же. */
    private static function push(array &$out, string $label, string $kind, string $quantity): void {
        $label = self::cleanLabel($label);
        $qty = (float)str_replace(',', '.', $quantity);
        if ($label === '' || $qty <= 0) return;
        foreach ($out as $existing) {
            if (mb_strtolower($existing['label']) === mb_strtolower($label)) return;
        }
        $out[] = ['label' => $label, 'kind' => $kind, 'quantity' => $qty];
    }

    /** «s» → «S», «52 - 54» → «52-54»: метка, которую увидит менеджер. */
    private static function cleanLabel(string $label): string {
        $label = trim((string)preg_replace('/\s*([-–—\/])\s*/u', '-', trim($label)), " \t.,;:-");
        if ($label === '') return '';
        // Латинские размеры пишутся заглавными, всё остальное — как пришло
        return preg_match('/^[a-z]{1,4}$/i', $label) ? mb_strtoupper($label) : $label;
    }

    private static function labelSuffix(array $part): string {
        return $part['kind'] === 'color' ? '(цвет: ' . $part['label'] . ')' : '(размер ' . $part['label'] . ')';
    }

    /**
     * Название без перечисления модификаций: «Баллистический шлем Протон СВМПЭ
     * (р.S-5шт, р.M-13шт)» → «Баллистический шлем Протон СВМПЭ». По нему
     * каталог и ищет товар-родителя.
     */
    public static function baseName(string $name): string {
        $qty = '\s*[-–—:=]+\s*\d+(?:[.,]\d+)?\s*(?:шт|штук[аи]?|компл|пар[аы]?|ед)?\.?';
        $base = (string)preg_replace('/\([^)]*\)/u', ' ', $name);
        $base = (string)preg_replace('/' . self::SIZE_PREFIX . '\s*[:№]?\s*[A-Za-zА-Яа-я0-9\-\/]{1,8}' . $qty . '/iu', ' ', $base);
        // Перечисление без подсказки — «S - 2 шт, M - 4 шт» и «олива — 3 шт» —
        // тоже не часть названия товара
        $tokens = array_merge(self::SIZE_TOKENS, self::COLORS);
        $base = (string)preg_replace(
            '/(?:цвет\s*[:№]?\s*)?(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn($t) => preg_quote($t, '/'), $tokens))
            . ')\w{0,3}' . $qty . '/iu', ' ', $base);
        $base = trim((string)preg_replace('/\s+/u', ' ', $base), " \t,;:-");
        return $base !== '' ? $base : trim($name);
    }

    // ------------------------------------------------------------- каталог

    /** Модификации товара из каталога — то, что МойСклад зовёт «модификациями». */
    public static function forProduct(string $productId): array {
        if ($productId === '') return [];
        return Db::all(
            "SELECT moysklad_id, name, article, code, unit, price, prices_json, stock, reserved,
                    characteristics, parent_id
             FROM products_cache
             WHERE parent_id=? AND COALESCE(is_archived, 0) = 0
             ORDER BY name", [$productId]
        );
    }

    /** Есть ли у товара модификации — вопрос, который задаёт карточка запроса. */
    public static function has(string $productId): bool {
        return $productId !== '' && (int)Db::val(
            "SELECT COUNT(*) FROM products_cache WHERE parent_id=? AND COALESCE(is_archived, 0) = 0",
            [$productId]) > 0;
    }

    /**
     * Модификация, которая отвечает этой метке, или null.
     *
     * Сравниваем и характеристики («Размер: S»), и название («… (Размер: S)»):
     * каталог, загруженный из Excel, и каталог, синхронизированный по API,
     * пишут это в разные поля, а отвечать они обязаны одинаково.
     */
    public static function pick(array $variants, string $label): ?array {
        $needle = mb_strtolower(trim($label));
        if ($needle === '') return null;

        foreach ($variants as $v) {
            foreach (self::values($v) as $value) {
                if (mb_strtolower($value) === $needle) return $v;
            }
        }
        // Точного совпадения нет — пробуем вхождение целым словом: «52-54»
        // против «Размер: 52-54 (M)»
        foreach ($variants as $v) {
            foreach (self::values($v) as $value) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u',
                               mb_strtolower($value))) return $v;
            }
        }
        return null;
    }

    /** Значения характеристик модификации плюс то, что стоит в скобках имени. */
    private static function values(array $variant): array {
        $out = [];
        foreach (preg_split('/[;,\n]+/u', (string)($variant['characteristics'] ?? '')) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            $out[] = trim(substr($chunk, (int)mb_strpos($chunk, ':') + 1)) ?: $chunk;
            $out[] = $chunk;
        }
        if (preg_match_all('/\(([^)]+)\)/u', (string)($variant['name'] ?? ''), $m)) {
            foreach ($m[1] as $inside) {
                $out[] = trim($inside);
                $colon = mb_strpos($inside, ':');
                if ($colon !== false) $out[] = trim(mb_substr($inside, $colon + 1));
            }
        }
        return array_values(array_filter(array_map('trim', $out), fn($v) => $v !== ''));
    }

    /**
     * Строку «Подходящих позиций» — на её собственную модификацию.
     *
     * Подбор нашёл товар-родителя; если у строки есть метка размера или цвета и
     * у товара есть такая модификация, строка переезжает на неё: свой артикул,
     * своя цена, свой остаток. Не нашли — остаётся родитель, и строка честно
     * говорит, какой модификации не хватило.
     *
     * @return array поля для `request_items`, которые надо переписать (может быть пустым)
     */
    public static function resolveRow(array $row, ?int $counterpartyId = null): array {
        $label = trim((string)($row['variant_label'] ?? ''));
        $matched = trim((string)($row['moysklad_product_id'] ?? ''));
        if ($label === '' || $matched === '') return [];

        // Подбор мог попасть и в сам товар, и сразу в одну из его модификаций —
        // семья ищется от корня, иначе у модификации «своих» модификаций нет
        $parentId = (string)(Db::val("SELECT parent_id FROM products_cache WHERE moysklad_id=?", [$matched]) ?: '')
                 ?: $matched;

        $variants = self::forProduct($parentId);
        if (!$variants) return [];

        $pick = self::pick($variants, $label);
        if (!$pick) {
            return ['notes' => 'модификации «' . $label . '» нет в каталоге — уточните у менеджера'];
        }

        require_once __DIR__ . '/catalog.php';
        require_once __DIR__ . '/alternatives.php';
        return [
            'moysklad_product_id' => $pick['moysklad_id'],
            'product_name'        => $pick['name'],
            'article'             => $pick['article'],
            'unit'                => $pick['unit'] ?: 'шт.',
            'price'               => Catalog::priceFor($pick, $counterpartyId),
            'stock'               => Alternatives::freeStock($pick),
            'match_source'        => 'модификация',
        ];
    }

    private static function tidy(string $text): string {
        $text = str_replace(["\xC2\xA0", "\r"], [' ', ' '], $text);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }
}
