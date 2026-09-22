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

// Each event is handled and logged on its own: one that fails waits for the
// cron to repeat it, and the rest still go through (module 043).
foreach ($payload['events'] as $event) {
    if (!is_array($event)) continue;
    MsSync::runWebhookEvent($event, $raw);
}

// Keep the log from growing forever on a shared host
Db::q("DELETE FROM webhook_log WHERE created_at < datetime('now','-30 days')");
