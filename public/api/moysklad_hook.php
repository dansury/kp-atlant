<?php
/**
 * MoySklad webhook receiver (FR-028, NFR-011).
 * URL: https://<app>/api/moysklad_hook.php?secret=<secret>
 * Handles customerorder CREATE/UPDATE and invoiceout CREATE/UPDATE.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/sync.php';

$secret = (string)Db::val("SELECT value FROM settings WHERE key='moysklad_webhook_secret'");
$given = $_GET['secret'] ?? '';
if (!$secret || !hash_equals($secret, (string)$given)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload) || empty($payload['events'])) {
    http_response_code(200); // ack anything we cannot use, MoySklad must not retry forever
    echo 'ignored';
    exit;
}

// Ack immediately — MoySklad expects a fast response (NFR-010)
ignore_user_abort(true);
http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}

foreach ($payload['events'] as $event) {
    $href = $event['meta']['href'] ?? '';
    $entityType = $event['meta']['type'] ?? '';
    $action = $event['action'] ?? '';
    $msId = $href ? basename(parse_url($href, PHP_URL_PATH) ?: '') : '';
    $result = 'skipped';

    try {
        if (!$msId) {
            $result = 'no id';
        } elseif ($entityType === 'customerorder') {
            $localId = MsSync::upsertOrder($msId);
            if ($localId) {
                MsSync::syncInvoicesForOrder($localId);
                $result = "order #$localId synced";
            } else {
                $result = 'order not linked to any company';
            }
        } elseif ($entityType === 'invoiceout') {
            MsSync::init();
            $inv = MoySklad::getInvoice($msId);
            if ($inv) {
                $localOrder = !empty($inv['order_id'])
                    ? Db::one("SELECT id, counterparty_id FROM orders WHERE moysklad_id=?", [$inv['order_id']])
                    : null;
                $cpId = $localOrder['counterparty_id'] ?? MsSync::localCounterparty($inv['agent_id'] ?? '');
                if ($localOrder && empty($localOrder['counterparty_id'])) {
                    // Order arrived before the company link — refresh it
                    MsSync::upsertOrder($inv['order_id']);
                }
                $id = MsSync::upsertInvoice($inv, $localOrder ? (int)$localOrder['id'] : null, $cpId ? (int)$cpId : null);
                $result = "invoice #$id synced";
            } else {
                $result = 'invoice not found';
            }
        } else {
            $result = "unsupported entity: $entityType";
        }
    } catch (Throwable $e) {
        $result = 'error: ' . $e->getMessage();
        error_log("MoySklad webhook failed ($entityType/$action/$msId): " . $e->getMessage());
    }

    Db::insert('webhook_log', [
        'entity_type' => $entityType,
        'action'      => $action,
        'moysklad_id' => $msId,
        'payload'     => mb_substr($raw, 0, 4000),
        'result'      => $result,
    ]);
}

// Keep the log from growing forever on a shared host
Db::q("DELETE FROM webhook_log WHERE created_at < datetime('now','-30 days')");
