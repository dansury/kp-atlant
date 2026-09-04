<?php
/**
 * Email: IMAP reader + SMTP sender via PHPMailer.
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class EmailReader {
    private $imap;
    private array $cfg;
    private string $folder = 'INBOX';

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    public static function available(): bool {
        return function_exists('imap_open');
    }

    // Connect to IMAP. $folder is the mailbox to open (INBOX, Sent, ...)
    public function connect(string $folder = 'INBOX'): void {
        if (!self::available()) throw new RuntimeException('Расширение PHP imap не установлено на сервере');
        $this->folder = $folder;
        $this->imap = @imap_open($this->mailboxRef($folder), (string)$this->cfg['IMAP_USER'], (string)$this->cfg['IMAP_PASSWORD'], 0, 1);
        if (!$this->imap) throw new RuntimeException('IMAP connect failed: ' . imap_last_error());
    }

    private function mailboxRef(string $folder): string {
        $host = $this->cfg['IMAP_HOST'] ?? '';
        $port = $this->cfg['IMAP_PORT'] ?? 993;
        $enc  = $this->cfg['IMAP_ENCRYPTION'] ?? 'ssl';
        $flags = match ($enc) {
            'tls'   => '/imap/tls',
            'notls' => '/imap/notls',
            default => '/imap/ssl',
        };
        // Self-signed certificates are common on shared hosting; the connection stays encrypted
        if ($enc !== 'notls') $flags .= '/novalidate-cert';
        return '{' . $host . ':' . $port . $flags . '}' . $folder;
    }

    /** Folder names of the account — the admin picks INBOX / Sent from a real list. */
    public function folders(): array {
        $prefix = $this->mailboxRef('');
        $list = @imap_list($this->imap, $prefix, '*') ?: [];
        return array_map(fn($f) => str_replace($prefix, '', $f), $list);
    }

    public function messageCount(): int {
        $info = @imap_check($this->imap);
        return $info ? (int)$info->Nmsgs : 0;
    }

    /**
     * Everything newer than $sinceUid, oldest first — the archive syncs by UID and
     * never touches the \Seen flag, so the manager's own mail client is unaffected.
     */
    public function fetchSince(int $sinceUid, int $limit = 50): array {
        $from = $sinceUid + 1;
        $overviews = @imap_fetch_overview($this->imap, "$from:*", FT_UID) ?: [];
        $out = [];
        foreach ($overviews as $ov) {
            $uid = (int)($ov->uid ?? 0);
            if ($uid <= $sinceUid) continue;   // IMAP answers "*" with the last message even when nothing is newer
            $out[] = $uid;
        }
        sort($out);
        $out = array_slice($out, 0, max(1, $limit));

        $messages = [];
        foreach ($out as $uid) {
            $msg = $this->fetchUid($uid);
            if ($msg) $messages[] = $msg;
        }
        return $messages;
    }

    /** One message by UID, with body and attachments. */
    public function fetchUid(int $uid): ?array {
        $no = @imap_msgno($this->imap, $uid);
        if (!$no) return null;
        $header = @imap_headerinfo($this->imap, $no);
        if (!$header) return null;
        [$text, $html, $attachments] = $this->getBodyAndAttachments($no);

        return [
            'uid'         => $uid,
            'folder'      => $this->folder,
            'message_id'  => trim((string)($header->message_id ?? '')),
            'in_reply_to' => trim((string)($header->in_reply_to ?? '')),
            'from'        => $this->addr($header->from[0] ?? null),
            'from_name'   => $this->decodeMime($header->from[0]->personal ?? ''),
            'to'          => $this->addrList($header->to ?? []),
            'cc'          => $this->addrList($header->cc ?? []),
            'subject'     => $this->decodeMime($header->subject ?? ''),
            'date'        => date('Y-m-d H:i:s', strtotime($header->date ?? 'now') ?: time()),
            'seen'        => ($header->Unseen ?? 'U') !== 'U',
            'size'        => (int)($header->Size ?? 0),
            'body'        => $text,
            'body_html'   => $html,
            'attachments' => $attachments,
        ];
    }

    // Unseen messages of the open folder (kept for callers that only want new mail)
    public function fetchUnseen(): array {
        $ids = imap_search($this->imap, 'UNSEEN');
        if (!$ids) return [];

        $messages = [];
        foreach ($ids as $id) {
            $uid = imap_uid($this->imap, $id);
            $msg = $this->fetchUid((int)$uid);
            if ($msg) $messages[] = $msg;
            imap_setflag_full($this->imap, (string)$id, '\\Seen');
        }
        return $messages;
    }

    /** Put a copy of an outgoing message into the Sent folder, like a mail client does. */
    public function appendSent(string $rawMessage, string $folder): bool {
        return (bool)@imap_append($this->imap, $this->mailboxRef($folder), $rawMessage, "\\Seen");
    }

    private function addr(?object $a): string {
        if (!$a || empty($a->mailbox) || empty($a->host)) return '';
        return $a->mailbox . '@' . $a->host;
    }

    private function addrList(array $list): string {
        $out = [];
        foreach ($list as $a) {
            $mail = $this->addr($a);
            if ($mail !== '') $out[] = $mail;
        }
        return implode(', ', $out);
    }

    // Extract text body, html body and attachments (FR-021)
    private function getBodyAndAttachments(int $id): array {
        $struct = imap_fetchstructure($this->imap, $id);
        $text = '';
        $html = '';
        $attachments = [];

        if (empty($struct->parts)) {
            $raw = imap_fetchbody($this->imap, $id, '1');
            if (trim($raw) === '') $raw = imap_body($this->imap, $id);
            $decoded = $this->toUtf8($this->decodeBody($raw, $struct->encoding ?? 0), $this->partCharset($struct));
            if (strtoupper($struct->subtype ?? 'PLAIN') === 'HTML') $html = $decoded; else $text = $decoded;
        } else {
            $this->walkParts($id, $struct->parts, '', $text, $html, $attachments);
        }

        // Fall back to HTML part when there is no text/plain
        if (trim($text) === '' && $html !== '') {
            $text = trim(html_entity_decode(
                strip_tags(preg_replace('#<br[^>]*>|</p>#i', "\n", $html)),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));
        }

        return [$text, $html, $attachments];
    }

    // Recursive MIME walk: collects text, html and attachment parts
    private function walkParts(int $id, array $parts, string $prefix, string &$text, string &$html, array &$attachments): void {
        foreach ($parts as $i => $part) {
            $section = $prefix === '' ? (string)($i + 1) : $prefix . '.' . ($i + 1);
            $filename = $this->partFilename($part);
            $disposition = strtolower($part->disposition ?? '');
            $isAttachment = $filename !== '' || $disposition === 'attachment';

            if (!empty($part->parts) && !$isAttachment) {
                $this->walkParts($id, $part->parts, $section, $text, $html, $attachments);
                continue;
            }

            if ($isAttachment) {
                // Skip oversized attachments — the mailbox is not a file server
                if (($part->bytes ?? 0) > 25 * 1024 * 1024) continue;
                $raw = imap_fetchbody($this->imap, $id, $section);
                $attachments[] = [
                    'filename' => $filename !== '' ? $filename : "part-$section",
                    'content'  => $this->decodeBody($raw, $part->encoding ?? 0),
                    'mime'     => $this->partMime($part),
                ];
                continue;
            }

            if (($part->type ?? 1) !== 0) continue; // not text
            $raw = imap_fetchbody($this->imap, $id, $section);
            $decoded = $this->toUtf8($this->decodeBody($raw, $part->encoding ?? 0), $this->partCharset($part));
            $subtype = strtoupper($part->subtype ?? '');
            if ($subtype === 'PLAIN') {
                $text .= ($text !== '' ? "\n" : '') . $decoded;
            } elseif ($subtype === 'HTML') {
                $html .= $decoded;
            }
        }
    }

    // Attachment filename from dparameters/parameters, MIME-decoded
    private function partFilename(object $part): string {
        foreach (['dparameters', 'parameters'] as $bag) {
            foreach ($part->$bag ?? [] as $p) {
                if (in_array(strtolower($p->attribute), ['filename', 'name'], true) && $p->value !== '') {
                    return $this->decodeMime($p->value);
                }
            }
        }
        return '';
    }

    private function partCharset(object $part): string {
        foreach ($part->parameters ?? [] as $p) {
            if (strtolower($p->attribute) === 'charset') return $p->value;
        }
        return 'UTF-8';
    }

    private function partMime(object $part): string {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $type = $types[$part->type ?? 7] ?? 'application';
        return $type . '/' . strtolower($part->subtype ?? 'octet-stream');
    }

    private function toUtf8(string $s, string $charset): string {
        $charset = strtoupper(trim($charset));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
        }
        $converted = @mb_convert_encoding($s, 'UTF-8', $charset);
        return $converted !== false ? $converted : $s;
    }

    private function decodeBody(string $body, int $encoding): string {
        return match ($encoding) {
            3 => base64_decode($body),       // BASE64
            4 => quoted_printable_decode($body), // QP
            default => $body,
        };
    }

    private function decodeMime(string $str): string {
        $decoded = imap_mime_header_decode($str);
        $result = '';
        foreach ($decoded as $part) {
            $result .= $part->text;
        }
        return $result;
    }

    public function close(): void {
        if ($this->imap) imap_close($this->imap);
    }
}

