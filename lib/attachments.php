<?php
/**
 * Attachments: storage + text extraction (FR-021, FR-022).
 * PDF via vendored smalot/pdfparser, DOCX/XLSX via ZipArchive + SimpleXML,
 * scans via Yandex Vision OCR (C-010).
 */
class Attachments {

    private static bool $autoloadRegistered = false;

    // PSR-0 autoloader for the vendored pdfparser (kept out of composer vendor/)
    private static function registerPdfParser(): void {
        if (self::$autoloadRegistered) return;
        self::$autoloadRegistered = true;
        spl_autoload_register(function (string $class) {
            if (!str_starts_with($class, 'Smalot\\PdfParser\\')) return;
            $path = ROOT . '/lib/pdfparser/src/' . str_replace('\\', '/', $class) . '.php';
            if (is_file($path)) require_once $path;
        });
    }

    // Max attachment size to keep, in bytes
    public static function maxBytes(): int {
        return (int)(Db::val("SELECT value FROM settings WHERE key='attachment_max_mb'") ?: 10) * 1024 * 1024;
    }

    public static function ocrEnabled(): bool {
        return (string)(Db::val("SELECT value FROM settings WHERE key='ocr_enabled'") ?: '0') === '1';
    }

    /**
     * Store one attachment and extract its text.
     * $file: ['filename' => string, 'content' => binary, 'mime' => string]
     * $links: ['correspondence_id'|'request_id'|'counterparty_id' => int|null]
     * $opts:  ['ocr' => bool] — false skips recognition (the archive download does)
     */
    public static function store(array $file, array $links = [], array $opts = []): array {
        $name = self::sanitizeFilename($file['filename'] ?? 'attachment');
        $content = $file['content'] ?? '';
        $size = strlen($content);
        $mime = $file['mime'] ?? self::guessMime($name);
        $hash = sha1($content);

        // The bytes decide, not the name: a signature picture quoted back into
        // every letter of a thread, or the same letter re-read out of an mbox,
        // must not put a second copy of one file on one letter (module 021).
        if (!empty($links['mail_message_id'])) {
            $twin = Db::one("SELECT id, filename, extract_status FROM attachments WHERE mail_message_id=? AND content_hash=?",
                            [$links['mail_message_id'], $hash]);
            if ($twin) {
                return ['id' => (int)$twin['id'], 'filename' => (string)$twin['filename'],
                        'status' => (string)$twin['extract_status'], 'text' => '', 'duplicate' => true];
            }
        }

        $dir = ROOT . '/storage/attachments/' . date('Y/m');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . '/' . uniqid('', true) . '_' . $name;
        file_put_contents($path, $content);

        $text = '';
        $status = 'skipped';
        if ($size > self::maxBytes()) {
            $status = 'skipped'; // too big to parse, file still kept
        } else {
            try {
                [$text, $status] = self::extractText($path, $mime, $name, $opts);
            } catch (Throwable $e) {
                $text = '';
                $status = 'failed';
                Logger::exception('attachments', $e, ['file' => $name]);
            }
        }

        $id = Db::insert('attachments', [
            'correspondence_id' => $links['correspondence_id'] ?? null,
            'mail_message_id'   => $links['mail_message_id'] ?? null,
            'request_id'        => $links['request_id'] ?? null,
            'counterparty_id'   => $links['counterparty_id'] ?? null,
            'filename'          => $name,
            'path'              => str_replace(ROOT . '/', '', $path),
            'mime'              => $mime,
            'size'              => $size,
            'content_hash'      => $hash,
            'extracted_text'    => $text !== '' ? mb_substr($text, 0, 60000) : null,
            'extract_status'    => $status,
        ]);

        return ['id' => $id, 'filename' => $name, 'status' => $status, 'text' => $text];
    }

