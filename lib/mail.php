<?php
/**
 * Mail overlay: several mailboxes, a full archive of incoming AND outgoing
 * messages, and sending through the mailbox the manager works from.
 * The service behaves like a thin mail client on top of the company mailboxes.
 */
require_once __DIR__ . '/email.php';

final class Mailboxes {
    /** Columns the admin panel may write. Passwords are handled separately. */
    public const FIELDS = [
        'name','email','is_active','is_default','manager_id','create_requests','sync_sent',
        'imap_host','imap_port','imap_encryption','imap_user','imap_folder_in','imap_folder_sent',
        'smtp_host','smtp_port','smtp_encryption','smtp_user','from_name','from_email',
    ];
    private const SECRETS = ['imap_password', 'smtp_password'];

    public static function all(bool $activeOnly = false): array {
        $sql = "SELECT b.*, m.name AS manager_name FROM mailboxes b
                LEFT JOIN managers m ON m.id = b.manager_id";
        if ($activeOnly) $sql .= " WHERE b.is_active = 1";
        $sql .= " ORDER BY b.is_default DESC, b.id";
        return Db::all($sql);
    }

    public static function get(int $id): ?array {
        return Db::one("SELECT * FROM mailboxes WHERE id=?", [$id]);
    }

    /** Mailbox to send from when the caller did not pick one. */
    public static function default(?int $managerId = null): ?array {
        if ($managerId) {
            $own = Db::one("SELECT * FROM mailboxes WHERE manager_id=? AND is_active=1 ORDER BY is_default DESC, id LIMIT 1", [$managerId]);
            if ($own) return $own;
        }
        return Db::one("SELECT * FROM mailboxes WHERE is_active=1 ORDER BY is_default DESC, id LIMIT 1");
    }

    /** Mailboxes a manager may use: their own plus the shared ones. */
    public static function forManager(array $manager): array {
        if (!empty($manager['is_admin'])) return self::all(true);
        return Db::all("SELECT * FROM mailboxes WHERE is_active=1 AND (manager_id IS NULL OR manager_id=?) ORDER BY is_default DESC, id", [$manager['id']]);
    }

    /** IMAP/SMTP config for EmailReader / EmailSender, secrets decrypted. */
    public static function cfg(array $box): array {
        return [
            'IMAP_HOST'       => $box['imap_host'] ?? '',
            'IMAP_PORT'       => (int)($box['imap_port'] ?? 993),
            'IMAP_USER'       => $box['imap_user'] ?? '',
            'IMAP_PASSWORD'   => Crypt::decrypt((string)($box['imap_password'] ?? '')),
            'IMAP_ENCRYPTION' => $box['imap_encryption'] ?? 'ssl',
            'SMTP_HOST'       => $box['smtp_host'] ?? '',
            'SMTP_PORT'       => (int)($box['smtp_port'] ?? 465),
            'SMTP_USER'       => $box['smtp_user'] ?? '',
            'SMTP_PASSWORD'   => Crypt::decrypt((string)($box['smtp_password'] ?? '')),
            'SMTP_ENCRYPTION' => $box['smtp_encryption'] ?? 'ssl',
            'SMTP_FROM_NAME'  => $box['from_name'] ?? '',
            'SMTP_FROM_EMAIL' => ($box['from_email'] ?? '') ?: ($box['email'] ?? ''),
        ];
    }

    /** New mailbox pre-filled from the settings layer, so one click is enough. */
    public static function blank(): array {
        return [
            'name'             => 'Новый ящик',
            'email'            => (string)Settings::get('SMTP_FROM_EMAIL', ''),
            'is_active'        => 1,
            'is_default'       => Db::val("SELECT COUNT(*) FROM mailboxes") ? 0 : 1,
            'create_requests'  => 1,
            'sync_sent'        => (int)Settings::get('MAIL_SYNC_SENT', 1),
            'imap_host'        => (string)Settings::get('IMAP_HOST', ''),
            'imap_port'        => (int)Settings::get('IMAP_PORT', 993),
            'imap_encryption'  => (string)Settings::get('IMAP_ENCRYPTION', 'ssl'),
            'imap_user'        => (string)Settings::get('IMAP_USER', ''),
            'imap_folder_in'   => 'INBOX',
            'imap_folder_sent' => 'INBOX.Sent',
            'smtp_host'        => (string)Settings::get('SMTP_HOST', ''),
            'smtp_port'        => (int)Settings::get('SMTP_PORT', 465),
            'smtp_encryption'  => (string)Settings::get('SMTP_ENCRYPTION', 'ssl'),
            'smtp_user'        => (string)Settings::get('SMTP_USER', ''),
            'from_name'        => (string)Settings::get('SMTP_FROM_NAME', 'Atlant Armour'),
            'from_email'       => (string)Settings::get('SMTP_FROM_EMAIL', ''),
        ];
    }

