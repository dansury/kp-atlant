<?php
/**
 * API: Auth — login, logout, me.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/auth.php';

$action = $_GET['action'] ?? '';

switch ($action) {
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
