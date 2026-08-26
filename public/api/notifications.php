<?php
/**
 * API: Notifications — polling endpoint.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/notifier.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'poll':
        $manager = requireAuth();
        $items = Notifier::getUnread($manager['id']);
        jsonData(['items' => $items, 'unread_count' => count($items)]);

    case 'read':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID required');
        Notifier::markRead($id, $manager['id']);
        jsonOk();

    default:
        jsonError('Unknown action', 400);
}
