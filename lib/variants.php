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

    /** Слова, после которых идёт размер: «р.S», «р-р 52», «размер L», «размеры S/M». */
    private const SIZE_PREFIX = '(?:разм(?:ер(?:ами|ов|а|ы)?)?|р\s*[-\/\.]\s*р|рост|р\.)';

    /**
     * Буквенный размер кириллицей — тот же размер (issue #132): «размер Л, М».
     * Буквы-двойники, из которых клиент набирает S, M, L и X.
     */
    private const SIZE_HOMOGLYPHS = ['х' => 'x', 'с' => 's', 'м' => 'm', 'л' => 'l'];

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
            if (self::split($source) === [] && $tail !== '' && self::mentions($tail, $name)) {
                $source = $tail;
            }

            $parts = self::split($source);
            if (count($parts) < 2 && empty($item['variant_label'])) {
                // Несколько размеров одним «размер», количество одно на всех
                // (issue #132): «шлем (размер Л, М) — по 2 штуки каждого»
                $list = self::sizeList($name);
                if (count($list) < 2 && $tail !== '' && self::mentions($tail, $name)) $list = self::sizeList($tail);
                $lines = count($list) >= 2 ? self::spreadSizes($item, $name, $tail, $list) : [];
                if ($lines) {
                    foreach ($lines as $line) $out[] = $line;
                    continue;
                }
            }
            if (count($parts) < 2) {
                // Один размер со словом «размер» — тоже модификация (issue #118):
                // «плиты Бр3, размер XL» ищут товар, а встают на его XL
                $label = self::sizeLabel($name)
                    ?? (($tail !== '' && self::mentions($tail, $name)) ? self::sizeLabel($tail) : null);
                if ($label !== null && empty($item['variant_label'])) {
                    $base = self::stripSize($name);
                    $item['name']          = $base;
                    $item['raw_name']      = $base . ' (размер ' . $label . ')';
                    $item['base_name']     = $base;
                    $item['variant_label'] = $label;
                    $item['variant_kind']  = 'size';
                }
                $out[] = $item;
                continue;
            }

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

    /**
     * Один размер, названный словом «размер» (issue #118): «размер XL», «р. 52-54».
     *
     * Только с подсказкой И из закрытого набора (или числом-ростовкой): без
     * подсказки «Рукав 5ELEM» стал бы размером. Два разных размера без
     * количеств — вопрос, а не метка: null. Кириллица — те же размеры: «размер Л».
     */
    public static function sizeLabel(string $text): ?string {
        $found = [];
        foreach (self::sizeSpans(self::tidy($text)) as $span) {
            foreach ($span['labels'] as $label) $found[mb_strtolower($label)] = $label;
        }
        return count($found) === 1 ? reset($found) : null;
    }

    /**
     * Размеры, перечисленные после ОДНОЙ подсказки (issue #132): «размер Л, М»,
     * «размеры S/M/L», «р. 52-54, 56-58», «размер L и XL». Латиницей, в порядке
     * письма. «размер S или размер M» — это выбор, а не список: пусто.
     *
     * @return string[]
     */
    public static function sizeList(string $text): array {
        foreach (self::sizeSpans(self::tidy($text)) as $span) {
            if (count($span['labels']) >= 2) return $span['labels'];
        }
        return [];
    }

    /**
     * «л» → «L», «ХЛ» → «XL», «52 / 54» → «52-54»; не размер — null.
     * Кириллица принимается только здесь, после подсказки: сама по себе
     * «с» — предлог, а «м» — метр.
     */
    public static function canonSize(string $token): ?string {
        $t = mb_strtolower((string)preg_replace('/\s+/u', '', $token));
        if ($t === '') return null;
        if (preg_match('/^(\d{2,3})(?:[-–—\/](\d{2,3}))?$/u', $t, $m)) {
            return isset($m[2]) ? $m[1] . '-' . $m[2] : $m[1];
        }
        $latin = strtr($t, self::SIZE_HOMOGLYPHS);
        return in_array($latin, self::SIZE_TOKENS, true) ? strtoupper($latin) : null;
    }

    /**
     * Где в тексте названы размеры: подсказка и размеры за ней, через запятую,
     * «/», «+» или «и». Первое не-размерное слово список заканчивает.
     *
     * @return array<int,array{start:int,end:int,labels:string[]}> байтовые смещения
     */
    private static function sizeSpans(string $text): array {
        if ($text === '' || !preg_match_all('/(?<![\p{L}\p{N}])' . self::SIZE_PREFIX . '/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $token = '/\G(\d{2,3}(?:\s*[-–—\/]\s*\d{2,3})?|[A-Za-zА-Яа-яЁё0-9]{1,4})(?![\p{L}\p{N}])/u';
        $out = [];
        foreach ($m[0] as [$hint, $at]) {
            $pos = $at + strlen($hint);
            $labels = [];
            $end = $pos;
            while (true) {
                // Первый размер — сразу за подсказкой («размер: L»), следующие — через разделитель
                $sep = $labels ? '/\G\s*(?:[,;+\/]|и(?!\p{L}))\s*/u' : '/\G\s*[:№]?\s*/u';
                if (!preg_match($sep, $text, $sm, 0, $pos)) break;
                $at2 = $pos + strlen($sm[0]);
                if (!preg_match($token, $text, $tm, 0, $at2)) break;
                $label = self::canonSize($tm[1]);
                if ($label === null) break;
                if (!in_array($label, $labels, true)) $labels[] = $label;
                $pos = $end = $at2 + strlen($tm[0]);
            }
            if ($labels) $out[] = ['start' => $at, 'end' => $end, 'labels' => $labels];
        }
        return $out;
    }

    /**
     * Количество на каждый размер, а не на все: «по 2 штуки», «2 шт. каждого».
     */
    public static function perSize(string $text): bool {
        return (bool)preg_match('/(?<!\p{L})по\s+\d|(?<!\p{L})кажд(?:ого|ой|ый|ому|ая|ую)(?!\p{L})/iu', $text);
    }

    /**
     * Строка с перечнем размеров и одним количеством → строка на размер.
     *
     * «По N каждого» — N на каждый. Иначе в письме ИТОГ: он делится поровну,
     * остаток — первым размерам, и строка говорит менеджеру, что делили мы:
     * сумма строк равна количеству из письма, ошибка в количестве — ошибка в
     * деньгах. Итог меньше числа размеров — это «L или M», не делим вовсе.
     *
     * @return array строки или [] — делить нечего
     */
    private static function spreadSizes(array $item, string $name, string $tail, array $sizes): array {
        $total = (float)str_replace(',', '.', (string)($item['qty'] ?? $item['quantity'] ?? 1));
        if ($total <= 0) $total = 1;
        $each = !empty($item['each']) || self::perSize($name . ' ' . $tail);
        $k = count($sizes);
        if (!$each && $total < $k) return [];

        // Размеры ушли из имени; скобки с классом защиты («(Бр4)») — остаются
        $base = self::stripSize($name);
        $whole = (int)$total == $total;
        $out = [];
        foreach ($sizes as $n => $label) {
            $qty = $each ? ($whole ? (int)$total : $total)
                         : ($whole ? intdiv((int)$total, $k) + ($n < (int)$total % $k ? 1 : 0) : $total / $k);
            $line = $item;
            $line['name']          = $base;
            $line['raw_name']      = $base . ' (размер ' . $label . ')';
            $line['base_name']     = $base;
            $line['quantity']      = $qty;
            $line['qty']           = $qty;
            $line['variant_label'] = $label;
            $line['variant_kind']  = 'size';
            unset($line['each']);
            if (!$each) {
                $line['qty_note'] = 'в письме ' . self::num($total) . ' шт. на размеры ' . implode(', ', $sizes)
                                  . ' — разделено поровну, проверьте';
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * По чему искать товар строки с модификацией: без размера, цвета и
     * перечня количеств, но со скобкой класса — «Плита (Бр4) (размер L)» это
     * «Плита (Бр4)», а не любая плита.
     */
    public static function searchName(string $rawName): string {
        $name = self::stripSize($rawName);
        $name = (string)preg_replace('/\((?:\s*цвет\s*:[^)]*|[^)]*\d\s*(?:шт|штук|компл|пар|ед)[^)]*)\)/iu', ' ', $name);
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        return $name !== '' ? $name : trim($rawName);
    }

    private static function num(float $v): string {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }

    /** Название без «размер XL» и без «размеры L, M»: по нему ищется товар-родитель. */
    public static function stripSize(string $name): string {
        $spans = self::sizeSpans($name);
        if (!$spans) return trim($name);
        $base = $name;
        foreach (array_reverse($spans) as $span) {
            $base = substr($base, 0, $span['start']) . ' ' . substr($base, $span['end']);
        }
        // Скобки, от которых остались одни знаки, — не часть названия
        $base = (string)preg_replace('/\(\s*[,;:\-–—]*\s*\)/u', ' ', $base);
        $base = (string)preg_replace('/\s+([,;)])/u', '$1', (string)preg_replace('/\s+/u', ' ', $base));
        // trim() режет по байтам, а тире многобайтные — только регуляркой
        $base = (string)preg_replace('/^[\s,;:\-–—]+|[\s,;:\-–—]+$/u', '', $base);
        return $base !== '' ? $base : trim($name);
    }

    /**
     * Говорит ли кусок письма о товаре $name: основы его слов (от четырёх букв)
     * стоят в тексте. «5 плит для бронежилета» говорит о «плита для бронежилета»,
     * хотя первые десять букв у них разные.
     */
    public static function mentions(string $text, string $name): bool {
        $text = mb_strtolower($text);
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name)) ?: [],
                                           fn($w) => mb_strlen($w) >= 4));
        if (!$words) return $name !== '' && mb_stripos($text, $name) !== false;
        $hits = 0;
        foreach ($words as $w) {
            if (str_contains($text, mb_substr($w, 0, max(4, mb_strlen($w) - 2)))) $hits++;
        }
        return $hits / count($words) >= 0.6;
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
        // «р.Л-5шт» — тот же L (issue #132)
        $label = ($kind === 'size' ? self::canonSize($label) : null) ?? self::cleanLabel($label);
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
        return self::familiesOf([$productId])[$productId] ?? [];
    }

    /**
     * Модификации сразу нескольких товаров — одним запросом.
     *
     * @param string[] $parentIds id товаров-родителей
     * @return array<string,array<int,array>> id родителя → его модификации
     */
    public static function familiesOf(array $parentIds): array {
        $ids = self::ids($parentIds);
        if (!$ids) return [];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Db::all(
            "SELECT moysklad_id, name, article, code, unit, price, prices_json, stock, reserved,
                    characteristics, parent_id, product_type
             FROM products_cache
             WHERE parent_id IN ($ph) AND COALESCE(is_archived, 0) = 0
             ORDER BY name", $ids) as $row) {
            $out[(string)$row['parent_id']][] = $row;
        }
        return $out;
    }

    /** Строки каталога по id, ключом id. */
    private static function rowsByIds(array $ids): array {
        $ids = self::ids($ids);
        if (!$ids) return [];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Db::all(
            "SELECT moysklad_id, name, article, code, unit, price, prices_json, stock, reserved,
                    characteristics, parent_id, product_type
             FROM products_cache
             WHERE moysklad_id IN ($ph) AND COALESCE(is_archived, 0) = 0", $ids) as $row) {
            $out[(string)$row['moysklad_id']] = $row;
        }
        return $out;
    }

    /** Непустые id списком, без повторов. */
    private static function ids(array $values): array {
        return array_values(array_unique(array_filter(array_map('strval', $values), fn($v) => $v !== '')));
    }

    /**
     * Остатки по модификациям — для многих товаров за один запрос (модуль 026).
     *
     * У товара-родителя в МойСклад собственного остатка нет: он лежит на
     * размерах и цветах. Поэтому «Тактические штаны» показывались нулём и
     * уезжали в КП с красным «под заказ», хотя на складе было двадцать пар
     * четырёх размеров. Здесь родитель отвечает своими модификациями: сколько
     * какой и сколько всего.
     *
     * @param string[] $productIds id товаров-родителей
     * @return array<string,array{free:int,items:array<int,array{id:string,label:string,free:int}>}>
     */
    public static function stockFor(array $productIds): array {
        require_once __DIR__ . '/alternatives.php';
        $ids = self::ids($productIds);
        if (!$ids) return [];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Имя родителя нужно метке: без него модификация без характеристик
        // подписывается названием товара целиком
        $rows = Db::all(
            "SELECT v.moysklad_id, v.parent_id, v.name, v.characteristics, v.stock, v.reserved,
                    par.name AS parent_name
             FROM products_cache v
             LEFT JOIN products_cache par ON par.moysklad_id = v.parent_id
             WHERE v.parent_id IN ($ph) AND COALESCE(v.is_archived, 0) = 0
             ORDER BY v.name", $ids
        );

        $out = [];
        foreach ($rows as $r) {
            $parent = (string)$r['parent_id'];
            if (!isset($out[$parent])) $out[$parent] = ['free' => 0, 'items' => []];
            $free = Alternatives::freeStock($r);
            $out[$parent]['free'] += $free;
            $out[$parent]['items'][] = [
                'id'    => (string)$r['moysklad_id'],
                'label' => self::label($r),
                'free'  => $free,
            ];
        }
        return $out;
    }

    /**
     * Дописать строкам каталога остаток по модификациям — пачкой, одним запросом.
     *
     * `variant_stock` — разбивка («S — 5, M — 13»), `stock` становится суммой:
     * при выборе позиции человек должен видеть количества модификаций, а не
     * ноль абстрактного товара (модуль 026).
     *
     * @param array<int,array> $rows строки с ключом `moysklad_id`
     */
    public static function decorateStock(array $rows): array {
        $map = self::stockFor(array_column($rows, 'moysklad_id'));
        if (!$map) return $rows;
        foreach ($rows as &$row) {
            $id = (string)($row['moysklad_id'] ?? '');
            if (!isset($map[$id])) continue;
            $row['variant_stock'] = $map[$id]['items'];
            $row['stock'] = $map[$id]['free'];
            // Резерв модификаций уже вычтен — второй раз его вычитать нельзя
            $row['reserved'] = 0;
        }
        unset($row);
        return $rows;
    }

    /**
     * Строки каталога → то, что можно ВЫБРАТЬ: конкретные модификации.
     *
     * «Остаток 25 (S 5, M 13, L 7)» одной строкой — это справка, а не выбор:
     * в позицию всё равно должен встать размер, которого просят, со своим
     * артикулом, своей ценой и своим количеством. Поэтому подсказка показывает
     * САМИ модификации, каждую со своим остатком, а товар — только когда
     * модификаций у него нет. Нашёлся сам товар — показываем всю его семью;
     * нашлась одна модификация — её одну: менеджер спросил именно её.
     *
     * @param array $rows строки products_cache, как их вернул поиск
     * @param int   $max  предел длины подсказки; семья модификаций не режется
     * @return array строки подсказки + variant_label, group_name, group_article
     */
    public static function expandSuggest(array $rows, int $max = 40): array {
        $groups = [];   // id товара → [все ли его модификации нужны, сам товар, найденные модификации]
        foreach ($rows as $row) {
            $id = (string)($row['moysklad_id'] ?? '');
            if ($id === '') continue;
            $parentId = trim((string)($row['parent_id'] ?? ''));
            $key = $parentId !== '' ? $parentId : $id;
            if (!isset($groups[$key])) $groups[$key] = ['full' => false, 'own' => null, 'picked' => []];
            if ($parentId !== '') $groups[$key]['picked'][$id] = $row;
            else { $groups[$key]['full'] = true; $groups[$key]['own'] = $row; }
        }
        if (!$groups) return [];

        $keys = array_keys($groups);
        $families = self::familiesOf($keys);
        // Родителя, которого поиск не нашёл, дочитываем: его именем подписана группа
        $parents = self::rowsByIds(array_values(array_filter($keys, fn($k) => $groups[$k]['own'] === null)));

        $hot = $cold = [];
        foreach ($groups as $key => $g) {
            $parent = $g['own'] ?? ($parents[$key] ?? null);
            $family = $families[$key] ?? [];
            if (!$family) {
                // Модификаций нет — в подсказке сам товар, со своим количеством
                if (!$parent) continue;
                $out = [self::suggestRow($parent, null)];
            } else {
                $byId = array_column($family, null, 'moysklad_id');
                $picked = $g['full']
                    ? $family
                    : array_values(array_filter($family, fn($v) => isset($g['picked'][(string)$v['moysklad_id']])));
                // Модификация, которой в семье не нашлось, показывается сама по себе
                foreach ($g['picked'] as $id => $row) {
                    if (!isset($byId[$id])) $picked[] = $row;
                }
                $out = array_map(fn($v) => self::suggestRow($v, $parent), $picked);
                // Сам товар — первой строкой семьи и ТОЖЕ выбирается (модуль 040).
                // «Выбирают размер, а не товар вообще» верно для склада, но не
                // для КП: предложение на «боковую плиту Бр3» пишут без размера,
                // с вилкой цен от и до, а размеры уточняют в заказе.
                if ($parent) array_unshift($out, self::suggestGroupRow($parent, $out));
            }
            // Пустая полка — ниже: собственный остаток товара с модификациями
            // всегда ноль, и сортировка запроса о его размерах ничего не знает
            if (array_sum(array_column($out, 'stock')) > 0) $hot[] = $out; else $cold[] = $out;
        }

        $suggest = [];
        foreach ([...$hot, ...$cold] as $group) {
            foreach ($group as $row) $suggest[] = $row;
            if (count($suggest) >= $max) break;
        }
        return $suggest;
    }

    /**
     * Строка подсказки для САМОГО товара с модификациями (модуль 040).
     *
     * Цена у такого товара стоит на модификациях и стоит по-разному — поэтому
     * строка несёт вилку: низ в `price`, верх в `price_max`. Остаток — сумма
     * по модификациям: столько этого товара на складе, каких бы размеров.
     */
    private static function suggestGroupRow(array $parent, array $variants): array {
        $prices = array_values(array_filter(array_map(
            fn($v) => (float)($v['price'] ?? 0), $variants), fn($p) => $p > 0));
        $own = (float)($parent['price'] ?? 0);
        if ($own > 0) $prices[] = $own;

        return [
            'moysklad_id'     => (string)($parent['moysklad_id'] ?? ''),
            'name'            => (string)($parent['name'] ?? ''),
            'article'         => (string)($parent['article'] ?? ''),
            'code'            => (string)($parent['code'] ?? ''),
            'unit'            => (string)($parent['unit'] ?? '') ?: 'шт.',
            'price'           => $prices ? min($prices) : 0.0,
            'price_max'       => $prices ? max($prices) : 0.0,
            'prices_json'     => $parent['prices_json'] ?? null,
            'parent_id'       => '',
            'product_type'    => (string)($parent['product_type'] ?? ''),
            'characteristics' => (string)($parent['characteristics'] ?? ''),
            'stock'           => (int)array_sum(array_column($variants, 'stock')),
            'variant_label'   => '',
            // Заголовком группы подписана и эта строка: она стоит первой и
            // говорит, что весь товар целиком тоже можно выбрать
            'group_name'      => (string)($parent['name'] ?? ''),
            'group_article'   => (string)($parent['article'] ?? ''),
            'is_group'        => 1,
        ];
    }

    /** Строка подсказки: что встанет в позицию и сколько этого на складе. */
    private static function suggestRow(array $row, ?array $parent): array {
        require_once __DIR__ . '/alternatives.php';
        $isVariant = $parent !== null;
        $row['parent_name'] = (string)($parent['name'] ?? '');
        return [
            'moysklad_id'     => (string)($row['moysklad_id'] ?? ''),
            'name'            => (string)($row['name'] ?? ''),
            'article'         => (string)($row['article'] ?? ''),
            'code'            => (string)($row['code'] ?? ''),
            'unit'            => (string)($row['unit'] ?? '') ?: 'шт.',
            'price'           => (float)($row['price'] ?? 0),
            'prices_json'     => $row['prices_json'] ?? null,
            'parent_id'       => (string)($row['parent_id'] ?? ''),
            'product_type'    => (string)($row['product_type'] ?? ''),
            'characteristics' => (string)($row['characteristics'] ?? ''),
            // Резерв вычтен: обещать зарезервированное второй раз нельзя
            'stock'           => Alternatives::freeStock($row),
            'variant_label'   => $isVariant ? self::label($row) : '',
            'group_name'      => $isVariant ? (string)($parent['name'] ?? '') : '',
            'group_article'   => $isVariant ? (string)($parent['article'] ?? '') : '',
            'price_max'       => 0.0,
            'is_group'        => 0,
        ];
    }

    /** Остатки по модификациям одного товара, или null — модификаций нет. */
    public static function stockOf(string $productId): ?array {
        return self::stockFor([$productId])[$productId] ?? null;
    }

    /**
     * Свободный остаток строки каталога так, как его видит человек: у товара с
     * модификациями это сумма модификаций, у товара без них — его собственный.
     */
    public static function freeStock(array $product): int {
        require_once __DIR__ . '/alternatives.php';
        $id = (string)($product['moysklad_id'] ?? '');
        // У самой модификации своих модификаций нет — её остаток и есть её остаток
        if ($id !== '' && (string)($product['product_type'] ?? '') !== 'variant') {
            $byVariants = self::stockOf($id);
            if ($byVariants !== null) return $byVariants['free'];
        }
        return Alternatives::freeStock($product);
    }

    /**
     * Чем модификация называется в списке: «S», «олива», «Coyote Brown · arc».
     *
     * Характеристик у модификации бывает несколько — тогда метка это ВСЕ их
     * значения, а не название товара целиком: список, где каждая строка
     * начинается с одного и того же длинного имени, не читается, и разобрать
     * в нём, какого цвета сколько, нельзя.
     */
    public static function label(array $variant): string {
        $values = self::labelValues($variant);
        if ($values) return implode(' · ', $values);

        // Характеристик нет вовсе — остаётся имя; у модификации оно длиннее
        // имени товара ровно на то, чем она от него отличается
        $name = trim((string)($variant['name'] ?? ''));
        $parent = trim((string)($variant['parent_name'] ?? ''));
        if ($parent !== '' && mb_stripos($name, $parent) === 0) {
            // trim() режет по байтам, а тире здесь многобайтные — только регуляркой
            $tail = (string)preg_replace('/^[\s\-–—,;()]+|[\s\-–—,;()]+$/u', '',
                                         mb_substr($name, mb_strlen($parent)));
            if ($tail !== '') return $tail;
        }
        return $name;
    }

    /**
     * Характеристики парами: «Цвет: олива; Размер: L» → [Цвет => олива, Размер => L]
     * (модуль 058). API пишет через «;», Excel — через «,», импорт без колонки —
     * в скобках имени. Значение без названия получает ключ «».
     */
    public static function characteristicPairs(array $variant): array {
        $source = trim((string)($variant['characteristics'] ?? ''));
        if ($source === '' && preg_match('/\(([^)]+)\)\s*$/u', (string)($variant['name'] ?? ''), $m)) {
            $source = $m[1];
        }
        $out = [];
        // Делим только перед «Название:», чтобы запятая внутри значения его не рвала
        foreach (preg_split('/[;\n]+|,(?=\s*[^,:;]+:)/u', $source) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            $colon = mb_strpos($chunk, ':');
            $name = $colon === false ? '' : trim(mb_substr($chunk, 0, $colon));
            $value = trim($colon === false ? $chunk : mb_substr($chunk, $colon + 1));
            if ($value !== '' && !isset($out[$name])) $out[$name] = $value;
        }
        return $out;
    }

    /** Значения характеристик: «Цвет: олива; Размер: L» → [«олива», «L»]. */
    private static function labelValues(array $variant): array {
        $source = trim((string)($variant['characteristics'] ?? ''));
        // Импорт из Excel характеристик отдельно не знает — они в скобках имени
        if ($source === '' && preg_match('/\(([^)]+)\)\s*$/u', (string)($variant['name'] ?? ''), $m)) {
            $source = $m[1];
        }
        $out = [];
        foreach (preg_split('/[;,\n]+/u', $source) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            $colon = mb_strpos($chunk, ':');
            $value = trim($colon === false ? $chunk : mb_substr($chunk, $colon + 1));
            if ($value !== '') $out[] = $value;
        }
        return $out;
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
        return self::pickFor($variants, $label);
    }

    /** Цвет в письме по-русски, в каталоге — как у производителя. */
    private const COLOR_SYNONYMS = [
        'койот' => 'coyote', 'мультикам' => 'multicam', 'олива' => 'olive', 'оливковый' => 'olive',
        'черный' => 'black', 'чёрный' => 'black', 'хаки' => 'khaki', 'песочный' => 'sand', 'песок' => 'sand',
        'серый' => 'grey', 'зеленый' => 'green', 'зелёный' => 'green', 'мох' => 'moss', 'рейнджер' => 'ranger',
        'белый' => 'white', 'синий' => 'blue', 'бежевый' => 'beige', 'коричневый' => 'brown', 'пиксель' => 'pixel',
    ];

    /**
     * Модификация этого размера — та, что клиент может получить (issue #132).
     *
     * Размер «L» у шлема есть в трёх цветах. Порядок решения: метка (точное
     * значение, потом целым словом), затем цвет, который назван в письме
     * ($context), затем свободный остаток — хватает на количество, есть хоть
     * сколько-то, больше всего, — и имя. Результат несёт `other_choices`:
     * другие модификации этого размера, которые есть на складе, — строка
     * скажет менеджеру, что цвет выбрали мы.
     */
    public static function pickFor(array $variants, string $label, string $context = '', float $qty = 0): ?array {
        require_once __DIR__ . '/alternatives.php';
        $needle = mb_strtolower(trim($label));
        if ($needle === '') return null;

        $hits = self::ofLabel($variants, $needle);
        if (!$hits) return null;

        $ctx = mb_strtolower($context);
        if ($ctx !== '' && count($hits) > 1) {
            $named = array_values(array_filter($hits, fn($v) => self::namedIn($v, $needle, $ctx)));
            if ($named) $hits = $named;
        }
        usort($hits, function ($a, $b) use ($qty) {
            $key = function ($v) use ($qty) {
                $free = Alternatives::freeStock($v);
                return [$qty > 0 && $free >= $qty ? 0 : 1, $free > 0 ? 0 : 1, -$free, (string)($v['name'] ?? '')];
            };
            return $key($a) <=> $key($b);
        });

        $pick = $hits[0];
        $pick['other_choices'] = [];
        foreach (array_slice($hits, 1) as $v) {
            if (Alternatives::freeStock($v) > 0) $pick['other_choices'][] = self::label($v);
        }
        return $pick;
    }

    /**
     * Модификации с этой меткой: точным значением, а нет таких — целым словом
     * («L» против «L(60-62)»).
     */
    public static function ofLabel(array $variants, string $label): array {
        $needle = mb_strtolower(trim($label));
        if ($needle === '') return [];
        $hits = array_values(array_filter($variants, function ($v) use ($needle) {
            foreach (self::values($v) as $value) if (mb_strtolower($value) === $needle) return true;
            return false;
        }));
        if ($hits) return $hits;
        $re = '/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u';
        return array_values(array_filter($variants, function ($v) use ($re) {
            foreach (self::values($v) as $value) if (preg_match($re, mb_strtolower($value))) return true;
            return false;
        }));
    }

    /** Названо ли в письме что-то, кроме размера, из характеристик модификации (цвет). */
    private static function namedIn(array $variant, string $sizeNeedle, string $ctx): bool {
        // Значение размера пропускаем — целым словом: «l» есть и в «multicam»
        $size = '/(?<![\p{L}\p{N}])' . preg_quote($sizeNeedle, '/') . '(?![\p{L}\p{N}])/u';
        foreach (self::characteristicPairs($variant) as $value) {
            $value = mb_strtolower(trim($value));
            if ($value === '' || preg_match($size, $value)) continue;
            foreach (preg_split('/[^\p{L}]+/u', $value) ?: [] as $word) {
                if (mb_strlen($word) < 3) continue;
                if (str_contains($ctx, $word)) return true;
                foreach (self::COLOR_SYNONYMS as $ru => $en) {
                    if ($en === $word && str_contains($ctx, $ru)) return true;
                }
            }
        }
        return false;
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
    public static function resolveRow(array $row, ?int $counterpartyId = null, string $context = ''): array {
        $label = trim((string)($row['variant_label'] ?? ''));
        $matched = trim((string)($row['moysklad_product_id'] ?? ''));
        if ($label === '' || $matched === '') return [];

        // Подбор мог попасть и в сам товар, и сразу в одну из его модификаций —
        // семья ищется от корня, иначе у модификации «своих» модификаций нет
        $parentId = (string)(Db::val("SELECT parent_id FROM products_cache WHERE moysklad_id=?", [$matched]) ?: '')
                 ?: $matched;

        $variants = self::forProduct($parentId);
        if (!$variants) return [];

        $context = trim($context . ' ' . (string)($row['raw_name'] ?? ''));
        $pick = self::pickFor($variants, $label, $context, (float)($row['quantity'] ?? 0));
        if (!$pick) {
            // Подсказка менеджеру, не клиенту: в КП печатается `notes`, а это — нет
            return ['match_hint' => 'модификации «' . $label . '» нет в каталоге — проверьте размер'];
        }

        require_once __DIR__ . '/catalog.php';
        require_once __DIR__ . '/alternatives.php';
        require_once __DIR__ . '/terms.php';
        $out = [
            'moysklad_product_id' => $pick['moysklad_id'],
            'product_name'        => $pick['name'],
            'article'             => $pick['article'],
            'unit'                => $pick['unit'] ?: 'шт.',
            'price'               => Catalog::priceFor($pick, $counterpartyId),
            'stock'               => Alternatives::freeStock($pick),
            'match_source'        => 'модификация',
        ];
        // Цвет в письме не назван, а на складе их несколько — выбрали мы, и строка это говорит
        if ($pick['other_choices']) {
            $out['match_hint'] = 'выбрано: ' . self::label($pick) . '; есть также: '
                               . implode(', ', array_slice($pick['other_choices'], 0, 4));
        }
        return $out;
    }

    private static function tidy(string $text): string {
        $text = str_replace(["\xC2\xA0", "\r"], [' ', ' '], $text);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }
}
