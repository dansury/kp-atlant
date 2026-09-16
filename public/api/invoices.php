<?php
/**
 * API: Invoices — projection of MoySklad «Счёт покупателю» (US9).
 * list / sync / pdf / send.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/sync.php';
require_once ROOT . '/lib/mail.php';

$action = $_GET['action'] ?? '';

// Юрлицо-продавец: из конфига, иначе первое юрлицо аккаунта (с запоминанием).
// Тот же выбор, что у заказов в orders.php, — счёт и заказ не должны уходить
// от разных организаций.
function msOrgId(): string {
    $cfgOrg = $GLOBALS['cfg']['MOYSKLAD_ORG_ID'] ?? '';
    if ($cfgOrg) return $cfgOrg;

    $cached = Db::val("SELECT value FROM settings WHERE key='moysklad_org_id'");
    if ($cached) return (string)$cached;

    $orgs = MoySklad::getOrganizations();
    if (empty($orgs)) jsonError('МойСклад: не найдено ни одного юрлица (организации) в аккаунте', 400);
    Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('moysklad_org_id', ?)", [$orgs[0]['id']]);
    return $orgs[0]['id'];
}


switch ($action) {

    // Invoices of one company, newest first
    case 'list': {
        requireAuth();
        $cpId = (int)($_GET['counterparty_id'] ?? 0);
        $rows = Db::all(
            "SELECT i.*, o.name as order_name FROM invoices i
             LEFT JOIN orders o ON i.order_id = o.id
             WHERE i.counterparty_id=? ORDER BY i.moment DESC, i.id DESC",
            [$cpId]
        );
        foreach ($rows as &$r) {
            $r['has_pdf'] = !empty($r['pdf_path']) && is_file(ROOT . '/' . $r['pdf_path']);
            $r['url'] = MoySklad::invoiceUrl($r['moysklad_id']);
        }
        unset($r);
        jsonData(['items' => $rows, 'suggested_email' => Crm::primaryEmail($cpId)]);
    }

    /**
     * Счета, которые можно приложить к открытому письму (модуль 029).
     *
     * Раньше они стояли карточкой в самом низу правой колонки с кнопкой
     * «Отправить счёт» — вторым, параллельным способом послать клиенту файл.
     * Теперь список приходит сюда, под поле ответа: каждый счёт с уже готовым
     * именем файла, которое менеджер правит прямо в письме.
     */
    case 'for_request': {
        requireAuth();
        require_once ROOT . '/lib/invoice_name.php';
        $requestId = (int)($_GET['request_id'] ?? 0);
        $cpId      = (int)($_GET['counterparty_id'] ?? 0);
        if (!$requestId && !$cpId) jsonError('Не указан ни запрос, ни компания');

        $rows = $requestId
            ? Db::all(
                "SELECT i.* FROM invoices i
                 LEFT JOIN orders o ON o.id = i.order_id
                 LEFT JOIN proposals p ON p.id = i.proposal_id
                 WHERE o.request_id = ? OR p.request_id = ?
                 ORDER BY i.id DESC LIMIT 20", [$requestId, $requestId])
            : Db::all(
                "SELECT * FROM invoices WHERE counterparty_id=? ORDER BY id DESC LIMIT 20", [$cpId]);

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'          => (int)$r['id'],
                'name'        => (string)$r['name'],
                'sum'         => (float)$r['sum'],
                'payed_sum'   => (float)$r['payed_sum'],
                'moment'      => $r['moment'],
                'sent_at'     => $r['sent_at'],
                'proposal_id' => $r['proposal_id'] !== null ? (int)$r['proposal_id'] : null,
                'url'         => MoySklad::invoiceUrl($r['moysklad_id']),
                'pdf_url'     => '/api/invoices.php?action=pdf&id=' . (int)$r['id'],
                'filename'    => InvoiceName::forInvoice((int)$r['id']),
            ];
        }
        jsonData(['items' => $items]);
    }

    // Pull fresh orders and invoices for a company (FR-030)
    case 'sync': {
        requireAuth();
        $cpId = (int)($_GET['counterparty_id'] ?? 0);
        if (!$cpId) jsonError('counterparty_id required');
        try {
            $res = MsSync::syncCompany($cpId);
        } catch (Throwable $e) {
            jsonError('МойСклад недоступен: ' . $e->getMessage(), 502);
        }
        jsonOk($res);
    }

    // Serve the cached printform (downloading it on first request)
    case 'pdf': {
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $inv = Db::one("SELECT * FROM invoices WHERE id=?", [$id]);
        if (!$inv) jsonError('Счёт не найден', 404);

        $path = MsSync::ensureInvoicePdf($id);
        if (!$path || !is_file($path)) jsonError('Печатная форма счёта недоступна в МойСклад', 502);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode('Счёт ' . $inv['name'] . '.pdf') . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * Счёт по КП — из карточки, а не из МойСклад (issue #38).
     *
     * Менеджер, дошедший до «клиент согласен», выставляет счёт теми же
     * позициями, что ушли в КП: в МойСклад создаётся «Счёт покупателю», тут же
     * скачивается его печатная форма — и счёт готов к тому, чтобы приложить его
     * к письму (`attach_url`) или отдать отдельным файлом.
     *
     * Позиции без привязки к номенклатуре МойСклад в счёт не попадают: счёт с
     * выдуманной строкой хуже счёта, в котором строки не хватает, — и о каждой
     * пропущенной ответ говорит вслух.
     */
    case 'create_from_proposal': {
        $manager = requireAuth();
        $proposalId = (int)($_GET['proposal_id'] ?? 0);
        $p = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        if (!$p) jsonError('КП не найдено', 404);

        $cpId = (int)($p['counterparty_id'] ?? 0);
        $cp = $cpId ? Db::one("SELECT * FROM counterparties WHERE id=?", [$cpId]) : null;
        if (!$cp) jsonError('У КП нет компании — счёт выставлять не на кого', 400);

        // На какую организацию счёт (модуль 029). В одном письме просят счёт на
        // две фирмы сразу — карточка держит обе, и здесь выбирается нужная.
        // 0 (или ничего) — сама карточка, как было.
        $buyerOrgId = (int)($_GET['org_id'] ?? 0);
        $buyerOrg   = Crm::org($cpId, $buyerOrgId);
        if ($buyerOrgId && !$buyerOrg) jsonError('Такой организации в карточке нет', 404);
        $buyerMsId  = $buyerOrg ? (string)$buyerOrg['moysklad_id'] : (string)($cp['moysklad_id'] ?? '');
        $buyerName  = $buyerOrg ? (string)$buyerOrg['name'] : (string)$cp['name'];
        if ($buyerMsId === '') {
            jsonError($buyerOrgId
                ? "Организация «{$buyerName}» не связана с МойСклад — привяжите её в карточке, иначе счёт выставлять не на кого"
                : 'Компания не связана с МойСклад — свяжите её в карточке, иначе счёт выставлять не на кого', 400);
        }

        MoySklad::init($GLOBALS['cfg']['MOYSKLAD_TOKEN'] ?? '');
        $perms = MoySklad::checkPermissions();
        if (empty($perms['invoices'])) jsonError('МойСклад: нет доступа к счетам покупателям', 403);

        // «Нет в наличии» из КП исключено — в счёт такая позиция тем более не идёт
        $rows = Db::all("SELECT * FROM proposal_items
                         WHERE proposal_id=? AND (is_excluded IS NULL OR is_excluded=0)
                         ORDER BY position", [$proposalId]);

        $positions = [];
        $skipped   = [];
        foreach ($rows as $r) {
            $price = (float)$r['price'];
            $qty   = (float)$r['quantity'];
            if (empty($r['moysklad_product_id'])) { $skipped[] = (string)$r['product_name']; continue; }
            if ($price <= 0 || $qty <= 0)         { $skipped[] = (string)$r['product_name']; continue; }
            $positions[] = [
                'product_id' => $r['moysklad_product_id'],
                'quantity'   => $qty,
                'price'      => $price,
                'discount'   => (float)($r['discount_percent'] ?? 0),
                'vat'        => (int)($r['vat_rate'] ?? $p['vat_rate'] ?? 0),
            ];
        }
        if (!$positions) {
            jsonError('Ни одной позиции с ценой и карточкой МойСклад — счёт выставлять не из чего', 400);
        }

        $appUrl = rtrim($GLOBALS['cfg']['APP_URL'] ?? '', '/');
        $note = 'Счёт по КП ' . ((string)$p['number'] !== '' ? $p['number'] : '#' . $proposalId)
              . ($appUrl ? ", CRM: $appUrl/#mail/proposal/$proposalId" : '');

        // Счёт не висит в воздухе: сначала ЗАКАЗ покупателя, и счёт привязан к
        // нему (модуль 026). Заказ встаёт в статус «Резерв» и несёт имя
        // менеджера в доп. поле «СОТРУДНИК» — оба имени настраиваются.
        // Заказ не создался — счёт всё равно выставляем: клиенту нужен счёт,
        // а не наша внутренняя раскладка.
        $orgId = msOrgId();
        $order = null;
        $orderLocalId = null;
        $orderError = null;
        $orderMissing = [];
        if (!empty($perms['orders_write'])) {
            try {
                $order = MoySklad::createOrder([
                    'counterparty_id' => $buyerMsId,
                    'organization_id' => $orgId,
                    'positions'       => $positions,
                    'description'     => $note,
                    'state_name'      => trim((string)Settings::get('MS_ORDER_STATE', 'Резерв')),
                    'attributes'      => array_filter([
                        trim((string)Settings::get('MS_EMPLOYEE_ATTR', 'СОТРУДНИК'))
                            => trim((string)($manager['name'] ?? '')),
                    ], fn($v, $k) => $k !== '' && $v !== '', ARRAY_FILTER_USE_BOTH),
                ]);
                $orderMissing = $order['missing'] ?? [];
            } catch (Throwable $e) {
                $orderError = $e->getMessage();
                Logger::warning('moysklad', 'Заказ под счёт не создался: ' . $orderError,
                                ['proposal_id' => $proposalId]);
            }
        } else {
            $orderError = 'нет прав на создание заказов';
        }

        try {
            $inv = MoySklad::createInvoice([
                'counterparty_id' => $buyerMsId,
                'organization_id' => $orgId,
                'positions'       => $positions,
                'description'     => $note,
                'order_id'        => $order['id'] ?? null,
            ]);
        } catch (MoySkladPermissionException $e) {
            jsonError('МойСклад: нет прав на создание счетов', 403);
        } catch (Throwable $e) {
            jsonError('МойСклад не принял счёт: ' . $e->getMessage(), 502);
        }

        if ($order) {
            $orderLocalId = MsSync::upsertOrder($order['id'], [
                'proposal_id'     => $proposalId,
                'request_id'      => $p['request_id'] ? (int)$p['request_id'] : null,
                'counterparty_id' => $cpId,
                'manager_id'      => (int)$manager['id'],
                'org_id'          => $buyerOrgId ?: null,
            ]);
            // До какого числа держим резерв. Дальше — напоминание его снять
            // (cron/check_reserves.php), с кнопкой, снимающей проведение.
            $days = max(0, (int)Settings::get('MS_RESERVE_DAYS', 14));
            if ($orderLocalId && $days > 0) {
                Db::update('orders', ['reserve_until' => date('Y-m-d H:i:s', time() + $days * 86400)],
                           'id=?', [$orderLocalId]);
            }
            Db::update('proposals', ['moysklad_order_id' => $order['id'], 'updated_at' => date('Y-m-d H:i:s')],
                       'id=?', [$proposalId]);
        }

        $localId = MsSync::upsertInvoice($inv, $orderLocalId, $cpId);
        // Счёт помнит, по какому КП он выставлен: счетов у одного КП может быть
        // несколько, и на карточке они стоят под своим КП (модуль 027)
        Db::update('invoices', ['proposal_id' => $proposalId, 'org_id' => $buyerOrgId ?: null],
                   'id=?', [$localId]);
        $pdf = MsSync::ensureInvoicePdf($localId);

        Logger::info('moysklad', "Счёт {$inv['name']} выставлен по КП #$proposalId на «{$buyerName}»"
                     . ($order ? " вместе с заказом {$order['name']}" : ' без заказа'),
                     ['proposal_id' => $proposalId, 'invoice_id' => $localId,
                      'order_id' => $orderLocalId, 'manager_id' => (int)$manager['id'],
                      'order_error' => $orderError, 'missing' => $orderMissing]);

        jsonOk([
            'invoice_id' => $localId,
            'name'       => $inv['name'],
            'sum'        => $inv['sum'],
            'url'        => MoySklad::invoiceUrl($inv['id']),
            // Файл, который можно приложить к письму прямо из карточки
            'pdf_url'    => $pdf ? "/api/invoices.php?action=pdf&id=$localId" : null,
            'pdf_error'  => $pdf ? null : 'Печатная форма в МойСклад пока недоступна — счёт создан, файл появится позже',
            'skipped'    => $skipped,
            'proposal_id' => $proposalId,
            // Заказ, к которому привязан счёт, — и то, чего для него не нашлось
            'order'      => $order ? [
                'id'   => $orderLocalId,
                'name' => $order['name'],
                'url'  => MoySklad::orderUrl($order['id']),
            ] : null,
            'order_error'   => $orderError,
            'order_missing' => $orderMissing,
        ]);
    }

    /**
     * Снять резерв: заказ перестаёт быть проведённым в МойСклад (модуль 026).
     *
     * Это кнопка из напоминания «счёт не оплачен две недели». Товар перестаёт
     * числиться за этим клиентом, сам заказ остаётся — его видно и можно
     * провести обратно руками.
     */
    case 'release_reserve': {
        $manager = requireAuth();
        $id = (int)($_GET['order_id'] ?? 0);
        $order = Db::one("SELECT * FROM orders WHERE id=?", [$id]);
        if (!$order) jsonError('Заказ не найден', 404);

        MoySklad::init($GLOBALS['cfg']['MOYSKLAD_TOKEN'] ?? '');
        try {
            MoySklad::setOrderApplicable((string)$order['moysklad_id'], false);
        } catch (Throwable $e) {
            jsonError('МойСклад не снял проведение заказа: ' . $e->getMessage(), 502);
        }

        Db::update('orders', [
            'applicable'          => 0,
            'reserve_released_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$id]);

        Logger::info('moysklad', "Резерв по заказу {$order['name']} снят",
                     ['order_id' => $id, 'manager_id' => (int)$manager['id']]);

        jsonOk(['order_id' => $id, 'name' => $order['name'],
                'url' => MoySklad::orderUrl((string)$order['moysklad_id'])]);
    }

    // One-click send to the client (FR-032)
    case 'send': {
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $input = getInput();

        $inv = Db::one("SELECT i.*, c.name as counterparty_name FROM invoices i
                        LEFT JOIN counterparties c ON i.counterparty_id = c.id
                        WHERE i.id=?", [$id]);
        if (!$inv) jsonError('Счёт не найден', 404);

        $to = trim($input['to'] ?? '') ?: (Crm::primaryEmail((int)$inv['counterparty_id']) ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) jsonError('Укажите корректный email получателя');

        $path = MsSync::ensureInvoicePdf($id);
        if (!$path || !is_file($path)) jsonError('Печатная форма счёта недоступна в МойСклад', 502);

        $subject = trim($input['subject'] ?? '') ?:
            ((string)Db::val("SELECT value FROM settings WHERE key='invoice_email_subject'") . ' № ' . $inv['name']);

        $sum = number_format((float)$inv['sum'], 2, ',', ' ');
        $body = trim($input['body'] ?? '') ?: implode('', [
            '<p>Здравствуйте!</p>',
            "<p>Направляем счёт № {$inv['name']} на сумму $sum ₽. Счёт во вложении.</p>",
            '<p>По вопросам оплаты и отгрузки — ответьте на это письмо.</p>',
        ]);

        try {
            Mailer::send([
                'to'              => $to,
                'subject'         => $subject,
                'html'            => $body,
                'mailbox_id'      => $input['mailbox_id'] ?? null,
                'manager_id'      => $manager['id'] ?? null,
                'counterparty_id' => $inv['counterparty_id'] ? (int)$inv['counterparty_id'] : null,
                'attachments'     => [['path' => $path, 'name' => 'Счёт ' . $inv['name'] . '.pdf']],
            ]);
        } catch (Throwable $e) {
            jsonError('Не удалось отправить письмо: ' . $e->getMessage(), 502);
        }

        Db::update('invoices', [
            'sent_at' => date('Y-m-d H:i:s'),
            'sent_to' => $to,
        ], 'id=?', [$id]);

        // Outbound event clears the unanswered highlight (FR-038)
        Crm::logEvent((int)$inv['counterparty_id'], 'out', strip_tags($body), [
            'subject'    => $subject,
            'email_to'   => $to,
            'manager_id' => $manager['id'],
            'event_type' => 'invoice_sent',
            'meta'       => ['invoice_id' => $id, 'invoice_name' => $inv['name'], 'sum' => (float)$inv['sum']],
        ]);

        jsonOk(['sent_to' => $to]);
    }

    default:
        jsonError('Unknown action', 400);
}
