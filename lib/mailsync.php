<?php
/**
 * Mail sync: pulls every mailbox — INBOX and «Отправленные» — into the archive,
 * then turns fresh inbound messages into КП requests exactly as before.
 */
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/notifier.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/crm.php';

final class MailSync {
    /** Sync all active mailboxes (or one). Returns a per-mailbox report. */
    public static function run(?int $mailboxId = null): array {
        $boxes = $mailboxId ? array_filter([Mailboxes::get($mailboxId)]) : Mailboxes::all(true);
        $report = [];
        foreach ($boxes as $box) {
            $report[] = self::syncMailbox($box);
        }
        return $report;
    }

    public static function syncMailbox(array $box): array {
        $res = ['mailbox_id' => (int)$box['id'], 'name' => $box['name'], 'in' => 0, 'out' => 0, 'requests' => 0, 'error' => null];
        if (!EmailReader::available()) {
            $res['error'] = 'Расширение PHP imap не установлено на сервере';
            Db::update('mailboxes', ['last_error' => $res['error'], 'last_check_at' => date('Y-m-d H:i:s')], 'id=?', [$box['id']]);
            Logger::error('mail', $res['error'], ['mailbox_id' => $box['id']]);
            return $res;
        }

        $limit = max(1, (int)Settings::get('MAIL_FETCH_LIMIT', 50));
        try {
            $res['in'] = self::syncFolder($box, (string)($box['imap_folder_in'] ?: 'INBOX'), 'in', 'last_uid_in', $limit);
            if (!empty($box['sync_sent']) && Settings::get('MAIL_SYNC_SENT', 1)) {
                $sent = (string)($box['imap_folder_sent'] ?: '');
                if ($sent !== '') {
                    $res['out'] = self::syncFolder($box, $sent, 'out', 'last_uid_sent', $limit);
                }
            }
            Db::update('mailboxes', ['last_error' => null, 'last_check_at' => date('Y-m-d H:i:s')], 'id=?', [$box['id']]);
        } catch (Throwable $e) {
            $res['error'] = $e->getMessage();
            Db::update('mailboxes', ['last_error' => $e->getMessage(), 'last_check_at' => date('Y-m-d H:i:s')], 'id=?', [$box['id']]);
            Logger::exception('mail', $e, ['mailbox_id' => $box['id'], 'mailbox' => $box['name']]);
            return $res;
        }

        if (!empty($box['create_requests'])) {
            $res['requests'] = self::processInbound((int)$box['id']);
        }
        return $res;
    }

    /** Archive everything newer than the stored UID watermark. */
    private static function syncFolder(array $box, string $folder, string $direction, string $uidColumn, int $limit): int {
        $reader = new EmailReader(Mailboxes::cfg($box));
        $reader->connect($folder);
        $since = (int)($box[$uidColumn] ?? 0);
        $messages = $reader->fetchSince($since, $limit);

        $stored = 0;
        $maxUid = $since;
        foreach ($messages as $msg) {
            $maxUid = max($maxUid, (int)$msg['uid']);
            $id = MailArchive::storeIncoming($box, $msg, $direction);
            if (!$id) continue;
            $stored++;
            self::storeAttachments($id, $msg['attachments'] ?? []);
        }
        $reader->close();

        if ($maxUid > $since) Db::update('mailboxes', [$uidColumn => $maxUid], 'id=?', [$box['id']]);
        if ($stored) Logger::info('mail', "Ящик «{$box['name']}»: $folder — новых писем $stored", ['mailbox_id' => $box['id']]);
        return $stored;
    }

    private static function storeAttachments(int $mailMessageId, array $files): array {
        $stored = [];
        foreach ($files as $file) {
            try {
                $stored[] = Attachments::store($file, ['mail_message_id' => $mailMessageId]);
            } catch (Throwable $e) {
                Logger::exception('attachments', $e, ['mail_message_id' => $mailMessageId]);
            }
        }
        return $stored;
    }

