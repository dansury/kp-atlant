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
        $r = self::get('/entity/product?limit=1');
        $perms['products'] = ($r !== null);
        self::$diag['products'] = self::$lastHttp;

        // Counterparties — required
        $r = self::get('/entity/counterparty?limit=1');
        $perms['counterparties'] = ($r !== null);
        self::$diag['counterparties'] = self::$lastHttp;

        // Orders read
        $r = self::get('/entity/customerorder?limit=1');
        $perms['orders_read'] = ($r !== null);

        // Stock
        $r = self::get('/report/stock/all?limit=1');
        $perms['stock'] = ($r !== null);

        // Invoices read (module 002)
        $r = self::get('/entity/invoiceout?limit=1');
        $perms['invoices'] = ($r !== null);

        // Webhooks (module 002) — read access implies the scope is granted
        $r = self::get('/entity/webhook?limit=1');
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
            'token_tail' => $t === '' ? '' : substr($t, -4),
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

        do {
            $data = self::get("/entity/product?limit=$limit&offset=$offset&expand=salePrices");
            if (!$data || empty($data['rows'])) break;

            // Products in this MoySklad folder are offered as upsell modules
            $addonCategory = Db::val("SELECT value FROM settings WHERE key='addon_category'") ?: '';

            foreach ($data['rows'] as $p) {
                $mapped = self::mapProduct($p);
                $normalized = mb_strtolower(preg_replace('/[\s\-\"\'«»()]+/', ' ', $mapped['name']));
                $category = $mapped['category'] ?? '';
                $isAddon = ($addonCategory !== '' && $category === $addonCategory) ? 1 : 0;

                Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved, unit, description, category, is_addon, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
                    ON CONFLICT(moysklad_id) DO UPDATE SET
                        name=excluded.name, name_normalized=excluded.name_normalized,
                        article=excluded.article, price=excluded.price,
                        stock=excluded.stock, reserved=excluded.reserved,
                        unit=excluded.unit, description=excluded.description,
                        category=excluded.category, is_addon=excluded.is_addon,
                        updated_at=datetime('now')", [
                    $mapped['id'], $mapped['name'], $normalized,
                    $mapped['article'], $mapped['price'],
                    $mapped['stock'], $mapped['reserved'],
                    $mapped['unit'], $mapped['description'], $category, $isAddon,
                ]);
                $count++;
            }

            $offset += $limit;
        } while (count($data['rows']) === $limit);

        return $count;
    }

    // Product images (FR-040). Downloads up to $limit images into
    // storage/product_images/ and returns local file paths. MoySklad serves
    // image binaries from a signed downloadHref that still needs the token.
    public static function fetchProductImages(string $productId, int $limit = 6): array {
        $data = self::get("/entity/product/$productId/images?limit=$limit");
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
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . self::$token,
                'Accept: */*',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
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

    // Create customer order (US5)
    public static function createOrder(array $data): array {
        if (empty(self::$permissions['orders_read'])) {
            throw new MoySkladPermissionException('No write access to customerorder');
        }

        $positions = array_map(fn($p) => [
            'quantity' => $p['quantity'],
            'price' => $p['price'] * 100, // MoySklad uses kopeks
            'assortment' => ['meta' => [
                'href' => self::$base . '/entity/product/' . $p['product_id'],
                'type' => 'product',
                'mediaType' => 'application/json',
            ]],
        ], $data['positions']);

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

        $resp = self::post('/entity/customerorder', $body);
        return [
            'id' => self::extractId($resp['id'] ?? $resp['meta']['href'] ?? ''),
            'name' => $resp['name'] ?? '',
        ];
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
            'positions'   => $positions,
        ];
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

    private static function firstInvoiceTemplate(): ?array {
        foreach (['customtemplate', 'embeddedtemplate'] as $kind) {
            $data = self::get("/entity/invoiceout/metadata/$kind");
            if (!empty($data['rows'][0]['meta'])) return $data['rows'][0];
        }
        return null;
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

    public static function deleteWebhook(string $id): bool {
        [$code] = self::requestRaw('DELETE', "/entity/webhook/$id");
        return $code >= 200 && $code < 300;
    }

    // HTTP helpers with retry
    private static function get(string $path): ?array {
        return self::request('GET', $path);
    }

    private static function post(string $path, array $body): array {
        $resp = self::request('POST', $path, $body);
        if ($resp === null) throw new MoySkladException('POST failed: ' . $path);
        return $resp;
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
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . self::$token,
                'Content-Type: application/json',
                'Accept: application/json;charset=utf-8',
            ],
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
        curl_close($ch);
        return [$code, (string)$raw, $headers];
    }

    private static function request(string $method, string $path, ?array $body = null): ?array {
        $maxRetries = 3;
        $delay = 1;

        for ($i = 0; $i < $maxRetries; $i++) {
            $ch = curl_init(self::$base . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . self::$token,
                    'Content-Type: application/json',
                    'Accept: application/json;charset=utf-8',
                ],
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
            curl_close($ch);

            // Keep the last response for diagnostics
            self::$lastHttp = [
                'code' => $code,
                'body' => $curlErr !== '' ? ('curl: ' . $curlErr) : substr((string)$resp, 0, 500),
                'path' => $path,
            ];

            if ($code === 401) return null; // invalid or revoked token
            if ($code === 403) return null; // permission denied — not an error to retry
            if ($code === 404) return null;
            if ($code >= 200 && $code < 300) return json_decode($resp, true);
            if ($code === 429 || $code >= 500) {
                sleep($delay);
                $delay *= 2;
                continue;
            }
            return null;
        }
        throw new MoySkladException("MoySklad request failed after $maxRetries retries: $method $path");
    }

    private static function mapProduct(array $p): array {
        // Extract first sale price
        $price = 0;
        if (!empty($p['salePrices'])) {
            foreach ($p['salePrices'] as $sp) {
                if (($sp['priceType']['name'] ?? '') === 'Цена продажи' || true) {
                    $price = ($sp['value'] ?? 0) / 100; // kopeks → rubles
                    break;
                }
            }
        }

        return [
            'id' => self::extractId($p['id'] ?? $p['meta']['href'] ?? ''),
            'name' => $p['name'] ?? '',
            'article' => $p['article'] ?? '',
            'price' => $price,
            'stock' => 0, // filled from stock report
            'reserved' => 0,
            'unit' => $p['uom']['name'] ?? 'шт.',
            'description' => $p['description'] ?? '',
            'category' => $p['productFolder']['name'] ?? '',
        ];
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
