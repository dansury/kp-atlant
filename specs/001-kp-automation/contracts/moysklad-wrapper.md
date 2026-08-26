# MoySklad Wrapper Contract

**File**: `lib/moysklad.php`  
**API**: MoySklad JSON API 1.2  
**Base URL**: `https://api.moysklad.ru/api/remap/1.2`  
**Auth**: Bearer token (`MOYSKLAD_TOKEN` from config)

---

## Class: `MoySklad`

### `MoySklad::init(string $token): void`
Initialize with API token. Verifies connectivity on first call.

### `MoySklad::checkPermissions(): array`
Runtime scope check (D-005, C-004).
```php
// Returns
[
    'products' => true,        // GET /entity/product — required
    'counterparties' => true,  // GET /entity/counterparty — required
    'orders_read' => true,     // GET /entity/customerorder
    'orders_write' => true,    // POST /entity/customerorder — US5
    'stock' => true            // GET /report/stock/all
]
// Throws on products=false or counterparties=false (system can't start)
```

### `MoySklad::searchProducts(string $query, int $limit = 25): array`
Search products by name. Uses `filter=name~=query` + local fuzzy matching.
```php
// Returns
[
    ['id' => 'uuid', 'name' => '...', 'article' => '...', 'price' => 4500.0,
     'stock' => 25, 'reserved' => 3, 'unit' => 'шт.', 'description' => '...']
]
```

### `MoySklad::getProduct(string $id): ?array`
Get single product by UUID.

### `MoySklad::refreshProductCache(): int`
Fetch all products, upsert into `products_cache` table. Returns count.
Uses pagination (`offset` + `limit=1000`).

### `MoySklad::searchCounterparties(string $query): array`
Search by name or INN. `filter=name~=query` or `filter=inn=query`.

### `MoySklad::getCounterparty(string $id): ?array`

### `MoySklad::createCounterparty(array $data): array`
```php
// $data = ['name' => '...', 'inn' => '...', 'phone' => '...', 'email' => '...']
```

### `MoySklad::createOrder(array $data): array`
Create customer order (US5). Requires `orders_write` permission.
```php
// $data
[
    'counterparty_id' => 'uuid',    // MoySklad counterparty
    'organization_id' => 'uuid',    // our legal entity in MoySklad
    'positions' => [
        ['product_id' => 'uuid', 'quantity' => 10, 'price' => 4500.0]
    ],
    'description' => 'КП #001, CRM link: ...'
]
// Returns ['id' => 'uuid', 'name' => '00123']
// Throws MoySkladPermissionException if 403
```

### `MoySklad::getOrdersByCounterparty(string $counterpartyId, int $days = 90): array`
For US6 (follow-up check) and US7 (deal status).

### `MoySklad::getShipments(string $orderId): array`
### `MoySklad::getPayments(string $orderId): array`

---

## Error Handling

```php
class MoySkladException extends \RuntimeException {}
class MoySkladPermissionException extends MoySkladException {}  // 403
class MoySkladRateLimitException extends MoySkladException {}   // 429
class MoySkladNotFoundException extends MoySkladException {}    // 404
```

Retry policy: on 429 or 5xx, retry up to 3 times with 1s/2s/4s backoff.

---

## Rate Limits

MoySklad JSON API 1.2: 45 requests per 3 seconds per account.
Wrapper enforces: max 5 concurrent requests, 200ms delay between batches.
Product cache refresh paginates with `limit=1000` to minimize calls.
