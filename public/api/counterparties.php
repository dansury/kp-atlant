<?php
/**
 * API: Counterparties — company card, unified chat feed, contacts, notes,
 * merge/split, MoySklad lookup. Modules 001 + 002.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/mail_threads.php';
require_once ROOT . '/lib/mail.php';

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'search': {
        requireAuth();
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) jsonError('Слишком короткий запрос');

        // Название, ИНН, телефон, контакты, организации — без учёта регистра (модуль 055)
        SearchIndex::ready();
        $where = ['merged_into_id IS NULL'];
        $params = [];
        foreach (SearchIndex::terms($q) as $term) {
            $like = SearchIndex::like($term);
            $where[] = '(id IN (' . SearchIndex::hitsSql('cp') . ')
                         OR id IN (SELECT merged_into_id FROM counterparties WHERE id IN (' . SearchIndex::hitsSql('cp') . ')))';
            array_push($params, $like, $like);
        }
        $items = Db::all(
            "SELECT id, name, inn, contact_person, contact_email, contact_phone, moysklad_id,
                    last_inbound_at, last_outbound_at
             FROM counterparties
             WHERE " . implode(' AND ', $where) . "
             ORDER BY name LIMIT 20",
            $params
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
            "SELECT id, moysklad_id, name, sum, state_name, moment, synced_at,
                    COALESCE(applicable, 1) AS applicable,
                    reserve_until, reserve_reminded_at, reserve_released_at
             FROM orders WHERE counterparty_id=? ORDER BY id DESC LIMIT 20",
            [$id]
        );
        // Резерв, который пора снимать: счёт не оплачен, срок вышел, заказ
        // всё ещё проведён. Кнопка стоит прямо на заказе (модуль 026).
        require_once ROOT . '/lib/reserves.php';
        foreach ($cp['orders'] as &$o) $o['reserve'] = Reserves::state($o);
        unset($o);
        $cp['invoices'] = Db::all(
            "SELECT id, moysklad_id, name, sum, payed_sum, state_name, moment, pdf_path, sent_at, sent_to
             FROM invoices WHERE counterparty_id=? ORDER BY moment DESC, id DESC LIMIT 20",
            [$id]
        );
        // Организации, на которые эта компания просит счета (модуль 029).
        // Первая строка — сама карточка: счёт по умолчанию идёт на неё.
        $cp['orgs'] = Crm::orgs($id);
        $cp['merged_cards'] = Db::all("SELECT id, name FROM counterparties WHERE merged_into_id=?", [$id]);
        $cp['suggested_email'] = Crm::primaryEmail($id);
        // Сколько компаний на самом деле пишет из этой карточки: больше одной —
        // и на карточке появляется кнопка «Разделить по отправителям»
        $cp['senders_count'] = count(Crm::sendersOf($id));
        // Контрагента нет в МойСклад — карточка сама предлагает завести его с
        // ИНН, найденным в письмах компании (модуль 033)
        // Реквизиты ищутся по ВСЕЙ переписке и вложениям компании, а не в
        // последнем письме: ИНН чаще стоит в первом, в карточке предприятия
        // (модуль 034)
        $cp['moysklad_hint'] = Crm::moyskladHint($id, Crm::correspondenceText($id));
        jsonData($cp);
    }

    /**
     * Every conversation this company ever had, OLDEST first (module 029).
     * The company card is where mail is read now — there is no separate mail
     * list to switch to — so the request behind each thread and its КП come
     * back with it, and the card can be painted from one answer.
     *
     * Порядок — как в почтовом клиенте и как в самой переписке: старое сверху,
     * свежее снизу. Раньше список шёл сверху вниз от нового к старому, а письма
     * ВНУТРИ переписки — наоборот, и карточка читалась в две стороны сразу.
     */
    case 'threads': {
        $manager = requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        $ids = array_map(fn($r) => (int)$r['id'],
            Db::all("SELECT id FROM counterparties WHERE id=? OR merged_into_id=?", [$id, $id]));

        $items = [];
        foreach ($ids as $cpId) {
            foreach (MailThreads::query([
                'counterparty_id' => $cpId,
                // `all` — и в работе, и в архиве одним списком: карточка больше
                // не переключается кнопкой между двумя видами (модуль 026)
                'archived'        => ($_GET['archived'] ?? '') === 'all' ? 'all' : !empty($_GET['archived']),
                'limit'           => 100,
            ])['items'] as $t) {
                $items[$t['thread_key']] = $t;
            }
        }
        $items = array_values($items);
        usort($items, fn($a, $b) => strcmp((string)$a['last_at'], (string)$b['last_at']));

        // The КП that answered each request, so the thread row can link straight to it
        foreach ($items as &$t) {
            $t['proposal'] = $t['request_id']
                ? Db::one("SELECT id, number, status, sent_at FROM proposals WHERE request_id=? ORDER BY id DESC LIMIT 1", [$t['request_id']])
                : null;
            // They wrote last → we owe an answer. Bold on the card, dim once answered.
            $t['unanswered'] = $t['last_direction'] === 'in';
            // Чьё последнее письмо — словами, а не одной стрелкой: карточку
            // открывают именно ради этого вопроса (модуль 029)
            $t['last_mine'] = $t['last_direction'] === 'out';
        }
        unset($t);
        // Ящики нужны здесь же: поле ответа на карточке открыто всегда, в том
        // числе когда переписки ещё нет и письмо будет первым (модуль 019)
        jsonData([
            'items'     => $items,
            'mailboxes' => array_map(
                fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'email' => $b['email']],
                Mailboxes::forManager($manager)
            ),
        ]);
    }

    /**
     * Лента компании (FR-033): заметки и вехи сделки.
     *
     * Письма сюда больше не попадают — они читаются и отвечаются в «Переписке»
     * слева (модуль 020). `letters=1` возвращает ленту целиком для того, кому
     * нужна вся история одним списком.
     */
    case 'chat': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $withLetters = !empty($_GET['letters']);
        jsonData([
            'items'  => Crm::chat($id, $limit, $offset, $withLetters),
            // Заказы и счета идут той же лентой: сделка читается одним списком,
            // а не собирается из трёх карточек по углам экрана (модуль 029)
            'docs'   => Crm::documents($id),
            'total'  => Crm::chatCount($id, $withLetters),
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

    /**
     * Удалить заметку. Только заметку: веха сделки и письмо — не заметки, и
     * стирать их этой кнопкой нельзя, иначе «убрать лишнюю строку» однажды
     * сотрёт отправленное КП из истории.
     */
    /**
     * Печатная форма документа сделки для 👁 в ленте (issue #119): PDF прямо в
     * окне. Формы нет — страница с причиной и ссылкой в МойСклад, а не JSON в рамке.
     */
    case 'doc_pdf': {
        requireAuth();
        require_once ROOT . '/lib/sync.php';
        $doc = (string)($_GET['doc'] ?? '');
        $r = MsSync::docPdf($doc, (int)($_GET['id'] ?? 0));
        if ($r['path'] && is_file($r['path'])) {
            header('Content-Type: application/pdf');
            header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($r['name']));
            header('Content-Length: ' . filesize($r['path']));
            readfile($r['path']);
            exit;
        }
        http_response_code(502);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><body style="font:14px sans-serif;padding:16px">'
           . '<p>Печатная форма недоступна в МойСклад' . ($r['error'] !== '' ? ': ' . htmlspecialchars($r['error']) : '')
           . '</p><p>Документ можно открыть в МойСклад по ссылке в ленте.</p></body>';
        exit;
    }

    case 'note_delete': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        $noteId = (int)($_GET['note_id'] ?? 0) ?: (int)(getInput()['note_id'] ?? 0);
        if (!$noteId) jsonError('Не указана заметка');
        // Заметка ищется В ЭТОЙ карточке: описка в номере не должна стереть
        // чужую заметку из другой компании
        $row = Db::one("SELECT id, direction, event_type FROM correspondence
                        WHERE id=? AND counterparty_id=?", [$noteId, $id]);
        if (!$row) jsonError('Заметка не найдена', 404);
        if ((string)$row['direction'] !== 'note' || !empty($row['event_type'])) {
            jsonError('Это не заметка — удалить можно только заметку');
        }
        Db::q("DELETE FROM correspondence WHERE id=?", [$noteId]);
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

    /**
     * Разложить карточку по отправителям (модуль 025).
     *
     * Кнопка для того случая, когда в одной карточке оказались разные
     * компании: домен общий, а фирмы за ним свои. Домен после этого
     * перестаёт склеивать и будущие письма.
     */
    case 'split_senders': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        try {
            $created = Crm::splitBySender($id);
        } catch (Throwable $e) {
            jsonError($e->getMessage());
        }
        jsonOk([
            'created' => count($created),
            'ids'     => $created,
            'message' => $created
                ? 'Отделено карточек: ' . count($created)
                : 'В карточке один отправитель — делить нечего',
        ]);
    }

    /** Кого видно в карточке: адрес и сколько с него писем. */
    case 'senders': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        $items = [];
        foreach (Crm::sendersOf($id) as $email => $count) {
            $items[] = ['email' => $email, 'messages' => $count];
        }
        jsonData(['items' => $items]);
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
        foreach (['name','inn','contact_person','contact_email','contact_phone','notes','moysklad_id','default_price_type'] as $f) {
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

    /**
     * Организации карточки (модуль 029).
     *
     * «Прошу счёт на 15 штук в адрес АО ТИКО-Пластик, 2 штуки в адрес ООО Нова
     * Ролл Пак» — одно письмо, один контакт, две организации. Карточка держит
     * их списком, как держит несколько счетов, и счёт выставляется на выбранную.
     */
    case 'org_add': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        if (!Db::one("SELECT id FROM counterparties WHERE id=?", [$id])) jsonError('Не найдено', 404);
        $input = getInput();
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') jsonError('Название организации не может быть пустым');
        $orgId = Crm::addOrg($id, [
            'name'        => $name,
            'inn'         => trim((string)($input['inn'] ?? '')),
            'kpp'         => trim((string)($input['kpp'] ?? '')),
            'moysklad_id' => trim((string)($input['moysklad_id'] ?? '')),
            'edo_id'      => trim((string)($input['edo_id'] ?? '')),
            'note'        => trim((string)($input['note'] ?? '')),
        ]);
        jsonOk(['id' => $orgId, 'items' => Crm::orgs($id)]);
    }

    case 'org_delete': {
        requireAuth();
        $id = Crm::rootId((int)($_GET['id'] ?? 0));
        $orgId = (int)($_GET['org_id'] ?? 0);
        Db::q("DELETE FROM counterparty_orgs WHERE id=? AND counterparty_id=?", [$orgId, $id]);
        jsonOk(['items' => Crm::orgs($id)]);
    }

    /**
     * «Завести в МойСклад» — одной кнопкой на любой организации карточки
     * (модули 033 и 034).
     *
     * Письмо от компании, которой в МойСклад нет, дальше не едет: ни счёта,
     * ни заказа. Одно нажатие на карточке письма — и контрагент заведён с тем
     * ИНН, который нашёлся в подписи или во вложении.
     *
     * `org_id` указывает, КАКУЮ организацию карточки заводим: 0 (или пусто) —
     * саму карточку, иначе вторую фирму из «Организаций для счёта». Раньше на
     * это было две разные кнопки, делавшие одно и то же на разных строках.
     *
     * Двойника не заводим: ИНН сначала ищется в МойСклад, и найденный
     * контрагент просто привязывается — в справочнике должна остаться одна
     * компания, а не две с одинаковым ИНН.
     */
    case 'moysklad_create': {
        $manager = requireAuth();
        $input = getInput();

        $inn   = Crm::cleanInn((string)($input['inn'] ?? ''));
        $name  = trim((string)($input['name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $key   = trim((string)($input['thread_key'] ?? ''));
        if ($inn === null && trim((string)($input['inn'] ?? '')) !== '') {
            jsonError('ИНН — это 10 цифр у организации или 12 у предпринимателя');
        }

        // 1. Карточка компании у нас. Нет — заводим из того, что знает письмо
        $cpId = !empty($input['counterparty_id']) ? Crm::rootId((int)$input['counterparty_id']) : 0;
        if (!$cpId) {
            if ($name === '' && $email === '') jsonError('Не из чего завести компанию: нет ни названия, ни адреса');
            $cpId = (int)Crm::resolveCounterparty([
                'inn' => $inn ?: '', 'name' => $name, 'email' => $email, 'phone' => $phone ?: null,
            ]);
            if (!$cpId) jsonError('Компания не завелась');
            // Переписка уезжает на новую карточку вместе с запросами и контактами
            if ($key !== '') Crm::attachThread($key, $cpId);
        }
        $cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$cpId]);

        // Вторая фирма карточки: заводим ЕЁ, а привязка ложится на строку
        // организации, а не на карточку компании (модуль 034)
        $orgId = (int)($input['org_id'] ?? 0);
        $org = $orgId ? Db::one("SELECT * FROM counterparty_orgs WHERE id=? AND counterparty_id=?", [$orgId, $cpId]) : null;
        if ($orgId && !$org) jsonError('Организация не найдена в карточке', 404);

        if ($org) {
            if ($name === '') $name = (string)$org['name'];
            if ($inn === null) $inn = Crm::cleanInn((string)($org['inn'] ?? ''));
        } else {
            if ($name === '') $name = (string)$cp['name'];
            if ($inn === null) $inn = Crm::cleanInn((string)($cp['inn'] ?? ''));
            if ($inn !== null && trim((string)($cp['inn'] ?? '')) === '') {
                Db::update('counterparties', ['inn' => $inn, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$cpId]);
            }
        }

        require_once ROOT . '/lib/moysklad.php';

        // Уже привязан — второй раз не заводим
        $linked = trim((string)(($org['moysklad_id'] ?? null) ?? ($cp['moysklad_id'] ?? '')));
        if ($linked !== '') {
            jsonOk(['counterparty_id' => $cpId, 'org_id' => $orgId, 'moysklad_id' => $linked, 'created' => false,
                    'found' => true, 'orgs' => Crm::orgs($cpId), 'url' => MoySklad::counterpartyUrl($linked)]);
        }

        $token = trim((string)($cfg['MOYSKLAD_TOKEN'] ?? ''));
        if ($token === '') jsonError('В настройках не задан токен МойСклад', 400);
        MoySklad::init($token);

        // 2. Тот же ИНН уже в МойСклад — привязываем, а не плодим двойника
        $msId = ''; $created = false; $found = false;
        if ($inn) {
            foreach (MoySklad::searchCounterparties($inn) as $c) {
                if (Crm::cleanInn((string)($c['inn'] ?? '')) === $inn) { $msId = (string)$c['id']; $found = true; break; }
            }
        }
        if ($msId === '') {
            if ($name === '') jsonError('У компании нет названия — МойСклад его требует');
            $made = MoySklad::createCounterparty([
                'name'  => $name,
                'inn'   => $inn ?: '',
                'email' => $email ?: (string)($cp['contact_email'] ?? ''),
                'phone' => $phone ?: (string)($cp['contact_phone'] ?? ''),
            ]);
            $msId = (string)($made['id'] ?? '');
            if ($msId === '') jsonError('МойСклад не вернул контрагента: ' . MoySklad::lastErrorMessage(), 502);
            $created = true;
        }

        if ($org) {
            $upd = ['moysklad_id' => $msId];
            if ($inn && trim((string)($org['inn'] ?? '')) === '') $upd['inn'] = $inn;
            Db::update('counterparty_orgs', $upd, 'id=?', [$orgId]);
        } else {
            Db::update('counterparties', ['moysklad_id' => $msId, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$cpId]);
        }
        Logger::info('moysklad', ($created ? 'Контрагент заведён в МойСклад: ' : 'Контрагент найден в МойСклад по ИНН: ') . $name,
                     ['counterparty_id' => $cpId, 'org_id' => $orgId, 'moysklad_id' => $msId, 'inn' => $inn,
                      'manager_id' => (int)$manager['id']]);

        jsonOk(['counterparty_id' => $cpId, 'org_id' => $orgId, 'moysklad_id' => $msId,
                'created' => $created, 'found' => $found,
                'inn' => (string)($inn ?? ''), 'name' => $name, 'orgs' => Crm::orgs($cpId),
                'url' => MoySklad::counterpartyUrl($msId)]);
    }

    /**
     * «Найти ИНН» — по всей переписке и вложениям, а не глазами (модуль 034).
     *
     * Сначала обычный поиск по образцу: он бесплатен и отвечает в большинстве
     * писем. Не нашёл — спрашиваем нейросеть, она читает ту же переписку.
     * Найденный ИНН сразу ложится на карточку, если её поле пустое: перепечатать
     * его руками во второй раз не должно быть нужно.
     */
    case 'find_inn': {
        requireAuth();
        $cpId = !empty($_GET['id']) ? Crm::rootId((int)$_GET['id']) : 0;
        $key  = trim((string)($_GET['thread_key'] ?? ''));
        $text = Crm::correspondenceText($cpId ?: null, $key);
        if (trim($text) === '') jsonError('В переписке нет текста, в котором можно искать', 400);

        $found = Crm::requisitesFromText($text);
        $inn = Crm::cleanInn((string)($found['inn'] ?? ''));
        $source = $inn !== null ? 'найден в тексте письма' : '';
        $byLlm = null;

        if ($inn === null) {
            $byLlm = Crm::requisitesByLlm($text);
            $inn = $byLlm['inn'];
            $source = $inn !== null ? ((string)$byLlm['source'] ?: 'нашла нейросеть') : '';
            foreach (['kpp', 'ogrn', 'legal_title', 'legal_address'] as $f) {
                if (($found[$f] ?? null) === null && $byLlm[$f] !== null) $found[$f] = $byLlm[$f];
            }
        }

        // Пустое поле карточки заполняем, заполненное не трогаем
        if ($inn !== null && $cpId) {
            $was = trim((string)(Db::val("SELECT inn FROM counterparties WHERE id=?", [$cpId]) ?: ''));
            if ($was === '') {
                Db::update('counterparties', ['inn' => $inn, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$cpId]);
            }
        }

        jsonData([
            'inn'         => (string)($inn ?? ''),
            'kpp'         => (string)($found['kpp'] ?? ''),
            'ogrn'        => (string)($found['ogrn'] ?? ''),
            'legal_title' => (string)($found['legal_title'] ?? ''),
            'source'      => $source,
            'by_llm'      => $byLlm !== null && $inn !== null,
        ]);
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
