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
            $body = $this->getBody($id);
            $messages[] = [
                'uid' => imap_uid($this->imap, $id),
                'message_id' => $header->message_id ?? '',
                'from' => $header->from[0]->mailbox . '@' . $header->from[0]->host,
                'from_name' => $this->decodeMime($header->from[0]->personal ?? ''),
                'subject' => $this->decodeMime($header->subject ?? ''),
                'date' => date('Y-m-d H:i:s', strtotime($header->date)),
                'body' => $body,
            ];
            imap_setflag_full($this->imap, (string)$id, '\\Seen');
        }
        return $messages;
    }

    // Extract plain text body
    private function getBody(int $id): string {
        $struct = imap_fetchstructure($this->imap, $id);
        // Simple text message
        if ($struct->type === 0) {
            $body = imap_fetchbody($this->imap, $id, '1');
            return $this->decodeBody($body, $struct->encoding);
        }
        // Multipart — find text/plain
        if ($struct->type === 1 && !empty($struct->parts)) {
            foreach ($struct->parts as $i => $part) {
                if ($part->subtype === 'PLAIN') {
                    $body = imap_fetchbody($this->imap, $id, (string)($i + 1));
                    return $this->decodeBody($body, $part->encoding);
                }
            }
            // Fallback: first part
            $body = imap_fetchbody($this->imap, $id, '1');
            return $this->decodeBody($body, $struct->parts[0]->encoding ?? 0);
        }
        return imap_fetchbody($this->imap, $id, '1');
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
