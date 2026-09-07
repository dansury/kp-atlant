<?php
/**
 * The board (modules 010 + 011) — one kanban where the whole office works.
 *
 * A card is a COMPANY, not a letter and not a request: opening it shows every
 * conversation that company ever sent, the requests those letters became and
 * the КП that answered them. Companies, requests and mail were three lists a
 * manager had to cross-check by hand; here they are one object that moves
 * between columns.
 *
 * Nothing is put on the board by hand any more: `sync()` drops every company
 * that wrote to us into «Входящие», and a sender we do not know yet lands there
 * as a conversation card until a company card is resolved for it.
 */
require_once __DIR__ . '/mail_threads.php';
require_once __DIR__ . '/crm.php';

final class Boards {

    /** A first board that already makes sense for a КП pipeline. */
    private const DEFAULT_COLUMNS = [
        ['Входящие',      '#6b7fd7', 'inbox'],
        ['В работе',      '#e0a53c', null],
        ['КП отправлено', '#4f9e57', null],
        ['Ждём оплату',   '#b45cc0', null],
        ['Закрыто',       '#8a8f98', null],
    ];

    /** Letters of these categories never make a card of their own. */
    private const IGNORED_CATEGORIES = ['spam', 'service'];

    /** Never flood the board on the first run over a large archive. */
    private const SYNC_LIMIT = 400;

    public static function all(): array {
        $rows = Db::all("SELECT b.*, (SELECT COUNT(*) FROM board_columns c
                                      JOIN board_cards d ON d.column_id = c.id
                                      WHERE c.board_id = b.id) AS cards
                         FROM boards b ORDER BY b.position, b.id");
        // «Письма» (item 2) treats the board as always there — one letter
        // program, not a list you might find empty. An account with none yet
        // gets the usual КП pipeline columns instead of a dead end.
        if (!$rows) {
            self::createBoard('Письма');
            return self::all();
        }
        foreach ($rows as &$r) $r['cards'] = (int)$r['cards'];
        return $rows;
    }

    /** The single board this office works on — always exists (see all()). */
    public static function singleton(): array {
        return self::all()[0];
    }

    /**
     * The column new mail falls into. Named, not «the leftmost one», so
     * reordering the columns cannot silently redirect the intake.
     */
    public static function inboxColumn(int $boardId): ?array {
        return Db::one("SELECT * FROM board_columns WHERE board_id=? AND kind='inbox' ORDER BY position, id LIMIT 1", [$boardId])
            ?: Db::one("SELECT * FROM board_columns WHERE board_id=? ORDER BY position, id LIMIT 1", [$boardId]);
    }

    /** The board with its columns and cards — one request paints the whole page. */
    public static function get(int $id): ?array {
        $board = Db::one("SELECT * FROM boards WHERE id=?", [$id]);
        if (!$board) return null;

        $columns = Db::all("SELECT * FROM board_columns WHERE board_id=? ORDER BY position, id", [$id]);
        // Every card of the board is decorated in one batch — the counters of a
        // company are two queries for the whole board, not two per card
        $cards = Db::all(
            "SELECT d.*, g.name AS manager_name FROM board_cards d
             JOIN board_columns c ON c.id = d.column_id
             LEFT JOIN managers g ON g.id = d.manager_id
             WHERE c.board_id=? ORDER BY d.position, d.id", [$id]
        );
        self::decorateAll($cards);

        $byColumn = [];
        foreach ($cards as $card) $byColumn[(int)$card['column_id']][] = $card;
        foreach ($columns as &$col) {
            $col['id'] = (int)$col['id'];
            $col['cards'] = self::sortCards($byColumn[$col['id']] ?? []);
        }
        unset($col);

        return $board + ['columns' => $columns];
    }

