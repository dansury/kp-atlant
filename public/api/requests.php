<?php
/**
 * API: Requests — list, get, create (manual), assign.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $manager = requireAuth();
        $status = $_GET['status'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(50, max(1, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = '1=1';
        $params = [];
        if ($status) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }

        $total = Db::val("SELECT COUNT(*) FROM requests r WHERE $where", $params);
        $rows = Db::all(
            "SELECT r.id, r.source, r.status, r.email_from, r.created_at, r.updated_at,
                    c.name as counterparty_name, m.name as manager_name,
                    (SELECT COUNT(*) FROM proposal_items pi JOIN proposals p ON pi.proposal_id=p.id WHERE p.request_id=r.id) as items_count
             FROM requests r
             LEFT JOIN counterparties c ON r.counterparty_id = c.id
             LEFT JOIN managers m ON r.manager_id = m.id
             WHERE $where
             ORDER BY r.created_at DESC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        jsonData(['items' => $rows, 'total' => (int)$total, 'page' => $page]);

    case 'get':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $req = Db::one("SELECT r.*, c.name as counterparty_name, c.inn as counterparty_inn, m.name as manager_name
                         FROM requests r
                         LEFT JOIN counterparties c ON r.counterparty_id = c.id
                         LEFT JOIN managers m ON r.manager_id = m.id
                         WHERE r.id=?", [$id]);
        if (!$req) jsonError('Not found', 404);

        $req['parsed'] = $req['parsed_json'] ? json_decode($req['parsed_json'], true) : null;
        unset($req['parsed_json']);
        jsonData($req);

    case 'create':
        $manager = requireAuth();
        $input = getInput();
        $text = trim($input['text'] ?? '');
        if (!$text) jsonError('Text is required');

        // Parse via LLM
        require_once ROOT . '/lib/parser.php';
        $parsed = RequestParser::parse($text);

        // Find or create counterparty
        $counterpartyId = null;
        $orgName = $parsed['org_name'] ?? $input['counterparty_name'] ?? null;
        if ($orgName) {
            $existing = Db::one("SELECT id FROM counterparties WHERE name LIKE ?", ["%$orgName%"]);
            if ($existing) {
                $counterpartyId = $existing['id'];
            } else {
                $counterpartyId = Db::insert('counterparties', [
                    'name' => $orgName,
                    'contact_person' => $parsed['contact_person'] ?? null,
                    'contact_email' => $parsed['contact_email'] ?? null,
                ]);
            }
        }

        $requestId = Db::insert('requests', [
            'source' => 'manual',
            'raw_text' => $text,
            'parsed_json' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
            'counterparty_id' => $counterpartyId,
            'manager_id' => $manager['id'],
            'status' => 'processing',
        ]);

        // Notify
        require_once ROOT . '/lib/notifier.php';
        Notifier::notify('new_request', "Новый запрос на КП" . ($orgName ? " от $orgName" : ''), null, 'request', $requestId);

        jsonData(['id' => $requestId, 'status' => 'processing']);

    case 'assign':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $req = Db::one("SELECT id, manager_id FROM requests WHERE id=?", [$id]);
        if (!$req) jsonError('Not found', 404);

        Db::update('requests', [
            'manager_id' => $manager['id'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$id]);
        jsonOk();

    default:
        jsonError('Unknown action', 400);
}
