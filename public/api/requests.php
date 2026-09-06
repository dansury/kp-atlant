<?php
/**
 * API: Requests — list, get, create (manual), assign.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/parser.php';
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/attachments.php';

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
        if (!empty($_GET['category'])) {
            $where .= ' AND r.category = ?';
            $params[] = (string)$_GET['category'];
        } else {
            // Спам не должен занимать место в обычном списке запросов, пока его
            // явно не запросили через фильтр по категории
            $where .= " AND r.category IS NOT 'spam'";
        }
        if (trim((string)($_GET['q'] ?? '')) !== '') {
            // Поиск по всем запросам: контрагент, контактное лицо, тема письма, текст
            $where .= ' AND (c.name LIKE ? OR c.contact_person LIKE ? OR r.email_subject LIKE ? OR r.email_from LIKE ? OR r.raw_text LIKE ?)';
            $like = '%' . trim((string)$_GET['q']) . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $total = Db::val("SELECT COUNT(*) FROM requests r LEFT JOIN counterparties c ON r.counterparty_id = c.id WHERE $where", $params);
        $rows = Db::all(
            "SELECT r.id, r.source, r.status, r.type, r.type_source, r.email_from, r.created_at, r.updated_at,
                    r.category, r.category_confidence, r.category_reason, r.category_source,
                    r.counterparty_id, c.name as counterparty_name, c.contact_person, m.name as manager_name,
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
        $req = Db::one("SELECT r.*, c.name as counterparty_name, c.inn as counterparty_inn,
                               c.contact_person, c.contact_email, c.contact_phone, m.name as manager_name
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
        // The letter this request came from — «Создать ответ» works off it
        $req['mail_message_id'] = (int)Db::val(
            "SELECT id FROM mail_messages WHERE request_id=? AND direction='in' ORDER BY id DESC LIMIT 1",
            [$id]
        ) ?: null;
        $req['orders'] = Db::all(
            "SELECT id, moysklad_id, name, sum, state_name, synced_at FROM orders WHERE request_id=? ORDER BY id DESC",
            [$id]
        );
        // «Подходящие позиции» — built once from the parsed letter, edited by hand
        // afterwards. Matching here is local only: opening a card costs no model call.
        $req['items'] = RequestItems::ensure($id);
        $req['open_choices'] = RequestItems::openChoices($id);
        jsonData($req);

    // ---- Matched catalog positions of a request (module 008) ----

    case 'items':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::one("SELECT id FROM requests WHERE id=?", [$id])) jsonError('Not found', 404);
        jsonData(['items' => RequestItems::ensure($id)]);

    case 'items_save':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::one("SELECT id FROM requests WHERE id=?", [$id])) jsonError('Not found', 404);
        $input = getInput();
        jsonData(['items' => RequestItems::save($id, (array)($input['items'] ?? []))]);

    case 'items_choose':
        // The manager answered «какая из равнозначных» — the line stops asking
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::one("SELECT id FROM requests WHERE id=?", [$id])) jsonError('Not found', 404);
        $input = getInput();
        try {
            $items = RequestItems::choose($id, (int)($input['item_id'] ?? 0), (string)($input['moysklad_id'] ?? ''));
        } catch (Throwable $e) {
            jsonError($e->getMessage(), 400);
        }
        jsonData(['items' => $items]);

    case 'items_rematch':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::one("SELECT id FROM requests WHERE id=?", [$id])) jsonError('Not found', 404);
        // `smart=1` lets the model normalize the wording first — costs a call
        $useLlm = ($_GET['smart'] ?? '0') === '1';
        jsonData(['items' => RequestItems::rematch($id, $useLlm)]);

    case 'create':
        $manager = requireAuth();
        $input = getInput();
        $text = trim($input['text'] ?? '');
        if (!$text) jsonError('Text is required');

        // Parse via LLM
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

        // Pre-fill the matched-positions table right away — no model call here
        RequestItems::ensure($requestId);

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

    case 'set_category':
        // Manual override of the triage verdict (FR-060). The category picks the
        // reply prompt and the fact sources, so the manager must be able to fix it.
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $input = getInput();
        $category = (string)($input['category'] ?? '');
        if (!isset(Triage::CATEGORIES[$category])) jsonError('Неизвестная категория');
        if (!Db::one("SELECT id FROM requests WHERE id=?", [$id])) jsonError('Not found', 404);

        Db::update('requests', [
            'category'        => $category,
            'category_source' => 'manager',
            'type'            => Triage::requestType($category) ?: 'kp_request',
            'type_source'     => 'manual',
            'updated_at'      => date('Y-m-d H:i:s'),
        ], 'id=?', [$id]);
        Db::update('mail_messages', ['category' => $category], 'request_id=?', [$id]);
        jsonOk(['category' => $category, 'label' => Triage::label($category)]);

    case 'categories':
        requireAuth();
        jsonData(['categories' => array_map(
            fn($k) => ['key' => $k, 'label' => Triage::label($k)],
            array_keys(Triage::CATEGORIES)
        )]);

    case 'attachment':
        // Download one attachment (feed and request card)
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $a = Db::one("SELECT * FROM attachments WHERE id=?", [$id]);
        if (!$a) jsonError('Not found', 404);
        $path = ROOT . '/' . $a['path'];
        if (!is_file($path)) jsonError('File missing on disk', 404);

        header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: ' . Attachments::contentDisposition($a['filename']));
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;

    default:
        jsonError('Unknown action', 400);
}
