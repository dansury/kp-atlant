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
        if (!$cp || empty($cp['moysklad_id'])) {
            jsonError('Компания не связана с МойСклад — свяжите её в карточке, иначе счёт выставлять не на кого', 400);
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

        try {
            $inv = MoySklad::createInvoice([
                'counterparty_id' => $cp['moysklad_id'],
                'organization_id' => msOrgId(),
                'positions'       => $positions,
                'description'     => $note,
            ]);
        } catch (MoySkladPermissionException $e) {
            jsonError('МойСклад: нет прав на создание счетов', 403);
        } catch (Throwable $e) {
            jsonError('МойСклад не принял счёт: ' . $e->getMessage(), 502);
        }

        $localId = MsSync::upsertInvoice($inv, null, $cpId);
        $pdf = MsSync::ensureInvoicePdf($localId);

        Logger::info('moysklad', "Счёт {$inv['name']} выставлен по КП #$proposalId",
                     ['proposal_id' => $proposalId, 'invoice_id' => $localId, 'manager_id' => (int)$manager['id']]);

        jsonOk([
            'invoice_id' => $localId,
            'name'       => $inv['name'],
            'sum'        => $inv['sum'],
            'url'        => MoySklad::invoiceUrl($inv['id']),
            // Файл, который можно приложить к письму прямо из карточки
            'pdf_url'    => $pdf ? "/api/invoices.php?action=pdf&id=$localId" : null,
            'pdf_error'  => $pdf ? null : 'Печатная форма в МойСклад пока недоступна — счёт создан, файл появится позже',
            'skipped'    => $skipped,
        ]);
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