    public static function save(array $input, ?int $id, ?int $managerId): int {
        $data = [];
        foreach (self::FIELDS as $f) {
            if (!array_key_exists($f, $input)) continue;
            $data[$f] = in_array($f, ['is_active','is_default','create_requests','sync_sent'], true)
                ? (int)!empty($input[$f])
                : ($f === 'manager_id' ? (($input[$f] ?? '') !== '' ? (int)$input[$f] : null) : (string)$input[$f]);
        }
        foreach (self::SECRETS as $f) {
            // An empty field means "leave the stored password alone"
            if (($input[$f] ?? '') !== '') $data[$f] = Crypt::encrypt((string)$input[$f]);
        }
        if (($data['name'] ?? '') === '' && !$id) $data['name'] = $data['email'] ?? 'Ящик';

        if ($id) {
            Db::update('mailboxes', $data, 'id=?', [$id]);
        } else {
            $id = Db::insert('mailboxes', $data + self::blank());
        }
        if (!empty($data['is_default'])) Db::q("UPDATE mailboxes SET is_default=0 WHERE id<>?", [$id]);
        if (!Db::val("SELECT COUNT(*) FROM mailboxes WHERE is_default=1")) {
            Db::q("UPDATE mailboxes SET is_default=1 WHERE id=?", [$id]);
        }
        Logger::info('mail', 'Почтовый ящик сохранён', ['mailbox_id' => $id, 'manager_id' => $managerId]);
        return (int)$id;
    }

    public static function delete(int $id): void {
        Db::q("DELETE FROM mailboxes WHERE id=?", [$id]);
        Logger::info('mail', 'Почтовый ящик удалён', ['mailbox_id' => $id]);
    }

