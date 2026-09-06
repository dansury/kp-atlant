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
        return '{' . $host . ':' . $port . $flags . '}' . self::encodeFolder($folder);
    }

    /**
     * IMAP folder names travel in modified UTF-7 (RFC 2060). Yandex and Mail.ru
     * name their folders in Russian («Отправленные»), so a plain UTF-8 name
     * simply does not open.
     */
    public static function encodeFolder(string $folder): string {
        if ($folder === '' || !preg_match('/[^\x20-\x7E]|&/', $folder)) return $folder;
        if (function_exists('imap_utf8_to_mutf7')) {
            $encoded = imap_utf8_to_mutf7($folder);
            if ($encoded !== false) return $encoded;
        }
        // Older builds of ext-imap lack the helper — «Отправленные» still has to work
        $out = '';
        $buffer = '';
        foreach (preg_split('//u', $folder, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $plain = strlen($char) === 1 && ord($char) >= 0x20 && ord($char) <= 0x7E && $char !== '&';
            if ($plain) {
                $out .= self::mutf7Chunk($buffer) . $char;
                $buffer = '';
            } elseif ($char === '&') {
                $out .= self::mutf7Chunk($buffer) . '&-';
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }
        return $out . self::mutf7Chunk($buffer);
    }

    /** One run of non-ASCII characters as &base64-of-UTF-16BE- (RFC 2060 flavour). */
    private static function mutf7Chunk(string $run): string {
        if ($run === '') return '';
        $b64 = base64_encode(mb_convert_encoding($run, 'UTF-16BE', 'UTF-8'));
        return '&' . rtrim(strtr($b64, '/', ','), '=') . '-';
    }

    public static function decodeFolder(string $folder): string {
        if ($folder === '' || strpos($folder, '&') === false) return $folder;
        if (function_exists('imap_mutf7_to_utf8')) {
            $decoded = imap_mutf7_to_utf8($folder);
            if ($decoded !== false) return $decoded;
        }
        return preg_replace_callback('/&([A-Za-z0-9+,]*)-/', function ($m) {
            if ($m[1] === '') return '&';
            $b64 = strtr($m[1], ',', '/');
            $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
            return mb_convert_encoding((string)base64_decode($b64), 'UTF-8', 'UTF-16BE');
        }, $folder);
    }

    /** Folder names of the account — the admin picks INBOX / Sent from a real list. */
    public function folders(): array {
        $prefix = $this->mailboxRef('');
        $list = @imap_list($this->imap, $prefix, '*') ?: [];
        return array_map(fn($f) => self::decodeFolder(str_replace($prefix, '', $f)), $list);
    }

    public function messageCount(): int {
        $info = @imap_check($this->imap);
        return $info ? (int)$info->Nmsgs : 0;
    }

    /** Highest UID in the open folder — the finish line of a full archive download. */
    public function maxUid(): int {
        $count = $this->messageCount();
        if ($count < 1) return 0;
        return (int)@imap_uid($this->imap, $count);
    }

    /** UIDs present in a range, ascending. Ranges are sparse: deleted mail leaves gaps. */
    public function uidsInRange(int $from, int $to): array {
        if ($to < $from) return [];
        $overviews = @imap_fetch_overview($this->imap, "$from:$to", FT_UID) ?: [];
        $uids = [];
        foreach ($overviews as $ov) {
            $uid = (int)($ov->uid ?? 0);
            if ($uid >= $from && $uid <= $to) $uids[] = $uid;   // "*" ranges answer beyond the bounds
        }
        sort($uids);
        return $uids;
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
        // Raw headers: the triage prefilter reads List-Unsubscribe / Precedence /
        // Auto-Submitted from them to spot a mailing before spending a model call.
        $raw = (string)@imap_fetchheader($this->imap, $no);

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
            'headers'     => mb_strcut($raw, 0, 16384),
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

    /**
     * The account's real «Отправленные» folder.
     *
     * A copy of an outgoing letter kept going nowhere because the folder written
     * in the mailbox settings did not exist on the server: Yandex calls it
     * «Отправленные», Gmail «[Gmail]/Отправленные», cPanel «INBOX.Sent». The
     * name is therefore matched against what IMAP LIST actually reports, and the
     * configured one is trusted only when the server confirms it.
     */
    public function findSentFolder(string $configured = ''): ?string {
        $folders = $this->folders();
        if (!$folders) return $configured !== '' ? $configured : null;

        $eq = fn(string $a, string $b) => mb_strtolower(trim($a)) === mb_strtolower(trim($b));
        foreach ($folders as $f) {
            if ($configured !== '' && $eq($f, $configured)) return $f;
        }

        // Known names first, then anything whose last segment looks like «sent»
        $known = ['Отправленные', 'Sent', 'INBOX.Sent', 'Sent Items', 'Sent Messages',
                  '[Gmail]/Отправленные', '[Gmail]/Sent Mail', 'INBOX.Отправленные'];
        foreach ($known as $name) {
            foreach ($folders as $f) if ($eq($f, $name)) return $f;
        }
        foreach ($folders as $f) {
            $leaf = mb_strtolower((string)preg_replace('#^.*[/.]#u', '', $f));
            if (in_array($leaf, ['sent', 'отправленные', 'sent mail', 'sent items'], true)) return $f;
        }
        return $configured !== '' ? $configured : null;
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

    /**
     * MIME-encoded header → UTF-8.
     *
     * imap_mime_header_decode() decodes the base64/QP wrapper but leaves every
     * chunk in ITS OWN charset and reports it separately. Concatenating the raw
     * chunks produced a string that was part UTF-8 and part windows-1251, which
     * survived neither the archive nor json_encode: subjects reached the browser
     * as a row of «?» boxes. Each chunk is converted before it is joined.
     */
    private function decodeMime(string $str): string {
        if (trim($str) === '') return '';
        $parts = imap_mime_header_decode($str);
        if (!$parts) return utf8Text($str);

        $result = '';
        foreach ($parts as $part) {
            $text = (string)($part->text ?? '');
            $charset = strtoupper(trim((string)($part->charset ?? 'default')));
            // «default» means the chunk was not encoded at all: ASCII, or the
            // 8-bit bytes some clients drop into a header raw
            $result .= in_array($charset, ['DEFAULT', 'UTF-8', 'US-ASCII', 'ASCII', ''], true)
                ? utf8Text($text)
                : $this->toUtf8($text, $charset);
        }
        return utf8Text($result);
    }

    public function close(): void {
        if ($this->imap) imap_close($this->imap);
    }
}

class EmailSender {
    private array $cfg;
    public ?string $lastRawMessage = null;   // MIME source of the last message, for IMAP APPEND
    public ?string $lastMessageId = null;    // its Message-ID: the archive dedups the Sent sync by it

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    // Send email with optional attachments. $attachments: [[path, name], ...]
    public function send(string $to, string $subject, string $htmlBody, ?string $attachPath = null, ?string $attachName = null, array $opts = []): void {
        $dialog = [];
        $mail = $this->prepare($dialog);
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

        self::deliver($mail, $dialog);
        $this->remember($mail);
    }

    // Send plain text notification
    public function sendNotification(string $to, string $subject, string $text): void {
        $dialog = [];
        $mail = $this->prepare($dialog);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $text;
        self::deliver($mail, $dialog);
        $this->remember($mail);
    }

    /**
     * Keep the sent message and its Message-ID. Without the id the copy that the
     * next IMAP sync pulls back out of «Отправленные» looks like a different
     * letter, and the thread shows every answer twice.
     */
    private function remember(PHPMailer $mail): void {
        $this->lastRawMessage = $mail->getSentMIMEMessage();
        $this->lastMessageId = null;
        if (preg_match('/^Message-ID:\s*(<[^>]+>)/mi', (string)$this->lastRawMessage, $m)) {
            $this->lastMessageId = trim($m[1]);
        }
    }

    /** Send, and turn PHPMailer's terse failure into something an admin can act on. */
    private static function deliver(PHPMailer $mail, array &$dialog): void {
        try {
            $mail->send();
        } catch (Throwable $e) {
            throw new RuntimeException(self::explain($e->getMessage(), $dialog), 0, $e);
        }
    }

    /** Connect and authenticate without sending anything — the «проверить почту» button. */
    public function testConnection(): array {
        $dialog = [];
        $mail = $this->prepare($dialog);
        try {
            if (!$mail->smtpConnect()) {
                throw new RuntimeException('не удалось подключиться — ' . $mail->ErrorInfo);
            }
        } catch (Throwable $e) {
            throw new RuntimeException(self::explain($e->getMessage(), $dialog));
        }
        $mail->smtpClose();
        return [
            'host' => $mail->Host,
            'port' => $mail->Port,
            'user' => $mail->Username,
        ];
    }

    /**
     * «SMTP Error: Could not authenticate» says nothing on its own. The server's own
     * answer does, so the dialog goes into the message — and Yandex, Mail.ru and
     * Gmail all mean the same thing by it: the app password is missing or wrong.
     */
    private static function explain(string $error, array $dialog): string {
        $answer = '';
        foreach (array_reverse($dialog) as $line) {
            if (preg_match('/SERVER -> CLIENT:\s*(5\d\d.*)/', $line, $m)) { $answer = trim($m[1]); break; }
        }
        $msg = trim($error);
        if ($answer !== '') $msg .= ' | ответ сервера: ' . mb_substr($answer, 0, 300);
        if (stripos($error, 'authenticate') !== false) {
            $msg .= ' | Проверьте логин (обычно полный адрес) и пароль приложения — '
                  . 'для Яндекса, Mail.ru и Gmail обычный пароль от аккаунта по SMTP не работает. '
                  . 'Если пароль вводился только в поле IMAP, впишите его и в поле SMTP.';
        }
        return $msg;
    }

    private function prepare(?array &$dialog = null): PHPMailer {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        if ($dialog !== null) {
            // PHPMailer hides the credentials itself, so the dialog is safe to keep
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function (string $str, int $level) use (&$dialog): void {
                $dialog[] = trim($str);
            };
        }
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
