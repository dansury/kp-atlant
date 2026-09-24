<?php
/**
 * Fact sources for a drafted answer (module 006).
 *
 * The wiki knows how a product is built; only MoySklad knows its price and stock,
 * and only the orders table knows where a customer's parcel is. A draft that
 * invents any of the three is worse than no draft, so each of them is looked up
 * here and handed to the model as an explicit block.
 */
final class Catalog {

    /** Products from the local MoySklad cache that a letter is likely about. */
    public static function lookup(string $query, int $limit = 0): array {
        $limit = $limit ?: max(1, (int)Settings::get('TRIAGE_CATALOG_LIMIT', 6));
        $terms = self::terms($query);
        if (!$terms) return [];

        $rows = [];
        foreach ($terms as $term) {
            foreach (Db::all(
                "SELECT moysklad_id, name, article, price, stock, reserved, unit
                 FROM products_cache WHERE name_normalized LIKE ? LIMIT 20", ['%' . $term . '%']
            ) as $row) {
                $id = $row['moysklad_id'];
                // A product matched by two terms of the request is a better hit than by one
                $rows[$id] = $rows[$id] ?? $row + ['hits' => 0];
                $rows[$id]['hits']++;
            }
        }
        if (!$rows) return [];

        $rows = array_values($rows);
        usort($rows, fn($a, $b) => [$b['hits'], (int)$b['stock']] <=> [$a['hits'], (int)$a['stock']]);
        return array_slice($rows, 0, $limit);
    }

    /** `{{catalog}}` block: what we actually sell, with real prices and stock. */
    public static function block(string $query): string {
        $rows = self::lookup($query);
        if (!$rows) {
            return "===== КАТАЛОГ =====\nПо этому письму в каталоге ничего не найдено.\n"
                 . "Не называй цены и остатки — напиши, что уточнишь позицию и вернёшься с ответом.\n"
                 . "===== КОНЕЦ КАТАЛОГА =====";
        }
        $out = "===== КАТАЛОГ (МойСклад, актуальные цены и наличие) =====\n"
             . "Цены — за единицу товара. Наличие — «в наличии» или «нет в наличии»; "
             . "количество на складе клиенту не называй.\n"
             . "Называй цену и наличие ТОЛЬКО отсюда. Чего здесь нет — обещай уточнить.\n\n";
        foreach ($rows as $r) {
            $free = max(0, (int)$r['stock'] - (int)$r['reserved']);
            $out .= '- ' . $r['name']
                 . ($r['article'] ? " (арт. {$r['article']})" : '')
                 . ' — ' . self::money((float)$r['price']) . ' за ' . ($r['unit'] ?: 'шт.')
                 . ($free > 0 ? ', в наличии' : ', нет в наличии') . "\n";
        }
        return rtrim($out) . "\n===== КОНЕЦ КАТАЛОГА =====";
    }

    /**
     * `{{orders}}` block for a «где мой заказ» letter. Orders are found by the
     * number the client quotes («заказ 7136», «№6919») and by their email —
     * whichever the letter gives us.
     */
    public static function ordersBlock(string $text, string $email, ?int $counterpartyId = null): string {
        $orders = self::orders($text, $email, $counterpartyId);
        if (!$orders) {
            return "===== ЗАКАЗЫ КЛИЕНТА =====\nЗаказ по номеру и адресу не найден.\n"
                 . "Не выдумывай статус: попроси номер заказа или напиши, что проверишь и вернёшься.\n"
                 . "===== КОНЕЦ ЗАКАЗОВ =====";
        }
        $out = "===== ЗАКАЗЫ КЛИЕНТА (МойСклад) =====\n";
        foreach ($orders as $o) {
            $out .= '- Заказ ' . ($o['name'] ?: $o['moysklad_id'])
                 . ' от ' . substr((string)$o['moment'], 0, 10)
                 . ', статус «' . ($o['state_name'] ?: 'не указан') . '»'
                 . ', сумма ' . self::money((float)$o['sum']) . "\n";
        }
        return rtrim($out) . "\n===== КОНЕЦ ЗАКАЗОВ =====";
    }

