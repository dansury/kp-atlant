<?php
/**
 * Notification: create, poll, 24h email fallback.
 */
require_once __DIR__ . '/push.php';

class Notifier {

    /** In-app notification type => push kind the manager can mute separately. */
    private const PUSH_KINDS = [
        'new_request' => 'new_request',
        'new_order'   => 'order',
        'invoice'     => 'order',
        'followup'    => 'followup',
        // An answer that never arrived has to reach the manager as loudly as a
        // new letter does — it is a client who thinks he was ignored (module 015)
        'mail_bounced' => 'new_request',
        // Резерв, который держится под неоплаченный счёт (модуль 026)
        'reserve_hold' => 'order',
        // Ошибка сервиса — администратору, и глушится она отдельно (модуль 029)
        'app_error'    => 'system',
        // Обращение в поддержку и ответ на него (модуль 038)
        'support'      => 'system',
        // Вход в чужой аккаунт с нового адреса — администратору (issue #60)
        'new_login'    => 'system',
    ];

    /**
     * Create a notification for a manager (or all of them when $managerId is null).
     *
     * $url is where a tap should land — the letter, the request, the board card.
     * A caller that knows better than the ref_type guess (mail sync knows the
     * exact letter) passes it; everyone else gets the default target.
     */
    public static function notify(string $type, string $title, ?string $body = null, ?string $refType = null, ?int $refId = null, ?int $managerId = null, ?string $url = null): int {
        $url = $url ?: self::defaultUrl($refType, $refId);

        // The bell in the header only rings while a tab is open — push is what
        // reaches the administrator's phone when it is not (module 007).
        self::push($type, $title, (string)$body, $url, $managerId);

        $row = [
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'url' => $url,
        ];
        if ($managerId) {
            return Db::insert('notifications', $row + ['manager_id' => $managerId]);
        }
        // Notify all managers
        $lastId = 0;
        foreach (Db::all("SELECT id FROM managers") as $m) {
            $lastId = Db::insert('notifications', $row + ['manager_id' => (int)$m['id']]);
        }
        return $lastId;
    }

    /** Where a notification leads when the caller did not say. */
    private static function defaultUrl(?string $refType, ?int $refId): string {
        if (!$refId) return '/';
        return match ($refType) {
            'request'      => '/#mail/request/' . $refId,
            'mail'         => '/#mail/msg/' . $refId,
            'proposal'     => '/#mail/proposal/' . $refId,
            'counterparty' => '/#mail/company/' . $refId,
            'log'          => '/#settings/logs/error',
            'manager'      => '/#settings/managers',
            default        => '/',
        };
    }

    /** Mirror an in-app notification to the manager's devices. Never fatal. */
    private static function push(string $type, string $title, string $body, string $url, ?int $managerId): void {
        try {
            Push::notify(self::PUSH_KINDS[$type] ?? 'system', $title, $body, $url, $managerId);
        } catch (Throwable $e) {
            Logger::exception('push', $e, ['type' => $type]);
        }
    }

