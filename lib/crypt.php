<?php
/**
 * Secrets at rest. Passwords and API keys edited in the admin panel are stored
 * encrypted with a key file that lives next to the DB and never enters Git
 * (Constitution, V). No ext-openssl — falls back to storing the value as is,
 * because losing the mailbox password is worse than storing it like config.php does.
 */
final class Crypt {
    private const PREFIX = 'enc.v1:';
    private static ?string $key = null;

    public static function available(): bool {
        return function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    public static function encrypt(string $plain): string {
        if ($plain === '' || !self::available()) return $plain;
        $key = self::key();
        if ($key === null) return $plain;
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) return $plain;
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $stored): string {
        if (!str_starts_with($stored, self::PREFIX)) return $stored;   // plain value from config.php era
        $key = self::key();
        if ($key === null) return '';
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) return '';
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }

    /** Key file beside the DB; created on first use with owner-only rights. */
    private static function key(): ?string {
        if (self::$key !== null) return self::$key;
        $path = defined('ROOT') ? ROOT . '/data/secret.key' : sys_get_temp_dir() . '/kp-secret.key';
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (is_file($path)) {
            $raw = trim((string)@file_get_contents($path));
            if ($raw !== '') return self::$key = base64_decode($raw, true) ?: null;
        }
        $key = random_bytes(32);
        if (@file_put_contents($path, base64_encode($key)) === false) return null;
        @chmod($path, 0600);
        return self::$key = $key;
    }
}
