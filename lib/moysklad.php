<?php
/**
 * MoySklad JSON API 1.2 wrapper.
 * Product search, counterparties, orders, cache.
 */
class MoySklad {
    private static string $token = '';
    private static string $base = 'https://api.moysklad.ru/api/remap/1.2';
    private static array $permissions = [];
    // Last HTTP response, for diagnostics
    private static array $lastHttp = ['code' => 0, 'body' => '', 'path' => ''];
    private static array $diag = [];

    public static function init(string $token): void {
        self::$token = $token;
    }

    // Runtime permission check (C-004)
    public static function checkPermissions(): array {
        $perms = ['products' => false, 'counterparties' => false, 'orders_read' => false,
                  'orders_write' => false, 'stock' => false, 'invoices' => false, 'webhooks' => false];

        self::$diag = [];

        // Products — required
        $r = self::tryGet('/entity/product?limit=1');
        $perms['products'] = ($r !== null);
        self::$diag['products'] = self::$lastHttp;

        // Counterparties — required
        $r = self::tryGet('/entity/counterparty?limit=1');
        $perms['counterparties'] = ($r !== null);
        self::$diag['counterparties'] = self::$lastHttp;

        // Orders read
        $r = self::tryGet('/entity/customerorder?limit=1');
        $perms['orders_read'] = ($r !== null);

        // Stock
        $r = self::tryGet('/report/stock/all?limit=1');
        $perms['stock'] = ($r !== null);

        // Invoices read (module 002)
        $r = self::tryGet('/entity/invoiceout?limit=1');
        $perms['invoices'] = ($r !== null);

        // Webhooks (module 002) — read access implies the scope is granted
        $r = self::tryGet('/entity/webhook?limit=1');
        $perms['webhooks'] = ($r !== null);

        // Orders write — try with dry check (HEAD or tiny POST would fail gracefully)
        $perms['orders_write'] = $perms['orders_read']; // assume if read works

        if (!$perms['products'] || !$perms['counterparties']) {
            $codes = [];
            foreach (self::$diag as $name => $d) $codes[] = $name . '=HTTP ' . $d['code'];
            $code = (int)(self::$diag['products']['code'] ?? 0);
            $hint = match (true) {
                $code === 401 => 'токен неверный, отозван или не тот скопирован',
                $code === 403 => 'у сотрудника, чей токен используется, нет прав на товары/контрагентов',
                $code === 0   => 'запрос до api.moysklad.ru не дошёл (сеть/файрвол хостинга)',
                $code === 415 => 'API отклонил заголовки запроса (Content-Type на GET) — обновите код на сервере',
                default       => 'см. ответ API',
            };
            $body = trim((string)(self::$diag['products']['body'] ?? ''));
            throw new MoySkladException(
                'МойСклад: нет доступа к товарам или контрагентам (' . implode(', ', $codes) . '). '
                . 'Вероятная причина: ' . $hint . '.'
                . ($body !== '' ? ' Ответ API: ' . $body : '')
            );
        }

        self::$permissions = $perms;
        return $perms;
    }

    public static function getPermissions(): array {
        return self::$permissions;
    }

    // Diagnostics for the settings page: HTTP codes and MoySklad error text
    public static function getDiagnostics(): array {
        $t = self::$token;
        return [
            'token_len'  => strlen($t),
            // Both ends of the token, so the operator can tell which one is
            // stored without it ever being readable (Settings::mask)
            'token_mask' => Settings::mask($t),
            'token_tail' => $t === '' ? '' : substr($t, -4),
            'org_id'     => (string)Settings::get('MOYSKLAD_ORG_ID', ''),
            'probes'     => self::$diag,
        ];
    }

    public static function lastHttp(): array {
        return self::$lastHttp;
    }

    // Search products by name
    public static function searchProducts(string $query, int $limit = 25): array {
        $q = urlencode($query);
        $data = self::get("/entity/product?filter=name~=$q&limit=$limit&expand=salePrices");
        if (!$data || empty($data['rows'])) return [];

        return array_map(fn($p) => self::mapProduct($p), $data['rows']);
    }

    // Get single product
    public static function getProduct(string $id): ?array {
        $data = self::get("/entity/product/$id");
        return $data ? self::mapProduct($data) : null;
    }

