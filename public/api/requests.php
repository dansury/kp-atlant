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

    /**
     * Переписка, из которой завели этот запрос (модуль 023).
     *
     * Запрос и письмо — одна сущность, и открывать их двумя разными экранами
     * значит показывать одни и те же позиции в двух разных вёрстках. Экран
     * спрашивает здесь, есть ли у запроса письмо, и открывает карточку письма.
     */
    case 'thread':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        jsonData(['thread_key' => (string)(Db::val(
            "SELECT thread_key FROM mail_messages
             WHERE request_id=? AND thread_key IS NOT NULL AND archived_at IS NULL
             ORDER BY date_at DESC, id DESC LIMIT 1", [$id]) ?: '')]);

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

    // Строка, которой мы не занимаемся: с экрана она не исчезает, но в КП и в
    // ответ клиенту не уходит, и её слова пополняют список правил (модуль 022)
    case 'items_scope':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::one("SELECT id FROM requests WHERE id=?", [$id])) jsonError('Not found', 404);
        $input = getInput();
        try {
            $items = RequestItems::setScope($id, (int)($input['item_id'] ?? 0), !empty($input['out_of_scope']));
        } catch (Throwable $e) {
            jsonError($e->getMessage(), 400);
        }
        jsonData(['items' => $items, 'out_of_scope' => RequestItems::outOfScope($id)]);

    case 'items_rematch':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::one("SELECT id FROM requests WHERE id=?", [$id])) jsonError('Not found', 404);
        // `smart=1` lets the model normalize the wording first — costs a call
        $useLlm = ($_GET['smart'] ?? '0') === '1';
        // The counts come back with the rows: «ничего не нашлось» must not look
        // the same on screen as «нашлось всё» (module 018)
        jsonData(RequestItems::rematchReport($id, $useLlm));

    /**
     * Запрос, который принесли мимо почты (модуль 038).
     *
     * Клиент написал в мессенджер, позвонил или прислал файл — до сих пор это
     * значило «перепечатать руками и потерять вложение». Форма принимает текст
     * И ФАЙЛЫ, а запрос кладётся карточкой в «В работе»: он уже в работе, раз
     * его завели руками, и ждать, пока кто-то перетащит его из «Входящих», не
     * должен. Браузер получает адрес этой карточки и уходит на неё.
     */
    case 'create':
        $manager = requireAuth();
        $input = getInput();
        $text = trim($input['text'] ?? '');
        $files = array_values((array)($input['files'] ?? []));
        if (!$text && !$files) jsonError('Вставьте текст запроса или приложите файл');

        // Текст письма может быть и в файле: спецификация в .xlsx, запрос
        // сканом. Разбирать нечего, пока вложения не прочитаны.
        require_once ROOT . '/lib/outbox.php';
        $staged = [];
        foreach (Outbox::resolve($files, (int)$manager['id']) as $path) {
            $staged[] = ['path' => $path, 'name' => preg_replace('/^[0-9a-f]{16}__/', '', basename($path))];
        }
        if ($text === '') $text = '(запрос во вложении: ' . implode(', ', array_column($staged, 'name')) . ')';

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
            // Pasted text carries the same signature an e-mail does
            'text'           => $text,
        ]);
        if ($counterpartyId && !empty($parsed['contact_email'])) {
            Crm::upsertContact($counterpartyId, $parsed['contact_person'] ?? null, $parsed['contact_email'], $parsed['contact_phone'] ?? null);
        }

        $type = ($parsed['request_type'] ?? 'kp_request') === 'order' ? 'order' : 'kp_request';
        // Проверочный запрос заводит мастер настройки, а он админский: иначе
        // «тихий» запрос без уведомления мог бы создать кто угодно
        $isTrial = !empty($input['trial']) && !empty($manager['is_admin']);
        $requestId = Db::insert('requests', [
            'source' => 'manual',
            'raw_text' => $text,
            'parsed_json' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
            'counterparty_id' => $counterpartyId,
            'manager_id' => $manager['id'],
            'status' => 'processing',
            'type' => $type,
            'type_source' => 'llm',
            'is_trial' => $isTrial ? 1 : 0,
        ]);

        // Вложения — на запрос и на компанию: текст из них идёт в подбор
        // позиций так же, как текст из письма
        foreach ($staged as $file) {
            try {
                Attachments::store(
                    ['filename' => $file['name'], 'content' => (string)file_get_contents($file['path'])],
                    ['request_id' => $requestId, 'counterparty_id' => $counterpartyId]
                );
            } catch (Throwable $e) {
                Logger::exception('requests', $e, ['request_id' => $requestId, 'file' => $file['name']]);
            }
        }

        // Manual paste is still an inbound message in the company feed
        Crm::logEvent($counterpartyId, 'in', $text, [
            'request_id' => $requestId,
            'subject'    => 'Запрос добавлен вручную',
            'email_from' => $parsed['contact_email'] ?? null,
        ]);

        // Pre-fill the matched-positions table right away — no model call here
        RequestItems::ensure($requestId);

        // Карточка сразу в «В работе»: запрос, заведённый руками, уже разбирают
        require_once ROOT . '/lib/boards.php';
        $cardId = 0;
        try {
            $board = Boards::singleton();
            $work  = Boards::workColumn((int)$board['id']);
            if ($work) {
                $cardId = Boards::addCard((int)$work['id'], [
                    'counterparty_id' => $counterpartyId,
                    'request_id'      => $requestId,
                    'title'           => (string)($orgName ?: 'Запрос #' . $requestId),
                    'manager_id'      => (int)$manager['id'],
                ]);
            }
        } catch (Throwable $e) {
            Logger::exception('requests', $e, ['request_id' => $requestId]);
        }

        // Проверочный запрос мастера настройки — чтобы мастер знал, что оценивать
        if ($isTrial) {
            require_once ROOT . '/lib/support.php';
            require_once ROOT . '/lib/setup_wizard.php';
            SetupWizard::rememberTrial($requestId, (int)$counterpartyId);
        }

        // Notify — кроме проверочного: «Ромашка» из примера не клиент
        if (!$isTrial) {
            require_once ROOT . '/lib/notifier.php';
            Notifier::notify('new_request', "Новый запрос на КП" . ($orgName ? " от $orgName" : ''), null, 'request', $requestId);
        }

        jsonData([
            'id'     => $requestId,
            'status' => 'processing',
            'type'   => $type,
            'counterparty_id' => $counterpartyId,
            'card_id' => $cardId,
            'files'   => count($staged),
            // Куда уходит браузер: на карточку, которую только что положили на доску
            'hash'    => $counterpartyId ? 'mail/company/' . $counterpartyId : 'mail/request/' . $requestId,
        ]);

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
        // `answerable` — есть ли у категории свой промпт ответа. Выпадающий
        // список перед «Сгенерировать ответ» показывает их первыми: менеджер
        // выбирает, ЧЕМ отвечать, а не только чем письмо помечено (модуль 022)
        jsonData(['categories' => array_map(
            fn($k) => [
                'key'        => $k,
                'label'      => Triage::label($k),
                'answerable' => Triage::route($k)[0] !== null,
                'request'    => Triage::createsRequest($k),
            ],
            array_keys(Triage::CATEGORIES)
        )]);

    case 'attachment':
        // Download, or preview inline (item 5), one attachment (feed and request card)
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $a = Db::one("SELECT * FROM attachments WHERE id=?", [$id]);
        if (!$a) jsonError('Not found', 404);
        $path = ROOT . '/' . $a['path'];
        if (!is_file($path)) jsonError('File missing on disk', 404);

        $mime = (string)($a['mime'] ?: 'application/octet-stream');
        // Only types a browser can display, never run, may be served inline —
        // the MIME is attacker-controlled (it comes from the letter), so a file
        // claiming text/html or image/svg+xml always forces a download instead
        $inlineSafe = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
        $inline = $inlineSafe && !empty($_GET['inline']);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . Attachments::contentDisposition($a['filename'], $inline));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;

    default:
        jsonError('Unknown action', 400);
}
