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
require_once __DIR__ . '/triage.php';

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

    /**
     * $ocr=false during «скачать весь архив»: recognizing thousands of old scans
     * costs money, runs into the Vision rate limit and nobody asked for it —
     * the text layer of a PDF is still extracted.
     */
    private static function storeAttachments(int $mailMessageId, array $files, bool $ocr = true): array {
        $stored = [];
        foreach ($files as $file) {
            try {
                $stored[] = Attachments::store($file, ['mail_message_id' => $mailMessageId], ['ocr' => $ocr]);
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
                if (self::toRequest($row)) $count++;
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

    /** Returns true when the letter actually became a request. */
    private static function toRequest(array $row): bool {
        // Free verdict first: a Yandex.Direct digest or a MoySklad ticket is not a
        // request and must not cost a model call (module 006).
        $pre = Triage::prefilter($row);
        if ($pre) {
            Db::update('mail_messages', [
                'processed_at'  => date('Y-m-d H:i:s'),
                'category'      => $pre['category'],
                'triage_reason' => $pre['reason'],
                'error'         => null,
            ], 'id=?', [$row['id']]);
            return false;
        }

        $attachments = Db::all("SELECT * FROM attachments WHERE mail_message_id=?", [$row['id']]);
        $attachmentText = '';
        foreach ($attachments as $a) {
            if (!empty($a['extracted_text'])) {
                $attachmentText .= "--- Вложение: {$a['filename']} ---\n" . $a['extracted_text'] . "\n\n";
            }
        }

        // Nothing to read — a bare auto-reply, a picture-only newsletter. Archive it
        // and stop: asking the model to parse an empty letter only fills the log.
        if (mb_strlen(trim((string)$row['body_text'])) < 20 && trim($attachmentText) === '') {
            Db::update('mail_messages', [
                'processed_at'  => date('Y-m-d H:i:s'),
                'category'      => 'service',
                'triage_reason' => 'Пустое письмо — нечего разбирать',
                'error'         => null,
            ], 'id=?', [$row['id']]);
            return false;
        }

        $parsed = Triage::classify((string)$row['body_text'], $attachmentText);
        $category = (string)($parsed['category'] ?? 'other');
        $type = ($parsed['request_type'] ?? 'kp_request') === 'order' ? 'order' : 'kp_request';

        // A supplier pitch, a SEO mailing or a service notice the model recognised:
        // archive it under its category and stop — no request, no notification.
        if (!Triage::createsRequest($category)) {
            Db::update('mail_messages', [
                'processed_at'  => date('Y-m-d H:i:s'),
                'category'      => $category,
                'triage_reason' => (string)($parsed['category_reason'] ?? ''),
                'error'         => null,
            ], 'id=?', [$row['id']]);
            Logger::info('mail', "Письмо от {$row['from_email']} отнесено к «" . Triage::label($category) . "» — запрос не создаётся",
                         ['mail_message_id' => $row['id']]);
            return false;
        }

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
            'category'            => $category,
            'category_confidence' => (float)($parsed['category_confidence'] ?? 0),
            'category_reason'     => (string)($parsed['category_reason'] ?? ''),
            'category_source'     => (string)($parsed['category_source'] ?? 'llm'),
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
            'category'          => $category,
            'triage_reason'     => (string)($parsed['category_reason'] ?? ''),
            'request_id'        => $requestId,
            'counterparty_id'   => $counterpartyId,
            'correspondence_id' => $corrId,
            'error'             => null,
        ], 'id=?', [$row['id']]);

        self::autoDraft($row, $category, $counterpartyId, $attachmentText);

        $orgName = $parsed['org_name'] ?? null;
        $title = Triage::label($category) . ($orgName ? " от $orgName" : ' от ' . $row['from_email']);
        Notifier::notify('new_request', $title, $row['subject'], 'request', $requestId);
        return true;
    }

    /**
     * Draft the answer right at sync time when TRIAGE_AUTO_DRAFT is on. Off by
     * default: until the manager trusts the categories, every letter should not
     * cost a second model call. A failure here must never lose the request.
     */
    private static function autoDraft(array $row, string $category, ?int $counterpartyId, string $attachmentText): void {
        if ((int)Settings::get('TRIAGE_AUTO_DRAFT', 0) !== 1) return;
        if (Triage::route($category)[0] === null) return;
        try {
            $text = Triage::draft($row, $category, [
                'org_name'        => $counterpartyId ? (string)Db::val("SELECT name FROM counterparties WHERE id=?", [$counterpartyId]) : '',
                'attachments'     => $attachmentText,
                'counterparty_id' => $counterpartyId,
                'email_rules'     => (string)(Db::val("SELECT content FROM email_rules ORDER BY id DESC LIMIT 1") ?: ''),
                'tov'             => is_file(ROOT . '/reference/tov.md') ? (string)file_get_contents(ROOT . '/reference/tov.md') : '',
            ]);
            Db::update('mail_messages', ['draft_text' => $text, 'draft_at' => date('Y-m-d H:i:s')], 'id=?', [$row['id']]);
        } catch (Throwable $e) {
            Logger::exception('mail', $e, ['mail_message_id' => $row['id'], 'stage' => 'auto_draft']);
        }
    }

    // ---- Spam ----

    /**
     * The manager pressed «Спам» on a letter. Filed as spam here (so the
     * request pipeline stops treating it as one), moved into the mailbox's own
     * Spam/Junk folder (so the server itself — and every other client on the
     * account — agrees it is spam, not just our database), and the sender is
     * remembered so the next letter from them is never even offered as a request.
     */
    public static function markAsSpam(int $mailMessageId): array {
        $row = Db::one("SELECT * FROM mail_messages WHERE id=?", [$mailMessageId]);
        if (!$row) throw new RuntimeException('Письмо не найдено');

        Db::update('mail_messages', [
            'category'      => 'spam',
            'triage_reason' => 'Отмечено спамом вручную',
        ], 'id=?', [$mailMessageId]);
        if (!empty($row['request_id'])) {
            Db::update('requests', [
                'category'        => 'spam',
                'category_source' => 'manager',
            ], 'id=?', [(int)$row['request_id']]);
        }

        $moved = false;
        $moveError = null;
        if ($row['direction'] === 'in' && (int)$row['uid'] > 0 && !empty($row['mailbox_id']) && EmailReader::available()) {
            $box = Mailboxes::get((int)$row['mailbox_id']);
            if ($box) {
                try {
                    $reader = new EmailReader(Mailboxes::cfg($box));
                    $reader->connect((string)($row['folder'] ?: 'INBOX'));
                    $junk = $reader->findJunkFolder();
                    if ($junk !== null) {
                        $moved = $reader->moveToJunk((int)$row['uid'], $junk);
                    }
                    $reader->close();
                } catch (Throwable $e) {
                    $moveError = $e->getMessage();
                    Logger::exception('mail', $e, ['mail_message_id' => $mailMessageId, 'stage' => 'mark_spam']);
                }
            }
        }

        $from = mb_strtolower(trim((string)$row['from_email']));
        if ($from !== '') {
            $list = array_values(array_filter(array_map('trim', explode(',', (string)Settings::get('TRIAGE_SPAM_SENDERS', '')))));
            $already = array_map('mb_strtolower', $list);
            if (!in_array($from, $already, true)) {
                $list[] = $from;
                Settings::set('TRIAGE_SPAM_SENDERS', implode(', ', $list));
            }
        }

        Logger::info('mail', "Письмо #$mailMessageId отмечено как спам" . ($moved ? ' и перемещено в папку спама на сервере' : ''),
            ['mail_message_id' => $mailMessageId, 'moved' => $moved, 'move_error' => $moveError]);

        return ['moved' => $moved, 'move_error' => $moveError];
    }

    // ---- Full archive download (FR-053) ----

    /** Folders a full download walks: column prefix => [folder name, direction]. */
    private static function backfillFolders(array $box): array {
        $folders = ['in' => [(string)($box['imap_folder_in'] ?: 'INBOX'), 'in']];
        $sent = trim((string)($box['imap_folder_sent'] ?? ''));
        if ($sent !== '' && !empty($box['sync_sent'])) $folders['sent'] = [$sent, 'out'];
        return $folders;
    }

    /**
     * Pull the WHOLE history of a mailbox into the archive, not just what arrived
     * since the last check. Resumable by design: a shared host kills a long request,
     * so every batch stores its cursor and the next call carries on from there.
     * Nothing here creates requests — old mail is history, not a new КП.
     */
    public static function backfill(array $box, ?int $budgetSeconds = null, ?int $batch = null): array {
        $box = Mailboxes::get((int)$box['id']) ?: $box;
        $res = ['mailbox_id' => (int)$box['id'], 'name' => $box['name'], 'stored' => 0, 'scanned' => 0, 'error' => null];
        if (!EmailReader::available()) {
            $res['error'] = 'Расширение PHP imap не установлено на сервере';
            return $res + ['done' => false, 'percent' => 0, 'progress' => Mailboxes::backfillProgress($box)];
        }

        $deadline = microtime(true) + max(5, $budgetSeconds ?? (int)Settings::get('MAIL_BACKFILL_SECONDS', 20));
        $left     = max(1, $batch ?? (int)Settings::get('MAIL_BACKFILL_BATCH', 100));
        if (empty($box['backfill_started_at'])) {
            Db::update('mailboxes', ['backfill_started_at' => date('Y-m-d H:i:s'), 'backfill_finished_at' => null], 'id=?', [$box['id']]);
            $box['backfill_started_at'] = date('Y-m-d H:i:s');
        }

        try {
            foreach (self::backfillFolders($box) as $key => [$folder, $direction]) {
                if (!empty($box["backfill_done_$key"])) continue;
                if ($left <= 0 || microtime(true) >= $deadline) break;
                $one = self::backfillFolder($box, $key, $folder, $direction, $left, $deadline);
                $res['stored']  += $one['stored'];
                $res['scanned'] += $one['scanned'];
                $left -= $one['scanned'];
                $box = Mailboxes::get((int)$box['id']) ?: $box;   // cursors moved
            }
            Db::update('mailboxes', ['last_error' => null], 'id=?', [$box['id']]);
        } catch (Throwable $e) {
            $res['error'] = $e->getMessage();
            Db::update('mailboxes', ['last_error' => 'Архив: ' . $e->getMessage()], 'id=?', [$box['id']]);
            Logger::exception('mail', $e, ['mailbox_id' => $box['id'], 'stage' => 'backfill']);
            $box = Mailboxes::get((int)$box['id']) ?: $box;
        }

        $progress = Mailboxes::backfillProgress($box);
        if ($progress['done'] && empty($box['backfill_finished_at'])) {
            Db::update('mailboxes', ['backfill_finished_at' => date('Y-m-d H:i:s')], 'id=?', [$box['id']]);
            $progress['finished_at'] = date('Y-m-d H:i:s');
            Logger::info('mail', "Ящик «{$box['name']}»: архив писем скачан полностью", ['mailbox_id' => $box['id']]);
        }
        return $res + ['done' => $progress['done'], 'percent' => $progress['percent'], 'progress' => $progress];
    }

    /** One folder, one batch: walk UIDs upwards from the stored cursor. */
    private static function backfillFolder(array $box, string $key, string $folder, string $direction, int $limit, float $deadline): array {
        $cursorCol = "backfill_uid_$key";
        $maxCol    = "backfill_max_$key";
        $doneCol   = "backfill_done_$key";
        $uidCol    = $key === 'in' ? 'last_uid_in' : 'last_uid_sent';

        $reader = new EmailReader(Mailboxes::cfg($box));
        $reader->connect($folder);
        try {
            return self::backfillWalk($box, $key, $folder, $direction, $limit, $deadline, $reader);
        } finally {
            $reader->close();
        }
    }

    /**
     * The walk itself, split off from the connection so it can be exercised without
     * an IMAP server. $reader only has to answer maxUid / uidsInRange / fetchUid.
     */
    private static function backfillWalk(array $box, string $key, string $folder, string $direction, int $limit, float $deadline, $reader): array {
        $cursorCol = "backfill_uid_$key";
        $maxCol    = "backfill_max_$key";
        $doneCol   = "backfill_done_$key";
        $uidCol    = $key === 'in' ? 'last_uid_in' : 'last_uid_sent';

        $maxUid = $reader->maxUid();
        $cursor = (int)($box[$cursorCol] ?? 0);
        Db::update('mailboxes', [$maxCol => $maxUid], 'id=?', [$box['id']]);

        $stored = 0;
        $scanned = 0;
        // UIDs are sparse — deleted mail leaves holes, so we probe by windows
        $window = 500;
        while ($cursor < $maxUid && $scanned < $limit && microtime(true) < $deadline) {
            $to   = (int)min($maxUid, $cursor + $window);
            $uids = $reader->uidsInRange($cursor + 1, $to);
            foreach ($uids as $uid) {
                if ($scanned >= $limit || microtime(true) >= $deadline) break;
                $scanned++;
                $cursor = $uid;
                $msg = $reader->fetchUid($uid);
                if (!$msg) continue;
                $id = MailArchive::storeIncoming($box, $msg, $direction, true);
                if (!$id) continue;
                $stored++;
                self::storeAttachments($id, $msg['attachments'] ?? [], false);
            }
            // The window held nothing (or was fully consumed) — jump past it
            if (!$uids || $cursor >= end($uids)) $cursor = max($cursor, min($to, $maxUid));
        }

        $done = $cursor >= $maxUid;
        $update = [$cursorCol => $cursor, $doneCol => $done ? 1 : 0];
        // The regular sync may now start from where the archive ends — no re-reading
        if ($done && $cursor > (int)($box[$uidCol] ?? 0)) $update[$uidCol] = $cursor;
        Db::update('mailboxes', $update, 'id=?', [$box['id']]);

        if ($stored) Logger::info('mail', "Ящик «{$box['name']}»: архив $folder — загружено $stored", ['mailbox_id' => $box['id']]);
        return ['stored' => $stored, 'scanned' => $scanned, 'done' => $done];
    }

    /** Start the download over — after «Забрать заново» or a changed folder. */
    public static function backfillReset(int $mailboxId): void {
        Db::update('mailboxes', [
            'backfill_uid_in' => 0, 'backfill_uid_sent' => 0,
            'backfill_done_in' => 0, 'backfill_done_sent' => 0,
            'backfill_max_in' => 0, 'backfill_max_sent' => 0,
            'backfill_started_at' => null, 'backfill_finished_at' => null,
        ], 'id=?', [$mailboxId]);
        Logger::info('mail', 'Скачивание архива начато заново', ['mailbox_id' => $mailboxId]);
    }

    /** Connection check for the admin panel: opens the folders and counts messages. */
    public static function testImap(array $box): array {
        $reader = new EmailReader(Mailboxes::cfg($box));
        $reader->connect((string)($box['imap_folder_in'] ?: 'INBOX'));
        $out = [
            'ok'      => true,
            'folder'  => $box['imap_folder_in'] ?: 'INBOX',
            'count'   => $reader->messageCount(),
            'total'   => $reader->maxUid(),
            'folders' => $reader->folders(),
        ];
        $reader->close();
        return $out;
    }
}