class EmailSender {
    private array $cfg;
    public ?string $lastRawMessage = null;   // MIME source of the last message, for IMAP APPEND

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    // Send email with optional attachments. $attachments: [[path, name], ...]
    public function send(string $to, string $subject, string $htmlBody, ?string $attachPath = null, ?string $attachName = null, array $opts = []): void {
        $mail = $this->prepare();
        $mail->addAddress($to);
        foreach ((array)($opts['cc'] ?? []) as $cc) {
            if (trim((string)$cc) !== '') $mail->addCC(trim((string)$cc));
        }
        if (!empty($opts['reply_to_message_id'])) {
            $mail->addCustomHeader('In-Reply-To', $opts['reply_to_message_id']);
            $mail->addCustomHeader('References', $opts['reply_to_message_id']);
        }
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $opts['text'] ?? strip_tags($htmlBody);

        if ($attachPath && file_exists($attachPath)) {
            $mail->addAttachment($attachPath, $attachName ?? basename($attachPath));
        }
        foreach ((array)($opts['attachments'] ?? []) as $a) {
            $path = is_array($a) ? ($a['path'] ?? '') : $a;
            if ($path && file_exists($path)) $mail->addAttachment($path, is_array($a) ? ($a['name'] ?? basename($path)) : basename($path));
        }

        $mail->send();
        $this->lastRawMessage = $mail->getSentMIMEMessage();
    }

