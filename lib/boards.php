<?php
/**
 * Kanban boards (module 010) — letters moved by hand, the way Trello moves cards.
 *
 * A card points at a mail THREAD, not at a single letter: the whole conversation
 * travels together, and a new answer shows up on the card that is already in
 * «Ждём оплату» instead of starting a second one somewhere else.
 */
require_once __DIR__ . '/mail_threads.php';

final class Boards {

    /** A first board that already makes sense for a КП pipeline. */
    private const DEFAULT_COLUMNS = [
        ['Входящие',      '#6b7fd7'],
        ['В работе',      '#e0a53c'],
        ['КП отправлено', '#4f9e57'],
        ['Ждём оплату',   '#b45cc0'],
        ['Закрыто',       '#8a8f98'],
    ];

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

    /** The board with its columns and cards — one request paints the whole page. */
    public static function get(int $id): ?array {
        $board = Db::one("SELECT * FROM boards WHERE id=?", [$id]);
        if (!$board) return null;

        $columns = Db::all("SELECT * FROM board_columns WHERE board_id=? ORDER BY position, id", [$id]);
        foreach ($columns as &$col) {
            $col['cards'] = array_map([self::class, 'decorate'], Db::all(
                "SELECT d.*, g.name AS manager_name FROM board_cards d
                 LEFT JOIN managers g ON g.id = d.manager_id
                 WHERE d.column_id=? ORDER BY d.position, d.id", [$col['id']]
            ));
        }
        return $board + ['columns' => $columns];
    }

    /**
     * Live state of the thread behind a card: how many letters it holds now, how
     * many are unread, when the last one came. A card that has not been touched
     * for a week is the point of a board.
     */
    private static function decorate(array $card): array {
        $card['id'] = (int)$card['id'];
        $card['thread'] = null;
        if (!empty($card['thread_key'])) {
            $t = MailThreads::summary((string)$card['thread_key']);
            if ($t) {
                $card['thread'] = [
                    'subject'  => $t['subject'],
                    'count'    => $t['count'],
                    'unread'   => $t['unread'],
                    'last_at'  => $t['last_at'],
                    'last_direction' => $t['last_direction'],
                    'counterparty_id'   => $t['counterparty_id'],
                    'counterparty_name' => $t['counterparty_name'],
                    'mailboxes' => array_map(fn($b) => $b['name'], $t['mailboxes']),
                ];
                if (trim((string)$card['title']) === '') $card['title'] = $t['subject'];
            }
        }
        return $card;
    }

    /** Create a board; a brand new one comes with the usual columns filled in. */
    public static function createBoard(string $name, bool $withColumns = true): int {
        $pos = (int)Db::val("SELECT COALESCE(MAX(position), 0) + 1 FROM boards");
        $id = Db::insert('boards', ['name' => trim($name) ?: 'Новая доска', 'position' => $pos]);
        if ($withColumns) {
            foreach (self::DEFAULT_COLUMNS as $i => [$title, $color]) {
                Db::insert('board_columns', ['board_id' => $id, 'title' => $title, 'color' => $color, 'position' => $i]);
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

    public static function saveColumn(int $boardId, ?int $columnId, string $title, ?string $color): int {
        if ($columnId) {
            Db::update('board_columns', array_filter([
                'title' => trim($title) ?: 'Колонка',
                'color' => $color,
            ], fn($v) => $v !== null), 'id=? AND board_id=?', [$columnId, $boardId]);
            return $columnId;
        }
        $pos = (int)Db::val("SELECT COALESCE(MAX(position), -1) + 1 FROM board_columns WHERE board_id=?", [$boardId]);
        return Db::insert('board_columns', [
            'board_id' => $boardId,
            'title'    => trim($title) ?: 'Колонка',
            'color'    => $color ?: '#8a8f98',
            'position' => $pos,
        ]);
    }

    public static function deleteColumn(int $columnId): void {
        Db::q("DELETE FROM board_cards WHERE column_id=?", [$columnId]);
        Db::q("DELETE FROM board_columns WHERE id=?", [$columnId]);
    }

    /** Column order after a drag of the column headers. */
    public static function reorderColumns(int $boardId, array $columnIds): void {
        foreach (array_values($columnIds) as $i => $cid) {
            Db::update('board_columns', ['position' => $i], 'id=? AND board_id=?', [(int)$cid, $boardId]);
        }
    }

    /**
     * Put a thread on a board. The same thread is never added twice to one
     * board — it is moved to the asked-for column instead, because a
     * conversation that exists in two columns is exactly the confusion a board
     * is supposed to remove.
     */
    public static function addCard(int $columnId, array $o): int {
        $col = Db::one("SELECT * FROM board_columns WHERE id=?", [$columnId]);
        if (!$col) throw new RuntimeException('Колонка не найдена');

        $threadKey = trim((string)($o['thread_key'] ?? ''));
        if ($threadKey !== '') {
            $existing = Db::one(
                "SELECT d.id FROM board_cards d JOIN board_columns c ON c.id = d.column_id
                 WHERE c.board_id=? AND d.thread_key=?", [(int)$col['board_id'], $threadKey]
            );
            if ($existing) {
                self::moveCard((int)$existing['id'], $columnId, 0);
                return (int)$existing['id'];
            }
        }

        $title = trim((string)($o['title'] ?? ''));
        if ($title === '' && $threadKey !== '') {
            $t = MailThreads::summary($threadKey);
            $title = $t['subject'] ?? '';
        }

        // New cards land on top: what was just added is what is being worked on
        Db::q("UPDATE board_cards SET position = position + 1 WHERE column_id=?", [$columnId]);
        return Db::insert('board_cards', [
            'column_id'       => $columnId,
            'position'        => 0,
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
     * A card, wherever it lives: title/note of the card itself, plus the mail
     * thread and the request behind it — a manager searching for «Глори Эйр»
     * should find the card even when only the letter mentions the company.
     */
    public static function search(string $q, int $limit = 50): array {
        $like = '%' . $q . '%';
        return Db::all(
            "SELECT d.id AS card_id, d.title, d.note, d.thread_key, d.request_id, d.moved_at,
                    col.id AS column_id, col.title AS column_title, col.color,
                    b.id AS board_id, b.name AS board_name
             FROM board_cards d
             JOIN board_columns col ON col.id = d.column_id
             JOIN boards b ON b.id = col.board_id
             LEFT JOIN mail_messages m ON m.thread_key = d.thread_key
             LEFT JOIN requests r ON r.id = d.request_id
             WHERE d.title LIKE ? OR d.note LIKE ?
                OR m.subject LIKE ? OR m.body_text LIKE ? OR m.from_email LIKE ? OR m.from_name LIKE ?
                OR r.email_subject LIKE ? OR r.raw_text LIKE ? OR r.email_from LIKE ?
             GROUP BY d.id
             ORDER BY d.moved_at DESC
             LIMIT ?",
            [$like, $like, $like, $like, $like, $like, $like, $like, $like, $limit]
        );
    }

    /** Which boards a thread is already on — the mail page shows it as a chip. */
    public static function threadPlacement(string $threadKey): array {
        return Db::all(
            "SELECT d.id AS card_id, c.id AS column_id, c.title AS column_title, c.color,
                    b.id AS board_id, b.name AS board_name
             FROM board_cards d
             JOIN board_columns c ON c.id = d.column_id
             JOIN boards b ON b.id = c.board_id
             WHERE d.thread_key=?", [$threadKey]
        );
    }
}
