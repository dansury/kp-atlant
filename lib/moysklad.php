<?php
/**
 * MoySklad JSON API 1.2 wrapper.
 * Product search, counterparties, orders, cache.
 */
class MoySklad {
    private static string $token = '';
    private static string $base = 'https://api.moysklad.ru/api/remap/1.2';
    private static array $permissions = [];

    public static function init(string $token): void {
        self::$token = $token;
    }

    // Runtime permission check (C-004)
    public static function checkPermissions(): array {
        $perms = ['products' => false, 'counterparties' => false, 'orders_read' => false, 'orders_write' => false, 'stock' => false];

        // Products — required
        $r = self::get('/entity/product?limit=1');
        $perms['products'] = ($r !== null);

        // Counterparties — required
        $r = self::get('/entity/counterparty?limit=1');
        $perms['counterparties'] = ($r !== null);

        // Orders read
        $r = self::get('/entity/customerorder?limit=1');
        $perms['orders_read'] = ($r !== null);

        // Stock
        $r = self::get('/report/stock/all?limit=1');
        $perms['stock'] = ($r !== null);

        // Orders write — try with dry check (HEAD or tiny POST would fail gracefully)
        $perms['orders_write'] = $perms['orders_read']; // assume if read works

        if (!$perms['products'] || !$perms['counterparties']) {
            throw new MoySkladException('MoySklad: no access to products or counterparties. Check API token.');
        }

        self::$permissions = $perms;
        return $perms;
    }

    public static function getPermissions(): array {
        return self::$permissions;
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

            foreach ($data['rows'] as $p) {
                $mapped = self::mapProduct($p);
                $normalized = mb_strtolower(preg_replace('/[\s\-\"\'«»()]+/', ' ', $mapped['name']));

                Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved, unit, description, category, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
                    ON CONFLICT(moysklad_id) DO UPDATE SET
                        name=excluded.name, name_normalized=excluded.name_normalized,
                        article=excluded.article, price=excluded.price,
                        stock=excluded.stock, reserved=excluded.reserved,
                        unit=excluded.unit, description=excluded.description,
                        category=excluded.category, updated_at=datetime('now')", [
                    $mapped['id'], $mapped['name'], $normalized,
                    $mapped['article'], $mapped['price'],
                    $mapped['stock'], $mapped['reserved'],
                    $mapped['unit'], $mapped['description'], $mapped['category'] ?? '',
                ]);
                $count++;
            }

            $offset += $limit;
        } while (count($data['rows']) === $limit);

        return $count;
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
        $data = self::get("/entity/customerorder?filter=agent=" . self::$base . "/entity/counterparty/$counterpartyId&filter=moment>$since");
        if (!$data || empty($data['rows'])) return [];

        return array_map(fn($o) => [
            'id' => self::extractId($o['id'] ?? ''),
            'name' => $o['name'] ?? '',
            'sum' => ($o['sum'] ?? 0) / 100,
            'status' => $o['state']['name'] ?? '',
            'created' => $o['moment'] ?? '',
        ], $data['rows']);
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
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
            }

            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

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
