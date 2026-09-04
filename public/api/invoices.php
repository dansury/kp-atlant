<?php
/**
 * API: Invoices — projection of MoySklad «Счёт покупателю» (US9).
 * list / sync / pdf / send.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/sync.php';
require_once ROOT . '/lib/mail.php';

$action = $_GET['action'] ?? '';

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
