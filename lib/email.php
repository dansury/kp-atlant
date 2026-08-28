<?php
/**
 * Email: IMAP reader + SMTP sender via PHPMailer.
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class EmailReader {
    private $imap;
    private array $cfg;

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    // Connect to IMAP
    public function connect(): void {
        $host = $this->cfg['IMAP_HOST'];
        $port = $this->cfg['IMAP_PORT'] ?? 993;
        $enc = $this->cfg['IMAP_ENCRYPTION'] ?? 'ssl';
        $mailbox = "{" . $host . ":" . $port . "/imap/" . $enc . "}INBOX";
        $this->imap = imap_open($mailbox, $this->cfg['IMAP_USER'], $this->cfg['IMAP_PASSWORD']);
        if (!$this->imap) throw new RuntimeException('IMAP connect failed: ' . imap_last_error());
    }

    // Fetch unseen messages
    public function fetchUnseen(): array {
        $ids = imap_search($this->imap, 'UNSEEN');
        if (!$ids) return [];

        $messages = [];
        foreach ($ids as $id) {
            $header = imap_headerinfo($this->imap, $id);
            [$body, $attachments] = $this->getBodyAndAttachments($id);
            $messages[] = [
                'uid' => imap_uid($this->imap, $id),
                'message_id' => $header->message_id ?? '',
                'from' => $header->from[0]->mailbox . '@' . $header->from[0]->host,
                'from_name' => $this->decodeMime($header->from[0]->personal ?? ''),
                'subject' => $this->decodeMime($header->subject ?? ''),
                'date' => date('Y-m-d H:i:s', strtotime($header->date)),
                'body' => $body,
                'attachments' => $attachments,
            ];
            imap_setflag_full($this->imap, (string)$id, '\\Seen');
        }
        return $messages;
    }

    // Extract text body + attachments (FR-021)
    private function getBodyAndAttachments(int $id): array {
        $struct = imap_fetchstructure($this->imap, $id);
        $text = '';
        $html = '';
        $attachments = [];

        if (empty($struct->parts)) {
            $raw = imap_fetchbody($this->imap, $id, '1');
            if (trim($raw) === '') $raw = imap_body($this->imap, $id);
            $text = $this->toUtf8(
                $this->decodeBody($raw, $struct->encoding ?? 0),
                $this->partCharset($struct)
            );
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

        return [$text, $attachments];
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

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
    }

    // Send email with optional PDF attachment
    public function send(string $to, string $subject, string $htmlBody, ?string $attachPath = null, ?string $attachName = null): void {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->cfg['SMTP_HOST'];
        $mail->Port = $this->cfg['SMTP_PORT'] ?? 465;
        $mail->SMTPAuth = true;
        $mail->Username = $this->cfg['SMTP_USER'];
        $mail->Password = $this->cfg['SMTP_PASSWORD'];
        $mail->SMTPSecure = $this->cfg['SMTP_ENCRYPTION'] ?? 'ssl';
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            $this->cfg['SMTP_FROM_EMAIL'] ?? $this->cfg['SMTP_USER'],
            $this->cfg['SMTP_FROM_NAME'] ?? 'Atlant Armour'
        );
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        if ($attachPath && file_exists($attachPath)) {
            $mail->addAttachment($attachPath, $attachName ?? basename($attachPath));
        }

        $mail->send();
    }

    // Send plain text notification
    public function sendNotification(string $to, string $subject, string $text): void {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->cfg['SMTP_HOST'];
        $mail->Port = $this->cfg['SMTP_PORT'] ?? 465;
        $mail->SMTPAuth = true;
        $mail->Username = $this->cfg['SMTP_USER'];
        $mail->Password = $this->cfg['SMTP_PASSWORD'];
        $mail->SMTPSecure = $this->cfg['SMTP_ENCRYPTION'] ?? 'ssl';
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            $this->cfg['SMTP_FROM_EMAIL'] ?? $this->cfg['SMTP_USER'],
            $this->cfg['SMTP_FROM_NAME'] ?? 'Atlant Armour КП'
        );
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $text;

        $mail->send();
    }
}
