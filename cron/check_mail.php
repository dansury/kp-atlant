<?php
/**
 * Cron: IMAP poll — check for new emails every 2 min.
 * Stores attachments, classifies request type (KP request vs order),
 * glues the message onto the right company card. Usage: php cron/check_mail.php
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/email.php';
require_once ROOT . '/lib/parser.php';
require_once ROOT . '/lib/notifier.php';
require_once ROOT . '/lib/attachments.php';
require_once ROOT . '/lib/crm.php';

try {
    $reader = new EmailReader($cfg);
    $reader->connect();
    $messages = $reader->fetchUnseen();
    $reader->close();
} catch (\Exception $e) {
    error_log('IMAP check failed: ' . $e->getMessage());
    exit(1);
}

if (empty($messages)) exit(0);

foreach ($messages as $msg) {
    try {
        // 1. Store attachments and pull their text (FR-021, FR-022)
        $stored = [];
        $attachmentText = '';
        foreach ($msg['attachments'] ?? [] as $file) {
            $a = Attachments::store($file);
            $stored[] = $a;
            if (!empty($a['text'])) {
                $attachmentText .= "--- Вложение: {$a['filename']} ---\n" . $a['text'] . "\n\n";
            }
        }

        // 2. Parse body + attachments, classify type (FR-023)
        $parsed = RequestParser::parse($msg['body'], $attachmentText);
        $type = ($parsed['request_type'] ?? 'kp_request') === 'order' ? 'order' : 'kp_request';

        // 3. Company card: INN → email domain → name (FR-034)
        $counterpartyId = Crm::resolveCounterparty([
            'inn'            => $parsed['inn'] ?? '',
            'name'           => $parsed['org_name'] ?? '',
            'email'          => $msg['from'] ?? '',
            'contact_person' => $parsed['contact_person'] ?? ($msg['from_name'] ?: null),
            'phone'          => $parsed['contact_phone'] ?? null,
        ]);

        if ($counterpartyId) {
            Crm::upsertContact(
                $counterpartyId,
                $parsed['contact_person'] ?? ($msg['from_name'] ?: null),
                $msg['from'] ?? null,
                $parsed['contact_phone'] ?? null
            );
        }

        // 4. Request
        $requestId = Db::insert('requests', [
            'source'           => 'email',
            'raw_text'         => $msg['body'],
            'parsed_json'      => json_encode($parsed, JSON_UNESCAPED_UNICODE),
            'counterparty_id'  => $counterpartyId,
            'status'           => 'new',
            'type'             => $type,
            'type_source'      => 'llm',
            'email_from'       => $msg['from'],
            'email_subject'    => $msg['subject'],
            'email_message_id' => $msg['message_id'],
        ]);

        // 5. Feed entry + attachment links
        $corrId = Crm::logEvent($counterpartyId, 'in', $msg['body'], [
            'request_id' => $requestId,
            'subject'    => $msg['subject'],
            'email_from' => $msg['from'],
        ]);

        foreach ($stored as $a) {
            Db::update('attachments', [
                'correspondence_id' => $corrId,
                'request_id'        => $requestId,
                'counterparty_id'   => $counterpartyId,
            ], 'id=?', [$a['id']]);
        }
        if ($stored) {
            Db::update('correspondence', ['has_attachment' => 1], 'id=?', [$corrId]);
        }

        // 6. Notify
        $orgName = $parsed['org_name'] ?? null;
        $label = $type === 'order' ? 'Новый заказ' : 'Новый запрос на КП';
        $title = $label . ($orgName ? " от $orgName" : " от {$msg['from']}");
        Notifier::notify('new_request', $title, $msg['subject'], 'request', $requestId);

        echo "Processed email #{$msg['uid']} → request #$requestId ($type, вложений: " . count($stored) . ")\n";
    } catch (\Exception $e) {
        error_log("Failed to process email #{$msg['uid']}: " . $e->getMessage());
    }
}