    /** @return array<int,array<string,mixed>> */
    public static function orders(string $text, string $email, ?int $counterpartyId = null): array {
        $found = [];
        // «заказ 7136», «№ 6919», «Новый заказ N6764» — the number the client
        // quotes. The latin N is how the shop's own notification writes it, and
        // 141 letters of the archive are replies to exactly that subject line,
        // so the number lives in the SUBJECT and nowhere else (module 015).
        if (preg_match_all('/(?:заказ\w*|№|#)\s*[№#NnНн]?\s*(\d{3,8})/iu', $text, $m)) {
            foreach (array_unique($m[1]) as $num) {
                foreach (Db::all("SELECT * FROM orders WHERE name LIKE ? ORDER BY id DESC LIMIT 3", ['%' . $num . '%']) as $row) {
                    $found[$row['id']] = $row;
                }
            }
        }
        if ($counterpartyId) {
            foreach (Db::all("SELECT * FROM orders WHERE counterparty_id=? ORDER BY moment DESC LIMIT 5", [$counterpartyId]) as $row) {
                $found[$row['id']] = $row;
            }
        }
        if (!$found && $email !== '') {
            foreach (Db::all(
                "SELECT o.* FROM orders o JOIN counterparties c ON c.id = o.counterparty_id
                 WHERE c.contact_email = ? ORDER BY o.moment DESC LIMIT 5", [$email]
            ) as $row) {
                $found[$row['id']] = $row;
            }
        }
        return array_values($found);
    }