    // Refresh product cache — paginate through all products
    public static function refreshProductCache(): int {
        $offset = 0;
        $limit = 1000;
        $count = 0;
        self::$folderNames = null;

        do {
            $data = self::get("/entity/product?limit=$limit&offset=$offset&expand=salePrices");
            if (!$data || empty($data['rows'])) break;

            // Products in this MoySklad folder are offered as upsell modules
            $addonCategory = Db::val("SELECT value FROM settings WHERE key='addon_category'") ?: '';

            foreach ($data['rows'] as $p) {
                $mapped = self::mapProduct($p);
                // /u matters: without it the byte 0xBB of «»» is stripped out of
                // every «л» and the normalized name stops matching anything
                $normalized = trim(mb_strtolower(preg_replace('/[\s\-\"\'«»()]+/u', ' ', $mapped['name'])));
                $category = $mapped['category'] ?? '';
                $isAddon = ($addonCategory !== '' && $category === $addonCategory) ? 1 : 0;

                Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, code, price, prices_json, stock, reserved, unit, description, category, is_addon, vat, product_type, source, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'product', 'moysklad', datetime('now'))
                    ON CONFLICT(moysklad_id) DO UPDATE SET
                        name=excluded.name, name_normalized=excluded.name_normalized,
                        article=excluded.article, code=excluded.code, price=excluded.price,
                        prices_json=excluded.prices_json,
                        -- Остаток здесь НЕ трогается. `/entity/product` его не
                        -- отдаёт, `mapProduct()` ставит ноль-заглушку, и запись
                        -- этого нуля переводила весь каталог в «под заказ»
                        -- до следующего удачного отчёта — а если у токена нет
                        -- прав на отчёт, то навсегда (модуль 040). Остаток
                        -- пишет только `refreshStock()`.
                        unit=excluded.unit, description=excluded.description,
                        category=excluded.category, is_addon=excluded.is_addon,
                        vat=COALESCE(excluded.vat, products_cache.vat),
                        source='moysklad', updated_at=datetime('now')", [
                    $mapped['id'], $mapped['name'], $normalized,
                    $mapped['article'], $mapped['code'], $mapped['price'],
                    $mapped['prices'] ? json_encode($mapped['prices'], JSON_UNESCAPED_UNICODE) : null,
                    $mapped['stock'], $mapped['reserved'],
                    $mapped['unit'], $mapped['description'], $category, $isAddon,
                    $mapped['vat'],
                ]);
                $count++;
            }

            $offset += $limit;
        } while (count($data['rows']) === $limit);

        // Модификации и остатки — часть того же каталога (модуль 022). Обе
        // выгрузки необязательные: у токена может не быть прав на отчёт по
        // остаткам, и каталог от этого не должен перестать обновляться.
        try {
            $count += self::refreshVariantCache();
        } catch (Throwable $e) {
            Logger::warning('catalog', 'Модификации не загрузились: ' . $e->getMessage());
        }
        if ((int)Settings::get('MOYSKLAD_STOCK_SYNC', 1) === 1) {
            try {
                self::refreshStock();
            } catch (Throwable $e) {
                Logger::warning('catalog', 'Остатки не загрузились: ' . $e->getMessage());
            }
        }

        return $count;
    }

    // ======================= Склады и остатки (модуль 022) =======================

    /**
     * Склады МойСклад: id, имя, адрес, архивный ли.
     *
     * Нужны, чтобы можно было ВЫБРАТЬ, с каких складов считать остаток.
     * Раньше остаток не читался вовсе — `mapProduct()` писал в кэш ноль, и
     * каждая позиция КП уходила «под заказ», даже когда товар лежал на полке.
     */
    public static function stores(): array {
        $data = self::get('/entity/store?limit=200');
        if (!$data || empty($data['rows'])) return [];

        return array_map(fn($s) => [
            'id'       => self::extractId($s['id'] ?? $s['meta']['href'] ?? ''),
            'name'     => (string)($s['name'] ?? ''),
            'address'  => (string)($s['address'] ?? ''),
            'archived' => (bool)($s['archived'] ?? false),
        ], $data['rows']);
    }

    /** Выбранные склады — то, что стоит в `MOYSKLAD_STORES`. Пусто = все. */
    public static function selectedStores(): array {
        $raw = (string)Settings::get('MOYSKLAD_STORES', '');
        $ids = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: [])));
        return $ids;
    }

    /**
     * Склад заказа и счёта (модуль 053): выбранный, если он жив; затем первый
     * из складов остатков; затем первый неархивный. Складов нет — null.
     *
     * @param array $stores   stores(): [{id, name, archived}]
     * @param string $wanted  выбор менеджера или `MS_ORDER_STORE`
     * @param array $selected `MOYSKLAD_STORES`
     */
    public static function pickStore(array $stores, string $wanted, array $selected): ?string {
        $live = array_values(array_map(fn($s) => (string)$s['id'],
            array_filter($stores, fn($s) => empty($s['archived']) && (string)($s['id'] ?? '') !== '')));
        foreach (array_merge([trim($wanted)], $selected) as $id) {
            if ($id !== '' && in_array($id, $live, true)) return $id;
        }
        return $live[0] ?? null;
    }

    /** Склад по умолчанию — из настроек и живого списка МойСклад. */
    public static function defaultStoreId(string $wanted = ''): ?string {
        if ($wanted === '') $wanted = (string)Settings::get('MS_ORDER_STORE', '');
        return self::pickStore(self::stores(), $wanted, self::selectedStores());
    }

    /**
     * Перечитать остатки и записать их в `products_cache`.
     *
     * Отчёт `/report/stock/all` отдаёт и товары, и модификации одной таблицей —
     * это ровно то, что нам нужно: у размера L остаток свой, а не общий на
     * товар. Склады выбираются в настройках; ни одного не выбрано — считаем по
     * всем, как считает сам МойСклад.
     *
     * `reserve` — то, что уже обещано другим, и оно вычитается из свободного
     * остатка везде дальше (`Alternatives::freeStock`). Обещать зарезервированное
     * второй раз — это сорванный срок, а не оптимизм.
     *
     * @return array{rows:int,updated:int,stores:int}
     */
    public static function refreshStock(): array {
        $stores = self::selectedStores();

        // Склады спрашиваются ПО ОДНОМУ. Отчёт «Остатки» читает несколько
        // значений `store` в фильтре не как «или», а как «и», и на двух
        // выбранных складах отвечает пустой таблицей — из-за чего кнопка
        // «Пересчитать остатки» отрабатывала «успешно», а в каталоге всё
        // оставалось по нулям (`mapProduct()` пишет туда ноль).
        $batches = $stores ?: [null];

        $totals = [];        // moysklad_id => [stock, reserve], сложенные по складам
        $rows = 0;
        $errors = [];
        $usedFallback = false;

        foreach ($batches as $storeId) {
            try {
                $rows += self::readStockReport($storeId, $totals);
            } catch (Throwable $e) {
                $errors[] = ($storeId !== null ? "склад $storeId: " : '') . $e->getMessage();
            }
        }

        // Ни одной строки — это не «на складе пусто», это «отчёт не ответил».
        // Записать сюда нули значит перевести весь каталог в «под заказ», а
        // это уже обещание клиенту, поэтому сначала пробуем спросить иначе.
        if (!$totals) {
            if ($stores) {
                try {
                    $rows += self::readStockReport(null, $totals);
                    if ($totals) $usedFallback = true;
                } catch (Throwable $e) {
                    $errors[] = 'без фильтра по складам: ' . $e->getMessage();
                }
            }
            if (!$totals) {
                try {
                    $rows += self::readAssortmentStock($totals);
                    if ($totals) $usedFallback = true;
                } catch (Throwable $e) {
                    $errors[] = 'ассортимент: ' . $e->getMessage();
                }
            }
        }

        // Отчёт ответил, но про МОДИФИКАЦИИ в нём ни строчки, а в каталоге они
        // есть: у части аккаунтов «Остатки» отдают только товары, и все размеры
        // оставались с нулём, хотя лежат на полке. Спрашиваем ассортимент —
        // он знает и модификации — и дополняем им отчёт, а не заменяем его.
        if ($totals && self::hasVariants() && !self::touchesVariants($totals)) {
            try {
                $rows += self::readAssortmentStock($totals);
                $usedFallback = true;
            } catch (Throwable $e) {
                $errors[] = 'ассортимент (модификации): ' . $e->getMessage();
            }
        }

        $updated = 0;
        foreach ($totals as $id => $pair) {
            $updated += Db::q(
                "UPDATE products_cache SET stock=?, reserved=?, updated_at=datetime('now') WHERE moysklad_id=?",
                [(int)round($pair[0]), (int)round($pair[1]), $id]
            )->rowCount();
        }

        $result = [
            'rows'     => $rows,
            'updated'  => $updated,
            'stores'   => count($stores),
            'fallback' => $usedFallback,
            'error'    => $errors ? implode('; ', array_unique($errors)) : null,
        ];

        // Когда остатки читались в последний раз — это видно в карточке
        // каталога: «всё под заказ» из-за молча упавшего отчёта не должно
        // выглядеть как пустой склад (модуль 040)
        if ($updated > 0) Settings::set('catalog_stock_synced_at', date('Y-m-d H:i:s'));

        // Отчёт, который не нашёл НИ ОДНОЙ нашей позиции, — это поломка, а не
        // пустой склад: пусть она видна в логе и в ответе кнопки, а не только
        // в нулях на карточке товара.
        if ($updated === 0) {
            Logger::warning('catalog', 'Остатки из МойСклад: ни одна позиция не совпала с каталогом', $result);
        } else {
            Logger::info('catalog', "Остатки из МойСклад: строк $rows, обновлено $updated", $result);
        }
        return $result;
    }

    /**
     * Одна прогонка отчёта «Остатки», страницами, в накопитель.
     *
     * @param array<string,array{0:float,1:float}> $totals накопитель, по ссылке
     * @return int сколько строк отчёта прочитано
     */
    private static function readStockReport(?string $storeId, array &$totals): int {
        $filter = '';
        if ($storeId !== null && $storeId !== '') {
            $filter = '&filter=' . rawurlencode('store=' . self::$base . '/entity/store/' . $storeId);
        }

        $offset = 0;
        $limit = 1000;
        $rows = 0;

        do {
            $data = self::get("/report/stock/all?limit=$limit&offset=$offset$filter");
            if (!$data || empty($data['rows'])) break;

            foreach ($data['rows'] as $row) {
                $rows++;
                $id = self::extractId((string)($row['meta']['href'] ?? ''));
                if ($id === '') continue;
                // `stock` — на складе всего, `reserve` — уже обещано
                self::addStock($totals, $id, (float)($row['stock'] ?? 0), (float)($row['reserve'] ?? 0));
            }

            $offset += $limit;
        } while (count($data['rows']) === $limit);

        return $rows;
    }

    /**
     * Запасной источник остатка — сам ассортимент.
     *
     * `/entity/assortment` отдаёт товары и модификации вместе с полями
     * `stock`/`reserve`, и на него хватает прав «читать товары»: токен без
     * доступа к ОТЧЁТАМ иначе оставляет весь каталог с нулевым остатком.
     */
    private static function readAssortmentStock(array &$totals): int {
        $offset = 0;
        $limit = 1000;
        $rows = 0;

        do {
            $data = self::get("/entity/assortment?limit=$limit&offset=$offset");
            if (!$data || empty($data['rows'])) break;

            foreach ($data['rows'] as $row) {
                $rows++;
                if (!array_key_exists('stock', $row)) continue;
                $id = self::extractId((string)($row['id'] ?? $row['meta']['href'] ?? ''));
                if ($id === '') continue;
                self::addStock($totals, $id, (float)($row['stock'] ?? 0), (float)($row['reserve'] ?? 0));
            }

            $offset += $limit;
        } while (count($data['rows']) === $limit);

        return $rows;
    }

    /** Есть ли в каталоге модификации вообще — иначе их нечего и искать. */
    private static function hasVariants(): bool {
        return (int)Db::val("SELECT COUNT(*) FROM products_cache WHERE product_type='variant'") > 0;
    }

    /** Попала ли в накопитель хоть одна модификация. */
    private static function touchesVariants(array $totals): bool {
        $ids = array_slice(array_keys($totals), 0, 900);
        if (!$ids) return false;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return (int)Db::val("SELECT COUNT(*) FROM products_cache
                             WHERE product_type='variant' AND moysklad_id IN ($ph)", $ids) > 0;
    }

    /** Остаток одной позиции, сложенный по всем прочитанным складам. */
    private static function addStock(array &$totals, string $id, float $stock, float $reserve): void {
        if (!isset($totals[$id])) $totals[$id] = [0.0, 0.0];
        $totals[$id][0] += $stock;
        $totals[$id][1] += $reserve;
    }

    // ===================== Модификации товара (модуль 022) =====================

    /**
     * Перечитать модификации — то, что МойСклад зовёт `variant`.
     *
     * Размер и цвет в МойСклад — это отдельная карточка со своим артикулом,
     * своей ценой и своим остатком, привязанная к товару. Без них запрос
     * «шлем: S-5, M-13, L-7» ложился в КП одной строкой на 25 штук, потому что
     * в каталоге просто не было, из чего выбрать.
     *
     * Строка кэша выглядит так же, как у импорта из Excel (модуль 008):
     * `product_type = 'variant'`, `parent_id` товара, характеристики строкой.
     * Так обе дороги в каталог дают одинаковый ответ.
     */
    public static function refreshVariantCache(): int {
        if ((int)Settings::get('MOYSKLAD_VARIANTS', 1) !== 1) return 0;
        self::$folderNames = null;

        $offset = 0;
        $limit = 1000;
        $count = 0;

        do {
            $data = self::get("/entity/variant?limit=$limit&offset=$offset&expand=product");
            if (!$data || empty($data['rows'])) break;

            foreach ($data['rows'] as $v) {
                $mapped = self::mapVariant($v);
                if ($mapped['id'] === '' || $mapped['name'] === '') continue;
                $normalized = trim(mb_strtolower((string)preg_replace('/[\s\-\"\'«»()]+/u', ' ', $mapped['name'])));

                Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, code, price,
                        prices_json, unit, description, category, vat, product_type, parent_id, characteristics,
                        is_archived, source, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'variant', ?, ?, ?, 'moysklad', datetime('now'))
                    ON CONFLICT(moysklad_id) DO UPDATE SET
                        name=excluded.name, name_normalized=excluded.name_normalized,
                        article=excluded.article, code=excluded.code, price=excluded.price,
                        prices_json=excluded.prices_json, unit=excluded.unit,
                        description=excluded.description, category=excluded.category,
                        vat=COALESCE(excluded.vat, products_cache.vat),
                        product_type='variant', parent_id=excluded.parent_id,
                        characteristics=excluded.characteristics, is_archived=excluded.is_archived,
                        source='moysklad', updated_at=datetime('now')", [
                    $mapped['id'], $mapped['name'], $normalized,
                    $mapped['article'], $mapped['code'], $mapped['price'],
                    $mapped['prices'] ? json_encode($mapped['prices'], JSON_UNESCAPED_UNICODE) : null,
                    $mapped['unit'], $mapped['description'], $mapped['category'], $mapped['vat'],
                    $mapped['parent_id'], $mapped['characteristics'], $mapped['archived'],
                ]);
                $count++;
            }

            $offset += $limit;
        } while (count($data['rows']) === $limit);

        return $count;
    }

    /** Модификация МойСклад → строка каталога: имя с характеристиками в скобках. */
    private static function mapVariant(array $v): array {
        $product = $v['product'] ?? [];
        $parentId = self::extractId((string)($product['id'] ?? $product['meta']['href'] ?? ''));

        $pairs = [];
        foreach ($v['characteristics'] ?? [] as $c) {
            $name = trim((string)($c['name'] ?? ''));
            $value = trim((string)($c['value'] ?? ''));
            if ($value === '') continue;
            $pairs[] = $name !== '' ? "$name: $value" : $value;
        }
        $characteristics = implode('; ', $pairs);

        $prices = [];
        foreach ($v['salePrices'] ?? [] as $sp) {
            $name = (string)($sp['priceType']['name'] ?? '');
            if ($name === '') continue;
            $prices[$name] = ($sp['value'] ?? 0) / 100;
        }
        // Своей цены у модификации может не быть — тогда она наследует товар,
        // и подставить ноль вместо неё значит выставить КП на ноль рублей.
        // Наследуется КАЖДЫЙ тип цены отдельно: у размера L своя «Розница», но
        // «Опт безнал» только у товара — и КП, выставляемое по опту, должно
        // взять оптовую цену товара, а не розничную модификации.
        if ($parentId !== '') {
            $parentPrices = $product['salePrices'] ?? null;
            if (is_array($parentPrices)) {
                $inherited = [];
                foreach ($parentPrices as $sp) {
                    $name = (string)($sp['priceType']['name'] ?? '');
                    if ($name === '') continue;
                    $inherited[$name] = ($sp['value'] ?? 0) / 100;
                }
            } else {
                $cached = Db::val("SELECT prices_json FROM products_cache WHERE moysklad_id=?", [$parentId]);
                $inherited = $cached ? (json_decode((string)$cached, true) ?: []) : [];
            }
            // Свои цены модификации сильнее унаследованных
            $prices = $prices + $inherited;
        }

        $wanted = (string)Settings::get('CATALOG_DEFAULT_PRICE_TYPE', '');
        $price = ($wanted !== '' && array_key_exists($wanted, $prices))
            ? $prices[$wanted]
            : (float)(reset($prices) ?: 0);

        // Имя товара-родителя: из ответа, а если его там нет — из кэша
        $parentName = trim((string)($product['name'] ?? ''));
        if ($parentName === '' && $parentId !== '') {
            $parentName = (string)(Db::val("SELECT name FROM products_cache WHERE moysklad_id=?", [$parentId]) ?: '');
        }
        $own = trim((string)($v['name'] ?? ''));
        $name = $parentName !== '' ? $parentName : $own;
        if ($characteristics !== '') $name .= ' (' . $characteristics . ')';

        return [
            'id'              => self::extractId((string)($v['id'] ?? $v['meta']['href'] ?? '')),
            'name'            => trim($name),
            'article'         => (string)($v['article'] ?? $product['article'] ?? ''),
            'code'            => (string)($v['code'] ?? ''),
            'price'           => $price,
            'prices'          => $prices,
            'unit'            => (string)($product['uom']['name'] ?? 'шт.'),
            'description'     => (string)($v['description'] ?? $product['description'] ?? ''),
            'category'        => self::folderName($product['productFolder'] ?? null),
            'vat'             => self::vatOf($product),
            'parent_id'       => $parentId ?: null,
            'characteristics' => $characteristics,
            'archived'        => !empty($v['archived']) ? 1 : 0,
        ];
    }

    // Product images (FR-040). Downloads up to $limit images into
    // storage/product_images/ and returns local file paths. MoySklad serves
    // image binaries from a signed downloadHref that still needs the token.
    public static function fetchProductImages(string $productId, int $limit = 6): array {
        // A modification has its own endpoint: /entity/product/{id} knows products only (module 047)
        $type = Db::val("SELECT product_type FROM products_cache WHERE moysklad_id=?", [$productId]) === 'variant'
            ? 'variant' : 'product';
        $data = self::get("/entity/$type/$productId/images?limit=$limit");
        if (!$data || empty($data['rows'])) return [];

        $dir = ROOT . '/storage/product_images';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $paths = [];
        foreach ($data['rows'] as $i => $row) {
            // Prefer the full image; miniature is a fallback for huge originals
            $href = $row['meta']['downloadHref'] ?? $row['miniature']['downloadHref'] ?? '';
            if (!$href) continue;

            $binary = self::download($href);
            if ($binary === null) continue;

            $ext = strtolower(pathinfo($row['filename'] ?? '', PATHINFO_EXTENSION)) ?: 'jpg';
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';

            $path = "$dir/$productId-$i.$ext";
            if (file_put_contents($path, $binary) === false) continue;
            $paths[] = $path;
        }

        // Cache the result so the KP generator never waits on the API twice
        Db::q("UPDATE products_cache SET images_json=?, images_synced_at=datetime('now') WHERE moysklad_id=?",
            [json_encode($paths, JSON_UNESCAPED_UNICODE), $productId]);

        return $paths;
    }

    // Cached images for a product; fetches on first use or after $ttlDays.
    public static function productImages(string $productId, int $ttlDays = 30): array {
        $row = Db::one("SELECT images_json, images_synced_at FROM products_cache WHERE moysklad_id=?", [$productId]);
        if ($row && !empty($row['images_synced_at'])) {
            $age = time() - strtotime($row['images_synced_at']);
            if ($age < $ttlDays * 86400) {
                $paths = json_decode($row['images_json'] ?: '[]', true) ?: [];
                // A cached path is only good while the file is still on disk
                $paths = array_values(array_filter($paths, 'file_exists'));
                if ($paths) return $paths;
            }
        }
        try {
            return self::fetchProductImages($productId);
        } catch (MoySkladException $e) {
            return [];   // images are decoration — never block PDF generation
        }
    }

    // Authenticated binary GET against an absolute MoySklad href
    private static function download(string $href): ?string {
        $ch = curl_init($href);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . self::$token,
                'Accept: */*',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ($code >= 200 && $code < 300 && $body !== false && $body !== '') ? (string)$body : null;
    }

    // Search counterparties
    public static function searchCounterparties(string $query): array {
        // Try INN first
        if (preg_match('/^\d{10,12}$/', $query)) {
            $data = self::get("/entity/counterparty?filter=inn=$query");
        } else {
            $q = urlencode($query);
            $data = self::get("/entity/counterparty?filter=name~=$q&limit=10");
        }
        if (!$data || empty($data['rows'])) return [];

        return array_map(fn($c) => [
            'id' => self::extractId($c['id'] ?? $c['meta']['href'] ?? ''),
            'name' => $c['name'] ?? '',
            'inn' => $c['inn'] ?? '',
            'phone' => $c['phone'] ?? '',
            'email' => $c['email'] ?? '',
        ], $data['rows']);
    }

    // Get counterparty by ID
    public static function getCounterparty(string $id): ?array {
        $data = self::get("/entity/counterparty/$id");
        if (!$data) return null;
        return [
            'id' => $id,
            'name' => $data['name'] ?? '',
            'inn' => $data['inn'] ?? '',
            'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? '',
        ];
    }

    // Create counterparty
    public static function createCounterparty(array $data): array {
        $body = ['name' => $data['name']];
        if (!empty($data['inn'])) $body['inn'] = $data['inn'];
        if (!empty($data['phone'])) $body['phone'] = $data['phone'];
        if (!empty($data['email'])) $body['email'] = $data['email'];

        $resp = self::post('/entity/counterparty', $body);
        return ['id' => self::extractId($resp['id'] ?? $resp['meta']['href'] ?? ''), 'name' => $resp['name']];
    }

    /**
     * Ссылка на номенклатуру позиции: товар или МОДИФИКАЦИЯ.
     *
     * Тип здесь был зашит как `product`, и заказ с размером L в позиции
     * МойСклад либо не принимал, либо принимал не тот товар. Тип берётся из
     * каталога: там у модификации стоит `product_type = 'variant'` (модуль 026).
     */
    private static function assortmentMeta(string $productId, string $type = ''): array {
        // A service (delivery line, module 045) is never in products_cache
        if ($type === '') $type = (string)(Db::val("SELECT product_type FROM products_cache WHERE moysklad_id=?", [$productId]) ?: '');
        $entity = in_array($type, ['variant', 'service'], true) ? $type : 'product';
        return ['meta' => [
            'href'      => self::$base . '/entity/' . $entity . '/' . $productId,
            'type'      => $entity,
            'mediaType' => 'application/json',
        ]];
    }

    /** Статусы заказа покупателя: имя → id. Читается один раз за запрос. */
    public static function orderStates(): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        $data = self::get('/entity/customerorder/metadata');
        foreach ((array)($data['states'] ?? []) as $st) {
            $name = trim((string)($st['name'] ?? ''));
            if ($name !== '') $cache[$name] = self::extractId($st['id'] ?? $st['meta']['href'] ?? '');
        }
        return $cache;
    }

    /** Статус по имени без учёта регистра, пробелов и «ё» (модуль 053): «резерв » = «Резерв». */
    public static function stateId(array $states, string $name): ?string {
        $norm = fn(string $v) => str_replace('ё', 'е', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v))));
        $want = $norm($name);
        if ($want === '') return null;
        foreach ($states as $n => $id) {
            if ($norm((string)$n) === $want) return (string)$id;
        }
        return null;
    }

    /** Meta склада для тела документа. */
    private static function storeMeta(string $storeId): array {
        return ['meta' => [
            'href'      => self::$base . '/entity/store/' . $storeId,
            'type'      => 'store',
            'mediaType' => 'application/json',
        ]];
    }

    /**
     * POST документа с «Сотрудником» (`owner`, модуль 053). Сотрудник не
     * найден или МойСклад не дал его назначить — документ уходит без owner
     * (его ставит сам МойСклад), а причина — в $missing.
     */
    private static function postWithOwner(string $path, array $body, ?array $manager, array &$missing): array {
        $who = trim((string)($manager['name'] ?? ''));
        $note = 'сотрудник МойСклад для менеджера' . ($who !== '' ? " «{$who}»" : '');
        $owner = $manager ? self::employeeMeta($manager) : null;
        if (!$owner) {
            if ($manager) $missing[] = $note;
            return self::post($path, $body);
        }
        try {
            return self::post($path, $body + ['owner' => ['meta' => $owner]]);
        } catch (MoySkladException $e) {
            if (self::lastErrorCode() >= 500 || self::lastErrorCode() === 0) throw $e;
            $missing[] = $note . ' (МойСклад не дал назначить: ' . $e->getMessage() . ')';
            return self::post($path, $body);
        }
    }

    /** Дополнительные поля заказа покупателя: имя → [id, type, required, dictionary]. */
    public static function orderAttributes(): array {
        return self::entityAttributes('customerorder');
    }

    /**
     * Доп. поля документа (`customerorder`, `invoiceout`): имя → id, тип,
     * обязательность и справочник (для `customentity`). Читается один раз.
     */
    public static function entityAttributes(string $entity): array {
        static $cache = [];
        if (isset($cache[$entity])) return $cache[$entity];
        $out = [];
        $data = self::get("/entity/$entity/metadata/attributes");
        foreach ((array)($data['rows'] ?? []) as $a) {
            $name = trim((string)($a['name'] ?? ''));
            if ($name === '') continue;
            $out[$name] = [
                'id'         => self::extractId($a['id'] ?? $a['meta']['href'] ?? ''),
                'type'       => (string)($a['type'] ?? 'string'),
                'required'   => !empty($a['required']),
                'dictionary' => self::extractId((string)($a['customEntityMeta']['href'] ?? '')),
            ];
        }
        return $cache[$entity] = $out;
    }

    /**
     * Доп. поля документа по ИМЕНИ — в тело запроса МойСклад (модуль 052).
     *
     * Значение строится по типу поля: строка/текст — как есть; «Сотрудник» —
     * ссылка на сотрудника менеджера ($manager: moysklad_uid, email, name);
     * справочник — элемент с тем же названием. Не вышло — имя поля попадает в
     * $missing, а поле в запрос не идёт.
     *
     * @param array<string,string> $values   имя поля → значение строкой
     * @param array<string,array>  $known    entityAttributes()
     * @param callable(array):?array $employee  метаданные поля → meta сотрудника
     * @param callable(string,string):?array $entry  справочник, название → meta элемента
     */
    public static function attributesBody(string $entity, array $values, array $known, array &$missing,
                                          callable $employee, callable $entry): array {
        $out = [];
        foreach ($values as $name => $value) {
            $value = trim((string)$value);
            if (!isset($known[$name])) { $missing[] = 'доп. поле «' . $name . '»'; continue; }
            $a = $known[$name];
            $v = match ($a['type']) {
                'string', 'text', 'link' => $value !== '' ? $value : null,
                'employee'     => ($m = $employee($a)) ? ['meta' => $m] : null,
                'customentity' => ($a['dictionary'] !== '' && $value !== '' && ($m = $entry($a['dictionary'], $value)))
                                      ? ['meta' => $m] : null,
                default        => null,
            };
            if ($v === null) { $missing[] = 'доп. поле «' . $name . '»'; continue; }
            $out[] = [
                'meta'  => [
                    'href'      => self::$base . "/entity/$entity/metadata/attributes/" . $a['id'],
                    'type'      => 'attributemetadata',
                    'mediaType' => 'application/json',
                ],
                'value' => $v,
            ];
        }
        return $out;
    }

    /** attributesBody() с живыми справочниками МойСклад. */
    private static function liveAttributes(string $entity, array $values, ?array $manager, array &$missing): array {
        if (!$values) return [];
        return self::attributesBody($entity, $values, self::entityAttributes($entity), $missing,
            fn(array $a) => $manager ? self::employeeMeta($manager) : null,
            fn(string $dict, string $name) => self::customEntityMeta($dict, $name));
    }

    /**
     * Сотрудник МойСклад, который стоит за менеджером: по «UID в МойСклад» из
     * карточки менеджера, затем по почте, затем по ФИО.
     */
    public static function employeeMeta(array $manager): ?array {
        static $cache = [];
        $uid   = trim((string)($manager['moysklad_uid'] ?? ''));
        $email = trim((string)($manager['email'] ?? ''));
        $name  = trim((string)($manager['name'] ?? ''));
        $key = "$uid|$email|$name";
        if (array_key_exists($key, $cache)) return $cache[$key];
        $rows = [];
        foreach (array_filter(['uid' => $uid, 'email' => $email]) as $field => $v) {
            $data = self::get('/entity/employee?filter=' . rawurlencode("$field=$v") . '&limit=5');
            $rows = (array)($data['rows'] ?? []);
            if ($rows) break;
        }
        if (!$rows && $name !== '') {
            $data = self::get('/entity/employee?limit=1000');
            $rows = array_values(array_filter((array)($data['rows'] ?? []),
                fn($e) => self::sameEmployee($e, $name)));
        }
        return $cache[$key] = !empty($rows[0]['meta']) ? $rows[0]['meta'] : null;
    }

    /** ФИО менеджера совпадает с сотрудником: «Яна Петрова» = «Петрова Яна» = «Петрова Я.». */
    public static function sameEmployee(array $e, string $name): bool {
        $norm = fn(string $s) => array_values(array_filter(preg_split('/[\s.]+/u',
            str_replace('ё', 'е', mb_strtolower(trim($s)))) ?: []));
        $want = $norm($name);
        if (!$want) return false;
        sort($want);
        foreach ([$e['fullName'] ?? '', $e['name'] ?? '',
                  trim(($e['lastName'] ?? '') . ' ' . ($e['firstName'] ?? ''))] as $cand) {
            $got = $norm((string)$cand);
            if (!$got) continue;
            sort($got);
            if ($got === $want) return true;
        }
        // «Петрова Я.» — фамилия и первая буква имени
        $last = $norm((string)($e['lastName'] ?? ''));
        $first = mb_substr((string)($e['firstName'] ?? ''), 0, 1);
        return $last && $first !== '' && count($want) >= 2
            && in_array($last[0], $want, true)
            && (bool)array_filter($want, fn($w) => $w !== $last[0]
                                             && mb_substr($w, 0, 1) === mb_strtolower($first));
    }

    /** Элемент справочника МойСклад с этим названием. */
    public static function customEntityMeta(string $dictionaryId, string $name): ?array {
        $data = self::get("/entity/customentity/$dictionaryId?search=" . rawurlencode($name) . '&limit=50');
        $norm = fn(string $s) => str_replace('ё', 'е', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s))));
        foreach ((array)($data['rows'] ?? []) as $r) {
            if ($norm((string)($r['name'] ?? '')) === $norm($name) && !empty($r['meta'])) return $r['meta'];
        }
        return null;
    }

    // Create customer order (US5)
    public static function createOrder(array $data): array {
        if (empty(self::$permissions['orders_read'])) {
            throw new MoySkladPermissionException('No write access to customerorder');
        }

        // С заданным складом товар и модификация встают в резерв целиком
        // (модуль 053); услугу резервировать нечем
        $storeId = (string)($data['store_id'] ?? '');
        $positions = array_map(function ($p) use ($storeId) {
            $meta = self::assortmentMeta((string)$p['product_id'], (string)($p['type'] ?? ''));
            return array_filter([
                'quantity' => $p['quantity'],
                'reserve' => ($storeId !== '' && $meta['meta']['type'] !== 'service') ? $p['quantity'] : null,
                'price' => round($p['price'] * 100), // MoySklad uses kopeks
                'discount' => $p['discount'] ?? null,
                'vat' => $p['vat'] ?? null,
                'assortment' => $meta,
            ], fn($v) => $v !== null);
        }, $data['positions']);

        $body = [
            'organization' => ['meta' => [
                'href' => self::$base . '/entity/organization/' . $data['organization_id'],
                'type' => 'organization',
                'mediaType' => 'application/json',
            ]],
            'agent' => ['meta' => [
                'href' => self::$base . '/entity/counterparty/' . $data['counterparty_id'],
                'type' => 'counterparty',
                'mediaType' => 'application/json',
            ]],
            'positions' => $positions,
        ];
        if (!empty($data['description'])) $body['description'] = $data['description'];
        if ($storeId !== '') $body['store'] = self::storeMeta($storeId);

        // Включён ли налог в цену позиции — то же, что КП сказало клиенту
        // (модуль 030). Счёт «ценой + НДС» по КП «в т.ч. НДС» — это другая
        // сумма в руках у клиента, чем та, которую он согласовал.
        if (array_key_exists('vat_enabled', $data))  $body['vatEnabled']  = (bool)$data['vat_enabled'];
        if (array_key_exists('vat_included', $data)) $body['vatIncluded'] = (bool)$data['vat_included'];

        // Статус («Резерв») и доп. поле («СОТРУДНИК») — по ИМЕНИ, как их видит
        // человек в МойСклад. Имени нет в аккаунте — заказ всё равно создаётся:
        // счёт клиенту важнее нашей внутренней раскладки (модуль 026).
        $missing = [];
        if (!empty($data['state_name'])) {
            $stateId = self::stateId(self::orderStates(), (string)$data['state_name']);
            if ($stateId) {
                $body['state'] = ['meta' => [
                    'href'      => self::$base . '/entity/customerorder/metadata/states/' . $stateId,
                    'type'      => 'state',
                    'mediaType' => 'application/json',
                ]];
            } else {
                $missing[] = 'статус «' . $data['state_name'] . '»';
            }
        }
        $attrs = self::liveAttributes('customerorder', (array)($data['attributes'] ?? []),
                                      $data['manager'] ?? null, $missing);
        if ($attrs) $body['attributes'] = $attrs;
        if (array_key_exists('applicable', $data)) $body['applicable'] = (bool)$data['applicable'];

        if (array_key_exists('store_id', $data) && $storeId === '') $missing[] = 'склад';

        $resp = self::postWithOwner('/entity/customerorder', $body, $data['manager'] ?? null, $missing);
        return [
            'id' => self::extractId($resp['id'] ?? $resp['meta']['href'] ?? ''),
            'name' => $resp['name'] ?? '',
            // Чего в аккаунте не нашлось — чтобы сказать это вслух, а не молча
            'missing' => $missing,
        ];
    }

    /**
     * Снять проведение заказа — «убрать резерв» (модуль 026).
     *
     * `applicable = false` в МойСклад и значит «документ не проведён»: товар
     * перестаёт числиться зарезервированным за этим клиентом.
     */
    public static function setOrderApplicable(string $orderId, bool $applicable): array {
        $resp = self::request('PUT', "/entity/customerorder/$orderId", ['applicable' => $applicable]);
        if ($resp === null) {
            throw new MoySkladException('PUT failed: /entity/customerorder/' . $orderId . self::lastErrorSuffix());
        }
        return ['id' => self::extractId($resp['id'] ?? $resp['meta']['href'] ?? ''),
                'applicable' => (bool)($resp['applicable'] ?? $applicable)];
    }

    /**
     * Выставить счёт покупателю (issue #38).
     *
     * Счёт создаётся из позиций КП: менеджер, дошедший до «клиент согласен»,
     * не должен уходить в МойСклад и набирать те же строки руками. Права
     * проверяются заранее — `invoiceout` без права записи отвечает 403, и
     * пустой счёт в аккаунте лучше не оставлять.
     */
    public static function createInvoice(array $data): array {
        $positions = array_map(fn($p) => [
            'quantity'   => $p['quantity'],
            'price'      => round($p['price'] * 100),   // МойСклад считает в копейках
            'discount'   => $p['discount'] ?? 0,
            'vat'        => $p['vat'] ?? 0,
            'assortment' => self::assortmentMeta((string)$p['product_id'], (string)($p['type'] ?? '')),
        ], $data['positions']);

        $body = [
            'organization' => ['meta' => [
                'href'      => self::$base . '/entity/organization/' . $data['organization_id'],
                'type'      => 'organization',
                'mediaType' => 'application/json',
            ]],
            'agent' => ['meta' => [
                'href'      => self::$base . '/entity/counterparty/' . $data['counterparty_id'],
                'type'      => 'counterparty',
                'mediaType' => 'application/json',
            ]],
            'positions' => $positions,
        ];
        if (!empty($data['order_id'])) {
            $body['customerOrder'] = ['meta' => [
                'href'      => self::$base . '/entity/customerorder/' . $data['order_id'],
                'type'      => 'customerorder',
                'mediaType' => 'application/json',
            ]];
        }
        if (!empty($data['description'])) $body['description'] = $data['description'];
        if (!empty($data['store_id'])) $body['store'] = self::storeMeta((string)$data['store_id']);

        // Тот же ответ, что и у заказа: налог в цене или сверху — как напечатано
        // в КП (модуль 030)
        if (array_key_exists('vat_enabled', $data))  $body['vatEnabled']  = (bool)$data['vat_enabled'];
        if (array_key_exists('vat_included', $data)) $body['vatIncluded'] = (bool)$data['vat_included'];

        // Доп. поля счёта — «СОТРУДНИК» из карточки менеджера (модуль 052).
        // Обязательное поле, которое заполнить нечем, МойСклад отвергнет
        // кодом 412 — говорим заранее и по-человечески.
        $missing = [];
        $attrs = self::liveAttributes('invoiceout', (array)($data['attributes'] ?? []),
                                      $data['manager'] ?? null, $missing);
        if ($attrs) $body['attributes'] = $attrs;
        foreach (self::requiredGaps('invoiceout', $attrs) as $name) {
            $who = trim((string)($data['manager']['name'] ?? ''));
            throw new MoySkladException("у счёта в МойСклад обязательное доп. поле «{$name}», а заполнить его нечем — "
                . (array_key_exists($name, (array)($data['attributes'] ?? []))
                    ? "не нашли сотрудника МойСклад для менеджера" . ($who !== '' ? " «{$who}»" : '')
                      . '. Укажите «UID в МойСклад» (логин сотрудника) в карточке менеджера: Админ → Менеджеры'
                    : 'впишите его название в «Настройки → МойСклад → Доп. поле с именем менеджера»'));
        }

        $resp = self::postWithOwner('/entity/invoiceout', $body, $data['manager'] ?? null, $missing);
        return self::mapInvoice($resp) + ['missing' => $missing];
    }

    /** Обязательные доп. поля документа, которых нет в $attrs (имена). */
    private static function requiredGaps(string $entity, array $attrs): array {
        $filled = [];
        foreach ($attrs as $a) $filled[basename((string)($a['meta']['href'] ?? ''))] = true;
        $gaps = [];
        foreach (self::entityAttributes($entity) as $name => $a) {
            if ($a['required'] && !isset($filled[$a['id']])) $gaps[] = $name;
        }
        return $gaps;
    }

    // Get orders by counterparty (US6, US7)
    public static function getOrdersByCounterparty(string $counterpartyId, int $days = 90): array {
        $since = date('Y-m-d', strtotime("-{$days} days"));
        // MoySklad expects one filter param with ';'-joined conditions
        $filter = urlencode("agent=" . self::$base . "/entity/counterparty/$counterpartyId;moment>=$since 00:00:00");
        $data = self::get("/entity/customerorder?filter=$filter&expand=state&limit=100");
        if (!$data || empty($data['rows'])) return [];

        return array_map(fn($o) => [
            'id' => self::extractId($o['id'] ?? ''),
            'name' => $o['name'] ?? '',
            'sum' => ($o['sum'] ?? 0) / 100,
            'status' => $o['state']['name'] ?? '',
            'created' => $o['moment'] ?? '',
        ], $data['rows']);
    }

    // ==== Module 002: orders, invoices, webhooks ====

    // Web UI links so managers land on the document itself (FR-026)
    public static function orderUrl(string $id): string {
        return "https://online.moysklad.ru/app/#customerorder/edit?id=$id";
    }

    public static function invoiceUrl(string $id): string {
        return "https://online.moysklad.ru/app/#invoiceout/edit?id=$id";
    }

    public static function counterpartyUrl(string $id): string {
        return "https://online.moysklad.ru/app/#counterparty/edit?id=$id";
    }

    // First organization of the account — used as the seller in orders
    public static function getOrganizations(): array {
        $data = self::get('/entity/organization?limit=10');
        if (!$data || empty($data['rows'])) return [];
        return array_map(fn($o) => [
            'id'   => self::extractId($o['id'] ?? $o['meta']['href'] ?? ''),
            'name' => $o['name'] ?? '',
            'inn'  => $o['inn'] ?? '',
        ], $data['rows']);
    }

    /**
     * The организация as a legal entity (module 013): everything a КП has to
     * print about us — requisites, addresses, the bank account and whether we
     * charge VAT at all. `expand=accounts` brings the bank accounts along, so
     * the whole block costs one request.
     *
     * $id null → the account's first organization, which is what a one-company
     * МойСклад has and what «Настройки → ID организации» is left empty for.
     */
    public static function getOrganizationFull(?string $id = null): ?array {
        $data = $id
            ? self::get("/entity/organization/$id?expand=accounts")
            : (self::get('/entity/organization?limit=1&expand=accounts')['rows'][0] ?? null);
        if (!$data) return null;

        // The default account is the one invoices are issued against; МойСклад
        // marks exactly one, and a company with a single account marks none
        $accounts = $data['accounts']['rows'] ?? [];
        $account = null;
        foreach ($accounts as $row) {
            if (!empty($row['isDefault'])) { $account = $row; break; }
        }
        $account = $account ?? ($accounts[0] ?? null);

        return [
            'id'             => self::extractId($data['id'] ?? $data['meta']['href'] ?? ''),
            'name'           => $data['name'] ?? '',
            'legal_title'    => $data['legalTitle'] ?? '',
            'company_type'   => $data['companyType'] ?? '',
            'inn'            => $data['inn'] ?? '',
            'kpp'            => $data['kpp'] ?? '',
            'ogrn'           => $data['ogrn'] ?? '',
            'ogrnip'         => $data['ogrnip'] ?? '',
            'okpo'           => $data['okpo'] ?? '',
            'legal_address'  => $data['legalAddress'] ?? '',
            'actual_address' => $data['actualAddress'] ?? '',
            'phone'          => $data['phone'] ?? '',
            'email'          => $data['email'] ?? '',
            // «Плательщик НДС» — the flag that decides whether a КП says
            // «в т.ч. НДС 5%» or «НДС не облагается». Absent means yes.
            'pays_vat'       => !array_key_exists('payerVat', $data) || (bool)$data['payerVat'],
            'signatory'      => $data['director'] ?? $data['chiefAccountant'] ?? '',
            'account'        => $account ? [
                'account'      => $account['accountNumber'] ?? '',
                'bank_name'    => $account['bankName'] ?? '',
                'bic'          => $account['bankLocation'] ?? '',
                'corr_account' => $account['correspondentAccount'] ?? '',
            ] : [],
        ];
    }

    /** The buyer as a legal entity — the other half of the same block. */
    public static function getCounterpartyFull(string $id): ?array {
        $data = self::get("/entity/counterparty/$id");
        if (!$data) return null;
        return [
            'id'             => $id,
            'name'           => $data['name'] ?? '',
            'legal_title'    => $data['legalTitle'] ?? '',
            'inn'            => $data['inn'] ?? '',
            'kpp'            => $data['kpp'] ?? '',
            'ogrn'           => $data['ogrn'] ?? '',
            'ogrnip'         => $data['ogrnip'] ?? '',
            'okpo'           => $data['okpo'] ?? '',
            'legal_address'  => $data['legalAddress'] ?? '',
            'actual_address' => $data['actualAddress'] ?? '',
            'phone'          => $data['phone'] ?? '',
            'email'          => $data['email'] ?? '',
        ];
    }

    /**
     * Договоры between this buyer and us, newest first.
     * A КП that names the contract it is issued under saves the client a
     * question, and the number must be the real one — so it is read here, never
     * composed.
     */
    public static function getContracts(string $counterpartyId, ?string $organizationId = null): array {
        $conditions = ['agent=' . self::$base . "/entity/counterparty/$counterpartyId"];
        if ($organizationId) $conditions[] = 'organization=' . self::$base . "/entity/organization/$organizationId";

        $data = self::get('/entity/contract?filter=' . urlencode(implode(';', $conditions)) . '&limit=50');
        if (!$data || empty($data['rows'])) return [];

        $rows = array_map(fn($c) => [
            'id'     => self::extractId($c['id'] ?? $c['meta']['href'] ?? ''),
            'name'   => $c['name'] ?? '',
            'moment' => $c['moment'] ?? '',
            'type'   => $c['contractType'] ?? '',
            'archived' => (bool)($c['archived'] ?? false),
        ], $data['rows']);

        // An archived contract is not the one we are selling under today
        $rows = array_values(array_filter($rows, fn($r) => !$r['archived']));
        usort($rows, fn($a, $b) => strcmp((string)$b['moment'], (string)$a['moment']));
        return $rows;
    }

    // Full order with positions, agent and state (FR-027)
    public static function getOrder(string $id): ?array {
        $data = self::get("/entity/customerorder/$id?expand=positions.assortment,agent,state");
        if (!$data) return null;

        $positions = [];
        foreach ($data['positions']['rows'] ?? [] as $row) {
            $positions[] = [
                'name'     => $row['assortment']['name'] ?? '',
                'quantity' => $row['quantity'] ?? 0,
                'price'    => ($row['price'] ?? 0) / 100,
                'discount' => $row['discount'] ?? 0,
                'vat'      => $row['vat'] ?? 0,
                'sum'      => (($row['price'] ?? 0) / 100) * ($row['quantity'] ?? 0),
            ];
        }

        return [
            'id'          => $id,
            'name'        => $data['name'] ?? '',
            'moment'      => $data['moment'] ?? '',
            'sum'         => ($data['sum'] ?? 0) / 100,
            'state_name'  => $data['state']['name'] ?? '',
            'description' => $data['description'] ?? '',
            'agent_id'    => self::extractId($data['agent']['meta']['href'] ?? ''),
            'agent_name'  => $data['agent']['name'] ?? '',
            'updated'     => $data['updated'] ?? '',
            // «Проведён» — то же самое, что «товар зарезервирован» (модуль 026)
            'applicable'  => (bool)($data['applicable'] ?? true),
            'positions'   => $positions,
            // Доп. поля заказа по имени: «СЛУЖБА ДОСТАВКИ», «ТРЕК-НОМЕР» (модуль 047)
            'attributes'  => self::attributeValues($data['attributes'] ?? []),
        ];
    }

    /** Доп. поля документа: имя → значение строкой (справочник отдаёт своё имя). */
    public static function attributeValues(array $attrs): array {
        $out = [];
        foreach ($attrs as $a) {
            $name = trim((string)($a['name'] ?? ''));
            if ($name === '') continue;
            $v = $a['value'] ?? '';
            if (is_array($v)) $v = $v['name'] ?? '';
            elseif (is_bool($v)) $v = $v ? 'да' : '';
            $out[$name] = trim((string)$v);
        }
        return $out;
    }

    /**
     * Входящий платёж по счёту (модуль 047) — как «на основании» счёта в
     * МойСклад: организация, контрагент и заказ берутся у самого счёта, платёж
     * привязан к счёту своей суммой.
     *
     * @param array $o purpose, number (номер платёжки), date (Y-m-d H:i:s)
     * @return array{id:string,name:string}
     */
    public static function createPaymentIn(string $invoiceId, float $sum, array $o = []): array {
        $inv = self::get("/entity/invoiceout/$invoiceId");
        if (!$inv) throw new MoySkladException('Счёт не найден в МойСклад: ' . $invoiceId . self::lastErrorSuffix());
        $kopecks = (int)round($sum * 100);
        $date = (string)($o['date'] ?? '') ?: date('Y-m-d H:i:s');

        $body = [
            'organization' => ['meta' => $inv['organization']['meta']],
            'agent'        => ['meta' => $inv['agent']['meta']],
            'sum'          => $kopecks,
            'moment'       => $date,
            'operations'   => [['meta' => $inv['meta'], 'linkedSum' => $kopecks]],
        ];
        if (!empty($inv['organizationAccount']['meta'])) {
            $body['organizationAccount'] = ['meta' => $inv['organizationAccount']['meta']];
        }
        if (!empty($inv['customerOrder']['meta'])) {
            // Заказ виден и в самом платеже, а не только через счёт
            $body['operations'][] = ['meta' => $inv['customerOrder']['meta'], 'linkedSum' => $kopecks];
        }
        if (($o['purpose'] ?? '') !== '') $body['paymentPurpose'] = mb_substr((string)$o['purpose'], 0, 255);
        if (($o['number'] ?? '') !== '') {
            $body['incomingNumber'] = (string)$o['number'];
            $body['incomingDate']   = $date;
        }
        try {
            $resp = self::post('/entity/paymentin', $body);
        } catch (MoySkladException $e) {
            // Не каждый аккаунт связывает платёж с заказом и счётом сразу —
            // тогда платёж привязывается к одному счёту
            if (count($body['operations']) < 2) throw $e;
            $body['operations'] = [$body['operations'][0]];
            $resp = self::post('/entity/paymentin', $body);
        }
        return ['id' => self::extractId($resp['id'] ?? $resp['meta']['href'] ?? ''), 'name' => (string)($resp['name'] ?? '')];
    }

    public static function paymentInUrl(string $id): string {
        return "https://online.moysklad.ru/app/#paymentin/edit?id=$id";
    }

    // Invoices issued against one order (FR-030)
    public static function getInvoicesByOrder(string $orderId): array {
        $filter = urlencode('customerOrder=' . self::$base . "/entity/customerorder/$orderId");
        $data = self::get("/entity/invoiceout?filter=$filter&expand=state&limit=50");
        if (!$data || empty($data['rows'])) return [];
        return array_map(fn($i) => self::mapInvoice($i), $data['rows']);
    }

    // Invoices of a company, regardless of order (edge case: invoice without order)
    public static function getInvoicesByCounterparty(string $counterpartyId, int $days = 180): array {
        $since = date('Y-m-d', strtotime("-{$days} days"));
        $filter = urlencode('agent=' . self::$base . "/entity/counterparty/$counterpartyId;moment>=$since 00:00:00");
        $data = self::get("/entity/invoiceout?filter=$filter&expand=state&limit=100");
        if (!$data || empty($data['rows'])) return [];
        return array_map(fn($i) => self::mapInvoice($i), $data['rows']);
    }

    public static function getInvoice(string $id): ?array {
        $data = self::get("/entity/invoiceout/$id?expand=state,agent,customerOrder");
        return $data ? self::mapInvoice($data) : null;
    }

    private static function mapInvoice(array $i): array {
        return [
            'id'         => self::extractId($i['id'] ?? $i['meta']['href'] ?? ''),
            'name'       => $i['name'] ?? '',
            'moment'     => $i['moment'] ?? '',
            'sum'        => ($i['sum'] ?? 0) / 100,
            'payed_sum'  => ($i['payedSum'] ?? 0) / 100,
            'state_name' => $i['state']['name'] ?? '',
            'agent_id'   => self::extractId($i['agent']['meta']['href'] ?? ''),
            'order_id'   => self::extractId($i['customerOrder']['meta']['href'] ?? ''),
            'updated'    => $i['updated'] ?? '',
        ];
    }

    /**
     * Download the printable invoice PDF (C-013).
     * Returns raw PDF bytes or null when unavailable.
     */
    public static function exportInvoicePdf(string $invoiceId): ?string {
        $template = self::firstInvoiceTemplate();
        if (!$template) return null;

        $body = [
            'template'  => ['meta' => $template['meta']],
            'extension' => 'pdf',
        ];

        // MoySklad answers 200 with the file, or 3xx/202 with a Location to poll
        for ($attempt = 0; $attempt < 4; $attempt++) {
            [$code, $raw, $headers] = self::requestRaw('POST', "/entity/invoiceout/$invoiceId/export", $body);

            if ($code === 200 && $raw !== '' && str_starts_with($raw, '%PDF')) return $raw;

            if (in_array($code, [202, 301, 302, 303, 307], true)) {
                $location = $headers['location'] ?? '';
                if ($location) {
                    sleep(1);
                    [$c2, $raw2] = self::requestRawUrl('GET', $location);
                    if ($c2 === 200 && str_starts_with($raw2, '%PDF')) return $raw2;
                }
                sleep(1);
                continue;
            }

            if ($code === 403) return null;
            sleep(1);
        }
        return null;
    }

    /**
     * The print form the invoice is exported with (module 045).
     *
     * `MS_INVOICE_TEMPLATE` names it the way the «Печать» menu does — «Счет
     * покупателю с печатью с QR и с подписью» by default: exact name first,
     * then a name containing it; nothing matched — the first template, as
     * before.
     */
    private static function firstInvoiceTemplate(): ?array {
        $rows = [];
        foreach (['customtemplate', 'embeddedtemplate'] as $kind) {
            $data = self::get("/entity/invoiceout/metadata/$kind");
            foreach ((array)($data['rows'] ?? []) as $row) {
                if (!empty($row['meta'])) $rows[] = $row;
            }
        }
        return self::pickTemplate($rows, (string)Settings::get('MS_INVOICE_TEMPLATE', ''));
    }

    /** @param list<array> $rows templates as MoySklad lists them */
    public static function pickTemplate(array $rows, string $wanted): ?array {
        if (!$rows) return null;
        $norm = fn(string $s) => str_replace('ё', 'е', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s))));
        $wanted = $norm($wanted);
        if ($wanted !== '') {
            foreach ($rows as $r) if ($norm((string)($r['name'] ?? '')) === $wanted) return $r;
            foreach ($rows as $r) if (str_contains($norm((string)($r['name'] ?? '')), $wanted)) return $r;
        }
        return $rows[0];
    }

    // ---- Webhooks (FR-028, FR-029) ----

    public static function listWebhooks(): array {
        $data = self::get('/entity/webhook?limit=100');
        if (!$data || empty($data['rows'])) return [];
        return array_map(fn($w) => [
            'id'         => self::extractId($w['id'] ?? $w['meta']['href'] ?? ''),
            'url'        => $w['url'] ?? '',
            'entityType' => $w['entityType'] ?? '',
            'action'     => $w['action'] ?? '',
            'enabled'    => $w['enabled'] ?? false,
        ], $data['rows']);
    }

    public static function createWebhook(string $url, string $entityType, string $action): array {
        $resp = self::post('/entity/webhook', [
            'url'        => $url,
            'entityType' => $entityType,
            'action'     => $action,
            'enabled'    => true,
        ]);
        return ['id' => self::extractId($resp['id'] ?? $resp['meta']['href'] ?? '')];
    }

    // Point an existing webhook at a new URL / re-enable it
    public static function updateWebhook(string $id, string $url, bool $enabled = true): array {
        $resp = self::request('PUT', "/entity/webhook/$id", ['url' => $url, 'enabled' => $enabled]);
        if ($resp === null) throw new MoySkladException('PUT failed: /entity/webhook/' . $id . self::lastErrorSuffix());
        return ['id' => self::extractId($resp['id'] ?? $resp['meta']['href'] ?? '')];
    }

    public static function deleteWebhook(string $id): bool {
        [$code] = self::requestRaw('DELETE', "/entity/webhook/$id");
        return $code >= 200 && $code < 300;
    }

    // HTTP helpers with retry
    private static function get(string $path): ?array {
        return self::request('GET', $path);
    }

    /**
     * A probe, not a request: «did not answer» is an answer here. The permission
     * check reads the codes itself and explains them better than the exception
     * would (module 043 made an unreachable API throw instead of returning null).
     */
    private static function tryGet(string $path): ?array {
        try {
            return self::get($path);
        } catch (MoySkladException $e) {
            return null;
        }
    }

    private static function post(string $path, array $body): array {
        $resp = self::request('POST', $path, $body);
        if ($resp === null) throw new MoySkladException('POST failed: ' . $path . self::lastErrorSuffix());
        return $resp;
    }

    // Human-readable tail for exceptions: HTTP code + MoySklad error text
    private static function lastErrorSuffix(): string {
        $code = (int)(self::$lastHttp['code'] ?? 0);
        $msg = self::lastErrorMessage();
        return ' (HTTP ' . $code . ($msg !== '' ? ': ' . $msg : '') . ')';
    }

    // MoySklad returns {"errors":[{"error":"...","parameter":"...","code":N}]}
    public static function lastErrorMessage(): string {
        $body = (string)(self::$lastHttp['body'] ?? '');
        $data = json_decode($body, true);
        if (is_array($data) && !empty($data['errors'])) {
            $parts = [];
            foreach ($data['errors'] as $e) {
                $t = trim((string)($e['error'] ?? ''));
                if (($e['parameter'] ?? '') !== '') $t .= ' [' . $e['parameter'] . ']';
                if (($e['code'] ?? 0) !== 0) $t .= ' code=' . $e['code'];
                if ($t !== '') $parts[] = $t;
            }
            if ($parts) return implode('; ', $parts);
        }
        return trim(substr($body, 0, 300));
    }

    public static function lastErrorCode(): int {
        return (int)(self::$lastHttp['code'] ?? 0);
    }

    /**
     * Request headers. MoySklad's nginx answers 415 Unsupported Media Type to
     * any request that declares Content-Type but carries no body (all GETs),
     * so the header is only sent when a JSON body is attached.
     */
    private static function headers(bool $hasBody): array {
        $h = [
            'Authorization: Bearer ' . self::$token,
            'Accept: application/json;charset=utf-8',
        ];
        if ($hasBody) $h[] = 'Content-Type: application/json';
        return $h;
    }

    // Raw request against the API base — returns [httpCode, body, headers]
    private static function requestRaw(string $method, string $path, ?array $body = null): array {
        return self::requestRawUrl($method, self::$base . $path, $body);
    }

    // Raw request against an absolute URL (used for async export redirects)
    private static function requestRawUrl(string $method, string $url, ?array $body = null): array {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_HTTPHEADER     => self::headers($body !== null),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, (string)$raw, $headers];
    }

    /**
     * One API request with retries.
     *
     * MoySklad allows 45 requests per 3 seconds per token, and a webhook burst
     * (one event per order) spends them in a moment. The refusal carries its own
     * `X-RateLimit-Retry-After` — before module 043 it was never read, and three
     * attempts with a 1-2-4 s backoff all landed inside the same window.
     */
    private const RETRIES = 5;
    /**
     * Wall clock the whole retry loop may take. A rate limit answers instantly,
     * so five attempts fit easily; a host that hangs eats the budget on timeouts
     * and stops after two or three — a page must not wait minutes either way.
     */
    private const RETRY_BUDGET_SEC = 45.0;

    private static function request(string $method, string $path, ?array $body = null): ?array {
        $delay = 1.0;
        $started = microtime(true);
        $attempt = 0;

        for ($i = 1; $i <= self::RETRIES; $i++) {
            $attempt = $i;
            $headers = [];
            $ch = curl_init(self::$base . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_ENCODING => 'gzip',
                CURLOPT_HTTPHEADER => self::headers($body !== null),
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    return strlen($line);
                },
            ]);
            if ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($body !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
                }
            }

            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            // Let the handle go now: the retry backoff below sleeps for seconds
            unset($ch);

            // Keep the last response for diagnostics
            self::$lastHttp = [
                'code' => $code,
                'body' => $curlErr !== '' ? ('curl: ' . $curlErr) : substr((string)$resp, 0, 500),
                'path' => $path,
            ];

            if ($code === 401) return null; // invalid or revoked token
            if ($code === 403) return null; // permission denied — not an error to retry
            if ($code === 404) return null;
            if ($code >= 200 && $code < 300) return json_decode((string)$resp, true);
            // Code 0 is «never connected»: a timeout or a dropped connection is
            // worth repeating, and it used to be indistinguishable from «no such
            // document» — the caller got null and the order counted as unlinked.
            if (!($code === 0 || $code === 429 || $code >= 500)) return null;

            $pause = self::retryPause($headers, $delay);
            $spent = microtime(true) - $started;
            if ($i === self::RETRIES || $spent + $pause >= self::RETRY_BUDGET_SEC) break;
            usleep((int)round($pause * 1_000_000));
            $delay = min(8.0, $delay * 2);
        }
        throw new MoySkladException("MoySklad request failed after $attempt attempts: $method $path"
                                    . self::lastErrorSuffix());
    }

    /**
     * How long to wait before the next attempt. MoySklad names it itself in
     * `X-RateLimit-Retry-After` (milliseconds); `Retry-After` (seconds) is the
     * standard fallback. Without either — exponential backoff with jitter, so
     * parallel webhook handlers do not come back all at once.
     *
     * Public because it is pure and the test checks it without a network.
     */
    public static function retryPause(array $headers, float $delay): float {
        $ms = (float)($headers['x-ratelimit-retry-after'] ?? 0);
        if ($ms > 0) return min(10.0, $ms / 1000);
        $sec = (float)($headers['retry-after'] ?? 0);
        if ($sec > 0) return min(10.0, $sec);
        return $delay + random_int(0, 250) / 1000;
    }

    /** @var array<string,string>|null id папки → имя; список не отдаёт имя папки */
    private static ?array $folderNames = null;

    /**
     * Имя папки товара (модуль 048). В списке товаров `productFolder` — голая
     * ссылка без имени, поэтому имя берётся из карты `/entity/productfolder`.
     */
    private static function folderName(?array $folder): string {
        if (!$folder) return '';
        $name = trim((string)($folder['name'] ?? ''));
        if ($name !== '') return $name;
        $id = self::extractId((string)($folder['id'] ?? $folder['meta']['href'] ?? ''));
        if ($id === '') return '';
        if (self::$folderNames === null) {
            self::$folderNames = [];
            try {
                $offset = 0;
                do {
                    $data = self::get("/entity/productfolder?limit=1000&offset=$offset");
                    foreach ($data['rows'] ?? [] as $f) {
                        self::$folderNames[(string)($f['id'] ?? '')] = (string)($f['name'] ?? '');
                    }
                    $offset += 1000;
                } while (count($data['rows'] ?? []) === 1000);
            } catch (Throwable $e) {
                Logger::warning('moysklad', 'Папки товаров не прочитались: ' . $e->getMessage());
            }
        }
        return self::$folderNames[$id] ?? '';
    }

    private static function mapProduct(array $p): array {
        // Every sale price MoySklad has for this product, by type name — a
        // product commonly carries several («Цена продажи», «Розничная цена»,
        // opt/wholesale…), and which one is canonical is a choice the settings
        // layer makes (Catalog::priceFor), not something to guess here.
        $prices = [];
        foreach ($p['salePrices'] ?? [] as $sp) {
            $name = (string)($sp['priceType']['name'] ?? '');
            if ($name === '') continue;
            $prices[$name] = ($sp['value'] ?? 0) / 100; // kopeks → rubles
        }

        $wanted = (string)Settings::get('CATALOG_DEFAULT_PRICE_TYPE', '');
        $price = ($wanted !== '' && array_key_exists($wanted, $prices))
            ? $prices[$wanted]
            : (float)(reset($prices) ?: 0);

        return [
            'id' => self::extractId($p['id'] ?? $p['meta']['href'] ?? ''),
            'name' => $p['name'] ?? '',
            'article' => $p['article'] ?? '',
            'code' => $p['code'] ?? '',
            'price' => $price,
            'prices' => $prices,
            'stock' => 0, // filled from stock report
            'reserved' => 0,
            'unit' => $p['uom']['name'] ?? 'шт.',
            'description' => $p['description'] ?? '',
            'category' => self::folderName($p['productFolder'] ?? null),
            // The VAT of a КП line is the product's own, not a house default —
            // `vatEnabled: false` is «без НДС» and is not the same as a 0% rate
            'vat' => self::vatOf($p),
        ];
    }

    /**
     * Ставка НДС товара. `effectiveVat` — ставка с учётом группы товара
     * (`useParentVat`), её МойСклад присылает рядом с собственной (модуль 046).
     */
    public static function vatOf(array $p): ?int {
        if (array_key_exists('effectiveVat', $p)) {
            return ($p['effectiveVatEnabled'] ?? true) ? (int)$p['effectiveVat'] : 0;
        }
        if (!array_key_exists('vat', $p)) return null;
        return ($p['vatEnabled'] ?? true) ? (int)$p['vat'] : 0;
    }

    private static function extractId(string $idOrHref): string {
        if (str_contains($idOrHref, '/')) {
            return basename($idOrHref);
        }
        return $idOrHref;
    }
}

class MoySkladException extends RuntimeException {}
class MoySkladPermissionException extends MoySkladException {}
