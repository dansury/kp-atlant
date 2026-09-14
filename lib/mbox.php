<?php
/**
 * Import of an mbox archive — the file Gmail («Скачать данные»), Thunderbird and
 * every Unix mail client export (module 021).
 *
 * The point is history: three years of correspondence that already happened land
 * on the company cards they belong to, in the order they were written, with the
 * files that travelled with them — and WITHOUT a second copy of a letter the
 * archive already holds (`MailArchive::exists()` does the deciding).
 *
 * A Gmail export is measured in gigabytes, so the import is resumable the way
 * «Скачать весь архив» is: one step reads for a few seconds, stores what it read
 * and remembers the BYTE OFFSET of the message it did not get to. A shared host
 * kills the request long before the file ends, and the next step continues from
 * that offset instead of starting over.
 */
require_once __DIR__ . '/mime.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/mailsync.php';
require_once __DIR__ . '/crm.php';

final class MboxImport {
    /** Files land here: uploaded through the panel, or put next to it over FTP. */
    public static function dir(): string {
        $dir = ROOT . '/storage/mbox';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    /** An mbox is plain text — a compressed archive has to be unpacked first. */
    public const EXTENSIONS = ['mbox', 'mbx', 'eml', 'txt'];

    /** Files lying in the drop folder, with the import that already knows each. */
    public static function files(): array {
        $out = [];
        foreach (glob(self::dir() . '/*') ?: [] as $path) {
            if (!is_file($path)) continue;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, self::EXTENSIONS, true)) continue;
            $name = basename($path);
            $row = Db::one("SELECT * FROM mbox_imports WHERE filename=? ORDER BY id DESC LIMIT 1", [$name]);
            $out[] = [
                'filename'  => $name,
                'size'      => (int)filesize($path),
                'mtime'     => date('Y-m-d H:i:s', (int)filemtime($path)),
                'import'    => $row ? self::describe($row) : null,
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['mtime'], $a['mtime']));
        return $out;
    }

    /** Register a file for import (or return the unfinished import it already has). */
    public static function register(string $filename, array $opts = []): array {
        $filename = basename($filename);
        $path = self::dir() . '/' . $filename;
        if (!is_file($path)) throw new RuntimeException('Файл не найден: ' . $filename);

        $mailboxId = (int)($opts['mailbox_id'] ?? 0);
        if (!$mailboxId) {
            $box = Mailboxes::default();
            $mailboxId = $box ? (int)$box['id'] : 0;
        }
        if (!$mailboxId) throw new RuntimeException('Нет ни одного почтового ящика — письма некуда положить');

        $existing = Db::one("SELECT * FROM mbox_imports WHERE filename=? AND done=0 ORDER BY id DESC LIMIT 1", [$filename]);
        if ($existing) {
            Db::update('mbox_imports', [
                'mailbox_id'       => $mailboxId,
                'create_companies' => !empty($opts['create_companies']) ? 1 : 0,
                'size'             => (int)filesize($path),
                'error'            => null,
            ], 'id=?', [$existing['id']]);
            return self::describe(Db::one("SELECT * FROM mbox_imports WHERE id=?", [$existing['id']]));
        }

        $id = Db::insert('mbox_imports', [
            'filename'         => $filename,
            'size'             => (int)filesize($path),
            'byte_offset'      => 0,
            'mailbox_id'       => $mailboxId,
            'create_companies' => !empty($opts['create_companies']) ? 1 : 0,
            'manager_id'       => $opts['manager_id'] ?? null,
            'started_at'       => date('Y-m-d H:i:s'),
        ]);
        Logger::info('mail', "Импорт mbox «{$filename}» начат", ['import_id' => $id, 'mailbox_id' => $mailboxId]);
        return self::describe(Db::one("SELECT * FROM mbox_imports WHERE id=?", [$id]));
    }

    public static function all(int $limit = 20): array {
        return array_map([self::class, 'describe'],
            Db::all("SELECT * FROM mbox_imports ORDER BY id DESC LIMIT ?", [$limit]));
    }

    public static function get(int $id): ?array {
        $row = Db::one("SELECT * FROM mbox_imports WHERE id=?", [$id]);
        return $row ? self::describe($row) : null;
    }

    /** Row + the numbers the panel draws: percent, path on disk, mailbox name. */
    public static function describe(array $row): array {
        $size = max(1, (int)$row['size']);
        $row['percent'] = min(100, (int)round(100 * (int)$row['byte_offset'] / $size));
        $row['path'] = self::dir() . '/' . $row['filename'];
        $row['exists'] = is_file($row['path']);
        $row['mailbox_name'] = (string)Db::val("SELECT name FROM mailboxes WHERE id=?", [$row['mailbox_id']]);
        return $row;
    }

    /**
     * One step: read for `$seconds` or `$limit` letters, whichever ends first.
     * Returns the counters of THIS step plus the import's own progress.
     */
    public static function step(int $importId, ?int $seconds = null, ?int $limit = null): array {
        $import = Db::one("SELECT * FROM mbox_imports WHERE id=?", [$importId]);
        if (!$import) throw new RuntimeException('Импорт не найден');
        if (!empty($import['done'])) return ['scanned' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0]
                                          + ['import' => self::describe($import), 'done' => true];

        $box = Mailboxes::get((int)$import['mailbox_id']);
        if (!$box) throw new RuntimeException('Ящик импорта удалён — выберите другой');

        $path = self::dir() . '/' . $import['filename'];
        if (!is_file($path)) {
            Db::update('mbox_imports', ['error' => 'Файл пропал из storage/mbox'], 'id=?', [$importId]);
            throw new RuntimeException('Файл пропал из storage/mbox: ' . $import['filename']);
        }

        $seconds = max(3, $seconds ?: (int)Settings::get('MBOX_STEP_SECONDS', 20));
        $limit   = max(1, $limit ?: (int)Settings::get('MBOX_STEP_LETTERS', 200));
        $deadline = microtime(true) + $seconds;

        $res = ['scanned' => 0, 'imported' => 0, 'duplicates' => 0, 'failed' => 0, 'oversized' => 0];
        $offset = (int)$import['byte_offset'];
        $size = (int)filesize($path);

        $fh = fopen($path, 'rb');
        if (!$fh) throw new RuntimeException('Файл не читается: ' . $import['filename']);

        try {
            $offset = self::walk($fh, $offset, $size, $box, $import, $deadline, $limit, $res);
        } finally {
            fclose($fh);
        }

        $done = $offset >= $size;
        Db::update('mbox_imports', [
            'byte_offset' => $offset,
            'size'        => $size,
            'scanned'     => (int)$import['scanned'] + $res['scanned'],
            'imported'    => (int)$import['imported'] + $res['imported'],
            'duplicates'  => (int)$import['duplicates'] + $res['duplicates'],
            'failed'      => (int)$import['failed'] + $res['failed'],
            'done'        => $done ? 1 : 0,
            'finished_at' => $done ? date('Y-m-d H:i:s') : null,
            'error'       => null,
        ], 'id=?', [$importId]);

        if ($res['imported'] || $res['duplicates']) {
            Logger::info('mail', "Импорт mbox «{$import['filename']}»: добавлено {$res['imported']}, "
                . "дубликатов {$res['duplicates']}", ['import_id' => $importId]);
        }
        if ($done) {
            Logger::info('mail', "Импорт mbox «{$import['filename']}» завершён", ['import_id' => $importId]);
        }

        return $res + ['done' => $done, 'import' => self::get($importId)];
    }

    /**
     * The walk itself: lines in, whole messages out.
     *
     * The cursor is saved at the START of the message that was NOT consumed, so
     * a step that runs out of time never cuts a letter in half and never repeats
     * one. `>From ` at the beginning of a body line is the mbox escape and is
     * unescaped here (mboxrd), otherwise every quoted letter loses a character.
     */
    private static function walk($fh, int $offset, int $size, array $box, array $import,
                                 float $deadline, int $limit, array &$res): int {
        fseek($fh, $offset);
        $raw = '';
        $cursor = $offset;
        $afterBlank = true;   // the resume point is always a separator line
        // A letter with a 40 MB video in it must not take the whole PHP memory
        // limit with it: we keep walking to the next separator, but stop keeping
        // the bytes, and the letter is counted as skipped rather than parsed.
        $cap = max(1, (int)Settings::get('MBOX_MESSAGE_MAX_MB', 40)) * 1024 * 1024;
        $skip = false;

        while (!feof($fh)) {
            $lineStart = ftell($fh);
            $line = fgets($fh);
            if ($line === false) break;

            if ($afterBlank && self::isSeparator($line)) {
                if ($raw !== '' || $skip) {
                    if ($skip) {
                        $res['scanned']++;
                        $res['failed']++;
                        Logger::warning('mail', 'Письмо из mbox пропущено: больше '
                            . (int)($cap / 1024 / 1024) . ' МБ', ['import_id' => $import['id']]);
                    } else {
                        self::consume($raw, $box, $import, $res);
                    }
                    $raw = '';
                    $skip = false;
                    $cursor = $lineStart;
                    if ($res['scanned'] >= $limit || microtime(true) >= $deadline) return $cursor;
                }
                $afterBlank = false;
                continue;
            }

            $afterBlank = trim($line) === '';
            if ($skip) continue;
            $raw .= preg_replace('/^>(>*From )/', '$1', $line);
            if (strlen($raw) > $cap) { $raw = ''; $skip = true; }
        }

        if ($raw !== '') self::consume($raw, $box, $import, $res);
        return $size;
    }

    /**
     * «From dansury@gmail.com Mon Sep  5 10:03:11 2022» — the record separator.
     * A body line that merely starts with «From » has no envelope address and no
     * asctime date after it, and that is what keeps it out of this.
     */
    public static function isSeparator(string $line): bool {
        if (!str_starts_with($line, 'From ')) return false;
        $rest = trim(substr($line, 5));
        return $rest !== '' && (bool)preg_match(
            '/^\S+\s+(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}\s/i',
            $rest
        );
    }

    /** One raw message → the archive, or a counted duplicate. */
    private static function consume(string $raw, array $box, array $import, array &$res): void {
        $res['scanned']++;
        try {
            $maxMb = max(1, (int)Settings::get('MBOX_ATTACH_MAX_MB', 25));
            $msg = Mime::parseMessage($raw, ['max_attachment_bytes' => $maxMb * 1024 * 1024]);
            $res['oversized'] += (int)($msg['oversized'] ?? 0);

            // A letter with neither a sender nor a subject is the mbox's own
            // header junk, not correspondence
            if (trim($msg['from']) === '' && trim($msg['subject']) === '' && trim($msg['body']) === '') return;

            $direction = self::directionOf($msg, $box);
            $msg['folder'] = $direction === 'out' ? 'SENT' : 'INBOX';
            $msg['uid'] = 0;
            $msg['seen'] = true;                 // history is read; an import must not raise the unread badge
            $msg['import_id'] = (int)$import['id'];
            if (($msg['date'] ?? '') === '') $msg['date'] = date('Y-m-d H:i:s');

            $id = MailArchive::storeIncoming($box, $msg, $direction, true);
            if (!$id) { $res['duplicates']++; return; }

            // OCR stays off for the same reason the full-archive download keeps it
            // off: thousands of old scans cost money nobody asked to spend.
            MailSync::storeAttachments($id, $msg['attachments'] ?? [], false);

            // The point of the import: the letter lands on the company card it
            // belongs to. A card is only CREATED when the operator asked for it —
            // otherwise a three-year archive invents a company per newsletter.
            MailArchive::linkCounterparty($id, !empty($import['create_companies']));

            $res['imported']++;
        } catch (Throwable $e) {
            $res['failed']++;
            Logger::exception('mail', $e, ['import_id' => $import['id'], 'stage' => 'mbox']);
        }
    }

    /**
     * Ours or theirs. A Gmail export holds both halves of the correspondence and
     * says which is which in `X-Gmail-Labels`; without the label the sender
     * decides, against our own mailboxes and the box this import belongs to.
     */
    public static function directionOf(array $msg, array $box): string {
        $labels = mb_strtolower((string)($msg['labels'] ?? ''));
        foreach (['sent', 'отправленные'] as $needle) {
            if ($labels !== '' && str_contains($labels, $needle)) return 'out';
        }
        $from = mb_strtolower(trim((string)($msg['from'] ?? '')));
        if ($from === '') return 'in';

        $boxEmail = mb_strtolower(trim((string)($box['email'] ?? '')));
        if ($boxEmail !== '' && $from === $boxEmail) return 'out';
        return Crm::isOurAddress($from) ? 'out' : 'in';
    }

    /** Start the file over — after «Импортировать заново». */
    public static function reset(int $id): void {
        Db::update('mbox_imports', [
            'byte_offset' => 0, 'scanned' => 0, 'imported' => 0, 'duplicates' => 0,
            'failed' => 0, 'done' => 0, 'finished_at' => null, 'error' => null,
            'started_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$id]);
        Logger::info('mail', 'Импорт mbox начат заново', ['import_id' => $id]);
    }

    /** Forget the import; `$withFile` also removes the uploaded archive itself. */
    public static function remove(int $id, bool $withFile = false): void {
        $row = Db::one("SELECT * FROM mbox_imports WHERE id=?", [$id]);
        if (!$row) return;
        if ($withFile) @unlink(self::dir() . '/' . $row['filename']);
        Db::q("DELETE FROM mbox_imports WHERE id=?", [$id]);
        Logger::info('mail', 'Импорт mbox удалён из списка', ['import_id' => $id, 'file_removed' => $withFile]);
    }

    /** Accept an uploaded file into the drop folder. Returns its name. */
    public static function accept(array $file): string {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename((string)($file['name'] ?? 'archive.mbox')));
        $name = trim((string)$name, '-.') ?: 'archive.mbox';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSIONS, true)) $name .= '.mbox';

        $dest = self::dir() . '/' . $name;
        if (file_exists($dest)) {
            $base = pathinfo($name, PATHINFO_FILENAME);
            $ext  = pathinfo($name, PATHINFO_EXTENSION);
            $name = $base . '-' . date('Ymd-His') . '.' . $ext;
            $dest = self::dir() . '/' . $name;
        }
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            // The tests and the CLI path have no upload to move
            if (!@rename($file['tmp_name'], $dest)) throw new RuntimeException('Файл не сохранился в storage/mbox');
        }
        return $name;
    }
}
