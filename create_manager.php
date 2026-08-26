<?php
/**
 * CLI: Create first manager.
 * Usage: php create_manager.php <login> <password> <name> [email] [--admin]
 */
if (php_sapi_name() !== 'cli') die('CLI only');

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

$args = $argv;
array_shift($args);

if (count($args) < 3) {
    echo "Usage: php create_manager.php <login> <password> <name> [email] [--admin]\n";
    echo "Example: php create_manager.php admin secret123 'Кирилл Сурков' admin@atlant-armour.ru --admin\n";
    exit(1);
}

$login = $args[0];
$password = $args[1];
$name = $args[2];
$email = (isset($args[3]) && $args[3] !== '--admin') ? $args[3] : null;
$isAdmin = in_array('--admin', $args) ? 1 : 0;

// Check if exists
if (Db::one("SELECT id FROM managers WHERE login=?", [$login])) {
    echo "Manager '$login' already exists.\n";
    exit(1);
}

$id = Db::insert('managers', [
    'login' => $login,
    'password_hash' => Auth::hashPassword($password),
    'name' => $name,
    'email' => $email,
    'is_admin' => $isAdmin,
]);

echo "Manager created: #$id $name ($login)" . ($isAdmin ? ' [admin]' : '') . "\n";
