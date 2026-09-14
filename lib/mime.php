<?php
/**
 * RFC 5322 / MIME parsing WITHOUT ext/imap (module 021).
 *
 * `EmailReader` reads letters through the IMAP extension, which does the
 * decoding itself. An mbox file has no server behind it — and importing one is
 * exactly what an operator does when the hosting has no imap extension at all —
 * so the whole walk (folded headers, RFC 2047 words, multipart boundaries,
 * base64/quoted-printable, charsets) lives here in plain PHP.
 *
 * The result is the SAME array shape `EmailReader::fetchSince()` returns, so
 * `MailArchive::storeIncoming()` cannot tell the two sources apart.
 */
final class Mime {
    /** Nested multiparts deeper than this are a malformed letter, not a letter. */
    private const MAX_DEPTH = 12;
    private const MAX_PARTS = 200;

    /**
     * Raw message (headers + body) → the archive's message array.
     * $opts: ['max_attachment_bytes' => int] — a bigger file is counted and dropped.
     */
    public static function parseMessage(string $raw, array $opts = []): array {
        [$headerBlock, $body] = self::split($raw);
        $headers = self::parseHeaders($headerBlock);

        $msg = [
            'message_id'  => self::angle(self::header($headers, 'message-id')),
            'in_reply_to' => self::angle(self::header($headers, 'in-reply-to')
                             ?: self::lastReference(self::header($headers, 'references'))),
            'subject'     => self::decodeHeader(self::header($headers, 'subject')),
            'from'        => self::firstAddress(self::header($headers, 'from')),
            'from_name'   => self::addressName(self::header($headers, 'from')),
            'to'          => self::addressList(self::header($headers, 'to')),
            'cc'          => self::addressList(self::header($headers, 'cc')),
            'date'        => self::parseDate(self::header($headers, 'date')),
            'headers'     => $headerBlock,
            'size'        => strlen($raw),
            'labels'      => self::decodeHeader(self::header($headers, 'x-gmail-labels')),
            'body'        => '',
            'body_html'   => '',
            'attachments' => [],
            'oversized'   => 0,
        ];

        $text = '';
        $html = '';
        $files = [];
        $count = 0;
        self::walk($headers, $body, 0, $count, $text, $html, $files, $opts, $msg['oversized']);

        // A letter that is only HTML still has to be readable as text: the triage,
        // the thread preview and every prompt read `body_text`, never the markup.
        if (trim($text) === '' && trim($html) !== '') {
            require_once __DIR__ . '/mail_text.php';
            $text = MailText::fromHtml($html);
        }

        $msg['body'] = utf8Text($text);
        $msg['body_html'] = utf8Text($html);
        $msg['attachments'] = $files;
        return $msg;
    }