    /**
     * Words of a request worth searching the catalog by. Short words and the
     * pleasantries every letter opens with would match half the nomenclature.
     */
    private static function terms(string $query): array {
        $query = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query));
        $stop = ['прошу', 'просим', 'добрый', 'здравствуйте', 'пожалуйста', 'уважаемые', 'коммерческое',
                 'предложение', 'поставки', 'поставку', 'выставить', 'направить', 'подскажите', 'стоимость',
                 'количество', 'наличие', 'уважением', 'спасибо', 'заранее', 'компания', 'который', 'который'];
        $out = [];
        foreach (preg_split('/\s+/u', trim($query)) ?: [] as $w) {
            if (mb_strlen($w) < 5 || in_array($w, $stop, true)) continue;
            // Cut the Russian ending so «бронежилеты» finds «бронежилет»
            $out[mb_substr($w, 0, 7)] = true;
            if (count($out) >= 12) break;
        }
        return array_keys($out);
    }

    private static function money(float $v): string {
        return number_format($v, 2, ',', ' ') . ' ₽';
    }

    // ---------------------------------------------------------------- price types

    /**
     * The price to actually use for one catalog row, resolved in the order a
     * manager expects: an explicit choice for this one line beats the
     * counterparty's own default, which beats the service-wide default, which
     * beats whatever `products_cache.price` already holds (a catalog synced
     * before this feature existed still has exactly one price per product).
     */
    public static function priceFor(array $product, ?int $counterpartyId = null, ?string $priceType = null): float {
        return self::priceRange($product, $counterpartyId, $priceType)['min'];
    }

    /**
     * Цена строки каталога ВИЛКОЙ: сколько стоит дешёвая и сколько дорогая
     * (модуль 036).
     *
     * У общего товара цены часто нет вовсе — она проставлена на модификациях, и
     * ровно потому, что они стоят по-разному. Такой товар печатался в КП нулём.
     * Теперь он отвечает своими модификациями: `min` — с чего начинается,
     * `max` — чем кончается. Цены совпали — вилки нет, `min === max`, и
     * документ печатает одну цену, а не «от 1 200 до 1 200».
     *
     * Обратное правило — модификации без цены — стоит здесь же и работает в ту
     * же сторону: цену ей даёт товар-родитель, ПО ТИПУ ЦЕНЫ (модуль 023).
     *
     * @return array{min:float,max:float}
     */
    public static function priceRange(array $product, ?int $counterpartyId = null, ?string $priceType = null): array {
        $prices = self::decodePrices($product['prices_json'] ?? null);
        // У модификации цены может не быть вовсе, а нужного ТИПА цены — не быть
        // даже когда другие типы есть. И то, и другое берётся с товара-родителя:
        // ноль в этой строке — это КП на ноль рублей (модуль 023).
        $prices = $prices + self::parentPrices($product);

        $wanted = self::wantedType($counterpartyId, $priceType);
        $own = (float)($product['price'] ?? 0);

        if ($wanted !== '' && array_key_exists($wanted, $prices) && (float)$prices[$wanted] > 0) {
            return self::flat((float)$prices[$wanted]);
        }
        if ($own > 0) return self::flat($own);

        // Ни выбранного типа, ни своей цены. Своя цена есть у модификаций —
        // спрашиваем их, и это вилка; нет модификаций — годится любая цена
        // родителя, лишь бы это не был ноль.
        $range = self::variantRange($product, $counterpartyId, $priceType);
        if ($range) return $range;

        foreach ($prices as $value) {
            if ((float)$value > 0) return self::flat((float)$value);
        }
        return self::flat($own);
    }

    /** Тип цены: выбор на строке → тип контрагента → настройка сервиса. */
    private static function wantedType(?int $counterpartyId, ?string $priceType): string {
        $wanted = $priceType;
        if ($wanted === null && $counterpartyId) {
            $wanted = (string)(Db::val("SELECT default_price_type FROM counterparties WHERE id=?", [$counterpartyId]) ?: '') ?: null;
        }
        return (string)($wanted ?? Settings::get('CATALOG_DEFAULT_PRICE_TYPE', ''));
    }

    /** @return array{min:float,max:float} */
    private static function flat(float $v): array {
        return ['min' => $v, 'max' => $v];
    }

    /**
     * Вилка по модификациям товара, или null — модификаций нет или все они
     * тоже без цены. Сама модификация сюда не ходит: у неё своих модификаций
     * нет, и рекурсия была бы лишним запросом на каждую строку подбора.
     *
     * @return array{min:float,max:float}|null
     */
    private static function variantRange(array $product, ?int $counterpartyId, ?string $priceType): ?array {
        $id = trim((string)($product['moysklad_id'] ?? ''));
        if ($id === '' || (string)($product['product_type'] ?? '') === 'variant') return null;

        $values = [];
        foreach (Db::all("SELECT moysklad_id, price, prices_json, parent_id, product_type
                          FROM products_cache
                          WHERE parent_id=? AND COALESCE(is_archived, 0) = 0", [$id]) as $variant) {
            // Родителя модификация уже не переспрашивает: его цены мы только что
            // не нашли, и второй заход в базу на каждую из них ничего не даст
            $variant['parent_id'] = '';
            $price = self::priceFor($variant, $counterpartyId, $priceType);
            if ($price > 0) $values[] = $price;
        }
        if (!$values) return null;
        return ['min' => min($values), 'max' => max($values)];
    }

    /**
     * Расшифровка вилки: какая модификация сколько стоит (модуль 058).
     *
     * Только по характеристикам, от которых цена ЗАВИСИТ: цвет цену не
     * меняет — строки называют одни размеры. Одинаковые цены сливаются в
     * одну строку. Пусто — вилки нет.
     *
     * @return array<int,array{label:string,price:float}>
     */
    public static function rangeBreakdown(array $product, ?int $counterpartyId = null, ?string $priceType = null): array {
        require_once __DIR__ . '/variants.php';
        $id = trim((string)($product['moysklad_id'] ?? ''));
        if ($id === '' || (string)($product['product_type'] ?? '') === 'variant') return [];

        $rows = [];
        foreach (Db::all("SELECT moysklad_id, name, price, prices_json, product_type, characteristics
                          FROM products_cache
                          WHERE parent_id=? AND COALESCE(is_archived, 0) = 0 ORDER BY id", [$id]) as $v) {
            $v['parent_id'] = '';   // как в variantRange(): без второго захода к родителю
            $price = self::priceFor($v, $counterpartyId, $priceType);
            if ($price > 0) $rows[] = ['chars' => Variants::characteristicPairs($v), 'price' => round($price, 2)];
        }
        if (count(array_unique(array_column($rows, 'price'))) < 2) return [];

        // Ключи в порядке появления; лишние — те, без которых цена всё ещё однозначна
        $keys = [];
        foreach ($rows as $r) foreach (array_keys($r['chars']) as $k) $keys[$k] = true;
        $keys = array_keys($keys);
        if (!self::priceDependsOn($rows, $keys)) {
            $keys = [];   // даже все характеристики цену не объясняют — строки целиком
        } else {
            foreach ($keys as $k) {
                $rest = array_values(array_diff($keys, [$k]));
                if (self::priceDependsOn($rows, $rest)) $keys = $rest;
            }
        }

        // Группа — набор значений нужных характеристик; одинаковые цены — одна строка
        $byPrice = [];
        foreach ($rows as $r) {
            $parts = [];
            foreach ($keys ?: array_keys($r['chars']) as $k) {
                $value = (string)($r['chars'][$k] ?? '');
                if ($value === '') continue;
                $parts[] = count($keys) === 1 ? $value : ($k !== '' ? "$k: $value" : $value);
            }
            $label = implode(', ', $parts);
            $key = (string)$r['price'];
            if (!isset($byPrice[$key])) $byPrice[$key] = ['price' => $r['price'], 'labels' => []];
            if ($label !== '' && !in_array($label, $byPrice[$key]['labels'], true)) $byPrice[$key]['labels'][] = $label;
        }
        usort($byPrice, fn($a, $b) => $a['price'] <=> $b['price']);

        $out = [];
        foreach ($byPrice as $g) {
            if (!$g['labels']) continue;
            $label = count($keys) === 1
                ? ($keys[0] !== '' ? $keys[0] . ': ' : '') . implode(', ', $g['labels'])
                : implode('; ', $g['labels']);
            $out[] = ['label' => $label, 'price' => (float)$g['price']];
        }
        return count($out) >= 2 ? $out : [];
    }

    /** Одна ли цена у каждого сочетания значений $keys. */
    private static function priceDependsOn(array $rows, array $keys): bool {
        $seen = [];
        foreach ($rows as $r) {
            $sig = implode("\x1f", array_map(fn($k) => (string)($r['chars'][$k] ?? ''), $keys));
            if (isset($seen[$sig]) && $seen[$sig] !== $r['price']) return false;
            $seen[$sig] = $r['price'];
        }
        return true;
    }

    /** Цены товара-родителя модификации, по типам. Для товара — пустой массив. */
    private static function parentPrices(array $product): array {
        $parentId = trim((string)($product['parent_id'] ?? ''));
        if ($parentId === '') return [];

        $row = Db::one("SELECT price, prices_json FROM products_cache WHERE moysklad_id=?", [$parentId]);
        if (!$row) return [];

        $prices = self::decodePrices($row['prices_json'] ?? null);
        if (!$prices && (float)($row['price'] ?? 0) > 0) {
            $prices[(string)Settings::get('CATALOG_DEFAULT_PRICE_TYPE', 'Цена продажи')] = (float)$row['price'];
        }
        return $prices;
    }

    /** `{type name: value}` decoded from products_cache.prices_json, or []. */
    public static function decodePrices(?string $json): array {
        if (!$json) return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Every price type the synced catalog knows about — for the settings/pickers. */
    public static function priceTypes(): array {
        $types = [];
        foreach (Db::all("SELECT prices_json FROM products_cache WHERE prices_json IS NOT NULL AND prices_json <> ''") as $row) {
            foreach (array_keys(self::decodePrices($row['prices_json'])) as $t) $types[$t] = true;
        }
        return array_keys($types);
    }
}