    /**
     * A card with fresh mail belongs at the top of its column, whatever column
     * the manager dragged it into — «пришло новое письмо» is the one thing that
     * must never be scrolled to. Everything else keeps the order it was dragged
     * into, so the board a manager arranged still looks arranged.
     */
    private static function sortCards(array $cards): array {
        usort($cards, function ($a, $b) {
            $ha = !empty($a['hot']) ? 1 : 0;
            $hb = !empty($b['hot']) ? 1 : 0;
            if ($ha !== $hb) return $hb <=> $ha;
            if ($ha) return strcmp((string)($b['last_at'] ?? ''), (string)($a['last_at'] ?? ''));
            return [(int)$a['position'], (int)$a['id']] <=> [(int)$b['position'], (int)$b['id']];
        });
        return array_values($cards);
    }

    /**
     * Live state of what a card carries — for a company card that is its whole
     * mail history, not one thread: how many letters, how many unread, whether
     * the last word was theirs (an unanswered card is what a board is for).
     *
     * Written as batched queries on purpose: a board of two hundred companies
     * must not cost two hundred round trips per column.
     *
     * @param array<int,array> $cards passed by reference, decorated in place
     */
    private static function decorateAll(array &$cards): void {
        $cpIds = [];
        foreach ($cards as $c) if (!empty($c['counterparty_id'])) $cpIds[] = (int)$c['counterparty_id'];
        $cpIds = array_values(array_unique($cpIds));

        $stats = $cpIds ? self::companyStats($cpIds) : [];

        foreach ($cards as &$card) {
            $card['id'] = (int)$card['id'];
            $card['position'] = (int)$card['position'];
            $card['counterparty_id'] = $card['counterparty_id'] ? (int)$card['counterparty_id'] : null;
            $card['kind'] = $card['counterparty_id'] ? 'company' : ($card['thread_key'] ? 'thread' : 'note');
            $card['company'] = null;
            $card['thread'] = null;
            $card['unread'] = 0;
            $card['unanswered'] = false;
            $card['last_at'] = null;

            if ($card['counterparty_id'] && isset($stats[$card['counterparty_id']])) {
                $s = $stats[$card['counterparty_id']];
                $card['company'] = $s;
                $card['unread'] = $s['unread'];
                $card['unanswered'] = $s['unanswered'];
                $card['last_at'] = $s['last_at'];
                if (trim((string)$card['title']) === '' || $card['title'] === 'Карточка') $card['title'] = $s['name'];
            } elseif (!empty($card['thread_key'])) {
                $t = MailThreads::summary((string)$card['thread_key']);
                if ($t) {
                    $card['thread'] = [
                        'subject'   => $t['subject'],
                        'count'     => $t['count'],
                        'unread'    => $t['unread'],
                        'last_at'   => $t['last_at'],
                        'last_direction'    => $t['last_direction'],
                        'last_from'         => $t['last_from'],
                        'counterparty_id'   => $t['counterparty_id'],
                        'counterparty_name' => $t['counterparty_name'],
                        'mailboxes' => array_map(fn($b) => $b['name'], $t['mailboxes']),
                    ];
                    $card['unread'] = $t['unread'];
                    $card['unanswered'] = $t['last_direction'] === 'in';
                    $card['last_at'] = $t['last_at'];
                    if (trim((string)$card['title']) === '') $card['title'] = $t['subject'];
                }
            }
            // Bright and on top: a letter nobody has read, or one nobody has answered
            $card['hot'] = $card['unread'] > 0 || $card['unanswered'];
        }
        unset($card);
    }

