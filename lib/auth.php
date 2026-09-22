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
        $_SESSION['session_epoch'] = (int)($manager['session_epoch'] ?? 0);
        Logger::info('auth', 'Вход в систему: ' . $manager['login'], ['manager_id' => $manager['id']]);

        self::noteLogin($manager);

        return [
            'id' => $manager['id'],
            'name' => $manager['name'],
            'is_admin' => (bool)$manager['is_admin'],
        ];
    }

    /**
     * Постоянные разрывы сессии (issue #60) делают повторный вход обычным
     * явлением — сам он никого не выбивает (см. Auth::kickSession). Он только
     * предупреждает ДРУГИХ администраторов, когда предыдущая сессия этого же
     * аккаунта ещё не истекла по SESSION_LIFETIME: это может быть тот же
     * человек с другого устройства, а может — кто-то ещё с тем же логином.
     */
    private static function noteLogin(array $manager): void {
        $lastAt = $manager['session_last_login_at'] ?? null;
        $lifetime = (int)Settings::get('SESSION_LIFETIME', 86400);
        $wasActive = $lastAt && (time() - strtotime((string)$lastAt)) < $lifetime;

        Db::update('managers', ['session_last_login_at' => date('Y-m-d H:i:s')], 'id=?', [$manager['id']]);
        if (!$wasActive) return;

        require_once __DIR__ . '/notifier.php';
        $name = $manager['name'] ?: $manager['login'];
        $admins = Db::all(
            "SELECT id FROM managers WHERE is_admin=1 AND COALESCE(is_active,1)=1 AND id<>?",
            [$manager['id']]
        );
        foreach ($admins as $admin) {
            Notifier::notify(
                'system',
                "Новый вход: {$name}",
                'Предыдущая сессия этого менеджера ещё не истекла — похоже, аккаунт открыт на другом устройстве.',
                null,
                null,
                (int)$admin['id'],
                '/#settings/managers'
            );
        }
    }

    /**
     * Принудительно завершить активную сессию менеджера на всех устройствах
     * (кнопка «Сбросить вход» в панели менеджеров, issue #60). Смена номера
     * эпохи делает несовпадающим то, что уже лежит в $_SESSION каждого
     * открытого сейчас браузера этого менеджера — currentManager() разлогинит
     * его на следующем же запросе.
     */
    public static function kickSession(int $managerId): void {
        Db::q("UPDATE managers SET session_epoch = session_epoch + 1 WHERE id=?", [$managerId]);
        Logger::info('auth', 'Вход сброшен администратором', ['manager_id' => $managerId]);
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

    // Hash password
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}
