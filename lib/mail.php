<?php
/**
 * Mail overlay: several mailboxes, a full archive of incoming AND outgoing
 * messages, and sending through the mailbox the manager works from.
 * The service behaves like a thin mail client on top of the company mailboxes.
 */
require_once __DIR__ . '/email.php';

/**
 * Ready-made settings of the mail services people actually use here.
 * Yandex, Mail.ru and Gmail all refuse the account password over IMAP/SMTP:
 * the mailbox needs a separate application password, and that is the single
 * thing an admin gets wrong most often — hence the hint travels with the preset.
 */
final class MailProviders {
    public const PRESETS = [
        'yandex' => [
            'title'            => 'Яндекс.Почта',
            'domains'          => ['yandex.ru', 'yandex.com', 'ya.ru', 'yandex.by', 'yandex.kz', 'narod.ru'],
            'imap_host'        => 'imap.yandex.ru',
            'imap_port'        => 993,
            'imap_encryption'  => 'ssl',
            'imap_folder_in'   => 'INBOX',
            'imap_folder_sent' => 'Отправленные',
            'smtp_host'        => 'smtp.yandex.ru',
            'smtp_port'        => 465,
            'smtp_encryption'  => 'ssl',
            'login_is_email'   => true,
            'app_password'     => true,
            'help_url'         => 'https://id.yandex.ru/security/app-passwords',
            'hint'             => 'Яндекс не пускает по обычному паролю. В «Яндекс ID → Безопасность → Пароли приложений» '
                . 'создайте пароль для «Почты» и вставьте его в оба поля пароля. Для ящика на своём домене '
                . '(Яндекс 360) логин — полный адрес, серверы те же. В настройках почты должен быть включён IMAP.',
        ],
        'mailru' => [
            'title'            => 'Mail.ru',
            'domains'          => ['mail.ru', 'bk.ru', 'inbox.ru', 'list.ru', 'internet.ru'],
            'imap_host'        => 'imap.mail.ru',
            'imap_port'        => 993,
            'imap_encryption'  => 'ssl',
            'imap_folder_in'   => 'INBOX',
            'imap_folder_sent' => 'Отправленные',
            'smtp_host'        => 'smtp.mail.ru',
            'smtp_port'        => 465,
            'smtp_encryption'  => 'ssl',
            'login_is_email'   => true,
            'app_password'     => true,
            'help_url'         => 'https://account.mail.ru/user/2-step-auth/passwords/',
            'hint'             => 'Mail.ru требует пароль для внешнего приложения — создайте его в настройках аккаунта.',
        ],
        'gmail' => [
            'title'            => 'Gmail',
            'domains'          => ['gmail.com', 'googlemail.com'],
            'imap_host'        => 'imap.gmail.com',
            'imap_port'        => 993,
            'imap_encryption'  => 'ssl',
            'imap_folder_in'   => 'INBOX',
            'imap_folder_sent' => '[Gmail]/Sent Mail',
            'smtp_host'        => 'smtp.gmail.com',
            'smtp_port'        => 465,
            'smtp_encryption'  => 'ssl',
            'login_is_email'   => true,
            'app_password'     => true,
            'help_url'         => 'https://myaccount.google.com/apppasswords',
            'hint'             => 'Нужен пароль приложения Google (при включённой двухфакторной аутентификации).',
        ],
        'custom' => [
            'title'            => 'Другой (свои настройки)',
            'domains'          => [],
            'imap_port'        => 993,
            'imap_encryption'  => 'ssl',
            'imap_folder_in'   => 'INBOX',
            'imap_folder_sent' => 'INBOX.Sent',
            'smtp_port'        => 465,
            'smtp_encryption'  => 'ssl',
            'login_is_email'   => false,
            'app_password'     => false,
            'help_url'         => '',
            'hint'             => 'Хостинговый ящик: серверы и папки возьмите из панели хостинга.',
        ],
    ];

    /** Preset by key, always a full row — an unknown key falls back to «свои настройки». */
    public static function get(string $key): array {
        return self::PRESETS[$key] ?? self::PRESETS['custom'];
    }

    /** Guess the provider from the address, so «добавить ящик» is one field long. */
    public static function detect(string $email): string {
        $domain = strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));
        if ($domain === '') return 'custom';
        foreach (self::PRESETS as $key => $preset) {
            if (in_array($domain, $preset['domains'], true)) return $key;
        }
        return 'custom';
    }

    /** Preset values for a mailbox row: hosts, ports, folders and the login. */
    public static function apply(string $key, string $email): array {
        $p = self::get($key);
        $values = [];
        foreach (['imap_host','imap_port','imap_encryption','imap_folder_in','imap_folder_sent',
                  'smtp_host','smtp_port','smtp_encryption'] as $f) {
            if (isset($p[$f])) $values[$f] = $p[$f];
        }
        if (!empty($p['login_is_email']) && $email !== '') {
            $values['imap_user'] = $email;
            $values['smtp_user'] = $email;
            $values['from_email'] = $email;
        }
        return $values;
    }

    /** The panel list: key, title, hint and where to create the app password. */
    public static function describe(): array {
        $out = [];
        foreach (self::PRESETS as $key => $p) {
            $out[] = [
                'key'          => $key,
                'title'        => $p['title'],
                'hint'         => $p['hint'],
                'help_url'     => $p['help_url'],
                'app_password' => (bool)$p['app_password'],
                'defaults'     => self::apply($key, ''),
            ];
        }
        return $out;
    }
}

