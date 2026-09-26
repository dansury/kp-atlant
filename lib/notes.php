<?php
/**
 * Notes for colleagues (module 064, issue #129).
 *
 * A note is a `correspondence` row with `direction = 'note'` and no
 * `event_type`. It belongs to a company card; a letter with no company keeps
 * its notes by `thread_key`, and `Crm::attachThread()` moves them onto the
 * company once the conversation gets one.
 */
require_once __DIR__ . '/crm.php';

class Notes {
    public const MAX_LEN = 5000;

    /**
     * Notes of a card, newest first: the company's, plus the thread's own
     * notes that have no company yet.
     */
    public static function forCard(?int $counterpartyId, string $threadKey = ''): array {
        $where = [];
        $args = [];
        if ($counterpartyId) {
            $where[] = 'c.counterparty_id = ?';
            $args[] = Crm::rootId($counterpartyId);
        }
        $threadKey = trim($threadKey);
        if ($threadKey !== '') {
            $where[] = '(c.thread_key = ? AND c.counterparty_id IS NULL)';
            $args[] = $threadKey;
        }
        if (!$where) return [];
        return Db::all(
            "SELECT c.id, c.body, c.created_at, c.manager_id, c.thread_key, c.request_id, m.name AS manager_name
             FROM correspondence c LEFT JOIN managers m ON m.id = c.manager_id
             WHERE c.direction = 'note' AND c.event_type IS NULL AND (" . implode(' OR ', $where) . ")
             ORDER BY c.created_at DESC, c.id DESC", $args);
    }

    /** @return int id of the new note */
    public static function add(?int $counterpartyId, string $threadKey, int $managerId, string $text): int {
        $text = trim($text);
        if ($text === '') throw new InvalidArgumentException('Пустая заметка');
        $text = mb_substr($text, 0, self::MAX_LEN);
        $threadKey = trim($threadKey);
        $cpId = $counterpartyId ? Crm::rootId($counterpartyId) : null;
        if ($cpId && !Db::val("SELECT 1 FROM counterparties WHERE id=?", [$cpId])) {
            throw new InvalidArgumentException('Компания не найдена');
        }
        if (!$cpId && $threadKey === '') throw new InvalidArgumentException('Не указано, к чему заметка');
        // Запрос переписки — чтобы заметка знала, о какой сделке она
        $requestId = $threadKey !== ''
            ? ((int)(Db::val("SELECT MAX(request_id) FROM mail_messages WHERE thread_key=?", [$threadKey]) ?: 0) ?: null)
            : null;

        $id = Crm::logEvent($cpId, 'note', $text, [
            'manager_id' => $managerId,
            'request_id' => $requestId,
            'subject'    => 'Заметка',
        ]);
        if ($threadKey !== '') Db::update('correspondence', ['thread_key' => $threadKey], 'id=?', [$id]);
        return $id;
    }

    /** Only a note: a milestone or a letter is history, not something to erase. */
    public static function delete(int $id): bool {
        $row = Db::one("SELECT direction, event_type FROM correspondence WHERE id=?", [$id]);
        if (!$row || (string)$row['direction'] !== 'note' || !empty($row['event_type'])) return false;
        Db::q("DELETE FROM correspondence WHERE id=?", [$id]);
        return true;
    }
}
