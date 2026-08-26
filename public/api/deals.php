<?php
/**
 * API: Deals — deal status by counterparty from MoySklad (US7).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/moysklad.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'status':
        requireAuth();
        $counterpartyId = (int)($_GET['counterparty_id'] ?? 0);
        $cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$counterpartyId]);
        if (!$cp) jsonError('Counterparty not found', 404);

        $result = [
            'counterparty' => $cp['name'],
            'status' => 'unknown',
            'orders_count' => 0,
            'total_amount' => 0,
            'last_order_date' => null,
            'proposals_count' => 0,
            'proposals_sent' => 0,
        ];

        // Local stats
        $result['proposals_count'] = (int)Db::val("SELECT COUNT(*) FROM proposals WHERE counterparty_id=?", [$counterpartyId]);
        $result['proposals_sent'] = (int)Db::val("SELECT COUNT(*) FROM proposals WHERE counterparty_id=? AND status IN ('sent','order_created')", [$counterpartyId]);

        // MoySklad stats
        if ($cp['moysklad_id']) {
            MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');
            try {
                $orders = MoySklad::getOrdersByCounterparty($cp['moysklad_id'], 365);
                $result['orders_count'] = count($orders);
                $result['total_amount'] = array_sum(array_column($orders, 'sum'));
                if (!empty($orders)) {
                    $result['last_order_date'] = $orders[0]['created'] ?? null;
                }
            } catch (\Exception $e) {
                // MoySklad unavailable — use local data only
            }
        }

        // Determine status
        if ($result['orders_count'] >= 2) {
            $result['status'] = 'repeat_client';
        } elseif ($result['orders_count'] === 1) {
            $result['status'] = 'completed';
        } elseif ($result['proposals_sent'] > 0) {
            $result['status'] = 'pending';
        } else {
            $result['status'] = 'new';
        }

        $labels = [
            'repeat_client' => 'Повторный клиент',
            'completed' => 'Сделка состоялась',
            'pending' => 'В процессе',
            'new' => 'Новый контрагент',
            'unknown' => 'Нет данных',
        ];
        $result['status_label'] = $labels[$result['status']] ?? $result['status'];

        jsonData($result);

    default:
        jsonError('Unknown action', 400);
}
