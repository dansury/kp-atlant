<?php
/**
 * API: web push (module 007) — subscribe a device, mute a kind, send a test.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/push.php';

$manager = requireAuth();
$action  = $_GET['action'] ?? '';
$input   = in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true) ? getInput() : [];

try {
    switch ($action) {

        case 'key':
            $reason = Push::unavailableReason();
            if ($reason !== '') jsonError($reason, 400);
            jsonData(['key' => Push::publicKey()]);

        case 'subscribe':
            $sub = $input['subscription'] ?? [];
            Push::subscribe(
                (int)$manager['id'],
                (string)($sub['endpoint'] ?? ''),
                (string)($sub['keys']['p256dh'] ?? ''),
                (string)($sub['keys']['auth'] ?? '')
            );
            jsonOk(['prefs' => Push::prefs((int)$manager['id'])]);

        case 'unsubscribe':
            Push::unsubscribe((int)$manager['id'], (string)($input['endpoint'] ?? ''));
            jsonOk(['prefs' => Push::prefs((int)$manager['id'])]);

        case 'prefs':
            jsonData(Push::prefs((int)$manager['id']) + ['available' => Push::unavailableReason() === '']);

        case 'mute':
            $kind = array_key_exists('kind', $input) && $input['kind'] !== null && $input['kind'] !== ''
                ? (string)$input['kind'] : null;
            Push::setMute((int)$manager['id'], $kind, !empty($input['muted']));
            jsonOk(['prefs' => Push::prefs((int)$manager['id'])]);

        case 'test':
            $sent = Push::sendTest((int)$manager['id']);
            jsonOk(['sent' => $sent]);

        default:
            jsonError('Unknown action', 400);
    }
} catch (Throwable $e) {
    Logger::exception('push', $e, ['action' => $action]);
    jsonError($e->getMessage(), 500);
}
