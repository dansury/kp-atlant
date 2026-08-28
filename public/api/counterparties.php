<?php
/**
 * API: Counterparties — company card, unified chat feed, contacts, notes,
 * merge/split, MoySklad lookup. Modules 001 + 002.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/crm.php';

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'search': {
        requireAuth();
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) jsonError('Слишком короткий запрос');

        $items = Db::all(
            "SELECT id, name, inn, contact_person, contact_email, contact_phone, moysklad_id,
                    last_inbound_at, last_outbound_at
             FROM counterparties
             WHERE merged_into_id IS NULL AND (name LIKE ? OR inn LIKE ? OR email_domain LIKE ?)
             ORDER BY name LIMIT 20",
            ["%$q%", "%$q%", "%$q%"]
        );
        foreach ($items as &$i) {
            $i['answer_state'] = Crm::answerState($i['last_inbound_at'], $i['last_outbound_at']);
        }
        unset($i);
        jsonData(['items' => $items]);
    }

    // Companies needing attention first — default list of the CRM tab
    case 'recent': {
        requireAuth();
        $items = Db::all(
            "SELECT id, name, inn, contact_person, contact_email, moysklad_id,
                    last_inbound_at, last_outbound_at, updated_at
             FROM counterparties
             WHERE merged_into_id IS NULL
             ORDER BY COALESCE(last_inbound_at, updated_at) DESC
             LIMIT 50"
        );
        foreach ($items as &$i) {
            $i['answer_state'] = Crm::answerState($i['last_inbound_at'], $i['last_outbound_at']);
        }
        unset($i);
        // Unanswered on top, then most recent
        usort($items, function ($a, $b) {
            $ua = $a['answer_state']['unanswered'] ? 1 : 0;
            $ub = $b['answer_state']['unanswered'] ? 1 : 0;
            if ($ua !== $ub) return $ub <=> $ua;
            return strcmp((string)$b['last_inbound_at'], (string)$a['last_inbound_at']);
        });
        jsonData(['items' => $items]);
    }

    case 'get': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        $cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$id]);
        if (!$cp) jsonError('Не найдено', 404);

        $cp['answer_state'] = Crm::answerState($cp['last_inbound_at'], $cp['last_outbound_at']);
        $cp['contacts'] = Crm::contacts($id);
        $cp['requests'] = Db::all(
            "SELECT r.id, r.status, r.type, r.created_at, p.id as proposal_id, p.status as proposal_status
             FROM requests r LEFT JOIN proposals p ON p.request_id = r.id
             WHERE r.counterparty_id = ? ORDER BY r.created_at DESC LIMIT 20",
            [$id]
        );
        $cp['orders'] = Db::all(
            "SELECT id, moysklad_id, name, sum, state_name, moment, synced_at FROM orders
             WHERE counterparty_id=? ORDER BY id DESC LIMIT 20",
            [$id]
        );
        $cp['invoices'] = Db::all(
            "SELECT id, moysklad_id, name, sum, payed_sum, state_name, moment, pdf_path, sent_at, sent_to
             FROM invoices WHERE counterparty_id=? ORDER BY moment DESC, id DESC LIMIT 20",
            [$id]
        );
        $cp['merged_cards'] = Db::all("SELECT id, name FROM counterparties WHERE merged_into_id=?", [$id]);
        $cp['suggested_email'] = Crm::primaryEmail($id);
        jsonData($cp);
    }

    // Unified company feed (FR-033)
    case 'chat': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        jsonData([
            'items'  => Crm::chat($id, $limit, $offset),
            'total'  => Crm::chatCount($id),
            'offset' => $offset,
        ]);
    }

    // Internal note for colleagues (FR-036)
    case 'note': {
        $manager = requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        $input = getInput();
        $text = trim($input['text'] ?? '');
        if ($text === '') jsonError('Пустая заметка');
        if (!Db::one("SELECT id FROM counterparties WHERE id=?", [$id])) jsonError('Не найдено', 404);

        $noteId = Crm::logEvent($id, 'note', $text, [
            'manager_id' => $manager['id'],
            'request_id' => !empty($input['request_id']) ? (int)$input['request_id'] : null,
            'subject'    => 'Заметка',
        ]);
        jsonOk(['id' => $noteId]);
    }

    case 'contacts': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        jsonData(['items' => Crm::contacts($id)]);
    }

    // Manual merge of two company cards (FR-037)
    case 'merge': {
        requireAuth();
        $input = getInput();
        $source = (int)($input['source_id'] ?? 0);
        $target = (int)($input['target_id'] ?? 0);
        if (!$source || !$target) jsonError('Укажите обе карточки');
        try {
            Crm::merge($source, $target);
        } catch (Throwable $e) {
            jsonError($e->getMessage());
        }
        jsonOk(['target_id' => $target]);
    }

    // Split one contact out into a separate card (FR-037)
    case 'split': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        $input = getInput();
        $email = trim($input['email'] ?? '');
        if (!$email) jsonError('Укажите email контакта');
        try {
            $newId = Crm::splitContact($id, $email);
        } catch (Throwable $e) {
            jsonError($e->getMessage());
        }
        jsonOk(['id' => $newId]);
    }

    case 'create': {
        requireAuth();
        $input = getInput();
        $name = trim($input['name'] ?? '');
        if (!$name) jsonError('Укажите название');

        $id = Crm::resolveCounterparty([
            'inn'            => $input['inn'] ?? '',
            'name'           => $name,
            'email'          => $input['contact_email'] ?? '',
            'contact_person' => $input['contact_person'] ?? null,
            'phone'          => $input['contact_phone'] ?? null,
        ]);
        jsonData(['id' => $id, 'name' => $name]);
    }

    case 'update': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        if (!Db::one("SELECT id FROM counterparties WHERE id=?", [$id])) jsonError('Не найдено', 404);

        $input = getInput();
        $fields = [];
        foreach (['name','inn','contact_person','contact_email','contact_phone','notes','moysklad_id'] as $f) {
            if (array_key_exists($f, $input)) $fields[$f] = $input[$f];
        }
        if (isset($fields['name'])) $fields['name_normalized'] = normalizeCompanyName($fields['name']);
        if (isset($fields['inn'])) $fields['inn'] = Crm::cleanInn((string)$fields['inn']);
        if (isset($fields['contact_email'])) $fields['email_domain'] = Crm::corporateDomain((string)$fields['contact_email']);
        if ($fields) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            Db::update('counterparties', $fields, 'id=?', [$id]);
        }
        jsonOk();
    }

    case 'lookup_moysklad': {
        requireAuth();
        $q = trim($_GET['inn'] ?? $_GET['q'] ?? '');
        if (!$q) jsonError('Укажите ИНН или название');

        require_once ROOT . '/lib/moysklad.php';
        MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');
        jsonData(['items' => MoySklad::searchCounterparties($q)]);
    }

    default:
        jsonError('Unknown action', 400);
}
