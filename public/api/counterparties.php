<?php
/**
 * API: Counterparties — search, get, create, MoySklad lookup.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'search':
        requireAuth();
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) jsonError('Query too short');

        $items = Db::all(
            "SELECT id, name, inn, contact_person, contact_email, contact_phone, moysklad_id
             FROM counterparties WHERE name LIKE ? OR inn LIKE ? ORDER BY name LIMIT 20",
            ["%$q%", "%$q%"]
        );
        jsonData(['items' => $items]);

    case 'get':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$id]);
        if (!$cp) jsonError('Not found', 404);

        // Include request/proposal history
        $cp['requests'] = Db::all(
            "SELECT r.id, r.status, r.created_at, p.id as proposal_id, p.status as proposal_status
             FROM requests r LEFT JOIN proposals p ON p.request_id = r.id
             WHERE r.counterparty_id = ? ORDER BY r.created_at DESC LIMIT 20",
            [$id]
        );
        $cp['correspondence'] = Db::all(
            "SELECT id, direction, subject, created_at FROM correspondence WHERE counterparty_id = ? ORDER BY created_at DESC LIMIT 30",
            [$id]
        );
        jsonData($cp);

    case 'create':
        requireAuth();
        $input = getInput();
        $name = trim($input['name'] ?? '');
        if (!$name) jsonError('Name required');

        $id = Db::insert('counterparties', [
            'name' => $name,
            'inn' => $input['inn'] ?? null,
            'contact_person' => $input['contact_person'] ?? null,
            'contact_email' => $input['contact_email'] ?? null,
            'contact_phone' => $input['contact_phone'] ?? null,
        ]);
        jsonData(['id' => $id, 'name' => $name]);

    case 'update':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::one("SELECT id FROM counterparties WHERE id=?", [$id])) jsonError('Not found', 404);

        $input = getInput();
        $fields = [];
        foreach (['name','inn','contact_person','contact_email','contact_phone','notes','moysklad_id'] as $f) {
            if (array_key_exists($f, $input)) $fields[$f] = $input[$f];
        }
        if ($fields) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            Db::update('counterparties', $fields, 'id=?', [$id]);
        }
        jsonOk();

    case 'lookup_moysklad':
        requireAuth();
        $inn = trim($_GET['inn'] ?? '');
        if (!$inn) jsonError('INN required');

        require_once ROOT . '/lib/moysklad.php';
        MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');
        $results = MoySklad::searchCounterparties($inn);
        jsonData(['items' => $results]);

    default:
        jsonError('Unknown action', 400);
}
