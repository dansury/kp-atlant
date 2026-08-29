<?php
/**
 * Auth helpers: login, password hashing, session management.
 */
class Auth {

    // Verify login credentials
    public static function login(string $login, string $password): ?array {
        $manager = Db::one("SELECT * FROM managers WHERE login=?", [trim($login)]);
        if (!$manager) return null;
        if (!password_verify($password, $manager['password_hash'])) return null;

        // Session must be running before we touch $_SESSION
        startSession();
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['manager_id'] = $manager['id'];

        return [
            'id' => $manager['id'],
            'name' => $manager['name'],
            'is_admin' => (bool)$manager['is_admin'],
        ];
    }

    // Logout
    public static function logout(): void {
        startSession();
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
