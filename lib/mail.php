<?php
/**
 * Mail overlay: several mailboxes, a full archive of incoming AND outgoing
 * messages, and sending through the mailbox the manager works from.
 * The service behaves like a thin mail client on top of the company mailboxes.
 */
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/mail_threads.php';
require_once __DIR__ . '/mail_text.php';
require_once __DIR__ . '/site_forms.php';

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

    /**
     * The mailbox an answer leaves from (module 015).
     *
     * Historically that was whichever mailbox the manager happened to open, so
     * the same client got answers from two different addresses and the reply
     * came back to a mailbox nobody reads. `MAIL_OUTGOING_FROM` names the ONE
     * address the company writes from; when it is set, it wins over the chosen
     * mailbox, and a setting that points at no active mailbox is a loud error
     * rather than a silent fallback to whatever was configured first.
     */
    public static function outgoing($mailboxId = null, $managerId = null): ?array {
        $forced = mb_strtolower(trim((string)Settings::get('MAIL_OUTGOING_FROM', '')));
        if ($forced !== '') {
            $box = Db::one("SELECT * FROM mailboxes WHERE is_active=1 AND lower(email)=? ORDER BY is_default DESC, id LIMIT 1", [$forced]);
            if ($box) {
                if ($mailboxId && (int)$mailboxId !== (int)$box['id']) {
                    Logger::info('mail', "Ответ уходит с общего адреса $forced, а не из ящика #" . (int)$mailboxId,
                                 ['mailbox_id' => (int)$box['id']]);
                }
                return $box;
            }
            Logger::error('mail', "В настройках задан адрес для исходящих «{$forced}», но активного ящика с таким адресом нет — письмо уйдёт из ящика по умолчанию");
        }
        return !empty($mailboxId) ? self::get((int)$mailboxId) : self::default($managerId);
    }

    /** Mailboxes a manager may use: their own plus the shared ones. */
    public static function forManager(array $manager): array {
        if (!empty($manager['is_admin'])) return self::all(true);
        return Db::all("SELECT * FROM mailboxes WHERE is_active=1 AND (manager_id IS NULL OR manager_id=?) ORDER BY is_default DESC, id", [$manager['id']]);
    }

    /**
     * Запомнить имя папки «Отправленные», которое на сервере действительно есть.
     *
     * Имя из настроек — догадка: у Яндекса папка называется «Отправленные», у
     * cPanel «INBOX.Sent», и ящик с чужим именем не открывался вовсе. Счётчик
     * UID принадлежит ПАПКЕ: сменилось имя — прежний счётчик ничей, и его надо
     * обнулить, иначе половина отправленных писем не будет забрана никогда.
     */
    public static function rememberSentFolder(int $id, string $folder, string $was): void {
        $data = ['imap_folder_sent' => $folder];
        if (trim($was) !== $folder) {
            $data += ['last_uid_sent' => 0, 'backfill_uid_sent' => 0,
                      'backfill_max_sent' => 0, 'backfill_done_sent' => 0];
        }
        Db::update('mailboxes', $data, 'id=?', [$id]);
        Logger::info('mail', "Папка «Отправленные» уточнена: $folder", ['mailbox_id' => $id, 'was' => $was]);
    }

    /** IMAP/SMTP config for EmailReader / EmailSender, secrets decrypted. */
    public static function cfg(array $box): array {
        $imapUser = (string)($box['imap_user'] ?? '');
        $imapPass = Crypt::decrypt((string)($box['imap_password'] ?? ''));

        // One account, one application password: an empty SMTP login or password
        // means «то же, что у IMAP», not «отправлять без авторизации» — otherwise
        // the mailbox reads fine and refuses to send.
        $smtpUser = (string)($box['smtp_user'] ?? '');
        if (trim($smtpUser) === '') $smtpUser = $imapUser ?: (string)($box['email'] ?? '');
        $smtpPass = Crypt::decrypt((string)($box['smtp_password'] ?? ''));
        if ($smtpPass === '') $smtpPass = $imapPass;

        return [
            'IMAP_HOST'       => $box['imap_host'] ?? '',
            'IMAP_PORT'       => (int)($box['imap_port'] ?? 993),
            'IMAP_USER'       => $imapUser,
            'IMAP_PASSWORD'   => $imapPass,
            'IMAP_ENCRYPTION' => $box['imap_encryption'] ?? 'ssl',
            'SMTP_HOST'       => $box['smtp_host'] ?? '',
            'SMTP_PORT'       => (int)($box['smtp_port'] ?? 465),
            'SMTP_USER'       => $smtpUser,
            'SMTP_PASSWORD'   => $smtpPass,
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

    /**
     * Выключить ящик, не удаляя его (модуль 019).
     *
     * Ящик перестаёт опрашиваться и исчезает из выбора отправителя, но остаётся
     * со всеми настройками и паролями — включить обратно можно одной кнопкой.
     *
     * $letters решает судьбу его переписки — выключенный ящик, чьи письма
     * продолжают висеть в списках и на доске, ничего не решает:
     *   keep   — оставить на экране;
     *   hide   — убрать с экрана (вернутся, когда ящик включат обратно);
     *   delete — удалить вместе с вложениями, на сервере не трогая.
     * Включение всегда возвращает то, что было скрыто.
     */
    public static function setActive(int $id, bool $active, string $letters = 'keep'): array {
        $box = self::get($id);
        if (!$box) throw new RuntimeException('Ящик не найден');

        Db::update('mailboxes', ['is_active' => $active ? 1 : 0], 'id=?', [$id]);
        // Выключенный ящик не может быть основным для отправки — иначе ответ
        // уйдёт в никуда; основным становится первый оставшийся активный.
        if (!$active && !empty($box['is_default'])) {
            Db::update('mailboxes', ['is_default' => 0], 'id=?', [$id]);
            $next = Db::val("SELECT id FROM mailboxes WHERE is_active=1 AND id<>? ORDER BY id LIMIT 1", [$id]);
            if ($next) Db::update('mailboxes', ['is_default' => 1], 'id=?', [(int)$next]);
        }

        require_once __DIR__ . '/mailsync.php';
        $touched = 0;
        if ($active) {
            $touched = MailSync::setMailboxMessagesHidden($id, false);
        } elseif ($letters === 'hide') {
            $touched = MailSync::setMailboxMessagesHidden($id, true);
        } elseif ($letters === 'delete') {
            Db::begin();
            try {
                foreach (Db::all("SELECT id FROM mail_messages WHERE mailbox_id=?", [$id]) as $m) {
                    MailSync::forgetMessage((int)$m['id']);
                    $touched++;
                }
                Db::commit();
            } catch (Throwable $e) {
                Db::rollback();
                throw $e;
            }
        }

        Logger::info('mail', 'Почтовый ящик ' . ($active ? 'включён' : 'выключен'),
                     ['mailbox_id' => $id, 'letters' => $letters, 'messages' => $touched]);
        return ['is_active' => $active, 'letters' => $letters, 'messages' => $touched];
    }

    /**
     * Удалить ящик. Письма ссылаются на него внешним ключом, поэтому прямое
     * `DELETE FROM mailboxes` падало на `FOREIGN KEY constraint failed` — ящик
     * с архивом удалить было нельзя вообще.
     *
     * $letters говорит, что делать с этим архивом:
     *   keep   — письма остаются, но теряют ящик (и уходят с экрана: читать их
     *            больше неоткуда, забрать заново тоже нечем);
     *   delete — письма уходят вместе с ящиком, с вложениями и карточками.
     * Сервер при удалении ящика не трогаем: доступа к нему у нас уже нет.
     */
    public static function delete(int $id, string $letters = 'keep'): array {
        $box = self::get($id);
        if (!$box) throw new RuntimeException('Ящик не найден');

        require_once __DIR__ . '/mailsync.php';
        $count = (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE mailbox_id=?", [$id]);

        Db::begin();
        try {
            if ($letters === 'delete') {
                foreach (Db::all("SELECT id FROM mail_messages WHERE mailbox_id=?", [$id]) as $m) {
                    MailSync::forgetMessage((int)$m['id']);
                }
            } else {
                // «Оставить письма» — значит оставить их НА ЭКРАНЕ. Прятать их,
                // как у выключенного ящика, нельзя: выключенный включают
                // обратно, а удалённый — уже никогда, и переписка оставалась в
                // архиве навсегда (модуль 023).
                Db::q("UPDATE mail_messages SET mailbox_id=NULL,
                              archived_at = CASE WHEN archived_reason='mailbox_off' THEN NULL ELSE archived_at END,
                              archived_reason = CASE WHEN archived_reason='mailbox_off' THEN NULL ELSE archived_reason END
                       WHERE mailbox_id=?", [$id]);
            }
            Db::q("UPDATE mail_deleted SET mailbox_id=NULL WHERE mailbox_id=?", [$id]);
            Db::q("DELETE FROM mailboxes WHERE id=?", [$id]);
            // Ящик по умолчанию не может исчезнуть вместе с удалённым
            if (!Db::val("SELECT COUNT(*) FROM mailboxes WHERE is_default=1 AND is_active=1")) {
                $next = Db::val("SELECT id FROM mailboxes WHERE is_active=1 ORDER BY id LIMIT 1");
                if ($next) Db::q("UPDATE mailboxes SET is_default=1 WHERE id=?", [(int)$next]);
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        Logger::info('mail', 'Почтовый ящик удалён', ['mailbox_id' => $id, 'letters' => $letters, 'messages' => $count]);
        return ['messages' => $count, 'letters' => $letters];
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
            $box['hidden_messages'] = (int)Db::val(
                "SELECT COUNT(*) FROM mail_messages WHERE mailbox_id=? AND archived_reason='mailbox_off'", [$box['id']]);
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
     * Below this many characters a body says nothing about WHICH letter it is:
     * «Спасибо!» twice in one conversation is two letters, not one. Such a letter
     * is deduplicated by its Message-ID alone (module 021).
     */
    private const DEDUP_MIN_BODY = 40;

    /**
     * Store an incoming message. Returns the row id, or 0 when already archived.
     * $markProcessed stamps the row as handled: old mail pulled by the full-archive
     * download must land in the archive WITHOUT waking the request pipeline —
     * a three-year-old letter is history, not a new КП request.
     */
    /**
     * @param bool $force Класть письмо, даже если такое уже есть и даже если
     *   такое когда-то удаляли. Так работает повторный импорт mbox «заново»:
     *   архив успели потерять вместе с ящиком, а файл — вот он (модуль 023).
     */
    public static function storeIncoming(array $box, array $msg, string $direction = 'in',
                                         bool $markProcessed = false, bool $force = false): int {
        // A letter the site form sent us is really the visitor's letter (module
        // 015). It is unwrapped FIRST, before the thread key and the sender are
        // read off it — otherwise every form submission is the same party, the
        // same conversation and the same company card. It also has to happen
        // before the duplicate check, or the fingerprint a letter is LOOKED UP by
        // is not the fingerprint it was STORED under (module 021).
        if ($direction === 'in') $msg = SiteForm::unwrap($msg);

        if (!$force && self::exists($box, $msg, $direction)) return 0;

        $limit = max(16, (int)Settings::get('MAIL_BODY_MAX_KB', 512)) * 1024;

        // The conversation this letter belongs to is decided on the way in, so a
        // Gmail answer to a Yandex letter is already in the right thread when the
        // page opens (module 010)
        $subject = self::utf8($msg['subject'] ?? '');
        $thread = MailThreads::keyFor([
            'subject'     => $subject,
            'message_id'  => $msg['message_id'] ?? '',
            'in_reply_to' => $msg['in_reply_to'] ?? '',
            // The conversation is «this subject WITH THIS CLIENT», so the key
            // needs both sides: for a letter we sent, the party is the addressee
            'direction'   => $direction,
            'from_email'  => $msg['from'] ?? '',
            'to_emails'   => $msg['to'] ?? '',
            'date_at'     => $msg['date'] ?? '',
        ]);
        return Db::insert('mail_messages', [
            'mailbox_id'   => (int)$box['id'],
            'thread_key'    => $thread,
            'thread_subject'=> MailThreads::displaySubject($subject),
            // The letter's own fingerprint, so the NEXT copy of it — from another
            // mailbox, from «Отправленные», from an imported mbox — is recognised
            'dedup_hash'   => self::fingerprint($msg) ?: null,
            'import_id'    => $msg['import_id'] ?? null,
            'direction'    => $direction,
            'folder'       => $msg['folder'] ?? 'INBOX',
            'uid'          => (int)($msg['uid'] ?? 0),
            'message_id'   => $msg['message_id'] ?? null,
            'in_reply_to'  => $msg['in_reply_to'] ?? null,
            'subject'      => $subject,
            'from_email'   => $msg['from'] ?? '',
            'from_name'    => self::utf8($msg['from_name'] ?? ''),
            'to_emails'    => self::utf8($msg['to'] ?? ''),
            'cc_emails'    => self::utf8($msg['cc'] ?? ''),
            'body_text'    => self::utf8(mb_strcut((string)($msg['body'] ?? ''), 0, $limit)),
            'body_html'    => self::utf8(mb_strcut((string)($msg['body_html'] ?? ''), 0, $limit)),
            'headers'      => self::utf8((string)($msg['headers'] ?? '')),
            'size'         => (int)($msg['size'] ?? 0),
            'has_attachment' => empty($msg['attachments']) ? 0 : 1,
            'source_channel' => $msg['source_channel'] ?? 'email',
            'form_json'      => $msg['form_json'] ?? null,
            'needs_call'     => (int)($msg['needs_call'] ?? 0),
            'is_read'      => !empty($msg['seen']) ? 1 : 0,
            'processed_at' => $markProcessed ? date('Y-m-d H:i:s') : null,
            'date_at'      => $msg['date'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /** Store a message the service itself sent. */
    public static function storeOutgoing(array $o): int {
        $subject = (string)($o['subject'] ?? '');
        // An answer sent from any mailbox lands in the same thread as the letter
        // it answers — that is what «переписка со всех ящиков в одной цепочке» is
        $thread = (string)($o['thread_key'] ?? '') ?: MailThreads::keyFor([
            'subject'     => $subject,
            'message_id'  => $o['message_id'] ?? '',
            'in_reply_to' => $o['in_reply_to'] ?? '',
            'direction'   => 'out',
            'from_email'  => $o['from_email'] ?? '',
            'to_emails'   => $o['to'] ?? ($o['to_emails'] ?? ''),
            'date_at'     => date('Y-m-d H:i:s'),
        ]);
        // Ответили — значит, прочитали: жирное выделение снимается со всей
        // переписки, а счётчик перестаёт считать разобранное (модуль 039)
        if ($thread) {
            Db::q("UPDATE mail_messages SET is_read=1 WHERE thread_key=? AND direction='in' AND is_read=0", [$thread]);
        }
        return Db::insert('mail_messages', [
            'mailbox_id'      => $o['mailbox_id'] ?? null,
            'thread_key'      => $thread,
            'thread_subject'  => MailThreads::displaySubject($subject),
            'sent_state'      => $o['sent_state'] ?? null,
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
            'attachments_json'=> !empty($o['attachments'])
                ? json_encode(array_map([self::class, 'attachmentName'], $o['attachments']), JSON_UNESCAPED_UNICODE) : null,
            'counterparty_id' => $o['counterparty_id'] ?? null,
            'request_id'      => $o['request_id'] ?? null,
            'manager_id'      => $o['manager_id'] ?? null,
            'is_read'         => 1,
            'processed_at'    => date('Y-m-d H:i:s'),
            'date_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /** Имя вложения в письме: `['path'=>…,'name'=>…]` или просто путь. */
    public static function attachmentName(array|string $a): string {
        if (is_array($a)) return (string)($a['name'] ?? basename((string)($a['path'] ?? '')));
        require_once __DIR__ . '/outbox.php';
        return Outbox::displayName($a);
    }

    /** Путь вложения на диске, какой бы формой его ни передали. */
    public static function attachmentPath(array|string $a): string {
        return is_array($a) ? (string)($a['path'] ?? '') : (string)$a;
    }

    /**
     * Файлы отправленного письма — в архив, как у входящего (модуль 039).
     *
     * Раньше от них оставался только список имён в `attachments_json`: в
     * переписке под нашим письмом не было ни одного вложения, и скачать
     * отправленное было неоткуда. Теперь файл копируется в хранилище и
     * становится обычной строкой `attachments` — той же, что у входящих.
     */
    public static function storeOutgoingFiles(int $mailMessageId, array $attachments, array $o = []): void {
        if (!$attachments) return;
        require_once __DIR__ . '/attachments.php';
        foreach ($attachments as $a) {
            $path = self::attachmentPath($a);
            if ($path === '' || !is_file($path)) continue;
            try {
                $content = (string)@file_get_contents($path);
                if ($content === '') continue;
                Attachments::store(
                    ['filename' => self::attachmentName($a), 'content' => $content],
                    ['mail_message_id' => $mailMessageId,
                     'counterparty_id' => $o['counterparty_id'] ?? null,
                     'request_id'      => $o['request_id'] ?? null],
                    // Наш же файл: распознавать в нём нечего, мы его и составили
                    ['ocr' => false]
                );
            } catch (Throwable $e) {
                Logger::warning('mail', 'Вложение отправленного письма не сохранилось: ' . $e->getMessage());
            }
        }
    }

    /**
     * Only valid UTF-8 goes into the archive: a letter declaring one charset and
     * carrying another makes json_encode() fail — and with it the whole mail page.
     */
    private static function utf8(string $s): string {
        return utf8Text($s);
    }

    /**
     * Sanitize an HTML email body for display (item 4 of the mobile/UX pass).
     *
     * An email body is attacker-controlled content: this strips every script
     * vector down to an explicit tag/attribute allowlist. It is still rendered
     * client-side inside a sandboxed `<iframe>` with no `allow-scripts` — so a
     * bug here is defense in depth, not the only guard, but it keeps the output
     * free of dead weight (no remote stylesheets, no forms, no event handlers).
     */
    public static function sanitizeHtml(string $html): string {
        $html = trim($html);
        if ($html === '') return '';

        $allowedTags = ['a', 'b', 'strong', 'i', 'em', 'u', 's', 'p', 'br', 'div', 'span',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'blockquote', 'pre', 'code',
            'hr', 'font', 'center', 'small', 'sub', 'sup', 'caption', 'col', 'colgroup'];
        $deniedTags = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
            'form', 'input', 'button', 'textarea', 'select', 'option', 'link', 'base',
            'meta', 'applet', 'audio', 'video', 'svg', 'math', 'noscript', 'template'];
        $globalAttrs = ['style', 'align', 'valign', 'width', 'height', 'colspan', 'rowspan',
            'border', 'cellpadding', 'cellspacing', 'bgcolor', 'color', 'class'];
        $tagAttrs = [
            'a'   => ['href', 'title', 'name'],
            'img' => ['src', 'alt', 'title'],
            'td'  => ['colspan', 'rowspan'],
            'th'  => ['colspan', 'rowspan'],
        ];

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // A stray "<?xml encoding" PI keeps DOMDocument from guessing a
        // different charset and mangling multi-byte (Cyrillic) text
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="atlant-root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );
        libxml_clear_errors();

        $root = $doc->getElementById('atlant-root');
        if (!$root) return '';

        self::sanitizeNode($root, $allowedTags, $deniedTags, $globalAttrs, $tagAttrs);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /** Recursive allowlist walk used by sanitizeHtml(). */
    private static function sanitizeNode(\DOMNode $node, array $allowedTags, array $deniedTags,
                                          array $globalAttrs, array $tagAttrs): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) continue; // text nodes are always safe as-is

            $tag = strtolower($child->tagName);
            if (in_array($tag, $deniedTags, true)) {
                $node->removeChild($child);
                continue;
            }
            if (!in_array($tag, $allowedTags, true)) {
                // Unknown tag — sanitize whatever it hides *first* (its children
                // are about to be promoted to this level and must not carry a
                // live <script>/on*= of their own out with them), then keep
                // just the cleaned content and drop the wrapper itself
                self::sanitizeNode($child, $allowedTags, $deniedTags, $globalAttrs, $tagAttrs);
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }

            $allowed = array_merge($tagAttrs[$tag] ?? [], $globalAttrs);
            foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                $name = strtolower($attr->name);
                if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                    $child->removeAttribute($attr->name);
                    continue;
                }
                $value = trim($attr->value);
                if (in_array($name, ['href', 'src'], true)) {
                    $safe = (bool)preg_match('#^(https?:|mailto:)#i', $value)
                        || ($name === 'src' && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $value));
                    if (!$safe) { $child->removeAttribute($attr->name); continue; }
                }
                if ($name === 'style' && preg_match('/expression\s*\(|javascript:|-moz-binding|behaviou?r\s*:|@import/i', $value)) {
                    $child->removeAttribute('style');
                }
            }
            if ($tag === 'a') {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer nofollow');
            }

            self::sanitizeNode($child, $allowedTags, $deniedTags, $globalAttrs, $tagAttrs);
        }
    }

    /**
     * Is this letter already in the archive? (module 021)
     *
     * Four keys, cheapest first. With `MAIL_DEDUP` on — and it is on out of the
     * box — the Message-ID and the content fingerprint are looked up across ALL
     * mailboxes, because the same letter reaching two of our addresses, pulled
     * back out of «Отправленные» and then imported again from a Gmail mbox is ONE
     * letter, not four rows in the conversation. Switched off, the checks narrow
     * back to the mailbox they were before.
     */
    private static function exists(array $box, array $msg, string $direction = 'in'): bool {
        $mailboxId = (int)$box['id'];
        $folder    = (string)($msg['folder'] ?? 'INBOX');
        $uid       = (int)($msg['uid'] ?? 0);
        $messageId = (string)($msg['message_id'] ?? '');

        // A letter the manager deleted must stay deleted: the row is gone, so the
        // tombstone is the only thing standing between it and the next sync
        if (self::wasDeleted($mailboxId, $folder, $uid, $messageId)) return true;
        if ($uid > 0 && Db::val("SELECT 1 FROM mail_messages WHERE mailbox_id=? AND folder=? AND uid=?", [$mailboxId, $folder, $uid])) return true;

        $global = self::dedupEnabled();

        // Письмо, написанное самим себе, — ДВА факта, а не один (модуль 036):
        // оно и отправлено, и получено. Дедупликация складывала их в одну
        // строку — ту, что легла первой, — и письмо с нашего адреса на наш же
        // во «Входящих» не появлялось вовсе: его съедала копия из
        // «Отправленных» (или строка, записанная самой отправкой из сервиса).
        // Поэтому у такой входящей копии дубликатом считается только другая
        // ВХОДЯЩАЯ. Копия себе в «Копию» письма клиенту сюда не попадает: там
        // в «Кому» стоит клиент, и ответ остаётся в переписке один.
        $selfCopy = $direction === 'in' && self::writtenToOurselves($msg);
        $sameWay  = $selfCopy ? " AND direction='in'" : '';

        // The same message can arrive twice (INBOX + Sent sync, or a re-created mailbox)
        if ($messageId !== '') {
            $found = $global
                ? Db::val("SELECT 1 FROM mail_messages WHERE message_id=?$sameWay", [$messageId])
                : Db::val("SELECT 1 FROM mail_messages WHERE mailbox_id=? AND message_id=? AND folder=?$sameWay",
                          [$mailboxId, $messageId, $folder]);
            if ($found) return true;
            // A letter WE sent is already in the archive under folder «SENT» or under
            // whatever the server calls it; pulling it back out of «Отправленные»
            // must not show the same answer twice in the thread
            if ($direction === 'out'
                && Db::val("SELECT 1 FROM mail_messages WHERE mailbox_id=? AND message_id=? AND direction='out'",
                           [$mailboxId, $messageId])) return true;
        }

        // Last key: the letter itself. A gateway that rewrote the Message-ID, a
        // client that never wrote one, a forwarded copy of our own answer — the
        // body and the files are the same, so it is the same letter.
        if ($global && (string)Settings::get('MAIL_DEDUP_CONTENT', 1) === '1') {
            $hash = self::fingerprint($msg);
            if ($hash !== '' && Db::val("SELECT 1 FROM mail_messages WHERE dedup_hash=?$sameWay", [$hash])) return true;
        }
        return false;
    }

    /**
     * Письмо, написанное самим себе: пришло с НАШЕГО адреса и адресовано
     * ТОЛЬКО нашим (модуль 036).
     *
     * Так менеджер проверяет почту — отправляет письмо себе и ждёт его во
     * «Входящих». Копия себе в «Копию» письма клиенту — не оно: в «Кому» там
     * стоит клиент, и второй строки в переписке быть не должно.
     */
    private static function writtenToOurselves(array $msg): bool {
        require_once __DIR__ . '/crm.php';

        $from = MailDomains::firstAddress((string)($msg['from'] ?? ''));
        if ($from === '' || !Crm::isOurAddress($from)) return false;

        $seen = false;
        foreach (preg_split('/[,;]/', (string)($msg['to'] ?? '')) ?: [] as $part) {
            $addr = MailDomains::firstAddress($part);
            if ($addr === '') continue;
            if (!Crm::isOurAddress($addr)) return false;
            $seen = true;
        }
        return $seen;
    }

    /** Deduplication is the default for every mailbox; a setting can switch it off. */
    public static function dedupEnabled(): bool {
        return (string)Settings::get('MAIL_DEDUP', 1) === '1';
    }

    /**
     * The letter's own fingerprint: sender, recipients, subject, text and the
     * bytes of every file. Returns '' when the letter carries too little to be
     * recognised by its content (see DEDUP_MIN_BODY) — such a letter is left to
     * the Message-ID, never guessed at.
     */
    public static function fingerprint(array $msg): string {
        $files = [];
        foreach ($msg['attachments'] ?? [] as $file) {
            $content = (string)($file['content'] ?? '');
            $files[] = ($file['content_hash'] ?? sha1($content)) . ':' . (int)($file['size'] ?? strlen($content));
        }
        sort($files);

        $body = self::normalizeForHash(utf8Text((string)($msg['body'] ?? '')));
        if ($body === '') {
            $html = utf8Text((string)($msg['body_html'] ?? ''));
            if (trim($html) !== '') $body = self::normalizeForHash(MailText::fromHtml($html));
        }
        if (mb_strlen($body) < self::DEDUP_MIN_BODY && !$files) return '';
        // The archive stores a body cut to MAIL_BODY_MAX_KB, so hashing the whole
        // of an incoming one would disagree with the hash of the same letter read
        // back out of the archive. The first 100 000 characters are inside both.
        $body = mb_substr($body, 0, 100000);

        $to = array_filter(array_map('trim', explode(',', mb_strtolower(utf8Text((string)($msg['to'] ?? ''))))));
        sort($to);

        return sha1(implode("\n", [
            mb_strtolower(trim(utf8Text((string)($msg['from'] ?? '')))),
            implode(',', $to),
            self::normalizeForHash(utf8Text((string)($msg['subject'] ?? ''))),
            $body,
            implode(',', $files),
        ]));
    }

    /**
     * The same letter read over IMAP and out of an mbox differs in line endings,
     * trailing spaces and the odd non-breaking space — never in its words.
     */
    private static function normalizeForHash(string $s): string {
        $s = str_replace(["\xC2\xA0", "\xEF\xBB\xBF"], [' ', ''], $s);
        $s = (string)preg_replace('/\s+/u', ' ', $s);
        return trim(mb_strtolower($s));
    }

    /** Fingerprint of a row that is already stored, files read from the archive. */
    public static function fingerprintRow(array $row): string {
        $files = [];
        foreach (Db::all("SELECT content_hash, size FROM attachments WHERE mail_message_id=?", [$row['id']]) as $a) {
            if (empty($a['content_hash'])) continue;   // hashed on the way in since module 021
            $files[] = ['content_hash' => $a['content_hash'], 'size' => (int)$a['size']];
        }
        return self::fingerprint([
            'from' => $row['from_email'] ?? '', 'to' => $row['to_emails'] ?? '',
            'subject' => $row['subject'] ?? '', 'body' => $row['body_text'] ?? '',
            'body_html' => $row['body_html'] ?? '', 'attachments' => $files,
        ]);
    }

    /**
     * Hash the letters archived before module 021 — in steps, because an archive
     * of twenty thousand bodies is not something a shared host does in one
     * request. Runs from the import, from the sync and from the panel's button;
     * until it finishes, those old letters are deduplicated by Message-ID alone.
     * @return array{done:int,left:int}
     */
    public static function backfillFingerprints(int $limit = 500, float $seconds = 5.0): array {
        $deadline = microtime(true) + $seconds;
        $done = 0;
        while ($done < $limit && microtime(true) < $deadline) {
            $rows = Db::all("SELECT id, from_email, to_emails, subject, body_text, body_html
                             FROM mail_messages WHERE dedup_hash IS NULL ORDER BY id LIMIT 50");
            if (!$rows) break;
            foreach ($rows as $row) {
                // '-' and not NULL: a letter too short to fingerprint must not be
                // looked at again on every following step
                Db::update('mail_messages', ['dedup_hash' => self::fingerprintRow($row) ?: '-'], 'id=?', [$row['id']]);
                $done++;
            }
        }
        return ['done' => $done, 'left' => (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE dedup_hash IS NULL")];
    }

    /**
     * Attach an archived letter to the company card it belongs to (module 021).
     *
     * History does not go through the request pipeline — `processInbound()` never
     * sees it — so nothing would otherwise set `counterparty_id`, and the
     * imported conversation would exist in the archive and nowhere else. The
     * party is the OTHER side: the sender of what came in, the addressee of what
     * we sent. `$createMissing` is off unless the operator asked: a three-year
     * archive would otherwise open a company card per newsletter.
     */
    public static function linkCounterparty(int $messageId, bool $createMissing = false): ?int {
        require_once __DIR__ . '/crm.php';
        $row = Db::one("SELECT * FROM mail_messages WHERE id=?", [$messageId]);
        if (!$row || !empty($row['counterparty_id'])) return $row ? ($row['counterparty_id'] ?? null) : null;

        $email = (string)($row['direction'] === 'out'
            ? trim(explode(',', (string)$row['to_emails'])[0] ?? '')
            : $row['from_email']);
        $email = mb_strtolower(trim($email));
        if ($email === '' || Crm::isOurAddress($email)) return null;

        $hints = [
            'email' => $email,
            'name'  => $row['direction'] === 'out' ? '' : (string)($row['from_name'] ?? ''),
            'text'  => (string)($row['body_text'] ?? ''),
        ];
        $id = $createMissing ? Crm::resolveCounterparty($hints) : Crm::findCounterparty($hints);
        if (!$id) return null;

        Db::update('mail_messages', ['counterparty_id' => $id], 'id=?', [$messageId]);
        return $id;
    }

    /** Was this letter thrown away by hand? (module 004, «Удалить») */
    public static function wasDeleted(int $mailboxId, string $folder, int $uid, string $messageId): bool {
        if ($uid > 0 && Db::val("SELECT 1 FROM mail_deleted WHERE mailbox_id=? AND folder=? AND uid=?",
                                [$mailboxId, $folder, $uid])) return true;
        if ($messageId !== '' && Db::val("SELECT 1 FROM mail_deleted WHERE mailbox_id=? AND message_id=?",
                                         [$mailboxId, $messageId])) return true;
        return false;
    }

    /**
     * Вернуть на экран письма, которые унёс с собой удалённый ящик.
     *
     * Выключенный ящик прячет свои письма (модуль 019) и возвращает их, когда
     * его включают обратно. Удалённый ящик включить нельзя — и письма
     * оставались в архиве навсегда: «я удалила ящик, и переписка из mbox
     * исчезла». Строки, спрятанные по причине `mailbox_off`, чей ящик больше не
     * существует, возвращаются сюда (модуль 023).
     *
     * @return int сколько писем вернулось
     */
    public static function restoreOrphaned(): int {
        Db::q("UPDATE mail_messages SET archived_at=NULL, archived_reason=NULL
               WHERE archived_reason='mailbox_off'
                 AND (mailbox_id IS NULL OR mailbox_id NOT IN (SELECT id FROM mailboxes))");
        $restored = (int)Db::pdo()->query("SELECT changes()")->fetchColumn();
        if ($restored) Logger::info('mail', "Возвращены письма удалённых ящиков: $restored");
        return $restored;
    }

    /** Message list for the mail page. */
    public static function query(array $f = []): array {
        $where = ['1=1'];
        $params = [];
        // Архив («не наш профиль», письма выключенного ящика) виден только тогда,
        // когда его спросили — иначе список показывает работу, а не историю.
        // `all` — поиск, которому всё равно, где лежит письмо: менеджер ищет
        // письмо, а не раздел, в который оно попало (модуль 023).
        if (($f['archived'] ?? null) !== 'all') {
            $where[] = !empty($f['archived']) ? 'm.archived_at IS NOT NULL' : 'm.archived_at IS NULL';
        }
        if (!empty($f['mailbox_id'])) { $where[] = 'm.mailbox_id = ?'; $params[] = (int)$f['mailbox_id']; }
        if (!empty($f['direction']) && in_array($f['direction'], ['in', 'out'], true)) {
            $where[] = 'm.direction = ?'; $params[] = $f['direction'];
        }
        if (!empty($f['counterparty_id'])) { $where[] = 'm.counterparty_id = ?'; $params[] = (int)$f['counterparty_id']; }
        if (!empty($f['unread'])) $where[] = 'm.is_read = 0';
        if (!empty($f['category'])) { $where[] = 'm.category = ?'; $params[] = (string)$f['category']; }
        if (trim((string)($f['q'] ?? '')) !== '') {
            // Поиск по ВСЕМУ письму, а не по четырём полям (модуль 023).
            // «Не могу найти письмо по адресу» получалось потому, что адрес
            // стоял в копии, в имени отправителя или в теле как HTML — ни одно
            // из этих мест раньше не просматривалось. Слова ищутся все сразу:
            // «иванов счёт» — это письмо, где есть и то, и другое, а не любое
            // из двух.
            foreach (self::searchTerms((string)$f['q']) as $term) {
                $like = '%' . $term . '%';
                $where[] = '(m.subject LIKE ? OR m.from_email LIKE ? OR m.from_name LIKE ?
                             OR m.to_emails LIKE ? OR m.cc_emails LIKE ?
                             OR m.body_text LIKE ? OR m.body_html LIKE ?
                             OR EXISTS (SELECT 1 FROM attachments a
                                        WHERE a.mail_message_id = m.id
                                          AND (a.filename LIKE ? OR a.extracted_text LIKE ?)))';
                array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
            }
        }
        $limit  = min(200, max(1, (int)($f['limit'] ?? 50)));
        $offset = max(0, (int)($f['offset'] ?? 0));
        $sql = "SELECT m.id, m.mailbox_id, m.direction, m.folder, m.subject, m.from_email, m.from_name,
                       m.to_emails, m.has_attachment, m.is_read, m.date_at, m.request_id, m.counterparty_id,
                       m.error, m.category, m.triage_reason, substr(m.body_text, 1, 200) AS preview,
                       b.name AS mailbox_name, c.name AS counterparty_name
                FROM mail_messages m
                LEFT JOIN mailboxes b ON b.id = m.mailbox_id
                LEFT JOIN counterparties c ON c.id = m.counterparty_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY m.date_at DESC, m.id DESC LIMIT ? OFFSET ?";
        return [
            'items'  => Db::all($sql, [...$params, $limit, $offset]),
            'total'  => (int)Db::val("SELECT COUNT(*) FROM mail_messages m WHERE " . implode(' AND ', $where), $params),
            // Один и тот же счёт по всему сервису: отвеченное письмо
            // непрочитанным не считается (модуль 039)
            'unread' => MailThreads::unreadCount(),
        ];
    }

    /**
     * Запрос → слова, по которым искать. Кавычки оставляют фразу целой,
     * короткие обрывки («по», «на») отбрасываются, а запрос, который целиком
     * короче трёх символов, ищется как есть — иначе поиск по «КП» ничего не
     * находит вообще.
     */
    public static function searchTerms(string $q): array {
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        if ($q === '') return [];

        $terms = [];
        if (preg_match_all('/"([^"]+)"/u', $q, $m)) {
            foreach ($m[1] as $phrase) {
                $phrase = trim($phrase);
                if ($phrase !== '') $terms[] = $phrase;
            }
            $q = trim((string)preg_replace('/"[^"]*"/u', ' ', $q));
        }
        foreach (preg_split('/\s+/u', $q) ?: [] as $word) {
            if ($word === '') continue;
            if (mb_strlen($word) < 3 && $terms) continue;
            $terms[] = $word;
        }
        if (!$terms) $terms[] = trim($q);
        // Восемь слов — это уже не поиск, а полный проход по архиву на каждое
        return array_slice(array_values(array_unique(array_filter($terms))), 0, 8);
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

    /**
     * Re-read letters archived before the header decoder learned about charsets.
     * Rows that still hold their original bytes come back readable. A subject
     * already turned into replacement characters («�») is structurally valid
     * UTF-8 — mb_check_encoding() sees nothing wrong with it — so that case is
     * re-decoded from the message's own stored raw headers (module 006 kept
     * them for the triage prefilter) instead; a letter archived before that
     * column existed has no raw headers to fall back to and stays as it is.
     * @return array{checked:int,fixed:int}
     */
    public static function repairEncoding(): array {
        $fields = ['subject', 'from_name', 'to_emails', 'cc_emails', 'body_text', 'body_html'];
        $checked = 0;
        $fixed = 0;
        $fixedSubjectByMessage = []; // mail_messages.id => corrected subject, for requests/notifications below

        foreach (Db::all("SELECT id, request_id, headers, " . implode(', ', $fields) . " FROM mail_messages") as $row) {
            $checked++;
            $upd = [];
            foreach ($fields as $f) {
                $value = (string)($row[$f] ?? '');
                if ($value === '') continue;

                $repaired = null;
                if (!mb_check_encoding($value, 'UTF-8')) {
                    $repaired = utf8Text($value);
                }
                if (($repaired === null || self::looksMojibake($repaired)) && in_array($f, ['subject', 'from_name'], true)) {
                    $fromHeader = self::headerDerived((string)($row['headers'] ?? ''), $f);
                    if ($fromHeader !== null && !self::looksMojibake($fromHeader)) $repaired = $fromHeader;
                }
                if ($repaired !== null && $repaired !== '' && $repaired !== $value) $upd[$f] = $repaired;
            }
            if (!$upd) continue;
            Db::update('mail_messages', $upd, 'id=?', [$row['id']]);
            $fixed++;
            if (isset($upd['subject']) && !empty($row['request_id'])) {
                $fixedSubjectByMessage[(int)$row['request_id']] = $upd['subject'];
            }
        }

        // The request card, and the notification already sent about it, both
        // copied the subject verbatim from the letter above at the time
        foreach (Db::all("SELECT id, email_subject, email_from, raw_text FROM requests") as $row) {
            $upd = [];
            foreach (['email_subject', 'email_from', 'raw_text'] as $f) {
                $value = (string)($row[$f] ?? '');
                if ($value === '' || mb_check_encoding($value, 'UTF-8')) continue;
                $repaired = utf8Text($value);
                if ($repaired !== '' && $repaired !== $value) $upd[$f] = $repaired;
            }
            if (isset($fixedSubjectByMessage[(int)$row['id']]) && self::looksMojibake((string)($upd['email_subject'] ?? $row['email_subject']))) {
                $upd['email_subject'] = $fixedSubjectByMessage[(int)$row['id']];
            }
            if ($upd) { Db::update('requests', $upd, 'id=?', [$row['id']]); $fixed++; }

            if (isset($upd['email_subject'])) {
                foreach (Db::all("SELECT id, body FROM notifications WHERE ref_type='request' AND ref_id=?", [$row['id']]) as $n) {
                    if (self::looksMojibake((string)($n['body'] ?? ''))) {
                        Db::update('notifications', ['body' => $upd['email_subject']], 'id=?', [$n['id']]);
                    }
                }
            }
        }

        Logger::info('mail', "Кодировка писем перечитана: исправлено $fixed из $checked");
        return ['checked' => $checked, 'fixed' => $fixed];
    }

    /** A string full of «�» or «?» in place of letters — data already lost, not just mis-tagged. */
    private static function looksMojibake(string $s): bool {
        if ($s === '') return false;
        if (str_contains($s, "\u{FFFD}")) return true;
        $len = mb_strlen($s);
        if ($len < 4) return false;
        return (substr_count($s, '?') / $len) > 0.25;
    }

    /** Subject/From-display-name re-decoded straight from the message's own raw headers. */
    private static function headerDerived(string $rawHeaders, string $field): ?string {
        if ($rawHeaders === '') return null;
        $header = $field === 'subject' ? 'Subject' : ($field === 'from_name' ? 'From' : '');
        if ($header === '') return null;

        $raw = EmailReader::headerValue($rawHeaders, $header);
        if ($raw === '') return null;
        $decoded = trim(EmailReader::decodeMime($raw));
        if ($decoded === '') return null;

        if ($field === 'from_name') {
            // «Имя» <addr@host> — only the display name is what we store separately
            if (!preg_match('/^"?(.*?)"?\s*<[^>]*>\s*$/u', $decoded, $m)) return null;
            $decoded = trim($m[1]);
        }
        return $decoded !== '' ? $decoded : null;
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
        $box = Mailboxes::outgoing($o['mailbox_id'] ?? null, $o['manager_id'] ?? null);
        $cfg = $box ? Mailboxes::cfg($box) : self::legacyCfg();

        $to      = trim((string)($o['to'] ?? ''));
        $subject = (string)($o['subject'] ?? '');
        $html    = $o['html'] ?? ('<p>' . nl2br(htmlspecialchars((string)($o['text'] ?? ''))) . '</p>');
        $text    = $o['text'] ?? MailText::fromHtml((string)$html);
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

        // The copy goes into «Отправленные» BEFORE the row is archived, so the
        // archive can say whether it landed there — «все отправленные письма
        // должны быть видны в отправленных на почтовом сервере», and when they
        // are not, the manager has to hear about it instead of finding out weeks
        // later from a client.
        $sent = self::appendToSent($box, $cfg, $sender->lastRawMessage ?? '');

        $archiveId = MailArchive::storeOutgoing([
            'mailbox_id'      => $box['id'] ?? null,
            'sent_state'      => $sent['state'],
            'thread_key'      => $o['thread_key'] ?? null,
            'folder'          => $sent['folder'] ?: 'SENT',
            'message_id'      => $sender->lastMessageId,
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

        // Приложенные файлы видны и в отправленном письме — их можно открыть
        // и переслать, а не гадать, что именно ушло (модуль 039)
        MailArchive::storeOutgoingFiles((int)$archiveId, (array)($o['attachments'] ?? []), $o);

        Logger::info('mail', "Письмо отправлено: $to", ['subject' => $subject, 'mailbox_id' => $box['id'] ?? null,
            'archive_id' => $archiveId, 'sent_folder' => $sent['folder'], 'sent_state' => $sent['state']]);
        return [
            'archive_id'  => $archiveId,
            'mailbox_id'  => $box['id'] ?? null,
            'sent_state'  => $sent['state'],
            'sent_folder' => $sent['folder'],
            'sent_error'  => $sent['error'],
        ];
    }

    /**
     * Copy an outgoing message into the mailbox's IMAP «Отправленные».
     *
     * Failing quietly was the bug: the letter left, the copy did not, and the
     * folder in Yandex stayed empty while the archive claimed everything was
     * fine. The folder written in the settings is now checked against the
     * server's real list, the working name is remembered on the mailbox, and a
     * failure is an error in the log and a line in the archive row.
     *
     * @return array{state:string,folder:string,error:?string}
     */
    private static function appendToSent(?array $box, array $cfg, string $raw): array {
        if (!$box)  return ['state' => 'skipped', 'folder' => '', 'error' => 'нет настроенного ящика'];
        if ((string)Settings::get('MAIL_APPEND_SENT', 1) !== '1') {
            return ['state' => 'off', 'folder' => '', 'error' => null];
        }
        if ($raw === '')                return ['state' => 'skipped', 'folder' => '', 'error' => 'нет исходного письма'];
        if (!EmailReader::available())  return ['state' => 'failed', 'folder' => '', 'error' => 'на сервере нет расширения PHP imap'];

        $configured = (string)($box['imap_folder_sent'] ?? '');
        try {
            $reader = new EmailReader($cfg);
            $reader->connect($box['imap_folder_in'] ?: 'INBOX');
            $folder = $reader->findSentFolder($configured) ?? '';
            if ($folder === '') {
                $reader->close();
                throw new RuntimeException('на сервере не нашлась папка «Отправленные»');
            }
            $ok = $reader->appendSent($raw, $folder);
            $reader->close();

            if (!$ok) throw new RuntimeException('сервер не принял копию в «' . $folder . '»');

            // The name that worked is worth keeping: the next send skips the search
            if ($folder !== $configured) Mailboxes::rememberSentFolder((int)$box['id'], $folder, $configured);
            return ['state' => 'appended', 'folder' => $folder, 'error' => null];
        } catch (Throwable $e) {
            Logger::error('mail', 'Копия письма не попала в «Отправленные»: ' . $e->getMessage(),
                ['mailbox_id' => $box['id'], 'folder' => $configured]);
            Db::update('mailboxes', ['last_error' => 'Отправленные: ' . $e->getMessage()], 'id=?', [$box['id']]);
            return ['state' => 'failed', 'folder' => $configured, 'error' => $e->getMessage()];
        }
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