    /**
     * Turn archived inbound mail into requests: parse, resolve the company card,
     * create the request and notify — the pipeline module 001/002 already had.
     */
    public static function processInbound(int $mailboxId): int {
        $rows = Db::all(
            "SELECT * FROM mail_messages WHERE mailbox_id=? AND direction='in' AND processed_at IS NULL ORDER BY id LIMIT 50",
            [$mailboxId]
        );
        $count = 0;
        foreach ($rows as $row) {
            try {
                self::toRequest($row);
                $count++;
            } catch (Throwable $e) {
                Db::update('mail_messages', [
                    'processed_at' => date('Y-m-d H:i:s'),
                    'error'        => mb_substr($e->getMessage(), 0, 500),
                ], 'id=?', [$row['id']]);
                Logger::exception('mail', $e, ['mail_message_id' => $row['id'], 'subject' => $row['subject']]);
            }
        }
        return $count;
    }

    private static function toRequest(array $row): void {
        $attachments = Db::all("SELECT * FROM attachments WHERE mail_message_id=?", [$row['id']]);
        $attachmentText = '';
        foreach ($attachments as $a) {
            if (!empty($a['extracted_text'])) {
                $attachmentText .= "--- Вложение: {$a['filename']} ---\n" . $a['extracted_text'] . "\n\n";
            }
        }

        $parsed = RequestParser::parse((string)$row['body_text'], $attachmentText);
        $type = ($parsed['request_type'] ?? 'kp_request') === 'order' ? 'order' : 'kp_request';

        $counterpartyId = Crm::resolveCounterparty([
            'inn'            => $parsed['inn'] ?? '',
            'name'           => $parsed['org_name'] ?? '',
            'email'          => $row['from_email'] ?? '',
            'contact_person' => $parsed['contact_person'] ?? ($row['from_name'] ?: null),
            'phone'          => $parsed['contact_phone'] ?? null,
        ]);
        if ($counterpartyId) {
            Crm::upsertContact(
                $counterpartyId,
                $parsed['contact_person'] ?? ($row['from_name'] ?: null),
                $row['from_email'] ?: null,
                $parsed['contact_phone'] ?? null
            );
        }

        $requestId = Db::insert('requests', [
            'source'           => 'email',
            'raw_text'         => $row['body_text'],
            'parsed_json'      => json_encode($parsed, JSON_UNESCAPED_UNICODE),
            'counterparty_id'  => $counterpartyId,
            'status'           => 'new',
            'type'             => $type,
            'type_source'      => 'llm',
            'email_from'       => $row['from_email'],
            'email_subject'    => $row['subject'],
            'email_message_id' => $row['message_id'],
        ]);

        $corrId = Crm::logEvent($counterpartyId, 'in', (string)$row['body_text'], [
            'request_id' => $requestId,
            'subject'    => $row['subject'],
            'email_from' => $row['from_email'],
        ]);

        foreach ($attachments as $a) {
            Db::update('attachments', [
                'correspondence_id' => $corrId,
                'request_id'        => $requestId,
                'counterparty_id'   => $counterpartyId,
            ], 'id=?', [$a['id']]);
        }
        if ($attachments) Db::update('correspondence', ['has_attachment' => 1], 'id=?', [$corrId]);

        Db::update('mail_messages', [
            'processed_at'      => date('Y-m-d H:i:s'),
            'request_id'        => $requestId,
            'counterparty_id'   => $counterpartyId,
            'correspondence_id' => $corrId,
            'error'             => null,
        ], 'id=?', [$row['id']]);

        $orgName = $parsed['org_name'] ?? null;
        $label = $type === 'order' ? 'Новый заказ' : 'Новый запрос на КП';
        $title = $label . ($orgName ? " от $orgName" : ' от ' . $row['from_email']);
        Notifier::notify('new_request', $title, $row['subject'], 'request', $requestId);
    }

    /** Connection check for the admin panel: opens the folders and counts messages. */
    public static function testImap(array $box): array {
        $reader = new EmailReader(Mailboxes::cfg($box));
        $reader->connect((string)($box['imap_folder_in'] ?: 'INBOX'));
        $out = [
            'ok'      => true,
            'folder'  => $box['imap_folder_in'] ?: 'INBOX',
            'count'   => $reader->messageCount(),
            'folders' => $reader->folders(),
        ];
        $reader->close();
        return $out;
    }
}