    /**
     * Everything the board needs to know about a company, for many companies at
     * once: mail counters, who spoke last, open requests, the last КП.
     *
     * @param int[] $ids
     * @return array<int,array>
     */
    private static function companyStats(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));

        $out = [];
        foreach (Db::all("SELECT id, name, inn, contact_email, default_price_type FROM counterparties WHERE id IN ($in)", $ids) as $c) {
            $out[(int)$c['id']] = [
                'id'            => (int)$c['id'],
                'name'          => (string)$c['name'],
                'inn'           => $c['inn'],
                'contact_email' => $c['contact_email'],
                'letters'       => 0, 'unread' => 0, 'threads' => 0,
                'last_at'       => null, 'last_in_at' => null, 'last_out_at' => null,
                'unanswered'    => false, 'has_attachment' => false,
                'subject'       => null, 'preview' => null,
                'requests_open' => 0, 'proposal_status' => null, 'proposal_id' => null,
            ];
        }
        if (!$out) return [];

        // A merged card's letters belong to the company it was merged into
        $map = [];
        foreach ($ids as $id) $map[$id] = $id;
        foreach (Db::all("SELECT id, merged_into_id FROM counterparties WHERE merged_into_id IN ($in)", $ids) as $m) {
            $map[(int)$m['id']] = (int)$m['merged_into_id'];
        }
        $mailIds = array_keys($map);
        $mIn = implode(',', array_fill(0, count($mailIds), '?'));

        $rows = Db::all(
            "SELECT counterparty_id,
                    COUNT(*) AS letters,
                    COUNT(DISTINCT thread_key) AS threads,
                    SUM(CASE WHEN direction='in' AND is_read=0 THEN 1 ELSE 0 END) AS unread,
                    SUM(has_attachment) AS files,
                    MAX(date_at) AS last_at,
                    MAX(CASE WHEN direction='in'  THEN date_at END) AS last_in_at,
                    MAX(CASE WHEN direction='out' THEN date_at END) AS last_out_at
             FROM mail_messages WHERE counterparty_id IN ($mIn) GROUP BY counterparty_id", $mailIds
        );
        foreach ($rows as $r) {
            $id = $map[(int)$r['counterparty_id']] ?? null;
            if ($id === null || !isset($out[$id])) continue;
            $c = &$out[$id];
            $c['letters'] += (int)$r['letters'];
            $c['threads'] += (int)$r['threads'];
            $c['unread']  += (int)$r['unread'];
            $c['has_attachment'] = $c['has_attachment'] || (int)$r['files'] > 0;
            foreach (['last_at', 'last_in_at', 'last_out_at'] as $f) {
                if ($r[$f] && (string)$r[$f] > (string)$c[$f]) $c[$f] = $r[$f];
            }
            unset($c);
        }

        // The subject and the first line of the newest letter — a card should
        // say what the company wants without being opened
        foreach ($mailIds as $mid) {
            $root = $map[$mid];
            if (!isset($out[$root])) continue;
            $last = Db::one("SELECT thread_subject, subject, body_text, date_at FROM mail_messages
                             WHERE counterparty_id=? ORDER BY date_at DESC, id DESC LIMIT 1", [$mid]);
            if (!$last) continue;
            if ($out[$root]['subject'] === null || (string)$last['date_at'] >= (string)$out[$root]['last_at']) {
                $out[$root]['subject'] = $last['thread_subject'] ?: $last['subject'];
                $out[$root]['preview'] = mb_substr(trim((string)$last['body_text']), 0, 140);
            }
        }

        foreach (Db::all("SELECT counterparty_id, COUNT(*) AS n FROM requests
                          WHERE counterparty_id IN ($mIn) AND status NOT IN ('closed','ordered')
                          GROUP BY counterparty_id", $mailIds) as $r) {
            $id = $map[(int)$r['counterparty_id']] ?? null;
            if ($id !== null && isset($out[$id])) $out[$id]['requests_open'] += (int)$r['n'];
        }

        foreach (Db::all("SELECT p.counterparty_id, p.id, p.status, p.created_at FROM proposals p
                          WHERE p.counterparty_id IN ($mIn) ORDER BY p.created_at", $mailIds) as $p) {
            $id = $map[(int)$p['counterparty_id']] ?? null;
            if ($id !== null && isset($out[$id])) {
                $out[$id]['proposal_id'] = (int)$p['id'];
                $out[$id]['proposal_status'] = $p['status'];
            }
        }

        foreach ($out as &$c) {
            // They wrote last → the ball is ours. That is the whole «жирным
            // шрифтом / блёклым шрифтом» rule of the board, decided here once.
            $c['unanswered'] = $c['last_in_at'] !== null
                && ((string)$c['last_in_at'] > (string)$c['last_out_at']);
        }
        unset($c);
        return $out;
    }

    /**
     * Put on the board everything that has written to us and is not there yet.
     * Runs on every board open: «Входящие» is not a list somebody fills in, it
     * is the mail itself.
     *
     * A company gets ONE card however many conversations it has — that is the
     * merger of companies, requests and letters into a single object. A sender
     * we cannot resolve to a company keeps a conversation card until we can,
     * and that card is upgraded in place (staying in whatever column it was
     * dragged to) the moment the company is known.
     */
    public static function sync(?int $boardId = null, bool $force = false): array {
        if ((int)Settings::get('BOARD_AUTOLOAD', 1) !== 1) return ['created' => 0, 'upgraded' => 0];

        $boardId = $boardId ?: (int)self::singleton()['id'];
        $inbox = self::inboxColumn($boardId);
        if (!$inbox) return ['created' => 0, 'upgraded' => 0];

        // The board is opened all day and the archive only changes when mail
        // arrives or triage resolves a sender. Four counters say whether
        // anything did — cheaper than grouping the whole archive every time.
        $sig = fn() => implode(':', [
            $boardId,
            (string)Db::val("SELECT COALESCE(MAX(id), 0) FROM mail_messages"),
            (string)Db::val("SELECT COUNT(*) FROM mail_messages WHERE counterparty_id IS NOT NULL"),
            (string)Db::val("SELECT COALESCE(MAX(id), 0) FROM counterparties"),
            (string)Db::val("SELECT COUNT(*) FROM board_cards"),
        ]);
        $before = $sig();
        if (!$force && (string)Db::val("SELECT value FROM settings WHERE key='board_sync_sig'") === $before) {
            return ['created' => 0, 'upgraded' => 0, 'skipped' => true];
        }

        $days  = max(1, (int)Settings::get('BOARD_INBOX_DAYS', 180));
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $ignored = "'" . implode("','", self::IGNORED_CATEGORIES) . "'";

        $onBoard = Db::all("SELECT d.counterparty_id, d.thread_key FROM board_cards d
                            JOIN board_columns c ON c.id = d.column_id WHERE c.board_id=?", [$boardId]);
        $haveCp = $haveThread = [];
        foreach ($onBoard as $r) {
            if ($r['counterparty_id']) $haveCp[(int)$r['counterparty_id']] = true;
            if ($r['thread_key']) $haveThread[(string)$r['thread_key']] = true;
        }

        $created = $upgraded = 0;
        $pos = (int)Db::val("SELECT COALESCE(MAX(position), -1) + 1 FROM board_cards WHERE column_id=?", [$inbox['id']]);

        // 1. Companies that wrote (or were written to) inside the window
        $companies = Db::all(
            "SELECT COALESCE(c.merged_into_id, c.id) AS cp_id, MAX(m.date_at) AS last_at
             FROM mail_messages m JOIN counterparties c ON c.id = m.counterparty_id
             WHERE m.date_at >= ? AND (m.category IS NULL OR m.category NOT IN ($ignored))
             GROUP BY cp_id ORDER BY last_at DESC LIMIT ?", [$since, self::SYNC_LIMIT]
        );
        foreach ($companies as $row) {
            $cpId = (int)$row['cp_id'];
            if (isset($haveCp[$cpId])) continue;

            // Already on the board as an unresolved conversation? Keep the card
            // (and its column) and let it grow up into the company card.
            $existing = Db::one(
                "SELECT d.id FROM board_cards d JOIN board_columns col ON col.id = d.column_id
                 WHERE col.board_id=? AND d.counterparty_id IS NULL AND d.thread_key IS NOT NULL
                   AND d.thread_key IN (SELECT DISTINCT thread_key FROM mail_messages
                                        WHERE counterparty_id IN (SELECT id FROM counterparties WHERE id=? OR merged_into_id=?))
                 ORDER BY d.id LIMIT 1", [$boardId, $cpId, $cpId]
            );
            $name = (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$cpId]) ?: 'Компания');
            if ($existing) {
                Db::update('board_cards', ['counterparty_id' => $cpId, 'thread_key' => null, 'title' => $name],
                           'id=?', [$existing['id']]);
                $upgraded++;
            } else {
                Db::insert('board_cards', [
                    'column_id'       => (int)$inbox['id'],
                    'position'        => $pos++,
                    'counterparty_id' => $cpId,
                    'title'           => $name,
                    'moved_at'        => date('Y-m-d H:i:s'),
                ]);
                $created++;
            }
            $haveCp[$cpId] = true;
        }

        // 2. Letters from a sender we have not resolved to a company yet — they
        //    are on the board all the same, because «все письма подгружаются»
        $orphans = Db::all(
            "SELECT m.thread_key, MAX(m.date_at) AS last_at, MAX(COALESCE(m.counterparty_id, 0)) AS cp
             FROM mail_messages m
             WHERE m.thread_key IS NOT NULL AND m.direction='in' AND m.date_at >= ?
               AND (m.category IS NULL OR m.category NOT IN ($ignored))
             GROUP BY m.thread_key HAVING cp = 0
             ORDER BY last_at DESC LIMIT ?", [$since, self::SYNC_LIMIT]
        );
        foreach ($orphans as $row) {
            $key = (string)$row['thread_key'];
            if (isset($haveThread[$key])) continue;
            $t = MailThreads::summary($key);
            Db::insert('board_cards', [
                'column_id'  => (int)$inbox['id'],
                'position'   => $pos++,
                'thread_key' => $key,
                'title'      => $t['subject'] ?? 'Без темы',
                'moved_at'   => date('Y-m-d H:i:s'),
            ]);
            $haveThread[$key] = true;
            $created++;
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('board_sync_sig', ?)", [$sig()]);
        return ['created' => $created, 'upgraded' => $upgraded];
    }

    /** Create a board; a brand new one comes with the usual columns filled in. */
    public static function createBoard(string $name, bool $withColumns = true): int {
        $pos = (int)Db::val("SELECT COALESCE(MAX(position), 0) + 1 FROM boards");
        $id = Db::insert('boards', ['name' => trim($name) ?: 'Новая доска', 'position' => $pos]);
        if ($withColumns) {
            foreach (self::DEFAULT_COLUMNS as $i => [$title, $color, $kind]) {
                Db::insert('board_columns', ['board_id' => $id, 'title' => $title, 'color' => $color,
                                             'kind' => $kind, 'position' => $i]);
            }
        }
        return $id;
    }

    public static function renameBoard(int $id, string $name): void {
        Db::update('boards', ['name' => trim($name) ?: 'Доска'], 'id=?', [$id]);
    }

    public static function deleteBoard(int $id): void {
        // SQLite enforces ON DELETE CASCADE only with foreign_keys=ON, which
        // bootstrap sets — but a board is cheap to clean up explicitly
        Db::q("DELETE FROM board_cards WHERE column_id IN (SELECT id FROM board_columns WHERE board_id=?)", [$id]);
        Db::q("DELETE FROM board_columns WHERE board_id=?", [$id]);
        Db::q("DELETE FROM boards WHERE id=?", [$id]);
    }

    public static function saveColumn(int $boardId, ?int $columnId, string $title, ?string $color, ?string $kind = null): int {
        if ($columnId) {
            $data = array_filter(['title' => trim($title) ?: 'Колонка', 'color' => $color], fn($v) => $v !== null);
            // Exactly one intake column per board, or new mail would double up
            if ($kind === 'inbox') {
                Db::q("UPDATE board_columns SET kind=NULL WHERE board_id=? AND id<>?", [$boardId, $columnId]);
                $data['kind'] = 'inbox';
            }
            Db::update('board_columns', $data, 'id=? AND board_id=?', [$columnId, $boardId]);
            return $columnId;
        }
        $pos = (int)Db::val("SELECT COALESCE(MAX(position), -1) + 1 FROM board_columns WHERE board_id=?", [$boardId]);
        if ($kind === 'inbox') Db::q("UPDATE board_columns SET kind=NULL WHERE board_id=?", [$boardId]);
        return Db::insert('board_columns', [
            'board_id' => $boardId,
            'title'    => trim($title) ?: 'Колонка',
            'color'    => $color ?: '#8a8f98',
            'kind'     => $kind === 'inbox' ? 'inbox' : null,
            'position' => $pos,
        ]);
    }

    public static function deleteColumn(int $columnId): void {
        $col = Db::one("SELECT * FROM board_columns WHERE id=?", [$columnId]);
        if (!$col) return;
        // Cards are never destroyed with the column: they go back to «Входящие»,
        // because a deleted column must not delete a client's correspondence
        $inbox = self::inboxColumn((int)$col['board_id']);
        if ($inbox && (int)$inbox['id'] !== $columnId) {
            Db::q("UPDATE board_cards SET column_id=? WHERE column_id=?", [$inbox['id'], $columnId]);
        } else {
            Db::q("DELETE FROM board_cards WHERE column_id=?", [$columnId]);
        }
        Db::q("DELETE FROM board_columns WHERE id=?", [$columnId]);
    }

    /** Column order after a drag of the column headers. */
    public static function reorderColumns(int $boardId, array $columnIds): void {
        foreach (array_values($columnIds) as $i => $cid) {
            Db::update('board_columns', ['position' => $i], 'id=? AND board_id=?', [(int)$cid, $boardId]);
        }
    }

    /**
     * Put a company (or a still-unresolved conversation) on a board. The same
     * company is never added twice to one board — it is moved to the asked-for
     * column instead, because a company that exists in two columns is exactly
     * the confusion a board is supposed to remove.
     */
    public static function addCard(int $columnId, array $o): int {
        $col = Db::one("SELECT * FROM board_columns WHERE id=?", [$columnId]);
        if (!$col) throw new RuntimeException('Колонка не найдена');

        $cpId      = !empty($o['counterparty_id']) ? (int)$o['counterparty_id'] : null;
        $threadKey = trim((string)($o['thread_key'] ?? ''));

        // A conversation whose company is known belongs to the company card
        if (!$cpId && $threadKey !== '') {
            $known = Db::val("SELECT counterparty_id FROM mail_messages
                              WHERE thread_key=? AND counterparty_id IS NOT NULL ORDER BY date_at DESC LIMIT 1", [$threadKey]);
            if ($known) { $cpId = (int)Crm::rootId((int)$known); $threadKey = ''; }
        }

        $existing = null;
        if ($cpId) {
            $existing = Db::one("SELECT d.id FROM board_cards d JOIN board_columns c ON c.id = d.column_id
                                 WHERE c.board_id=? AND d.counterparty_id=?", [(int)$col['board_id'], $cpId]);
        } elseif ($threadKey !== '') {
            $existing = Db::one("SELECT d.id FROM board_cards d JOIN board_columns c ON c.id = d.column_id
                                 WHERE c.board_id=? AND d.thread_key=?", [(int)$col['board_id'], $threadKey]);
        }
        if ($existing) {
            self::moveCard((int)$existing['id'], $columnId, 0);
            return (int)$existing['id'];
        }

        $title = trim((string)($o['title'] ?? ''));
        if ($title === '' && $cpId) {
            $title = (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$cpId]) ?: '');
        }
        if ($title === '' && $threadKey !== '') {
            $t = MailThreads::summary($threadKey);
            $title = $t['subject'] ?? '';
        }

        // New cards land on top: what was just added is what is being worked on
        Db::q("UPDATE board_cards SET position = position + 1 WHERE column_id=?", [$columnId]);
        return Db::insert('board_cards', [
            'column_id'       => $columnId,
            'position'        => 0,
            'counterparty_id' => $cpId,
            'thread_key'      => $threadKey ?: null,
            'mail_message_id' => !empty($o['mail_message_id']) ? (int)$o['mail_message_id'] : null,
            'request_id'      => !empty($o['request_id']) ? (int)$o['request_id'] : null,
            'title'           => $title ?: 'Карточка',
            'note'            => trim((string)($o['note'] ?? '')) ?: null,
            'manager_id'      => !empty($o['manager_id']) ? (int)$o['manager_id'] : null,
            'moved_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    /** Drop a card at a position inside a column — the whole point of the board. */
    public static function moveCard(int $cardId, int $columnId, int $position): void {
        $card = Db::one("SELECT * FROM board_cards WHERE id=?", [$cardId]);
        if (!$card) throw new RuntimeException('Карточка не найдена');
        if (!Db::one("SELECT id FROM board_columns WHERE id=?", [$columnId])) {
            throw new RuntimeException('Колонка не найдена');
        }

        $siblings = Db::all("SELECT id FROM board_cards WHERE column_id=? AND id<>? ORDER BY position, id",
                            [$columnId, $cardId]);
        $ids = array_map(fn($r) => (int)$r['id'], $siblings);
        $position = max(0, min(count($ids), $position));
        array_splice($ids, $position, 0, [$cardId]);

        foreach ($ids as $i => $id) {
            $data = ['position' => $i];
            // Only the dragged card changes column and «когда двигали» — the ones
            // it pushed aside keep their own history
            if ($id === $cardId) $data += ['column_id' => $columnId, 'moved_at' => date('Y-m-d H:i:s')];
            Db::update('board_cards', $data, 'id=?', [$id]);
        }
    }

    public static function updateCard(int $cardId, array $o): void {
        $data = [];
        if (array_key_exists('title', $o)) $data['title'] = trim((string)$o['title']) ?: 'Карточка';
        if (array_key_exists('note', $o))  $data['note']  = trim((string)$o['note']) ?: null;
        if (array_key_exists('manager_id', $o)) $data['manager_id'] = $o['manager_id'] ? (int)$o['manager_id'] : null;
        if ($data) Db::update('board_cards', $data, 'id=?', [$cardId]);
    }

    public static function deleteCard(int $cardId): void {
        Db::q("DELETE FROM board_cards WHERE id=?", [$cardId]);
    }

    /**
     * A card, wherever it lives: title/note of the card itself, plus the company,
     * the mail behind it and the request it came from — a manager searching for
     * «Глори Эйр» should find the card even when only the letter mentions it.
     */
    public static function search(string $q, int $limit = 50): array {
        $like = '%' . $q . '%';
        return Db::all(
            "SELECT d.id AS card_id, d.title, d.note, d.thread_key, d.counterparty_id, d.request_id, d.moved_at,
                    col.id AS column_id, col.title AS column_title, col.color,
                    b.id AS board_id, b.name AS board_name
             FROM board_cards d
             JOIN board_columns col ON col.id = d.column_id
             JOIN boards b ON b.id = col.board_id
             LEFT JOIN counterparties cp ON cp.id = d.counterparty_id
             LEFT JOIN mail_messages m ON m.thread_key = d.thread_key OR m.counterparty_id = d.counterparty_id
             LEFT JOIN requests r ON r.id = d.request_id
             WHERE d.title LIKE ? OR d.note LIKE ?
                OR cp.name LIKE ? OR cp.inn LIKE ?
                OR m.subject LIKE ? OR m.body_text LIKE ? OR m.from_email LIKE ? OR m.from_name LIKE ?
                OR r.email_subject LIKE ? OR r.raw_text LIKE ? OR r.email_from LIKE ?
             GROUP BY d.id
             ORDER BY d.moved_at DESC
             LIMIT ?",
            [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $limit]
        );
    }

    /** Which board a thread is already on — the mail page shows it as a chip. */
    public static function threadPlacement(string $threadKey): array {
        $cpId = Db::val("SELECT counterparty_id FROM mail_messages
                         WHERE thread_key=? AND counterparty_id IS NOT NULL ORDER BY date_at DESC LIMIT 1", [$threadKey]);
        return Db::all(
            "SELECT d.id AS card_id, c.id AS column_id, c.title AS column_title, c.color,
                    b.id AS board_id, b.name AS board_name
             FROM board_cards d
             JOIN board_columns c ON c.id = d.column_id
             JOIN boards b ON b.id = c.board_id
             WHERE d.thread_key=? OR (? IS NOT NULL AND d.counterparty_id=?)",
            [$threadKey, $cpId, $cpId]
        );
    }

    /** Where a company card sits — shown on the company card itself (module 011). */
    public static function companyPlacement(int $counterpartyId): array {
        return Db::all(
            "SELECT d.id AS card_id, c.id AS column_id, c.title AS column_title, c.color,
                    b.id AS board_id, b.name AS board_name
             FROM board_cards d
             JOIN board_columns c ON c.id = d.column_id
             JOIN boards b ON b.id = c.board_id
             WHERE d.counterparty_id=?", [$counterpartyId]
        );
    }
}