    // Dispatch by type. Returns [text, status]
    public static function extractText(string $path, string $mime, string $filename, array $opts = []): array {
        $ext = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $ocrAllowed = ($opts['ocr'] ?? true) && self::ocrEnabled();

        if ($ext === 'pdf' || str_contains($mime, 'pdf')) {
            $text = self::fromPdf($path);
            if (mb_strlen(trim($text)) >= 40) return [$text, 'ok'];
            // No usable text layer — likely a scan
            if ($ocrAllowed) {
                $ocr = self::ocr($path, 'application/pdf');
                if ($ocr !== null && trim($ocr) !== '') return [$ocr, 'ocr'];
            }
            return [$text, trim($text) === '' ? 'empty' : 'ok'];
        }

        if ($ext === 'docx') return [self::fromDocx($path), 'ok'];
        if ($ext === 'xlsx' || $ext === 'xlsm') return [self::fromXlsx($path), 'ok'];

        if (in_array($ext, ['txt', 'csv'], true) || str_starts_with($mime, 'text/')) {
            return [self::toUtf8(file_get_contents($path)), 'ok'];
        }

        if ($ext === 'odt' || $ext === 'ods') return [self::fromOpenDocument($path), 'ok'];
        if ($ext === 'rtf') return [self::fromRtf($path), 'ok'];

        // Legacy binary Word and Excel. 130 incoming .doc files of the archive
        // — карты партнёра, договоры, прайсы — used to be stored and never read
        // (module 015). There is no parser for them on shared hosting, so the
        // readable runs of text are pulled out of the stream itself.
        if (in_array($ext, ['doc', 'dot', 'xls', 'xlt'], true)
            || str_contains($mime, 'msword') || str_contains($mime, 'ms-excel')) {
            $text = self::fromLegacyOffice($path);
            return [$text, trim($text) === '' ? 'empty' : 'ok'];
        }

        if ($ext === 'zip' || str_contains($mime, 'zip')) return [self::fromZip($path, $opts), 'ok'];

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'heic', 'heif', 'webp', 'bmp', 'gif', 'jfif', 'tif', 'tiff'], true)
            || str_starts_with($mime, 'image/')) {
            if (!$ocrAllowed) return ['', 'skipped'];
            $ocr = self::ocr($path, self::imageMime($ext, $mime));
            return $ocr !== null ? [$ocr, 'ocr'] : ['', 'failed'];
        }

        return ['', 'skipped'];
    }

    /** Vision names the format explicitly; an unknown one goes in as JPEG. */
    private static function imageMime(string $ext, string $mime): string {
        return match ($ext) {
            'png'  => 'image/png',
            'heic', 'heif' => 'image/heic',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
            'gif'  => 'image/gif',
            'tif', 'tiff' => 'image/tiff',
            default => str_starts_with($mime, 'image/') && $mime !== 'image/jpeg' ? $mime : 'image/jpeg',
        };
    }

    /**
     * Readable text of a legacy .doc/.xls stream.
     *
     * The OLE container stores the text of a Word document as UTF-16LE runs and
     * the strings of a workbook as short records; both sit between long stretches
     * of binary. Pulling the runs out gives what a person would read — headings,
     * requisites, a price table — which is all the classifier and the requisites
     * reader need. It is not a converter and does not pretend to be one.
     */
    private static function fromLegacyOffice(string $path): string {
        $raw = (string)@file_get_contents($path, false, null, 0, 6 * 1024 * 1024);
        if ($raw === '') return '';

        $chunks = [];
        // UTF-16LE runs. Latin and digits are «X\x00»; Cyrillic lives on page
        // \x04 («К» is 1A 04), so both halves of the pair have to be allowed —
        // matching only printable low bytes would eat the first Russian letter
        // of every line.
        if (preg_match_all('/(?:[\x20-\x7E]\x00|[\x00-\x5F]\x04){6,}/', $raw, $m)) {
            foreach ($m[0] as $run) {
                $t = @iconv('UTF-16LE', 'UTF-8//IGNORE', $run);
                if ($t !== false) $chunks[] = $t;
            }
        }
        // Single-byte runs (older writers, and most of a .xls)
        if (preg_match_all('/[\x20-\x7E\xA0-\xFF]{10,}/', $raw, $m)) {
            foreach ($m[0] as $run) $chunks[] = self::toUtf8($run);
        }

        $text = implode("\n", $chunks);
        // Field codes and OLE bookkeeping read as text but mean nothing
        $text = (string)preg_replace('/\b(?:HYPERLINK|PAGEREF|MERGEFORMAT|Microsoft\s+\w+|Root\s+Entry|WordDocument|\w*Table|SummaryInformation|Objects?Pool|CompObj)\b/u', ' ', $text);
        $text = (string)preg_replace('/[^\P{C}\n]+/u', ' ', $text);
        $text = (string)preg_replace('/[ \t]{2,}/u', ' ', $text);
        $text = (string)preg_replace('/\n{2,}/u', "\n", $text);

        // Keep the lines a human could have written: a run of punctuation is
        // noise, and the same string often shows up in both passes.
        $keep = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (mb_strlen($line) < 4) continue;
            $letters = (int)preg_match_all('/[\p{L}\p{N}]/u', $line);
            if ($letters < mb_strlen($line) * 0.5) continue;
            $keep[mb_strtolower($line)] = $line;
        }
        return trim(implode("\n", array_slice(array_values($keep), 0, 2000)));
    }

    /** ODT/ODS — the same zip trick as DOCX, with OpenDocument's own part name. */
    private static function fromOpenDocument(string $path): string {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return '';
        $xml = $zip->getFromName('content.xml');
        $zip->close();
        if ($xml === false) return '';
        $xml = (string)preg_replace('#<text:(?:p|h)\b[^>]*>#', "\n", $xml);
        $xml = (string)preg_replace('#<table:table-cell\b[^>]*>#', "\t", $xml);
        return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** RTF — control words out, escaped Cyrillic back in. */
    private static function fromRtf(string $path): string {
        $raw = (string)@file_get_contents($path, false, null, 0, 4 * 1024 * 1024);
        if ($raw === '') return '';
        $raw = (string)preg_replace('/\{\\\*?(?:fonttbl|colortbl|stylesheet|info|pict|object)[^}]*\}/s', ' ', $raw);
        $raw = (string)preg_replace_callback("/\\\\'([0-9a-f]{2})/i",
            fn($m) => chr(hexdec($m[1])), $raw);
        $raw = (string)preg_replace_callback('/\\\\u(-?\d+)\??/',
            fn($m) => mb_chr(((int)$m[1] + 65536) % 65536, 'UTF-8') ?: '', $raw);
        $raw = (string)preg_replace('/\\\\par[d]?\b/', "\n", $raw);
        $raw = (string)preg_replace('/\\\\[a-z]+-?\d*\s?/i', ' ', $raw);
        $raw = str_replace(['{', '}'], ' ', $raw);
        $raw = self::toUtf8($raw);
        return trim((string)preg_replace('/[ \t]{2,}/u', ' ', $raw));
    }

    /**
     * A zip of documents — a spec, a card and a price list in one attachment.
     * Only the members we can already read are opened, and only a few of them:
     * an archive is not a reason to unpack a client's whole folder.
     */
    private static function fromZip(string $path, array $opts): string {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return '';
        $out = [];
        $opened = 0;
        for ($i = 0; $i < $zip->numFiles && $opened < 10; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'docx', 'xlsx', 'doc', 'xls', 'txt', 'csv', 'rtf', 'odt'], true)) continue;
            $stat = $zip->statIndex($i);
            if (($stat['size'] ?? 0) > 12 * 1024 * 1024) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'att');
            if ($tmp === false) continue;
            $data = $zip->getFromIndex($i);
            if ($data === false) { @unlink($tmp); continue; }
            file_put_contents($tmp, $data);
            // No OCR inside an archive: a zip of scans would spend the whole quota
            [$text] = self::extractText($tmp, '', $name, ['ocr' => false] + $opts);
            @unlink($tmp);
            $opened++;
            if (trim((string)$text) !== '') $out[] = "--- $name ---\n" . $text;
        }
        $zip->close();
        return trim(implode("\n\n", $out));
    }

    // PDF text layer
    private static function fromPdf(string $path): string {
        self::registerPdfParser();
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            return trim($pdf->getText());
        } catch (Throwable $e) {
            Logger::warning('attachments', 'PDF parse failed: ' . $e->getMessage(), ['file' => basename($path)]);
            return '';
        }
    }

    // DOCX — word/document.xml, paragraphs to lines
    private static function fromDocx(string $path): string {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return '';
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) return '';

        $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", "\t", "\n"], $xml);
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    // XLSX — shared strings + all sheets, rows as tab-separated lines
    private static function fromXlsx(string $path): string {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return '';

        // Shared strings
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $ss = @simplexml_load_string($ssXml);
            if ($ss) {
                foreach ($ss->si as $si) {
                    $s = '';
                    foreach ($si->xpath('.//*[local-name()="t"]') as $t) $s .= (string)$t;
                    $shared[] = $s;
                }
            }
        }

        $lines = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#', $entry)) continue;
            $sheet = @simplexml_load_string($zip->getFromName($entry));
            if (!$sheet) continue;

            foreach ($sheet->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $type = (string)$c['t'];
                    $v = null;
                    if ($type === 's') {
                        $idx = (int)$c->v;
                        $v = $shared[$idx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        foreach ($c->xpath('.//*[local-name()="t"]') as $t) $v .= (string)$t;
                    } else {
                        $v = (string)$c->v;
                    }
                    if ($v !== null && $v !== '') $cells[] = $v;
                }
                if ($cells) $lines[] = implode("\t", $cells);
            }
        }
        $zip->close();
        return trim(implode("\n", $lines));
    }

    /**
     * Yandex Vision OCR. Sync endpoint: one page per call for PDF.
     * Returns recognized text or null.
     */
    public static function ocr(string $path, string $mime): ?string {
        $cfg = $GLOBALS['cfg'] ?? [];
        $key = $cfg['YANDEX_API_KEY'] ?? '';
        $folder = $cfg['YANDEX_FOLDER_ID'] ?? '';
        if (!$key || !$folder) return null;

        $maxPages = (int)(Db::val("SELECT value FROM settings WHERE key='ocr_max_pages'") ?: 3);

        // Vision принимает запрос не больше 10 МБ, а файл едет в нём base64 —
        // это +33% к размеру. Скан на 9 МБ отвечал HTTP 400 без внятной
        // причины, и в журнале копились «Yandex Vision вернул HTTP 400»
        // (модуль 034). Такой файл не распознаём — и говорим почему.
        $size = (int)@filesize($path);
        if ($size <= 0 || (int)ceil($size * 4 / 3) > 9_500_000) {
            Logger::warning('ocr', $size <= 0
                ? 'Вложение пустое — распознавать нечего'
                : 'Вложение больше 7 МБ (' . round($size / 1048576, 1) . ' МБ) — Yandex Vision такие не принимает',
                ['path' => basename($path), 'bytes' => $size]);
            return null;
        }

        $content = base64_encode((string)file_get_contents($path));
        $out = [];

        // PDF: Vision recognizes one page per request, pass page index
        $pages = ($mime === 'application/pdf') ? $maxPages : 1;
        for ($page = 0; $page < $pages; $page++) {
            $body = [
                'mimeType'      => $mime,
                'languageCodes' => ['ru', 'en'],
                'model'         => 'page',
                'content'       => $content,
            ];
            if ($mime === 'application/pdf') $body['page'] = (string)$page;

            [$code, $resp] = self::ocrRequest($body, $key, $folder);

            if ($code !== 200) {
                if ($page === 0) {
                    // 429 is Vision's rate limit, not a broken setup — a whole archive
                    // download hits it routinely, so it is a warning with a hint
                    $rate = $code === 429;
                    // Причина, которую назвал Vision, — в самом заголовке записи:
                    // «HTTP 400» без неё ничего не говорит тому, кто читает журнал
                    $why = self::visionMessage((string)$resp);
                    Logger::log($rate ? 'warning' : 'error', 'ocr',
                        "Yandex Vision вернул HTTP $code"
                        . ($rate ? ' — превышен лимит запросов, распознавание пропущено' : ($why !== '' ? ' — ' . $why : '')),
                        ['response' => substr((string)$resp, 0, 500), 'mime' => $mime]);
                    return null;
                }
                break; // no more pages
            }
            $data = json_decode((string)$resp, true);
            $text = $data['result']['textAnnotation']['fullText'] ?? '';
            if (trim($text) === '') break;
            $out[] = $text;
        }

        return $out ? implode("\n\n", $out) : null;
    }

    /** Что Vision сказал про отказ — одной строкой для журнала. */
    private static function visionMessage(string $body): string {
        $data = json_decode($body, true);
        if (!is_array($data)) return '';
        $msg = (string)($data['message'] ?? ($data['error']['message'] ?? ($data['error'] ?? '')));
        return trim(mb_substr($msg, 0, 200));
    }

    /** One Vision call, retried with a pause while the service answers 429/5xx. */
    private static function ocrRequest(array $body, string $key, string $folder, int $attempts = 3): array {
        $code = 0;
        $resp = '';
        for ($try = 1; $try <= $attempts; $try++) {
            $ch = curl_init('https://ocr.api.cloud.yandex.net/ocr/v1/recognizeText');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Api-Key ' . $key,
                    'x-folder-id: ' . $folder,
                    'x-data-logging-enabled: false',
                ],
            ]);
            $resp = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($code !== 429 && $code < 500) break;
            if ($try < $attempts) sleep($try);   // 1s, then 2s — Vision limits requests per second
        }
        return [$code, $resp];
    }

    // All extracted attachment text for a request, for the LLM parser
    public static function textForRequest(int $requestId, int $maxChars = 12000): string {
        $rows = Db::all(
            "SELECT filename, extracted_text FROM attachments
             WHERE request_id = ? AND extracted_text IS NOT NULL AND extracted_text != ''
             ORDER BY id",
            [$requestId]
        );
        $parts = [];
        $len = 0;
        foreach ($rows as $r) {
            $chunk = "--- Вложение: {$r['filename']} ---\n" . $r['extracted_text'];
            $len += mb_strlen($chunk);
            if ($len > $maxChars) {
                $parts[] = mb_substr($chunk, 0, max(0, $maxChars - ($len - mb_strlen($chunk))));
                break;
            }
            $parts[] = $chunk;
        }
        return implode("\n\n", $parts);
    }

    public static function listForRequest(int $requestId): array {
        return Db::all(
            "SELECT id, filename, mime, size, extract_status FROM attachments WHERE request_id=? ORDER BY id",
            [$requestId]
        );
    }

    // cp1251 → utf8 when needed
    private static function toUtf8(string $s): string {
        return utf8Text($s);
    }

    /**
     * `Content-Disposition` for a download. A plain `filename="..."` with raw
     * Cyrillic bytes is what produced the unreadable name in the browser's save
     * dialog — RFC 6266/5987's `filename*=UTF-8''...` is what every current
     * browser actually reads a non-ASCII name from; the quoted `filename=` next
     * to it is an ASCII fallback for anything older.
     */
    public static function contentDisposition(string $filename, bool $inline = false): string {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'attachment';
        $ascii = str_replace('"', "'", $ascii);
        $kind = $inline ? 'inline' : 'attachment';
        return $kind . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    private static function sanitizeFilename(string $name): string {
        $name = basename(str_replace('\\', '/', $name));
        // A filename from an old letter is not always valid UTF-8; the /u patterns
        // below return null on such a string, so it is repaired first
        if (!mb_check_encoding($name, 'UTF-8')) $name = self::toUtf8($name);
        $name = preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '_', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        return mb_substr($name === '' ? 'attachment' : $name, 0, 120);
    }

    private static function guessMime(string $name): string {
        return match (mb_strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'doc'  => 'application/msword',
            'xls'  => 'application/vnd.ms-excel',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
            default => 'application/octet-stream',
        };
    }
}