final class Mailboxes {
    /** Columns the admin panel may write. Passwords are handled separately. */
    public const FIELDS = [
        'name','email','provider','is_active','is_default','manager_id','create_requests','sync_sent',
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
            'provider'         => 'custom',
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

        // A known provider fills in whatever the admin left blank — Yandex needs
        // nothing but the address and the application password.
        $email    = (string)($data['email'] ?? ($id ? (string)(self::get($id)['email'] ?? '') : ''));
        $provider = (string)($data['provider'] ?? '');
        if ($provider === '' && $email !== '' && !$id) $provider = $data['provider'] = MailProviders::detect($email);
        if ($provider !== '' && $provider !== 'custom') {
            foreach (MailProviders::apply($provider, $email) as $f => $v) {
                if (!isset($data[$f]) || trim((string)$data[$f]) === '') $data[$f] = $v;
            }
        }

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
            $box['oldest_at'] = (string)Db::val("SELECT MIN(date_at) FROM mail_messages WHERE mailbox_id=?", [$box['id']]);
            $box['backfill']  = self::backfillProgress($box);
            $out[] = $box;
        }
        return $out;
    }

    /** How far the «скачать весь архив» run got, in a shape the panel can render. */
    public static function backfillProgress(array $box): array {
        $folders = [
            'in'   => ['cursor' => (int)($box['backfill_uid_in'] ?? 0),   'max' => (int)($box['backfill_max_in'] ?? 0),   'done' => !empty($box['backfill_done_in'])],
            'sent' => ['cursor' => (int)($box['backfill_uid_sent'] ?? 0), 'max' => (int)($box['backfill_max_sent'] ?? 0), 'done' => !empty($box['backfill_done_sent'])],
        ];
        $wantSent = !empty($box['sync_sent']) && trim((string)($box['imap_folder_sent'] ?? '')) !== '';
        if (!$wantSent) unset($folders['sent']);

        $percent = 0;
        $parts = 0;
        foreach ($folders as $f) {
            $parts++;
            $percent += $f['done'] ? 100 : ($f['max'] > 0 ? min(100, (int)round($f['cursor'] / $f['max'] * 100)) : 0);
        }
        return [
            'folders'     => $folders,
            'done'        => !array_filter($folders, fn($f) => !$f['done']),
            'percent'     => $parts ? (int)round($percent / $parts) : 0,
            'started_at'  => $box['backfill_started_at'] ?? null,
            'finished_at' => $box['backfill_finished_at'] ?? null,
        ];
    }
}

/**
 * The archive itself: every message the service sees or sends, in one table.
 */
final class MailArchive {
    /**
     * Store an incoming message. Returns the row id, or 0 when already archived.
     * $markProcessed stamps the row as handled: old mail pulled by the full-archive
     * download must land in the archive WITHOUT waking the request pipeline —
     * a three-year-old letter is history, not a new КП request.
     */
    public static function storeIncoming(array $box, array $msg, string $direction = 'in', bool $markProcessed = false): int {
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
            'processed_at' => $markProcessed ? date('Y-m-d H:i:s') : null,
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

    /**
     * Stream the archive of a mailbox as an mbox file — the format Thunderbird,
     * Outlook importers and `grep` all understand. Written straight to the output
     * buffer: the archive is bigger than the memory a shared host gives us.
     */
    public static function exportMbox(?int $mailboxId): void {
        $where  = $mailboxId ? 'mailbox_id = ?' : '1=1';
        $params = $mailboxId ? [$mailboxId] : [];
        $stmt = Db::q("SELECT * FROM mail_messages WHERE $where ORDER BY date_at, id", $params);

        while ($row = $stmt->fetch()) {
            $from = $row['from_email'] ?: 'unknown@localhost';
            $date = date('D M j H:i:s Y', strtotime((string)$row['date_at']) ?: time());
            echo "From $from $date\r\n";
            foreach ([
                'Date'        => date('r', strtotime((string)$row['date_at']) ?: time()),
                'From'        => self::mimeAddress((string)$row['from_name'], $from),
                'To'          => (string)$row['to_emails'],
                'Cc'          => (string)$row['cc_emails'],
                'Subject'     => (string)$row['subject'],
                'Message-ID'  => (string)$row['message_id'],
                'In-Reply-To' => (string)$row['in_reply_to'],
                'X-Folder'    => (string)$row['folder'],
            ] as $header => $value) {
                if (trim($value) === '') continue;
                echo self::mimeHeader($header, $value) . "\r\n";
            }
            echo "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
            // «From » at the start of a body line is the mbox record separator
            echo preg_replace('/^(>*From )/m', '>$1', str_replace("\r\n", "\n", (string)$row['body_text'])) . "\r\n\r\n";
            flush();
        }
    }

    /** «Пётр» <p@corp.ru> — the display name is encoded, the address must stay readable. */
    private static function mimeAddress(string $name, string $email): string {
        $name = trim(str_replace(['"', "\r", "\n"], '', $name));
        if ($name === '') return "<$email>";
        $name = preg_match('/[\x80-\xFF]/', $name) ? '=?UTF-8?B?' . base64_encode($name) . '?=' : '"' . $name . '"';
        return "$name <$email>";
    }

    /** Non-ASCII headers travel base64-encoded, or a mail client shows mojibake. */
    private static function mimeHeader(string $name, string $value): string {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/[\x80-\xFF]/', $value)) $value = '=?UTF-8?B?' . base64_encode($value) . '?=';
        return "$name: $value";
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