    // Get unread notifications for a manager
    /**
     * Звуки уведомлений — то, что реально лежит в `sounds/` (модуль 023).
     *
     * Список читается с диска, а не хранится в настройках: файл положили по
     * FTP — он появился в выборе сам, файл убрали — он из выбора пропал, и
     * настройка, указывающая в пустоту, звука просто не даёт.
     *
     * @return array<int,array{file:string,name:string,url:string}>
     */
    public static function sounds(): array {
        $dir = ROOT . '/sounds';
        if (!is_dir($dir)) return [];

        $out = [];
        foreach (scandir($dir) ?: [] as $file) {
            if ($file[0] === '.') continue;
            $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp3', 'ogg', 'wav', 'm4a'], true)) continue;
            $out[] = [
                'file' => $file,
                // «universfield-new-notification-017-352293.mp3» человеку ничего
                // не говорит: выкидываем хвост из цифр и разделители
                'name' => self::soundName($file),
                'url'  => '/sounds/' . rawurlencode($file),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    private static function soundName(string $file): string {
        $name = (string)pathinfo($file, PATHINFO_FILENAME);
        $name = (string)preg_replace('/[-_]\d{4,}$/', '', $name);
        $name = str_replace(['-', '_'], ' ', $name);
        return mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');
    }

    public static function getUnread(int $managerId): array {
        return Db::all(
            "SELECT id, type, title, body, ref_type, ref_id, url, created_at FROM notifications WHERE manager_id=? AND is_read=0 ORDER BY created_at DESC",
            [$managerId]
        );
    }

    // Mark notification as read
    /**
     * Уведомления, которые ПОМЕЧАЮТ карточку (issue #103): «Сообщить складу»,
     * «заказ отправлен», недоставленное письмо, резерв, напоминание. Карточка
     * с таким уведомлением выделена и стоит наверху колонки, пока менеджер не
     * поставит галочку у него в самой карточке. Новое письмо сюда не входит —
     * его и так видно по жирному шрифту непрочитанного.
     */
    public const CARD_TYPES = ['order_paid', 'order_shipped', 'mail_bounced', 'reserve_hold',
                               'followup', 'invoice', 'new_order'];

    /**
     * Непрочитанные уведомления менеджера о карточках — по компании (корню
     * слияния) и по цепочке, для карточек без компании.
     *
     * @return array{cp: array<int, list<array>>, thread: array<string, list<array>>}
     */
    public static function cardNotices(int $managerId, ?int $counterpartyId = null): array {
        $out = ['cp' => [], 'thread' => []];
        if ($managerId <= 0 || !Db::hasTable('notifications')) return $out;
        require_once __DIR__ . '/crm.php';
        $in = implode(',', array_fill(0, count(self::CARD_TYPES), '?'));
        $rows = Db::all(
            "SELECT n.id, n.type, n.title, n.body, n.created_at, n.url,
                    CASE n.ref_type
                        WHEN 'counterparty' THEN n.ref_id
                        WHEN 'mail'     THEN (SELECT counterparty_id FROM mail_messages WHERE id = n.ref_id)
                        WHEN 'request'  THEN (SELECT counterparty_id FROM requests WHERE id = n.ref_id)
                        WHEN 'proposal' THEN (SELECT counterparty_id FROM proposals WHERE id = n.ref_id)
                    END AS cp_id,
                    CASE n.ref_type WHEN 'mail' THEN (SELECT thread_key FROM mail_messages WHERE id = n.ref_id) END AS thread_key
             FROM notifications n
             WHERE n.manager_id = ? AND n.is_read = 0 AND n.type IN ($in)
             ORDER BY n.id DESC LIMIT 500", [$managerId, ...self::CARD_TYPES]);
        $roots = [];
        foreach ($rows as $r) {
            $item = ['id' => (int)$r['id'], 'type' => (string)$r['type'], 'title' => (string)$r['title'],
                     'body' => (string)($r['body'] ?? ''), 'created_at' => (string)$r['created_at']];
            if (!empty($r['cp_id'])) {
                $cp = (int)$r['cp_id'];
                $root = $roots[$cp] ??= Crm::rootId($cp);
                if ($counterpartyId !== null && $root !== Crm::rootId($counterpartyId)) continue;
                $out['cp'][$root][] = $item;
            } elseif (!empty($r['thread_key'])) {
                $out['thread'][(string)$r['thread_key']][] = $item;
            }
        }
        return $out;
    }

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

        require_once __DIR__ . '/mail.php';

        foreach ($stale as $req) {
            $subject = "⚠ Необработанный запрос КП #{$req['id']}";
            $body = "Запрос на КП не обработан более {$hours} часов.\n\n";
            $body .= "ID: {$req['id']}\n";
            $body .= "От: {$req['email_from']}\n";
            $body .= "Тема: {$req['email_subject']}\n";
            $body .= "Контрагент: " . ($req['counterparty_name'] ?? 'не определён') . "\n";
            $body .= "Дата: {$req['created_at']}\n";

            try {
                Mailer::send(['to' => $fallbackEmail, 'subject' => $subject, 'text' => $body]);
                Db::update('requests', ['email_notified_at' => date('Y-m-d H:i:s')], 'id=?', [$req['id']]);
            } catch (\Exception $e) {
                Logger::exception('notify', $e, ['request_id' => $req['id']]);
            }
        }
    }
}
