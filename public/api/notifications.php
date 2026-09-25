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

    // Уведомления одной компании — полоска с галочками в её карточке (issue #103)
    case 'for_company':
        $manager = requireAuth();
        $cpId = (int)($_GET['id'] ?? 0);
        if (!$cpId) jsonError('ID required');
        $n = Notifier::cardNotices((int)$manager['id'], $cpId);
        jsonData(['items' => $n['cp'][Crm::rootId($cpId)] ?? []]);

    case 'read':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID required');
        Notifier::markRead($id, $manager['id']);
        jsonOk();

    default:
        jsonError('Unknown action', 400);
}
