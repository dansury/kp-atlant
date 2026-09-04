<?php
/**
 * API: mail — the archive of every incoming and outgoing message, and sending
 * from the mailboxes the manager has access to (module 004).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/crm.php';

$manager = requireAuth();
$action  = $_GET['action'] ?? '';
$input   = in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true) ? getInput() : [];

try {
    switch ($action) {

        case 'list':
            $data = MailArchive::query([
                'mailbox_id'      => $_GET['mailbox_id'] ?? null,
                'direction'       => $_GET['direction'] ?? null,
                'counterparty_id' => $_GET['counterparty_id'] ?? null,
                'unread'          => !empty($_GET['unread']),
                'q'               => trim((string)($_GET['q'] ?? '')),
                'limit'           => $_GET['limit'] ?? 50,
                'offset'          => $_GET['offset'] ?? 0,
            ]);
            $data['mailboxes'] = array_map(
                fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'email' => $b['email'], 'last_error' => $b['last_error']],
                Mailboxes::forManager($manager)
            );
            jsonData($data);

        case 'get':
            $msg = MailArchive::get((int)($_GET['id'] ?? 0));
            if (!$msg) jsonError('Not found', 404);
            unset($msg['body_html']);   // the browser gets plain text only — no remote content, no scripts
            MailArchive::markRead((int)$msg['id']);
            jsonData($msg);

        case 'read':
            MailArchive::markRead((int)($input['id'] ?? 0), (bool)($input['read'] ?? true));
            jsonOk();

        case 'sync':
            $id = (int)($input['mailbox_id'] ?? $_GET['mailbox_id'] ?? 0);
            jsonOk(['report' => MailSync::run($id ?: null)]);

        case 'send':
            $to = trim((string)($input['to'] ?? ''));
            if ($to === '') jsonError('Укажите адрес получателя');
            $text = (string)($input['text'] ?? '');
            if (trim($text) === '') jsonError('Письмо пустое');

            // Replying keeps the thread and the company card of the original message
            $replyTo = null;
            $counterpartyId = isset($input['counterparty_id']) ? (int)$input['counterparty_id'] : null;
            $requestId = isset($input['request_id']) ? (int)$input['request_id'] : null;
            if (!empty($input['reply_to_id'])) {
                $src = MailArchive::get((int)$input['reply_to_id']);
                if ($src) {
                    $replyTo = $src['message_id'] ?: null;
                    $counterpartyId = $counterpartyId ?: ($src['counterparty_id'] ? (int)$src['counterparty_id'] : null);
                    $requestId = $requestId ?: ($src['request_id'] ? (int)$src['request_id'] : null);
                }
            }

            $subject = (string)($input['subject'] ?? '');
            $res = Mailer::send([
                'to'              => $to,
                'cc'              => array_filter(array_map('trim', explode(',', (string)($input['cc'] ?? '')))),
                'subject'         => $subject,
                'text'            => $text,
                'mailbox_id'      => $input['mailbox_id'] ?? null,
                'manager_id'      => (int)$manager['id'],
                'counterparty_id' => $counterpartyId,
                'request_id'      => $requestId,
                'in_reply_to'     => $replyTo,
            ]);

            // The company chat shows the same message, so nothing is invisible there
            if ($counterpartyId) {
                Crm::logEvent($counterpartyId, 'out', $text, [
                    'request_id' => $requestId,
                    'subject'    => $subject,
                    'email_to'   => $to,
                    'manager_id' => (int)$manager['id'],
                    'event_type' => 'mail_sent',
                ]);
            }
            jsonOk($res);

        case 'link':
            // Attach an archived message to a company card by hand
            $id = (int)($input['id'] ?? 0);
            $cp = (int)($input['counterparty_id'] ?? 0);
            if (!$id || !$cp) jsonError('Нужны письмо и контрагент');
            Db::update('mail_messages', ['counterparty_id' => $cp], 'id=?', [$id]);
            jsonOk();

        case 'attachment':
            $a = Db::one("SELECT * FROM attachments WHERE id=?", [(int)($_GET['id'] ?? 0)]);
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
} catch (Throwable $e) {
    Logger::exception('mail', $e, ['action' => $action]);
    jsonError($e->getMessage(), 500);
}
