<?php
/**
 * Cron: IMAP poll — check for new emails every 2 min.
 * Usage: php cron/check_mail.php
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/email.php';
require_once ROOT . '/lib/parser.php';
require_once ROOT . '/lib/notifier.php';

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
        // Parse request text
        $parsed = RequestParser::parse($msg['body']);

        // Find or create counterparty
        $counterpartyId = null;
        $orgName = $parsed['org_name'] ?? null;
        if ($orgName) {
            $existing = Db::one("SELECT id FROM counterparties WHERE name LIKE ?", ["%$orgName%"]);
            if ($existing) {
                $counterpartyId = $existing['id'];
            } else {
                $counterpartyId = Db::insert('counterparties', [
                    'name' => $orgName,
                    'contact_person' => $parsed['contact_person'] ?? $msg['from_name'] ?: null,
                    'contact_email' => $parsed['contact_email'] ?? $msg['from'] ?: null,
                ]);
            }
        }

        // Create request
        $requestId = Db::insert('requests', [
            'source' => 'email',
            'raw_text' => $msg['body'],
            'parsed_json' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
            'counterparty_id' => $counterpartyId,
            'status' => 'new',
            'email_from' => $msg['from'],
            'email_subject' => $msg['subject'],
            'email_message_id' => $msg['message_id'],
        ]);

        // Save incoming correspondence
        Db::insert('correspondence', [
            'request_id' => $requestId,
            'counterparty_id' => $counterpartyId,
            'direction' => 'in',
            'subject' => $msg['subject'],
            'body' => $msg['body'],
            'email_from' => $msg['from'],
        ]);

        // Notify all managers
        $title = 'Новый запрос на КП' . ($orgName ? " от $orgName" : " от {$msg['from']}");
        Notifier::notify('new_request', $title, $msg['subject'], 'request', $requestId);

        echo "Processed email #{$msg['uid']} → request #$requestId\n";
    } catch (\Exception $e) {
        error_log("Failed to process email #{$msg['uid']}: " . $e->getMessage());
    }
}