    /** Panel view — never leaks a password, only whether one is stored. */
    public static function describe(): array {
        $out = [];
        foreach (self::all() as $box) {
            unset($box['imap_password'], $box['smtp_password']);
            $row = Db::one("SELECT * FROM mailboxes WHERE id=?", [$box['id']]);
            $box['imap_password_set'] = trim((string)($row['imap_password'] ?? '')) !== '';
            $box['smtp_password_set'] = trim((string)($row['smtp_password'] ?? '')) !== '';
            $box['messages'] = (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE mailbox_id=?", [$box['id']]);
            $out[] = $box;
        }
        return $out;
    }
}

/**
 * The archive itself: every message the service sees or sends, in one table.
 */
final class MailArchive {
    /** Store an incoming message. Returns the row id, or 0 when already archived. */
    public static function storeIncoming(array $box, array $msg, string $direction = 'in'): int {
        if (self::exists((int)$box['id'], $msg['folder'] ?? 'INBOX', (int)$msg['uid'], (string)($msg['message_id'] ?? ''))) return 0;

        $limit = max(16, (int)Settings::get('MAIL_BODY_MAX_KB', 512)) * 1024;
        return Db::insert('mail_messages', [
            'mailbox_id'   => (int)$box['id'],
            'direction'    => $direction,
            'folder'       => $msg['folder'] ?? 'INBOX',
            'uid'          => (int)($msg['uid'] ?? 0),
            'message_id'   => $msg['message_id'] ?? null,
            'in_reply_to'  => $msg['in_reply_to'] ?? null,
            'subject'      => $msg['subject'] ?? '',
            'from_email'   => $msg['from'] ?? '',
            'from_name'    => $msg['from_name'] ?? '',
            'to_emails'    => $msg['to'] ?? '',
            'cc_emails'    => $msg['cc'] ?? '',
            'body_text'    => mb_strcut((string)($msg['body'] ?? ''), 0, $limit),
            'body_html'    => mb_strcut((string)($msg['body_html'] ?? ''), 0, $limit),
            'size'         => (int)($msg['size'] ?? 0),
            'has_attachment' => empty($msg['attachments']) ? 0 : 1,
            'is_read'      => !empty($msg['seen']) ? 1 : 0,
            'date_at'      => $msg['date'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /** Store a message the service itself sent. */
    public static function storeOutgoing(array $o): int {
        return Db::insert('mail_messages', [
            'mailbox_id'      => $o['mailbox_id'] ?? null,
            'direction'       => 'out',
            'folder'          => $o['folder'] ?? 'SENT',
            'uid'             => 0,
            'message_id'      => $o['message_id'] ?? null,
            'in_reply_to'     => $o['in_reply_to'] ?? null,
            'subject'         => $o['subject'] ?? '',
            'from_email'      => $o['from_email'] ?? '',
            'from_name'       => $o['from_name'] ?? '',
            'to_emails'       => $o['to'] ?? '',
            'cc_emails'       => $o['cc'] ?? '',
            'body_text'       => $o['text'] ?? '',
            'body_html'       => $o['html'] ?? '',
            'has_attachment'  => empty($o['attachments']) ? 0 : 1,
            'attachments_json'=> !empty($o['attachments']) ? json_encode(array_map('basename', $o['attachments']), JSON_UNESCAPED_UNICODE) : null,
            'counterparty_id' => $o['counterparty_id'] ?? null,
            'request_id'      => $o['request_id'] ?? null,
            'manager_id'      => $o['manager_id'] ?? null,
            'is_read'         => 1,
            'processed_at'    => date('Y-m-d H:i:s'),
            'date_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    private static function exists(int $mailboxId, string $folder, int $uid, string $messageId): bool {
        if ($uid > 0 && Db::val("SELECT 1 FROM mail_messages WHERE mailbox_id=? AND folder=? AND uid=?", [$mailboxId, $folder, $uid])) return true;
        // The same message can arrive twice (INBOX + Sent sync, or a re-created mailbox)
        if ($messageId !== '' && Db::val("SELECT 1 FROM mail_messages WHERE mailbox_id=? AND message_id=? AND folder=?", [$mailboxId, $messageId, $folder])) return true;
        return false;
    }

    /** Message list for the mail page. */
    public static function query(array $f = []): array {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['mailbox_id'])) { $where[] = 'm.mailbox_id = ?'; $params[] = (int)$f['mailbox_id']; }
        if (!empty($f['direction']) && in_array($f['direction'], ['in', 'out'], true)) {
            $where[] = 'm.direction = ?'; $params[] = $f['direction'];
        }
        if (!empty($f['counterparty_id'])) { $where[] = 'm.counterparty_id = ?'; $params[] = (int)$f['counterparty_id']; }
        if (!empty($f['unread'])) $where[] = 'm.is_read = 0';
        if (!empty($f['q'])) {
            $where[] = '(m.subject LIKE ? OR m.from_email LIKE ? OR m.to_emails LIKE ? OR m.body_text LIKE ?)';
            $like = '%' . $f['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $limit  = min(200, max(1, (int)($f['limit'] ?? 50)));
        $offset = max(0, (int)($f['offset'] ?? 0));
        $sql = "SELECT m.id, m.mailbox_id, m.direction, m.folder, m.subject, m.from_email, m.from_name,
                       m.to_emails, m.has_attachment, m.is_read, m.date_at, m.request_id, m.counterparty_id,
                       m.error, substr(m.body_text, 1, 200) AS preview,
                       b.name AS mailbox_name, c.name AS counterparty_name
                FROM mail_messages m
                LEFT JOIN mailboxes b ON b.id = m.mailbox_id
                LEFT JOIN counterparties c ON c.id = m.counterparty_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY m.date_at DESC, m.id DESC LIMIT ? OFFSET ?";
        return [
            'items'  => Db::all($sql, [...$params, $limit, $offset]),
            'total'  => (int)Db::val("SELECT COUNT(*) FROM mail_messages m WHERE " . implode(' AND ', $where), $params),
            'unread' => (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE direction='in' AND is_read=0"),
        ];
    }

    public static function get(int $id): ?array {
        $row = Db::one("SELECT m.*, b.name AS mailbox_name, c.name AS counterparty_name
                        FROM mail_messages m
                        LEFT JOIN mailboxes b ON b.id = m.mailbox_id
                        LEFT JOIN counterparties c ON c.id = m.counterparty_id
                        WHERE m.id=?", [$id]);
        if (!$row) return null;
        $row['attachments'] = Db::all("SELECT id, filename, size, mime, extract_status FROM attachments WHERE mail_message_id=?", [$id]);
        return $row;
    }

    public static function markRead(int $id, bool $read = true): void {
        Db::update('mail_messages', ['is_read' => $read ? 1 : 0], 'id=?', [$id]);
    }
}

/**
 * Sending. Every outgoing message is archived and, when the mailbox allows it,
 * copied into the IMAP «Sent» folder so both sides of the correspondence match.
 */
final class Mailer {
    /**
     * $o: to, subject, html|text, mailbox_id, cc[], attachments[], manager_id,
     *     counterparty_id, request_id, in_reply_to, log_to_chat (bool)
     */
    public static function send(array $o): array {
        $box = !empty($o['mailbox_id']) ? Mailboxes::get((int)$o['mailbox_id']) : Mailboxes::default($o['manager_id'] ?? null);
        $cfg = $box ? Mailboxes::cfg($box) : self::legacyCfg();

        $to      = trim((string)($o['to'] ?? ''));
        $subject = (string)($o['subject'] ?? '');
        $html    = $o['html'] ?? ('<p>' . nl2br(htmlspecialchars((string)($o['text'] ?? ''))) . '</p>');
        $text    = $o['text'] ?? trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($to === '') throw new RuntimeException('Не указан адрес получателя');

        $sender = new EmailSender($cfg);
        try {
            $sender->send($to, $subject, $html, null, null, [
                'cc'                  => $o['cc'] ?? [],
                'text'                => $text,
                'attachments'         => $o['attachments'] ?? [],
                'reply_to_message_id' => $o['in_reply_to'] ?? null,
            ]);
        } catch (Throwable $e) {
            Logger::exception('mail', $e, ['to' => $to, 'subject' => $subject, 'mailbox_id' => $box['id'] ?? null]);
            if ($box) Db::update('mailboxes', ['last_error' => 'SMTP: ' . $e->getMessage()], 'id=?', [$box['id']]);
            throw $e;
        }

        $archiveId = MailArchive::storeOutgoing([
            'mailbox_id'      => $box['id'] ?? null,
            'subject'         => $subject,
            'from_email'      => $cfg['SMTP_FROM_EMAIL'] ?: $cfg['SMTP_USER'],
            'from_name'       => $cfg['SMTP_FROM_NAME'],
            'to'              => $to,
            'cc'              => implode(', ', (array)($o['cc'] ?? [])),
            'text'            => $text,
            'html'            => $html,
            'attachments'     => $o['attachments'] ?? [],
            'counterparty_id' => $o['counterparty_id'] ?? null,
            'request_id'      => $o['request_id'] ?? null,
            'manager_id'      => $o['manager_id'] ?? null,
            'in_reply_to'     => $o['in_reply_to'] ?? null,
        ]);

        // A copy in the IMAP Sent folder — so the manager sees it in Outlook too
        if ($box && Settings::get('MAIL_APPEND_SENT', 1) && $sender->lastRawMessage && EmailReader::available()) {
            try {
                $reader = new EmailReader($cfg);
                $reader->connect($box['imap_folder_in'] ?: 'INBOX');
                $reader->appendSent($sender->lastRawMessage, $box['imap_folder_sent'] ?: 'INBOX.Sent');
                $reader->close();
            } catch (Throwable $e) {
                Logger::warning('mail', 'Не удалось положить копию в «Отправленные»: ' . $e->getMessage(), ['mailbox_id' => $box['id']]);
            }
        }

        Logger::info('mail', "Письмо отправлено: $to", ['subject' => $subject, 'mailbox_id' => $box['id'] ?? null, 'archive_id' => $archiveId]);
        return ['archive_id' => $archiveId, 'mailbox_id' => $box['id'] ?? null];
    }

    /** No mailbox configured yet — fall back to the SMTP keys of the settings layer. */
    public static function legacyCfg(): array {
        return [
            'SMTP_HOST'       => (string)Settings::get('SMTP_HOST', ''),
            'SMTP_PORT'       => (int)Settings::get('SMTP_PORT', 465),
            'SMTP_USER'       => (string)Settings::get('SMTP_USER', ''),
            'SMTP_PASSWORD'   => (string)Settings::get('SMTP_PASSWORD', ''),
            'SMTP_ENCRYPTION' => (string)Settings::get('SMTP_ENCRYPTION', 'ssl'),
            'SMTP_FROM_NAME'  => (string)Settings::get('SMTP_FROM_NAME', 'Atlant Armour'),
            'SMTP_FROM_EMAIL' => (string)Settings::get('SMTP_FROM_EMAIL', ''),
        ];
    }
}