    // Send plain text notification
    public function sendNotification(string $to, string $subject, string $text): void {
        $mail = $this->prepare();
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $text;
        $mail->send();
        $this->lastRawMessage = $mail->getSentMIMEMessage();
    }

    /** Connect and authenticate without sending anything — the «проверить почту» button. */
    public function testConnection(): array {
        $mail = $this->prepare();
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        if (!$mail->smtpConnect()) throw new RuntimeException('SMTP: не удалось подключиться — ' . $mail->ErrorInfo);
        $mail->smtpClose();
        return ['host' => $mail->Host, 'port' => $mail->Port, 'user' => $mail->Username];
    }

    private function prepare(): PHPMailer {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->cfg['SMTP_HOST'] ?? '';
        $mail->Port = (int)($this->cfg['SMTP_PORT'] ?? 465);
        $mail->SMTPAuth = ($this->cfg['SMTP_USER'] ?? '') !== '';
        $mail->Username = $this->cfg['SMTP_USER'] ?? '';
        $mail->Password = $this->cfg['SMTP_PASSWORD'] ?? '';
        $secure = $this->cfg['SMTP_ENCRYPTION'] ?? 'ssl';
        $mail->SMTPSecure = $secure === '' ? false : $secure;
        $mail->SMTPAutoTLS = $secure !== '';
        $mail->Timeout = (int)($this->cfg['SMTP_TIMEOUT'] ?? 20);
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            ($this->cfg['SMTP_FROM_EMAIL'] ?? '') ?: ($this->cfg['SMTP_USER'] ?? ''),
            ($this->cfg['SMTP_FROM_NAME'] ?? '') ?: 'Atlant Armour'
        );
        return $mail;
    }
}
