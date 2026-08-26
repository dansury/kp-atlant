<?php
/**
 * API: Products — search MoySklad products + cache refresh.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/moysklad.php';

$action = $_GET['action'] ?? '';
MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');

switch ($action) {
    case 'search':
        requireAuth();
        $q = trim($_GET['q'] ?? '');
        if (!$q) jsonError('Query required');

        // Search local cache first
        $norm = mb_strtolower(trim($q));
        $items = Db::all(
            "SELECT moysklad_id, name, article, price, stock, reserved, unit
             FROM products_cache
             WHERE name_normalized LIKE ? OR article LIKE ?
             ORDER BY name LIMIT 20",
            ["%$norm%", "%$norm%"]
        );

        // If no local results, try MoySklad API
        if (empty($items)) {
            $items = MoySklad::searchProducts($q);
        }

        jsonData(['items' => $items]);

    case 'refresh_cache':
        requireAuth();
        $start = microtime(true);
        $count = MoySklad::refreshProductCache();
        $elapsed = round(microtime(true) - $start, 2);
        jsonOk(['count' => $count, 'elapsed_sec' => $elapsed]);

    default:
        jsonError('Unknown action', 400);
}
