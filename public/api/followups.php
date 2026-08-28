<?php
/**
 * API: Follow-ups — list, get, update, send, dismiss (US6).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/email.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        requireAuth();
        $items = Db::all(
            "SELECT f.id, f.status, f.created_at, f.proposal_id,
                    c.name as counterparty_name, p.number as proposal_number, p.sent_at
             FROM followups f
             JOIN counterparties c ON f.counterparty_id = c.id
             JOIN proposals p ON f.proposal_id = p.id
             WHERE f.status IN ('suggested','draft_ready')
             ORDER BY f.created_at DESC"
        );
        jsonData(['items' => $items]);

    case 'get':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $f = Db::one("SELECT f.*, c.name as counterparty_name, c.contact_email
                       FROM followups f
                       JOIN counterparties c ON f.counterparty_id = c.id
                       WHERE f.id=?", [$id]);
        if (!$f) jsonError('Not found', 404);
        jsonData($f);

    case 'update':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $input = getInput();
        if (isset($input['final_text'])) {
            Db::update('followups', [
                'final_text' => $input['final_text'],
                'status' => 'draft_ready',
            ], 'id=?', [$id]);
        }
        jsonOk();

    case 'send':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $f = Db::one("SELECT f.*, c.name as counterparty_name, c.contact_email
                       FROM followups f JOIN counterparties c ON f.counterparty_id = c.id
                       WHERE f.id=?", [$id]);
        if (!$f) jsonError('Not found', 404);

        $input = getInput();
        $to = $input['to'] ?? $f['contact_email'] ?? '';
        if (!$to) jsonError('Recipient email required');

        $text = $f['final_text'] ?: $f['draft_text'];
        $subject = $input['subject'] ?? "Atlant Armour — по вашему запросу";

        $sender = new EmailSender($cfg);
        $sender->send($to, $subject, '<p>' . nl2br(htmlspecialchars($text)) . '</p>');

        $now = date('Y-m-d H:i:s');
        Db::update('followups', ['status' => 'sent', 'sent_at' => $now], 'id=?', [$id]);

        // Feed entry — clears the unanswered highlight (FR-038)
        $manager = currentManager();
        Crm::logEvent($f['counterparty_id'] ? (int)$f['counterparty_id'] : null, 'out', $text, [
            'subject'    => $subject,
            'email_to'   => $to,
            'manager_id' => $manager['id'] ?? null,
            'event_type' => 'followup_sent',
        ]);
        jsonOk(['sent_at' => $now]);

    case 'dismiss':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        Db::update('followups', ['status' => 'dismissed'], 'id=?', [$id]);
        jsonOk();

    default:
        jsonError('Unknown action', 400);
}
