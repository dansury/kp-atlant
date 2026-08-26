<?php
/**
 * Notification: create, poll, 24h email fallback.
 */
class Notifier {

    // Create notification for a manager (or all if manager_id=null)
    public static function notify(string $type, string $title, ?string $body = null, ?string $refType = null, ?int $refId = null, ?int $managerId = null): int {
        if ($managerId) {
            return Db::insert('notifications', [
                'manager_id' => $managerId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'ref_type' => $refType,
                'ref_id' => $refId,
            ]);
        }
        // Notify all managers
        $managers = Db::all("SELECT id FROM managers");
        $lastId = 0;
        foreach ($managers as $m) {
            $lastId = Db::insert('notifications', [
                'manager_id' => $m['id'],
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'ref_type' => $refType,
                'ref_id' => $refId,
            ]);
        }
        return $lastId;
    }

    // Get unread notifications for a manager
    public static function getUnread(int $managerId): array {
        return Db::all(
            "SELECT id, type, title, body, ref_type, ref_id, created_at FROM notifications WHERE manager_id=? AND is_read=0 ORDER BY created_at DESC",
            [$managerId]
        );
    }

    // Mark notification as read
    public static function markRead(int $id, int $managerId): void {
        Db::q("UPDATE notifications SET is_read=1 WHERE id=? AND manager_id=?", [$id, $managerId]);
    }

    // Check for stale requests (>24h no reaction) and send email fallback
    public static function checkFallback(array $cfg): void {
        $fallbackEmail = $cfg['FALLBACK_EMAIL'] ?? '';
        $hours = $cfg['FALLBACK_HOURS'] ?? 24;
        if (!$fallbackEmail) return;

        $stale = Db::all(
            "SELECT r.id, r.email_from, r.email_subject, r.created_at, c.name as counterparty_name
             FROM requests r
             LEFT JOIN counterparties c ON r.counterparty_id = c.id
             WHERE r.status = 'new'
             AND r.email_notified_at IS NULL
             AND r.created_at <= datetime('now', ?)",
            ["-$hours hours"]
        );

        if (empty($stale)) return;

        require_once __DIR__ . '/email.php';
        $sender = new EmailSender($cfg);

        foreach ($stale as $req) {
            $subject = "⚠ Необработанный запрос КП #{$req['id']}";
            $body = "Запрос на КП не обработан более {$hours} часов.\n\n";
            $body .= "ID: {$req['id']}\n";
            $body .= "От: {$req['email_from']}\n";
            $body .= "Тема: {$req['email_subject']}\n";
            $body .= "Контрагент: " . ($req['counterparty_name'] ?? 'не определён') . "\n";
            $body .= "Дата: {$req['created_at']}\n";

            try {
                $sender->sendNotification($fallbackEmail, $subject, $body);
                Db::update('requests', ['email_notified_at' => date('Y-m-d H:i:s')], 'id=?', [$req['id']]);
            } catch (\Exception $e) {
                error_log("Fallback email failed for request #{$req['id']}: " . $e->getMessage());
            }
        }
    }
}
