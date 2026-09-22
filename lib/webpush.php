<?php
/**
 * Web Push (RFC 8291 «aes128gcm» + RFC 8292 «VAPID») on plain PHP — no Composer
 * package, only `openssl` and `hash_hkdf`, so it ships on shared hosting.
 * Ported from the kraskiweb implementation, with the VAPID pair kept in Settings
 * (encrypted) instead of an app_state table.
 *
 * `send()` encrypts one payload for one subscription and POSTs it; `Push` fans it
 * out over a manager's devices.
 */
final class WebPush {

    /** How long the push service keeps an undelivered message, seconds. */
    private const TTL = 2419200; // 28 days

    /** VAPID key pair, generated once on first use. */
    public static function vapid(): array {
        $pub  = trim((string)Settings::get('PUSH_VAPID_PUBLIC', ''));
        $priv = trim((string)Settings::get('PUSH_VAPID_PRIVATE', ''));
        if ($pub === '' || $priv === '') {
            [$pub, $priv] = self::generate();
            Settings::set('PUSH_VAPID_PUBLIC', $pub);
            Settings::set('PUSH_VAPID_PRIVATE', $priv);
            Logger::info('push', 'Сгенерирована пара ключей VAPID');
        }
        $subject = trim((string)Settings::get('PUSH_VAPID_SUBJECT', ''));
        if ($subject === '') {
            $url = trim((string)Settings::get('APP_URL', ''));
            $subject = $url !== '' ? $url : 'mailto:admin@atlant-armour.ru';
        }
        return ['public' => $pub, 'private' => $priv, 'subject' => $subject];
    }

    /** The base64url application server key the browser subscribes with. */
    public static function publicKey(): string {
        return self::vapid()['public'];
    }

    /**
     * Encrypt and deliver one payload. Returns the HTTP status (0 on transport
     * failure); 404/410 means the subscription is gone and should be deleted.
     */
    public static function send(array $sub, string $payload): int {
        $vapid      = self::vapid();
        $uaPublic   = self::b64uDecode((string)$sub['p256dh']);   // 65-byte UA public point
        $authSecret = self::b64uDecode((string)$sub['auth']);     // 16-byte auth secret
        if (strlen($uaPublic) !== 65 || $authSecret === '') return 0;

        // Ephemeral server ECDH key, one per message
        $server = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($server === false) return 0;
        $d = openssl_pkey_get_details($server);
        $asPublic = "\x04"
            . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $peer = openssl_pkey_get_public(self::rawPointToPem($uaPublic));
        if ($peer === false) return 0;
        $ecdh = openssl_pkey_derive($peer, $server, 32);
        if ($ecdh === false) return 0;

        // RFC 8291 §3.4 key derivation
        $salt    = random_bytes(16);
        $keyInfo = 'WebPush: info' . "\x00" . $uaPublic . $asPublic;
        $ikm     = hash_hkdf('sha256', $ecdh, 32, $keyInfo, $authSecret);
        $cek     = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce   = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // RFC 8188 single record: plaintext || 0x02 (last-record delimiter)
        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) return 0;
        $body = $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;

        return self::post((string)$sub['endpoint'], $body, [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . self::TTL,
            'Urgency: normal',
            'Authorization: vapid t=' . self::jwt((string)$sub['endpoint'], $vapid) . ', k=' . $vapid['public'],
        ]);
    }

    // ---- VAPID JWT (RFC 8292, ES256) --------------------------------------

    private static function jwt(string $endpoint, array $vapid): string {
        $parts = parse_url($endpoint);
        $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $header  = self::b64uEncode((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64uEncode((string)json_encode([
            'aud' => $aud, 'exp' => time() + 43200, 'sub' => $vapid['subject'],
        ]));
        $input = $header . '.' . $payload;
        $der = '';
        openssl_sign($input, $der, openssl_pkey_get_private($vapid['private']), OPENSSL_ALGO_SHA256);
        return $input . '.' . self::b64uEncode(self::derToRaw($der));
    }

    /** P-256 pair → [base64url public point (65 B), private PEM]. */
    private static function generate(): array {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key);
        $public = "\x04"
            . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        return [self::b64uEncode($public), $pem];
    }

    // ---- crypto/encoding helpers ------------------------------------------

    /** Wrap a raw 65-byte uncompressed EC point as a PEM SubjectPublicKeyInfo. */
    private static function rawPointToPem(string $raw): string {
        // Fixed ASN.1 prefix for id-ecPublicKey on prime256v1
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
             . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** ECDSA DER (SEQUENCE of two INTEGERs) → fixed 64-byte r||s for ES256. */
    private static function derToRaw(string $der): string {
        $pos = $der[1] === "\x81" ? 3 : 2;         // skip SEQUENCE tag + length
        $read = static function (string $der, int &$pos): string {
            $pos++;                                 // 0x02 INTEGER tag
            $len = ord($der[$pos]);
            $pos++;
            $val = substr($der, $pos, $len);
            $pos += $len;
            return ltrim($val, "\x00");             // drop sign padding
        };
        $r = $read($der, $pos);
        $s = $read($der, $pos);
        return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
    }

    public static function b64uEncode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad > 0) $s .= str_repeat('=', 4 - $pad);
        return (string)base64_decode($s, true);
    }

    private static function post(string $endpoint, string $body, array $headers): int {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return $status;
    }
}