    /** Headers and body of a message or a part. */
    private static function split(string $raw): array {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        if ($pos === false) return [rtrim($raw, "\n"), ''];
        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /**
     * Folded header block → ['name' => [values]]. Names are lower-cased; a header
     * that appears twice (Received, and a Subject some gateways duplicate) keeps
     * both values in order.
     */
    public static function parseHeaders(string $block): array {
        $out = [];
        $name = '';
        foreach (preg_split('/\r\n|\r|\n/', $block) ?: [] as $line) {
            if ($line === '') continue;
            if (($line[0] === ' ' || $line[0] === "\t") && $name !== '') {
                $out[$name][count($out[$name]) - 1] .= ' ' . trim($line);
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) continue;
            $name = strtolower(trim(substr($line, 0, $colon)));
            $out[$name][] = trim(substr($line, $colon + 1));
        }
        return $out;
    }

    public static function header(array $headers, string $name): string {
        return (string)($headers[strtolower($name)][0] ?? '');
    }

    /**
     * One part of the tree. text/plain and text/html with no filename are the
     * letter; everything else — including an inline image a signature carries —
     * is a file, because that is how a manager reads it in the card.
     */
    private static function walk(array $headers, string $body, int $depth, int &$count,
                                 string &$text, string &$html, array &$files, array $opts, int &$oversized): void {
        if ($depth > self::MAX_DEPTH || $count > self::MAX_PARTS) return;
        $count++;

        $ctype = strtolower(self::header($headers, 'content-type')) ?: 'text/plain';
        $mime = trim(explode(';', $ctype)[0]);
        $disposition = strtolower(trim(explode(';', self::header($headers, 'content-disposition'))[0]));
        $filename = self::partFilename($headers);

        if (str_starts_with($mime, 'multipart/')) {
            $boundary = self::param(self::header($headers, 'content-type'), 'boundary');
            if ($boundary === '') return;
            foreach (self::splitParts($body, $boundary) as $part) {
                [$partHeaders, $partBody] = self::split($part);
                self::walk(self::parseHeaders($partHeaders), $partBody, $depth + 1, $count,
                           $text, $html, $files, $opts, $oversized);
            }
            return;
        }

        $encoding = strtolower(self::header($headers, 'content-transfer-encoding'));
        $content = self::decodeBody($body, $encoding);

        $isText = $mime === 'text/plain' || $mime === 'text/html';
        if ($isText && $filename === '' && $disposition !== 'attachment') {
            $charset = self::param(self::header($headers, 'content-type'), 'charset');
            $decoded = self::toUtf8($content, $charset);
            if ($mime === 'text/html') {
                $html .= ($html !== '' ? "\n" : '') . $decoded;
            } else {
                $text .= ($text !== '' ? "\n" : '') . $decoded;
            }
            return;
        }

        // message/rfc822: the forwarded letter itself, kept as a file a manager
        // can open — unpacking it into this letter's body would mix two senders.
        if ($filename === '') {
            $filename = $mime === 'message/rfc822'
                ? 'forwarded-' . ($count) . '.eml'
                : 'part-' . $count . '.' . self::extensionFor($mime);
        }

        $max = (int)($opts['max_attachment_bytes'] ?? 0);
        if ($max > 0 && strlen($content) > $max) { $oversized++; return; }
        if ($content === '') return;

        $files[] = ['filename' => $filename, 'content' => $content, 'mime' => $mime];
    }

    /** Body between the boundary markers; the epilogue after `--boundary--` is dropped. */
    private static function splitParts(string $body, string $boundary): array {
        $body = str_replace("\r\n", "\n", $body);
        $marker = '--' . $boundary;
        $chunks = preg_split('/^' . preg_quote($marker, '/') . '(--)?[ \t]*$/m', $body) ?: [];
        array_shift($chunks);   // preamble: text for a client that cannot read MIME

        $parts = [];
        foreach ($chunks as $chunk) {
            $chunk = preg_replace('/^\n/', '', (string)$chunk);
            if (trim($chunk) !== '') $parts[] = $chunk;
        }
        return $parts;
    }

    private static function decodeBody(string $body, string $encoding): string {
        return match (trim($encoding)) {
            'base64'           => (string)base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    public static function toUtf8(string $s, string $charset): string {
        $charset = strtoupper(trim($charset, " \t\"'"));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8') return utf8Text($s);
        if (in_array($charset, ['US-ASCII', 'ASCII', 'ANSI_X3.4-1968'], true)) return utf8Text($s);
        // KOI8-R and cp1251 are half the Russian mail of the 2000s; an alias the
        // server never heard of must not lose the letter, only its accents.
        $converted = @mb_convert_encoding($s, 'UTF-8', $charset);
        if ($converted === false || $converted === '') $converted = (string)@iconv($charset, 'UTF-8//TRANSLIT', $s);
        return utf8Text($converted !== false && $converted !== '' ? $converted : $s);
    }

    /**
     * RFC 2047 «=?windows-1251?B?…?=» → UTF-8, without ext/imap.
     *
     * Each word is converted in ITS OWN charset before the words are joined —
     * concatenating raw chunks is what turns a subject into a row of «?» boxes.
     * Whitespace BETWEEN two encoded words is not part of the text (RFC 2047 §6.2).
     */
    public static function decodeHeader(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        if (!str_contains($value, '=?')) return utf8Text($value);

        $out = '';
        $offset = 0;
        $previousWasEncoded = false;
        $re = '/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/';
        while (preg_match($re, $value, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = (int)$m[0][1];
            $between = substr($value, $offset, $start - $offset);
            if (!($previousWasEncoded && trim($between) === '')) $out .= utf8Text($between);

            $charset = $m[1][0];
            $text = strtolower($m[2][0]) === 'b'
                ? (string)base64_decode($m[3][0], false)
                : quoted_printable_decode(str_replace('_', ' ', $m[3][0]));
            $out .= self::toUtf8($text, $charset);

            $offset = $start + strlen($m[0][0]);
            $previousWasEncoded = true;
        }
        return trim($out . utf8Text(substr($value, $offset)));
    }

    /** `filename="…"`, RFC 2231 `filename*=utf-8''…` and its numbered segments. */
    private static function partFilename(array $headers): string {
        foreach (['content-disposition', 'content-type'] as $key) {
            $raw = self::header($headers, $key);
            if ($raw === '') continue;
            foreach (['filename', 'name'] as $attr) {
                $segments = [];
                if (preg_match_all('/;\s*' . $attr . '\*(\d+)\*?\s*=\s*([^;]+)/i', $raw, $m, PREG_SET_ORDER)) {
                    foreach ($m as $hit) $segments[(int)$hit[1]] = trim($hit[2], " \t\"");
                }
                if ($segments) {
                    ksort($segments);
                    $name = self::decodeRfc2231(implode('', $segments));
                    if ($name !== '') return $name;
                }
                if (preg_match('/;\s*' . $attr . '\*\s*=\s*([^;]+)/i', $raw, $m)) {
                    $name = self::decodeRfc2231(trim($m[1], " \t\""));
                    if ($name !== '') return $name;
                }
                $plain = self::param($raw, $attr);
                if ($plain !== '') return self::decodeHeader($plain);
            }
        }
        return '';
    }

    /** `UTF-8''%D0%9A…` → UTF-8. */
    private static function decodeRfc2231(string $value): string {
        if (preg_match("/^([^']*)'([^']*)'(.*)$/s", $value, $m)) {
            return self::toUtf8(rawurldecode($m[3]), $m[1] !== '' ? $m[1] : 'UTF-8');
        }
        return self::toUtf8(rawurldecode($value), 'UTF-8');
    }

    /** One parameter of a structured header: `charset`, `boundary`, `name`. */
    public static function param(string $header, string $name): string {
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $header, $m)) return $m[1];
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*([^;\s]+)/i', $header, $m)) return trim($m[1], '"');
        return '';
    }

    /** `<id@host>` → `id@host`; a bare value stays as it is. */
    public static function angle(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        if (preg_match('/<([^>]+)>/', $value, $m)) return trim($m[1]);
        return $value;
    }

    /** In-Reply-To is missing more often than References — the parent is its last id. */
    private static function lastReference(string $references): string {
        if (preg_match_all('/<([^>]+)>/', $references, $m) && $m[1]) return '<' . end($m[1]) . '>';
        return '';
    }

    /** First address of a header: `«Пётр» <p@corp.ru>, s@corp.ru` → `p@corp.ru`. */
    public static function firstAddress(string $header): string {
        $list = self::addresses($header);
        return $list ? $list[0]['email'] : '';
    }

    public static function addressName(string $header): string {
        $list = self::addresses($header);
        return $list ? $list[0]['name'] : '';
    }

    /** Comma-separated addresses as one string, the way the archive stores them. */
    public static function addressList(string $header): string {
        return implode(', ', array_map(fn($a) => $a['email'], self::addresses($header)));
    }

    /**
     * Address header → [['name' => …, 'email' => …], …].
     * Commas inside a quoted display name («Иванов, Пётр» <p@…>) do not split it.
     */
    public static function addresses(string $header): array {
        $header = self::decodeHeader($header);
        if (trim($header) === '') return [];

        $items = [];
        $buffer = '';
        $inQuotes = false;
        $inAngle = false;
        foreach (str_split($header) as $ch) {
            if ($ch === '"') $inQuotes = !$inQuotes;
            if ($ch === '<') $inAngle = true;
            if ($ch === '>') $inAngle = false;
            if (($ch === ',' || $ch === ';') && !$inQuotes && !$inAngle) {
                $items[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        $items[] = $buffer;

        $out = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') continue;
            $name = '';
            $email = $item;
            if (preg_match('/^(.*?)<([^>]+)>\s*$/s', $item, $m)) {
                $name = trim(trim($m[1]), '"\' ');
                $email = trim($m[2]);
            }
            $email = trim($email, '<> ');
            if ($email === '') continue;
            $out[] = ['name' => $name, 'email' => mb_strtolower($email)];
        }
        return $out;
    }

    /** Date header → «Y-m-d H:i:s» in the service's own timezone. */
    public static function parseDate(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        // «Mon, 5 Sep 2022 10:03:11 +0300 (MSK)» — the trailing zone name is a
        // comment, and strtotime() reads some of them as a different zone
        $value = (string)preg_replace('/\s*\([^)]*\)\s*$/', '', $value);
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : '';
    }

    private static function extensionFor(string $mime): string {
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'application/pdf' => 'pdf', 'text/calendar' => 'ics', 'application/zip' => 'zip',
            default => 'bin',
        };
    }
}
