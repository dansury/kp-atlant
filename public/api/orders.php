<?php
/**
 * API: Orders — create a MoySklad customer order from a KP (US5)
 * or straight from an order-type request (US8), and keep the local projection fresh.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/parser.php';
require_once ROOT . '/lib/sync.php';

$action = $_GET['action'] ?? '';

// Seller organization: config value, else first organization of the account (cached)
function orgId(): string {
    $cfgOrg = $GLOBALS['cfg']['MOYSKLAD_ORG_ID'] ?? '';
    if ($cfgOrg) return $cfgOrg;

    $cached = Db::val("SELECT value FROM settings WHERE key='moysklad_org_id'");
    if ($cached) return (string)$cached;

    $orgs = MoySklad::getOrganizations();
    if (empty($orgs)) jsonError('МойСклад: не найдено ни одного юрлица (организации) в аккаунте', 400);
    Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('moysklad_org_id', ?)", [$orgs[0]['id']]);
    return $orgs[0]['id'];
}

// Company must be linked to MoySklad before an order can be created
function requireMsCounterparty(?int $counterpartyId): array {
    if (!$counterpartyId) jsonError('У запроса не указан контрагент', 400);
    $cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$counterpartyId]);
    if (!$cp) jsonError('Контрагент не найден', 404);

    if (!empty($cp['moysklad_id'])) return $cp;

    // Try to find it in MoySklad by INN, then by name
    $candidates = [];
    if (!empty($cp['inn'])) $candidates = MoySklad::searchCounterparties($cp['inn']);
    if (!$candidates && !empty($cp['name'])) $candidates = MoySklad::searchCounterparties($cp['name']);

    if (count($candidates) === 1) {
        Db::update('counterparties', ['moysklad_id' => $candidates[0]['id']], 'id=?', [$counterpartyId]);
        $cp['moysklad_id'] = $candidates[0]['id'];
        return $cp;
    }

    jsonErrorData('Контрагент не привязан к МойСклад', 409, [
        'need_counterparty' => true,
        'counterparty_id'   => $counterpartyId,
        'candidates'        => $candidates,
    ]);
}

// jsonError with extra payload
function jsonErrorData(string $msg, int $code, array $extra): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['error' => $msg, 'code' => $code], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {

    // Create the order from a confirmed KP (US5)
    case 'create': {
        $manager = requireAuth();
        $proposalId = (int)($_GET['proposal_id'] ?? 0);
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        if (!$proposal) jsonError('КП не найдено', 404);
        if (!in_array($proposal['status'], ['confirmed', 'sent'], true)) jsonError('Сначала подтвердите КП');

        MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');
        $perms = MoySklad::checkPermissions();
        if (!$perms['orders_write']) jsonError('МойСклад: нет прав на создание заказов', 403);

        $items = Db::all("SELECT * FROM proposal_items WHERE proposal_id=? AND moysklad_product_id IS NOT NULL", [$proposalId]);
        if (!$items) jsonError('В КП нет позиций, сопоставленных с товарами МойСклад');

        $cp = requireMsCounterparty($proposal['counterparty_id'] ? (int)$proposal['counterparty_id'] : null);

        $positions = array_map(fn($i) => [
            'product_id' => $i['moysklad_product_id'],
            'quantity'   => $i['quantity'],
            'price'      => $i['price'],
        ], $items);

        $appUrl = rtrim($cfg['APP_URL'] ?? '', '/');
        try {
            $order = MoySklad::createOrder([
                'counterparty_id' => $cp['moysklad_id'],
                'organization_id' => orgId(),
                'positions'       => $positions,
                'description'     => "КП №{$proposal['number']}, CRM: $appUrl/#proposal/$proposalId",
            ]);
        } catch (MoySkladPermissionException $e) {
            jsonError('МойСклад: нет прав на создание заказов', 403);
        }

        Db::update('proposals', [
            'status'            => 'order_created',
            'moysklad_order_id' => $order['id'],
            'updated_at'        => date('Y-m-d H:i:s'),
        ], 'id=?', [$proposalId]);
        Db::update('requests', ['status' => 'ordered', 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$proposal['request_id']]);

        $localId = MsSync::upsertOrder($order['id'], [
            'request_id'      => (int)$proposal['request_id'],
            'proposal_id'     => $proposalId,
            'counterparty_id' => (int)$proposal['counterparty_id'],
            'manager_id'      => $manager['id'],
        ]);

        jsonOk([
            'order_id'          => $localId,
            'moysklad_order_id' => $order['id'],
            'order_number'      => $order['name'],
            'url'               => MoySklad::orderUrl($order['id']),
        ]);
    }

    // Create the order straight from an order-type request (US8, FR-025)
    case 'create_from_request': {
        $manager = requireAuth();
        $requestId = (int)($_GET['request_id'] ?? 0);
        $req = Db::one("SELECT * FROM requests WHERE id=?", [$requestId]);
        if (!$req) jsonError('Запрос не найден', 404);

        MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');
        $perms = MoySklad::checkPermissions();
        if (!$perms['orders_write']) jsonError('МойСклад: нет прав на создание заказов', 403);

        $cp = requireMsCounterparty($req['counterparty_id'] ? (int)$req['counterparty_id'] : null);

        // Match parsed items (body + attachments) against the product cache
        $parsed = $req['parsed_json'] ? json_decode($req['parsed_json'], true) : [];
        $parsedItems = $parsed['items'] ?? [];
        if (!$parsedItems) jsonError('В запросе не распознано ни одной позиции');

        if (!Db::val("SELECT COUNT(*) FROM products_cache")) MoySklad::refreshProductCache();
        $matched = ProductMatcher::matchItems($parsedItems);

        $positions = [];
        $unmatched = [];
        foreach ($matched as $m) {
            if (!empty($m['match']['moysklad_id'])) {
                $positions[] = [
                    'product_id' => $m['match']['moysklad_id'],
                    'quantity'   => $m['quantity'],
                    'price'      => $m['match']['price'],
                ];
            } else {
                $unmatched[] = $m['raw_name'];
            }
        }
        if (!$positions) jsonError('Ни одна позиция не найдена в каталоге МойСклад. Обновите каталог или создайте КП вручную.');

        $appUrl = rtrim($cfg['APP_URL'] ?? '', '/');
        $note = "Заказ по письму от {$req['email_from']}, CRM: $appUrl/#request/$requestId";
        if ($unmatched) $note .= "\nНе найдено в каталоге: " . implode('; ', $unmatched);

        try {
            $order = MoySklad::createOrder([
                'counterparty_id' => $cp['moysklad_id'],
                'organization_id' => orgId(),
                'positions'       => $positions,
                'description'     => $note,
            ]);
        } catch (MoySkladPermissionException $e) {
            jsonError('МойСклад: нет прав на создание заказов', 403);
        }

        Db::update('requests', [
            'status'     => 'ordered',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$requestId]);

        $localId = MsSync::upsertOrder($order['id'], [
            'request_id'      => $requestId,
            'counterparty_id' => (int)$req['counterparty_id'],
            'manager_id'      => $manager['id'],
        ]);

        jsonOk([
            'order_id'          => $localId,
            'moysklad_order_id' => $order['id'],
            'order_number'      => $order['name'],
            'url'               => MoySklad::orderUrl($order['id']),
            'unmatched'         => $unmatched,
        ]);
    }

    // Link a local company card to a MoySklad counterparty, creating it if asked
    case 'link_counterparty': {
        requireAuth();
        $id = (int)($_GET['counterparty_id'] ?? 0);
        $input = getInput();
        $cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$id]);
        if (!$cp) jsonError('Контрагент не найден', 404);

        MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');

        if (!empty($input['moysklad_id'])) {
            Db::update('counterparties', ['moysklad_id' => $input['moysklad_id']], 'id=?', [$id]);
            jsonOk(['moysklad_id' => $input['moysklad_id']]);
        }

        if (!empty($input['create'])) {
            $created = MoySklad::createCounterparty([
                'name'  => $cp['name'],
                'inn'   => $cp['inn'],
                'email' => $cp['contact_email'],
                'phone' => $cp['contact_phone'],
            ]);
            Db::update('counterparties', ['moysklad_id' => $created['id']], 'id=?', [$id]);
            jsonOk(['moysklad_id' => $created['id'], 'created' => true]);
        }

        // Otherwise return candidates for the manager to choose from
        $query = $cp['inn'] ?: $cp['name'];
        jsonData(['candidates' => MoySklad::searchCounterparties($query)]);
    }

    // Re-read one order from MoySklad
    case 'sync': {
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $order = Db::one("SELECT * FROM orders WHERE id=?", [$id]);
        if (!$order) jsonError('Заказ не найден', 404);

        MsSync::upsertOrder($order['moysklad_id'], ['counterparty_id' => (int)$order['counterparty_id']]);
        MsSync::syncInvoicesForOrder($id);
        jsonData(Db::one("SELECT * FROM orders WHERE id=?", [$id]));
    }

    case 'list': {
        requireAuth();
        $cpId = (int)($_GET['counterparty_id'] ?? 0);
        $rows = Db::all(
            "SELECT o.*, (SELECT COUNT(*) FROM invoices i WHERE i.order_id=o.id) as invoices_count
             FROM orders o WHERE o.counterparty_id=? ORDER BY o.id DESC",
            [$cpId]
        );
        foreach ($rows as &$r) {
            $r['positions'] = $r['positions_json'] ? json_decode($r['positions_json'], true) : [];
            $r['url'] = MoySklad::orderUrl($r['moysklad_id']);
            unset($r['positions_json']);
        }
        unset($r);
        jsonData(['items' => $rows]);
    }

    default:
        jsonError('Unknown action', 400);
}
