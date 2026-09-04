<?php
/**
 * API: Auth — login, logout, me.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/auth.php';

$action = $_GET['action'] ?? '';

// No accounts at all — config.php may never have existed, or was removed before
// the first run. The login screen then asks for the first administrator.
$noManagers = fn(): bool => (int)Db::val("SELECT COUNT(*) FROM managers WHERE COALESCE(is_active,1)=1") === 0;

switch ($action) {
    case 'state':
        jsonData(['needs_setup' => $noManagers()]);

    case 'setup':
        if (!$noManagers()) jsonError('Администратор уже создан', 403);
        require_once ROOT . '/lib/managers.php';
        $input = getInput();
        $id = Managers::save([
            'name'     => trim((string)($input['name'] ?? '')) ?: 'Администратор',
            'login'    => $input['login'] ?? '',
            'password' => $input['password'] ?? '',
            'email'    => $input['email'] ?? '',
            'is_admin' => true,
        ], null, 0);
        Logger::info('auth', 'Создан первый администратор', ['manager_id' => $id]);
        $manager = Auth::login((string)($input['login'] ?? ''), (string)($input['password'] ?? ''));
        jsonOk(['manager' => $manager]);

    case 'login':
        $input = getInput();
        $login = $input['login'] ?? '';
        $password = $input['password'] ?? '';
        if (!$login || !$password) jsonError('Login and password required');

        $manager = Auth::login($login, $password);
        if (!$manager) jsonError('Invalid credentials', 401);
        jsonOk(['manager' => $manager]);

    case 'logout':
        Auth::logout();
        jsonOk();

    case 'me':
        $m = currentManager();
        if (!$m) jsonError('Unauthorized', 401);
        jsonData($m);

    default:
        jsonError('Unknown action', 400);
}
