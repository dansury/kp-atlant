<?php
/**
 * API: Corrections — list, export (NFR-008).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        requireAuth();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $total = Db::val("SELECT COUNT(*) FROM corrections");
        $items = Db::all(
            "SELECT c.id, c.field, c.auto_text, c.manager_text, c.created_at,
                    m.name as manager_name
             FROM corrections c
             LEFT JOIN managers m ON c.manager_id = m.id
             ORDER BY c.created_at DESC LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );
        jsonData(['items' => $items, 'total' => (int)$total, 'page' => $page]);

    case 'export':
        requireAuth();
        $all = Db::all(
            "SELECT c.id, c.field, c.auto_text, c.manager_text, c.context_json, c.created_at,
                    m.name as manager_name
             FROM corrections c LEFT JOIN managers m ON c.manager_id = m.id
             ORDER BY c.created_at"
        );
        header('Content-Disposition: attachment; filename="corrections_export.json"');
        jsonData($all);

    default:
        jsonError('Unknown action', 400);
}
