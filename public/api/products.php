<?php
/**
 * API: Products — the local catalog behind «Подходящие позиции» and the KP.
 * Search, cache refresh from MoySklad, import from an Excel export, photos.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/kp_content.php';

$action = $_GET['action'] ?? '';
MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');

switch ($action) {
    case 'search':
        requireAuth();
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) jsonData(['items' => []]);
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

        // Every word of the query has to appear somewhere in the row: typing
        // «шлем l» must narrow the list, not widen it
        $words = array_slice(array_filter(preg_split('/\s+/u', mb_strtolower($q)) ?: []), 0, 6);
        $where = ["is_archived IS NOT 1"];
        $params = [];
        foreach ($words as $w) {
            $where[] = "(name_normalized LIKE ? OR lower(name) LIKE ? OR lower(article) LIKE ?"
                     . " OR lower(code) LIKE ? OR lower(characteristics) LIKE ?)";
            array_push($params, "%$w%", "%$w%", "%$w%", "%$w%", "%$w%");
        }
        $items = Db::all(
            "SELECT moysklad_id, name, article, code, price, prices_json, stock, reserved, unit,
                    characteristics, product_type, category, parent_id
             FROM products_cache
             WHERE " . implode(' AND ', $where) . "
             ORDER BY (stock > 0) DESC, length(name), name
             LIMIT ?",
            [...$params, $limit]
        );
        $counterpartyId = (int)($_GET['counterparty_id'] ?? 0) ?: null;
        require_once ROOT . '/lib/catalog.php';
        require_once ROOT . '/lib/variants.php';
        // Выбирают не «товар вообще», а размер и цвет: в подсказке стоят САМИ
        // модификации, каждая со своим артикулом, ценой и количеством, а товар
        // — только когда модификаций у него нет (модуль 022).
        $items = Variants::expandSuggest($items, max(12, $limit * 5));
        // Описание товара — тем же текстом, каким карточка подбора заполняет
        // комментарий: выбрали другую позицию — описание поехало за ней, и
        // второго запроса за ним не нужно (модуль 032). Спрашивается оно по
        // строкам подсказки: у модификации своего описания может не быть, и
        // тогда она берёт описание товара
        require_once ROOT . '/lib/request_items.php';
        $descriptions = RequestItems::catalogDescriptions(array_column($items, 'moysklad_id'));
        foreach ($items as &$it) {
            $it['prices'] = Catalog::decodePrices($it['prices_json'] ?? null);
            $own = Catalog::priceFor($it, $counterpartyId);
            // У товара с модификациями своей цены обычно нет: строка семьи
            // несёт вилку по модификациям, и перетирать её нулём нельзя
            if (!empty($it['is_group'])) {
                if ($own > 0) $it['price'] = $own;
                if ((float)($it['price_max'] ?? 0) < (float)$it['price']) $it['price_max'] = (float)$it['price'];
            } else {
                $it['price'] = $own;
            }
            $it['description'] = $descriptions[(string)$it['moysklad_id']] ?? '';
            unset($it['prices_json']);
        }
        unset($it);

        // Nothing locally — ask MoySklad itself, if the token still works
        $fromApi = false;
        if (!$items && (string)($cfg['MOYSKLAD_TOKEN'] ?? '') !== '') {
            try {
                $items = MoySklad::searchProducts($q);
                $items = array_map(fn($p) => $p + ['moysklad_id' => $p['id'], 'characteristics' => '', 'code' => ''], $items);
                $fromApi = (bool)$items;
            } catch (Throwable $e) {
                Logger::warning('catalog', 'Поиск в МойСклад не удался: ' . $e->getMessage());
            }
        }
        jsonData(['items' => $items, 'from_api' => $fromApi]);

    // What the local catalog holds right now — shown above both refresh buttons
    case 'stats':
        requireAuth();
        jsonData([
            'total'       => (int)Db::val("SELECT COUNT(*) FROM products_cache"),
            'products'    => (int)Db::val("SELECT COUNT(*) FROM products_cache WHERE product_type IS NULL OR product_type<>'variant'"),
            'variants'    => (int)Db::val("SELECT COUNT(*) FROM products_cache WHERE product_type='variant'"),
            'archived'    => (int)Db::val("SELECT COUNT(*) FROM products_cache WHERE is_archived=1"),
            'with_photos' => (int)Db::val("SELECT COUNT(*) FROM products_cache WHERE (images_json IS NOT NULL AND images_json<>'[]') OR (image_urls IS NOT NULL AND image_urls<>'[]')"),
            'with_price'  => (int)Db::val("SELECT COUNT(*) FROM products_cache WHERE price > 0"),
            'from_excel'  => (int)Db::val("SELECT COUNT(*) FROM products_cache WHERE source='excel'"),
            'updated_at'  => Db::val("SELECT MAX(updated_at) FROM products_cache"),
            'imported_at' => Db::val("SELECT MAX(imported_at) FROM products_cache"),
        ]);

    case 'refresh_cache':
        requireAuth();
        $start = microtime(true);
        $count = MoySklad::refreshProductCache();
        $elapsed = round(microtime(true) - $start, 2);
        // 0 products is not success — surface why the API returned nothing
        if ($count === 0) {
            $h = MoySklad::lastHttp();
            jsonError('Каталог не загружен: МойСклад вернул HTTP ' . $h['code']
                . ($h['body'] !== '' ? ' — ' . $h['body'] : '')
                . '. Проверьте токен в «Настройках → МойСклад» или обновите базу импортом Excel.', 400);
        }
        jsonOk(['count' => $count, 'elapsed_sec' => $elapsed]);

    /**
     * Update the catalog from a MoySklad Excel export. This is the way in when
     * the API is unreachable — the file carries names, articles, prices,
     * descriptions, variants and photo links.
     */
    case 'import':
        requireAuth();
        require_once ROOT . '/lib/catalog_import.php';
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonError('Файл не загрузился. Проверьте размер: сервер принимает не больше '
                . ini_get('upload_max_filesize') . '.');
        }
        try {
            $report = CatalogImport::run($_FILES['file']['tmp_name'], (string)$_FILES['file']['name'], [
                'price_column'  => (string)Settings::get('CATALOG_PRICE_COLUMN', ''),
                'with_archived' => (string)Settings::get('CATALOG_IMPORT_ARCHIVED', '0') === '1',
                'prune'         => (($_POST['prune'] ?? '0') === '1'),
            ]);
        } catch (CatalogImportException $e) {
            jsonError($e->getMessage());
        } catch (Throwable $e) {
            Logger::exception('catalog', $e);
            jsonError('Импорт не удался: ' . $e->getMessage());
        }
        jsonOk(['report' => $report]);

    // Photos known for a product: what the API downloaded plus the CDN links
    // from the Excel export. The browser gets our own URL, never the CDN one.
    case 'images':
        requireAuth();
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') jsonError('Не указан товар');
        $items = array_map(fn($img) => [
            'key'    => $img['key'],
            'source' => $img['source'],
            'url'    => '/api/products.php?action=image&id=' . rawurlencode($id) . '&key=' . rawurlencode($img['key']),
        ], KpContent::productImageList($id));
        jsonData(['items' => $items]);

    case 'image':
        requireAuth();
        $id  = trim((string)($_GET['id'] ?? ''));
        $key = trim((string)($_GET['key'] ?? ''));
        $img = ($id !== '' && $key !== '') ? KpContent::imageBytes($id, $key) : null;
        if (!$img) jsonError('Изображение не найдено', 404);
        header('Content-Type: ' . $img['mime']);
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . strlen($img['bytes']));
        echo $img['bytes'];
        exit;

    // ---- Catalog vectors (module 009) ----

    // One resumable step of the indexing: the panel calls it in a loop and shows
    // how much is left, so a 1 200-position catalog never needs one long request
    case 'vector_index':
        requireAdmin();
        require_once ROOT . '/lib/embeddings.php';
        $report = Embeddings::indexCatalog([
            'budget' => (int)($_GET['budget'] ?? Settings::get('VECTOR_BUDGET_SEC', 20)),
        ]);
        // A step that moved something is a success even with failures in it;
        // one that moved nothing hands the reason to the panel as an error
        if ($report['error'] && !$report['indexed']) jsonError($report['error'], 400);
        jsonOk(['report' => $report]);

    case 'vector_stats':
        requireAuth();
        require_once ROOT . '/lib/embeddings.php';
        jsonData(Embeddings::stats());

    case 'vector_reset':
        requireAdmin();
        require_once ROOT . '/lib/embeddings.php';
        jsonOk(['removed' => Embeddings::reset()]);

    // One live request to Yandex: what the indexing runs into, in plain HTTP
    case 'vector_diagnose':
        requireAdmin();
        require_once ROOT . '/lib/embeddings.php';
        jsonData(Embeddings::diagnose());

    // What the request card will see for a phrase — words, meaning and the score
    case 'match_preview':
        requireAuth();
        require_once ROOT . '/lib/matcher.php';
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) jsonData(['items' => []]);
        jsonData([
            'items'  => ProductMatcher::findCandidates($q, max(2, (int)Settings::get('MATCH_CANDIDATES', 5))),
            'vector' => Embeddings::enabled(),
        ]);

    // Every price type the synced catalog knows — the picker for «цена по
    // умолчанию» in settings and on a counterparty card
    case 'price_types':
        requireAuth();
        require_once ROOT . '/lib/catalog.php';
        jsonData(['items' => Catalog::priceTypes()]);

    default:
        jsonError('Unknown action', 400);
}
