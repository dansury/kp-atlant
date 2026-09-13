<?php
/**
 * Undelivered answers (module 015).
 *
 * `Mailer::send()` records that SMTP accepted the message, and that is where the
 * story ended: the refusal comes back minutes later as a separate letter from
 * mailer-daemon, nobody read it, and the manager went on believing the client
 * had been answered. Twelve such letters sit in the archive.
 *
 * A bounce is therefore not just mail to file under «служебное»: it points at
 * the message that failed, marks it, and says so out loud.
 */
final class Bounce {

    private const DAEMONS = [
        'mailer-daemon', 'mail-daemon', 'postmaster', 'no-reply@dsn', 'bounce', 'bounces',
    ];

    /** A delivery report, or null. Reads headers first, the envelope second. */
    public static function detect(array $msg): ?array {
        $headers = mb_strtolower((string)($msg['headers'] ?? ''));
        $from    = mb_strtolower(trim((string)($msg['from'] ?? $msg['from_email'] ?? '')));
        $subject = mb_strtolower((string)($msg['subject'] ?? ''));

        $isReport = str_contains($headers, 'content-type: multipart/report')
                 || str_contains($headers, 'report-type=delivery-status')
                 || preg_match('/^content-type:\s*message\/delivery-status/m', $headers);

        $local = strstr($from, '@', true) ?: $from;
        $isDaemon = false;
        foreach (self::DAEMONS as $d) {
            if (str_contains($local, $d)) { $isDaemon = true; break; }
        }
        $saysSo = (bool)preg_match(
            '/(?:undeliver|delivery status notification|delivery has failed|returned mail|mail delivery failed'
            . '|не доставлено|недоставленное|сообщение не доставлено|ошибка доставки)/iu',
            $subject
        );
        // A delivery report is one by its MIME type; a plain «Undelivered mail»
        // letter is one only when a daemon sent it — a client may well write
        // «письмо не доставлено» about something else entirely.
        if (!$isReport && !($isDaemon && $saysSo)) return null;

        $body = (string)($msg['body'] ?? $msg['body_text'] ?? '');
        return [
            'recipient'  => self::recipient($body, $headers),
            'message_id' => self::originalId($body),
            'diagnostic' => self::diagnostic($body),
            'permanent'  => (bool)preg_match('/\b5\.\d\.\d\b|\b55\d\b|permanent failure/iu', $body),
        ];
    }

    /** Address the server refused — «Final-Recipient: rfc822; client@corp.ru». */
    private static function recipient(string $body, string $headers): string {
        if (preg_match('/^(?:final|original)-recipient:\s*[^;]*;\s*<?([^\s>]+@[^\s>]+)/im', $body, $m)) {
            return mb_strtolower(trim($m[1], '<>'));
        }
        if (preg_match('/<([^\s>]+@[^\s>]+)>[^\n]{0,40}(?:not found|unknown|does not exist|rejected)/i', $body, $m)) {
            return mb_strtolower($m[1]);
        }
        return '';
    }

    /** Message-ID of the letter that failed, when the report quotes its headers. */
    private static function originalId(string $body): string {
        return preg_match('/^message-id:\s*(<[^>]+>)/im', $body, $m) ? trim($m[1]) : '';
    }

    private static function diagnostic(string $body): string {
        if (preg_match('/^diagnostic-code:\s*(.+)$/im', $body, $m)) return trim(mb_substr($m[1], 0, 300));
        if (preg_match('/^status:\s*(\d\.\d\.\d)/im', $body, $m)) return 'SMTP status ' . $m[1];
        return '';
    }

    /**
     * Mark the outgoing letter this report is about. The Message-ID is the exact
     * link; without it the newest answer to that address is the one that failed.
     * Returns the archived row that was marked, or null.
     */
    public static function applyTo(array $report): ?array {
        $row = null;
        if ($report['message_id'] !== '') {
            $row = Db::one("SELECT * FROM mail_messages WHERE direction='out' AND message_id=? LIMIT 1",
                           [$report['message_id']]);
        }
        if (!$row && $report['recipient'] !== '') {
            $row = Db::one("SELECT * FROM mail_messages WHERE direction='out' AND lower(to_emails) LIKE ?
                            ORDER BY date_at DESC, id DESC LIMIT 1", ['%' . $report['recipient'] . '%']);
        }
        if (!$row) return null;

        $state = $report['permanent'] ? 'bounced' : 'bounce_soft';
        Db::update('mail_messages', [
            'sent_state' => $state,
            'error'      => mb_substr(trim('Письмо не доставлено. ' . $report['diagnostic']), 0, 500),
        ], 'id=?', [$row['id']]);

        Logger::error('mail', 'Ответ не доставлен: ' . ($report['recipient'] ?: 'адрес не указан в отчёте')
            . ($report['diagnostic'] !== '' ? ' — ' . $report['diagnostic'] : ''),
            ['mail_message_id' => (int)$row['id'], 'subject' => $row['subject']]);

        return $row;
    }
}
