<?php
/**
 * Mail threads (module 010).
 *
 * Letters are forwarded to two mailboxes at once — Яндекс and Gmail — and an
 * answer may leave from either of them. Grouped by mailbox, the same
 * conversation was three separate lists and nobody could tell whether a client
 * had already been answered.
 *
 * A thread is therefore keyed by what the client actually sees: **the subject
 * without its «Re:»/«Fwd:» prefixes**. That is the rule the manager asked for,
 * and it is the only one that joins a Gmail reply to a Yandex original when the
 * two mailboxes share no Message-ID history at all. `In-Reply-To` is used as a
 * second chance for letters whose subject is empty or was rewritten.
 */
final class MailThreads {

    /**
     * Reply and forward prefixes, in the forms these mailboxes actually send:
     * `Re:`, `RE[2]:`, `Fwd:`, `Ответ:`, `Пересылка:` and a `[list]` tag.
     */
    private const PREFIX = '/^\s*(?:\[[^\]]{1,40}\]\s*|(?:re|res|rif|aw|antw|sv|vs|fw|fwd|отв|ответ|пересылка|перенаправлено)\s*(?:\[\d+\])?\s*:\s*)+/iu';

    /** Subject as the eye reads it: no prefixes, no doubled spaces, no case. */
    public static function normalizeSubject(string $subject): string {
        $s = utf8Text(trim($subject));
        // A subject can carry several prefixes at once («Re: Fwd: Re: КП»)
        $prev = null;
        while ($prev !== $s) {
            $prev = $s;
            $s = (string)preg_replace(self::PREFIX, '', $s);
        }
        $s = (string)preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** The subject a thread is shown under — the cleaned one, original case. */
    public static function displaySubject(string $subject): string {
        $s = self::normalizeSubject($subject);
        return $s !== '' ? $s : 'Без темы';
    }

    /**
     * Thread key of a message row. Same subject → same key, in every mailbox.
     * A letter with no subject cannot be grouped by one, so it follows its
     * `In-Reply-To` when that message is already archived, and otherwise stands
     * alone under its own Message-ID.
     */
    public static function keyFor(array $row): string {
        $norm = self::normalizeSubject((string)($row['subject'] ?? ''));
        if ($norm !== '') return 's:' . md5(mb_strtolower($norm));

        $parent = trim((string)($row['in_reply_to'] ?? ''));
        if ($parent !== '') {
            $key = Db::val("SELECT thread_key FROM mail_messages WHERE message_id=? AND thread_key IS NOT NULL LIMIT 1", [$parent]);
            if ($key) return (string)$key;
            return 'm:' . md5($parent);
        }

        $mid = trim((string)($row['message_id'] ?? ''));
        return 'm:' . md5($mid !== '' ? $mid : ((string)($row['from_email'] ?? '') . '|' . (string)($row['date_at'] ?? '')));
    }

    /** Stamp one archived row with its thread. Called right after every insert. */
    public static function assign(int $messageId): ?string {
        $row = Db::one("SELECT id, subject, message_id, in_reply_to, from_email, date_at FROM mail_messages WHERE id=?", [$messageId]);
        if (!$row) return null;
        $key = self::keyFor($row);
        Db::update('mail_messages', [
            'thread_key'     => $key,
            'thread_subject' => self::displaySubject((string)$row['subject']),
        ], 'id=?', [$messageId]);
        return $key;
    }

    /**
     * Give every archived letter a thread key. Runs once in the migration and
     * again from «Настройки → Почта» when a subject was repaired afterwards.
     */
    public static function backfill(bool $all = false): int {
        $where = $all ? '1=1' : "(thread_key IS NULL OR thread_key = '')";
        $rows = Db::all("SELECT id, subject, message_id, in_reply_to, from_email, date_at
                         FROM mail_messages WHERE $where ORDER BY date_at, id");
        $n = 0;
        foreach ($rows as $row) {
            Db::update('mail_messages', [
                'thread_key'     => self::keyFor($row),
                'thread_subject' => self::displaySubject((string)$row['subject']),
            ], 'id=?', [$row['id']]);
            $n++;
        }
        return $n;
    }

    /**
     * The list the mail page shows: one row per conversation, newest first,
     * with the count of letters in it and who took part — the shape Gmail
     * renders as «Тема · 4».
     *
     * @param array{mailbox_id?:int,direction?:string,unread?:bool,q?:string,category?:string,limit?:int,offset?:int} $f
     */
    public static function query(array $f = []): array {
        $where  = ['m.thread_key IS NOT NULL'];
        $params = [];

        // A filter narrows WHICH THREADS are shown, never which letters are in
        // one: a thread with an unread letter must open with its whole history.
        if (!empty($f['mailbox_id'])) { $where[] = 'm.mailbox_id = ?'; $params[] = (int)$f['mailbox_id']; }
        if (!empty($f['direction']) && in_array($f['direction'], ['in', 'out'], true)) {
            $where[] = 'm.direction = ?'; $params[] = $f['direction'];
        }
        if (!empty($f['counterparty_id'])) { $where[] = 'm.counterparty_id = ?'; $params[] = (int)$f['counterparty_id']; }
        if (!empty($f['unread'])) $where[] = "(m.is_read = 0 AND m.direction = 'in')";
        if (!empty($f['category'])) { $where[] = 'm.category = ?'; $params[] = (string)$f['category']; }
        if (!empty($f['q'])) {
            $where[] = '(m.subject LIKE ? OR m.from_email LIKE ? OR m.to_emails LIKE ? OR m.body_text LIKE ?)';
            $like = '%' . $f['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);

        $limit  = min(200, max(1, (int)($f['limit'] ?? 50)));
        $offset = max(0, (int)($f['offset'] ?? 0));

        $keys = Db::all(
            "SELECT m.thread_key, MAX(m.date_at) AS last_at
             FROM mail_messages m WHERE $sqlWhere
             GROUP BY m.thread_key ORDER BY last_at DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );
        $total = (int)Db::val("SELECT COUNT(*) FROM (SELECT m.thread_key FROM mail_messages m
                               WHERE $sqlWhere GROUP BY m.thread_key)", $params);

        $items = [];
        foreach ($keys as $k) {
            $t = self::summary((string)$k['thread_key']);
            if ($t) $items[] = $t;
        }
        return [
            'items'  => $items,
            'total'  => $total,
            'unread' => (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE direction='in' AND is_read=0"),
        ];
    }

    /** One row of the thread list — everything the list needs, nothing more. */
    public static function summary(string $key): ?array {
        $agg = Db::one(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN direction='in'  THEN 1 ELSE 0 END) AS in_count,
                    SUM(CASE WHEN direction='out' THEN 1 ELSE 0 END) AS out_count,
                    SUM(CASE WHEN direction='in' AND is_read=0 THEN 1 ELSE 0 END) AS unread,
                    SUM(has_attachment) AS files,
                    MIN(date_at) AS first_at, MAX(date_at) AS last_at
             FROM mail_messages WHERE thread_key=?", [$key]
        );
        if (!$agg || !(int)$agg['total']) return null;

        $last = Db::one(
            "SELECT m.*, b.name AS mailbox_name, c.name AS counterparty_name
             FROM mail_messages m
             LEFT JOIN mailboxes b ON b.id = m.mailbox_id
             LEFT JOIN counterparties c ON c.id = m.counterparty_id
             WHERE m.thread_key=? ORDER BY m.date_at DESC, m.id DESC LIMIT 1", [$key]
        );

        // Which mailboxes this conversation lives in — «яндекс + gmail» is the
        // whole reason the thread exists, so the list says it out loud
        $boxes = Db::all(
            "SELECT DISTINCT b.id, b.name, b.email FROM mail_messages m
             JOIN mailboxes b ON b.id = m.mailbox_id WHERE m.thread_key=?", [$key]
        );

        return [
            'thread_key'      => $key,
            'subject'         => $last['thread_subject'] ?: self::displaySubject((string)$last['subject']),
            'raw_subject'     => (string)$last['subject'],
            'count'           => (int)$agg['total'],
            'in_count'        => (int)$agg['in_count'],
            'out_count'       => (int)$agg['out_count'],
            'unread'          => (int)$agg['unread'],
            'has_attachment'  => (int)$agg['files'] > 0,
            'first_at'        => $agg['first_at'],
            'last_at'         => $agg['last_at'],
            'last_direction'  => $last['direction'],
            'last_from'       => $last['from_email'],
            'last_to'         => $last['to_emails'],
            'last_id'         => (int)$last['id'],
            'preview'         => mb_substr(trim((string)$last['body_text']), 0, 160),
            'mailbox_id'      => $last['mailbox_id'] !== null ? (int)$last['mailbox_id'] : null,
            'mailbox_name'    => $last['mailbox_name'],
            'mailboxes'       => $boxes,
            'counterparty_id' => $last['counterparty_id'] ? (int)$last['counterparty_id'] : null,
            'counterparty_name' => $last['counterparty_name'],
            'request_id'      => $last['request_id'] ? (int)$last['request_id'] : null,
            'category'        => $last['category'] ?? null,
            'participants'    => self::participants($key),
        ];
    }

    /** Everyone who wrote in the thread, in the order they first appear. */
    private static function participants(string $key): array {
        $rows = Db::all("SELECT DISTINCT from_email, from_name FROM mail_messages
                         WHERE thread_key=? AND from_email <> '' ORDER BY date_at", [$key]);
        return array_values(array_filter(array_map(
            fn($r) => trim((string)$r['from_email']),
            $rows
        )));
    }

    /**
     * The whole conversation, oldest first, whichever mailbox each letter is in.
     * This is what makes a Gmail answer visible in the Yandex thread.
     */
    public static function messages(string $key): array {
        $rows = Db::all(
            "SELECT m.id, m.mailbox_id, m.direction, m.folder, m.subject, m.thread_subject,
                    m.from_email, m.from_name, m.to_emails, m.cc_emails, m.body_text, m.body_html,
                    m.has_attachment, m.is_read, m.date_at, m.request_id, m.counterparty_id,
                    m.category, m.error, m.sent_state, m.message_id,
                    b.name AS mailbox_name, b.email AS mailbox_email, c.name AS counterparty_name,
                    g.name AS manager_name
             FROM mail_messages m
             LEFT JOIN mailboxes b ON b.id = m.mailbox_id
             LEFT JOIN counterparties c ON c.id = m.counterparty_id
             LEFT JOIN managers g ON g.id = m.manager_id
             WHERE m.thread_key=? ORDER BY m.date_at, m.id", [$key]
        );
        foreach ($rows as &$row) {
            $row['attachments'] = $row['has_attachment']
                ? Db::all("SELECT id, filename, size, mime FROM attachments WHERE mail_message_id=?", [$row['id']])
                : [];
        }
        return $rows;
    }

    /** Mark every inbound letter of a thread read — opening it is reading it. */
    public static function markRead(string $key): void {
        Db::q("UPDATE mail_messages SET is_read=1 WHERE thread_key=? AND direction='in'", [$key]);
    }

    /**
     * Where a reply should go from. The mailbox that carried the last letter of
     * the thread keeps the conversation in the client's inbox where it started —
     * answering a Gmail-forwarded letter from Yandex is what split threads in
     * the first place.
     */
    public static function replyContext(string $key): array {
        $last = Db::one(
            "SELECT * FROM mail_messages WHERE thread_key=? ORDER BY date_at DESC, id DESC LIMIT 1", [$key]
        );
        if (!$last) return [];

        // Answer the last INCOMING letter; if there is none, answer ourselves
        $lastIn = Db::one(
            "SELECT * FROM mail_messages WHERE thread_key=? AND direction='in' ORDER BY date_at DESC, id DESC LIMIT 1", [$key]
        ) ?: $last;

        return [
            'reply_to_id'     => (int)$lastIn['id'],
            'to'              => $lastIn['direction'] === 'in' ? (string)$lastIn['from_email'] : (string)$lastIn['to_emails'],
            'subject'         => 'Re: ' . self::displaySubject((string)$lastIn['subject']),
            'mailbox_id'      => $last['mailbox_id'] !== null ? (int)$last['mailbox_id'] : null,
            'counterparty_id' => $lastIn['counterparty_id'] ? (int)$lastIn['counterparty_id'] : null,
            'request_id'      => $lastIn['request_id'] ? (int)$lastIn['request_id'] : null,
            'category'        => $lastIn['category'] ?? null,
        ];
    }
}
