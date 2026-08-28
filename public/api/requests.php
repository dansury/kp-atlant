<?php
/**
 * API: Requests — list, get, create (manual), assign.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/crm.php';

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
            "SELECT r.id, r.source, r.status, r.type, r.type_source, r.email_from, r.created_at, r.updated_at,
                    r.counterparty_id, c.name as counterparty_name, m.name as manager_name,
                    c.last_inbound_at, c.last_outbound_at,
                    (SELECT COUNT(*) FROM proposal_items pi JOIN proposals p ON pi.proposal_id=p.id WHERE p.request_id=r.id) as items_count,
                    (SELECT COUNT(*) FROM attachments a WHERE a.request_id=r.id) as attachments_count
             FROM requests r
             LEFT JOIN counterparties c ON r.counterparty_id = c.id
             LEFT JOIN managers m ON r.manager_id = m.id
             WHERE $where
             ORDER BY r.created_at DESC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        // Unanswered highlighting (FR-038)
        foreach ($rows as &$row) {
            $row['answer_state'] = Crm::answerState($row['last_inbound_at'] ?? null, $row['last_outbound_at'] ?? null);
        }
        unset($row);

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

        $req['attachments'] = Db::all(
            "SELECT id, filename, mime, size, extract_status FROM attachments WHERE request_id=? ORDER BY id",
            [$id]
        );
        $req['proposals'] = Db::all(
            "SELECT id, number, status, created_at FROM proposals WHERE request_id=? ORDER BY id DESC",
            [$id]
        );
        $req['orders'] = Db::all(
            "SELECT id, moysklad_id, name, sum, state_name, synced_at FROM orders WHERE request_id=? ORDER BY id DESC",
            [$id]
        );
        jsonData($req);

    case 'create':
        $manager = requireAuth();
        $input = getInput();
        $text = trim($input['text'] ?? '');
        if (!$text) jsonError('Text is required');

        // Parse via LLM
        require_once ROOT . '/lib/parser.php';
        $parsed = RequestParser::parse($text);

        // Company card: INN → email domain → name (FR-034)
        $orgName = $parsed['org_name'] ?? $input['counterparty_name'] ?? null;
        $counterpartyId = Crm::resolveCounterparty([
            'inn'            => $parsed['inn'] ?? '',
            'name'           => $orgName ?? '',
            'email'          => $parsed['contact_email'] ?? '',
            'contact_person' => $parsed['contact_person'] ?? null,
            'phone'          => $parsed['contact_phone'] ?? null,
        ]);
        if ($counterpartyId && !empty($parsed['contact_email'])) {
            Crm::upsertContact($counterpartyId, $parsed['contact_person'] ?? null, $parsed['contact_email'], $parsed['contact_phone'] ?? null);
        }

        $type = ($parsed['request_type'] ?? 'kp_request') === 'order' ? 'order' : 'kp_request';
        $requestId = Db::insert('requests', [
            'source' => 'manual',
            'raw_text' => $text,
            'parsed_json' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
            'counterparty_id' => $counterpartyId,
            'manager_id' => $manager['id'],
            'status' => 'processing',
            'type' => $type,
            'type_source' => 'llm',
        ]);

        // Manual paste is still an inbound message in the company feed
        Crm::logEvent($counterpartyId, 'in', $text, [
            'request_id' => $requestId,
            'subject'    => 'Запрос добавлен вручную',
            'email_from' => $parsed['contact_email'] ?? null,
        ]);

        // Notify
        require_once ROOT . '/lib/notifier.php';
        Notifier::notify('new_request', "Новый запрос на КП" . ($orgName ? " от $orgName" : ''), null, 'request', $requestId);

        jsonData(['id' => $requestId, 'status' => 'processing', 'type' => $type]);

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

    case 'set_type':
        // Manual override of the LLM classification (FR-024)
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $input = getInput();
        $type = ($input['type'] ?? '') === 'order' ? 'order' : 'kp_request';
        if (!Db::one("SELECT id FROM requests WHERE id=?", [$id])) jsonError('Not found', 404);

        Db::update('requests', [
            'type'        => $type,
            'type_source' => 'manual',
            'updated_at'  => date('Y-m-d H:i:s'),
        ], 'id=?', [$id]);
        jsonOk(['type' => $type]);

    case 'attachment':
        // Download one attachment (feed and request card)
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $a = Db::one("SELECT * FROM attachments WHERE id=?", [$id]);
        if (!$a) jsonError('Not found', 404);
        $path = ROOT . '/' . $a['path'];
        if (!is_file($path)) jsonError('File missing on disk', 404);

        header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($a['filename']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;

    default:
        jsonError('Unknown action', 400);
}
