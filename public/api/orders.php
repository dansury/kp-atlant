<?php
/**
 * API: Orders — create MoySklad order from confirmed KP (US5).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/moysklad.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        $manager = requireAuth();
        $proposalId = (int)($_GET['proposal_id'] ?? 0);
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        if (!$proposal) jsonError('Proposal not found', 404);
        if (!in_array($proposal['status'], ['confirmed', 'sent'])) jsonError('KP must be confirmed first');

        MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');
        $perms = MoySklad::checkPermissions();
        if (!$perms['orders_write']) jsonError('MoySklad API: no write access to customerorder', 403);

        // Get proposal items
        $items = Db::all("SELECT * FROM proposal_items WHERE proposal_id=? AND moysklad_product_id IS NOT NULL", [$proposalId]);
        if (empty($items)) jsonError('No matched products to create order');

        // Get counterparty MoySklad ID
        $counterparty = null;
        if ($proposal['counterparty_id']) {
            $cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$proposal['counterparty_id']]);
            if ($cp && $cp['moysklad_id']) {
                $counterparty = $cp['moysklad_id'];
            }
        }
        if (!$counterparty) jsonError('Counterparty not linked to MoySklad. Link via counterparty card first.');

        // Get organization ID from MoySklad (first one)
        $orgs = MoySklad::searchCounterparties(''); // placeholder — need org endpoint
        // For now use a config or first org
        $orgId = $cfg['MOYSKLAD_ORG_ID'] ?? '';

        $positions = array_map(fn($i) => [
            'product_id' => $i['moysklad_product_id'],
            'quantity' => $i['quantity'],
            'price' => $i['price'],
        ], $items);

        $appUrl = $cfg['APP_URL'] ?? '';
        try {
            $order = MoySklad::createOrder([
                'counterparty_id' => $counterparty,
                'organization_id' => $orgId,
                'positions' => $positions,
                'description' => "КП #{$proposal['number']}, CRM: $appUrl/#proposal/$proposalId",
            ]);

            Db::update('proposals', [
                'status' => 'order_created',
                'moysklad_order_id' => $order['id'],
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id=?', [$proposalId]);

            Db::update('requests', [
                'status' => 'ordered',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id=?', [$proposal['request_id']]);

            jsonOk(['moysklad_order_id' => $order['id'], 'order_number' => $order['name']]);
        } catch (MoySkladPermissionException $e) {
            jsonError('MoySklad API: no write access to customerorder', 403);
        }

    default:
        jsonError('Unknown action', 400);
}
