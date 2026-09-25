<?php
/**
 * Web-push orchestration (module 007): per-manager subscriptions, per-kind mutes
 * and the fan-out that turns an event of the service into a notification on the
 * administrator's phone.
 *
 * A manager already gets in-app notifications (`Notifier`), but only while the tab
 * is open. Push is what reaches them when the browser is closed — the whole point
 * of installing the panel as an app.
 */
require_once __DIR__ . '/webpush.php';

final class Push {

    /** Kinds a manager can switch off individually. */
    public const KINDS = [
        'new_request' => 'Новые запросы и письма',
        'order'       => 'Заказы и счета',
        'followup'    => 'Напоминания по КП',
        'system'      => 'Ошибки и служебные события',
    ];

    public static function enabled(): bool {
        return (int)Settings::get('PUSH_ENABLED', 1) === 1 && function_exists('openssl_pkey_derive');
    }

    /** Why push is unavailable, for «Настройки → Обзор». '' when everything is fine. */
    public static function unavailableReason(): string {
        if (!function_exists('openssl_pkey_derive')) {
            return 'В PHP нет openssl_pkey_derive — расширение openssl собрано без поддержки ECDH';
        }
        if (!function_exists('curl_init')) return 'В PHP нет расширения curl';
        if ((int)Settings::get('PUSH_ENABLED', 1) !== 1) return 'Push-уведомления выключены в настройках';
        return '';
    }

    public static function publicKey(): string {
        return WebPush::publicKey();
    }

    // ---- subscriptions ----------------------------------------------------

    public static function subscribe(int $managerId, string $endpoint, string $p256dh, string $auth): void {
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new InvalidArgumentException('Некорректная подписка на уведомления');
        }
        Db::q(
            "INSERT INTO push_subscriptions (manager_id, endpoint, p256dh, auth, user_agent)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(endpoint) DO UPDATE SET
                manager_id = excluded.manager_id, p256dh = excluded.p256dh,
                auth = excluded.auth, user_agent = excluded.user_agent",
            [$managerId, $endpoint, $p256dh, $auth, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300)]
        );
    }

    public static function unsubscribe(int $managerId, string $endpoint): void {
        Db::q("DELETE FROM push_subscriptions WHERE manager_id=? AND endpoint=?", [$managerId, $endpoint]);
    }

    /** What the notification settings screen shows for one manager. */
    public static function prefs(int $managerId): array {
        $muted = array_column(Db::all("SELECT kind FROM push_mutes WHERE manager_id=?", [$managerId]), 'kind');
        return [
            'kinds'   => self::KINDS,
            'muted'   => array_values(array_filter($muted, fn($k) => $k !== null)),
            'all_off' => in_array(null, $muted, true),
            'devices' => (int)Db::val("SELECT COUNT(*) FROM push_subscriptions WHERE manager_id=?", [$managerId]),
        ];
    }

    /** Mute or un-mute one kind (or everything when $kind is null). */
    public static function setMute(int $managerId, ?string $kind, bool $muted): void {
        if ($kind !== null && !isset(self::KINDS[$kind])) {
            throw new InvalidArgumentException('Неизвестный тип уведомления');
        }
        if ($muted) {
            Db::q("INSERT OR IGNORE INTO push_mutes (manager_id, kind) VALUES (?, ?)", [$managerId, $kind]);
        } else {
            // NULL-safe match so clearing the «all» row works
            Db::q("DELETE FROM push_mutes WHERE manager_id=? AND kind IS ?", [$managerId, $kind]);
        }
    }

    public static function isMuted(int $managerId, string $kind): bool {
        return (bool)Db::val(
            "SELECT 1 FROM push_mutes WHERE manager_id=? AND (kind IS NULL OR kind=?) LIMIT 1",
            [$managerId, $kind]
        );
    }

    // ---- fan-out ----------------------------------------------------------

    /**
     * Notify managers about an event. $managerId null = everyone.
     * Best-effort and never fatal: a dead push service must not break mail sync.
     */
    public static function notify(string $kind, string $title, string $body, string $url = '/', ?int $managerId = null): int {
        if (!self::enabled()) return 0;
        $ids = $managerId
            ? [$managerId]
            : array_column(Db::all("SELECT id FROM managers"), 'id');

        // The tag is per-target, not per-kind: two new letters have to be two
        // notifications, or the second one silently replaces the first and the
        // tap opens the wrong letter.
        $payload = (string)json_encode([
            'title' => $title,
            'body'  => mb_substr($body, 0, 300),
            'url'   => self::appUrl($url),
            'kind'  => $kind,
            'tag'   => $kind . ':' . substr(md5($url), 0, 12),
        ], JSON_UNESCAPED_UNICODE);

        $sent = 0;
        foreach ($ids as $id) {
            if (self::isMuted((int)$id, $kind)) continue;
            $sent += self::sendToManager((int)$id, $payload);
        }
        return $sent;
    }

    /** «Проверить» in the settings: prove the whole chain works, on this device. */
    public static function sendTest(int $managerId): int {
        $payload = (string)json_encode([
            'title' => 'Atlant Armour КП',
            'body'  => 'Тестовое уведомление — push работает ✅',
            'url'   => self::appUrl('/'),
            'kind'  => 'system',
            'tag'   => 'push-test',
        ], JSON_UNESCAPED_UNICODE);
        return self::sendToManager($managerId, $payload);
    }

    /** Push to every device of one manager, pruning endpoints the service rejects. */
    private static function sendToManager(int $managerId, string $payload): int {
        $sent = 0;
        foreach (Db::all("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE manager_id=?", [$managerId]) as $s) {
            try {
                $status = WebPush::send($s, $payload);
            } catch (Throwable $e) {
                Logger::exception('push', $e, ['manager_id' => $managerId, 'subscription_id' => $s['id']]);
                continue;
            }
            if ($status === 404 || $status === 410) {
                // The browser dropped the subscription — stop pushing to a dead endpoint
                Db::q("DELETE FROM push_subscriptions WHERE id=?", [$s['id']]);
            } elseif ($status >= 200 && $status < 300) {
                Db::update('push_subscriptions', ['last_used_at' => date('Y-m-d H:i:s')], 'id=?', [$s['id']]);
                $sent++;
            } else {
                Logger::warning('push', "Push-сервис ответил HTTP $status", ['subscription_id' => $s['id']]);
            }
        }
        return $sent;
    }

    /** Absolute URL the notification opens — the phone has no relative context. */
    private static function appUrl(string $path): string {
        // An issue link is already a whole address — prefixing it made
        // https://kp…/https://github.com/… (issue #104)
        if (preg_match('#^https?://#i', $path)) return $path;
        $base = rtrim((string)Settings::get('APP_URL', ''), '/');
        if ($base === '') {
            $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . '/' . ltrim($path, '/');
    }
}
