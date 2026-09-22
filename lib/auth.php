<?php
/**
 * Auth helpers: login, password hashing, session management.
 */
class Auth {

    // Verify login credentials
    public static function login(string $login, string $password): ?array {
        $manager = Db::one("SELECT * FROM managers WHERE login=?", [trim($login)]);
        if (!$manager) return null;
        if (isset($manager['is_active']) && !$manager['is_active']) return null;
        if (!password_verify($password, $manager['password_hash'])) {
            Logger::warning('auth', 'Неверный пароль при входе', ['login' => trim($login)]);
            return null;
        }

        // The session must be running before $_SESSION is written, otherwise
        // the login is silently lost and the user bounces back to the form.
        startSession();
        // A stale cookie from an older build would keep shadowing ours
        clearLegacySessionCookies();
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['manager_id'] = $manager['id'];
        // Поколение сессии: администратор может обнулить вход, и сессии со
        // старым номером перестанут открываться (issue #60)
        $_SESSION['epoch'] = (int)($manager['session_epoch'] ?? 0);
        $_SESSION['cookie_renewed'] = time();
        Logger::info('auth', 'Вход в систему: ' . $manager['login'], ['manager_id' => $manager['id']]);
        self::recordLogin((int)$manager['id'], (string)$manager['name']);

        return [
            'id' => $manager['id'],
            'name' => $manager['name'],
            'is_admin' => (bool)$manager['is_admin'],
        ];
    }

    // Logout
    public static function logout(): void {
        startSession();
        clearLegacySessionCookies();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /**
     * Запись о входе — и уведомление о входе с НОВОГО адреса (issue #60).
     *
     * «Постоянно слетает авторизация» сама по себе чинится долгой кукой, но
     * рядом стоит вторая просьба: видеть чужие входы. Уведомление шлётся не на
     * каждый вход, а когда адрес отличается от тех, с которых этот человек
     * заходил раньше: иначе колокольчик звонит каждое утро и его перестают
     * читать.
     */
    private static function recordLogin(int $managerId, string $name): void {
        if (!Db::hasTable('manager_logins')) return;
        $ip = self::clientIp();
        $agent = mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);
        $known = $ip !== '' && Db::val(
            "SELECT id FROM manager_logins WHERE manager_id=? AND ip=? LIMIT 1", [$managerId, $ip]);
        $first = !Db::val("SELECT id FROM manager_logins WHERE manager_id=? LIMIT 1", [$managerId]);

        Db::insert('manager_logins', [
            'manager_id' => $managerId,
            'ip'         => $ip !== '' ? $ip : null,
            'user_agent' => $agent !== '' ? $agent : null,
        ]);
        if ($known || $first) return;

        require_once ROOT . '/lib/notifier.php';
        foreach (Db::all("SELECT id FROM managers WHERE is_admin=1 AND COALESCE(is_active,1)=1") as $admin) {
            Notifier::notify('new_login', 'Вход с нового устройства: ' . $name,
                'Адрес ' . ($ip !== '' ? $ip : 'неизвестен') . ($agent !== '' ? ' · ' . $agent : ''),
                'manager', $managerId, (int)$admin['id'], '/#settings/managers');
        }
    }

    /** Адрес клиента с оглядкой на прокси хостинга. */
    public static function clientIp(): string {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            $raw = trim((string)($_SERVER[$key] ?? ''));
            if ($raw === '') continue;
            // X-Forwarded-For — список: первый адрес и есть клиент
            $ip = trim(explode(',', $raw)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        return '';
    }

    /**
     * Обнулить вход менеджера (issue #60): все его сессии перестают открываться.
     *
     * Поколение хранится в карточке, а не в списке сессий: файлы сессий лежат
     * там, где их положил хостинг, и перебрать их нельзя.
     */
    public static function resetSessions(int $managerId): int {
        $epoch = (int)(Db::val("SELECT session_epoch FROM managers WHERE id=?", [$managerId]) ?? 0) + 1;
        Db::update('managers', ['session_epoch' => $epoch], 'id=?', [$managerId]);
        Logger::info('auth', 'Вход обнулён администратором', ['manager_id' => $managerId, 'epoch' => $epoch]);
        return $epoch;
    }

    // Hash password
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}
