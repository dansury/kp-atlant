<?php
/**
 * Full-text search index over letters, companies, requests and board cards
 * (module 055). SQLite `LIKE` folds case for ASCII only — «легион» never
 * matched «Легион». Documents here are stored normalized (lowercase, ё → е,
 * phones as bare digits) and matched with `LIKE` on an FTS5 trigram table.
 */
class SearchIndex {
    public const KINDS = ['mail' => 0, 'cp' => 1, 'req' => 2, 'card' => 3];
    private const MAX_DOC = 200000;
    private const BATCH = 200;

    /** Tables, triggers and a full rebuild queue — migration v47. */
    public static function install(): void {
        if (!Db::hasTable('search_docs')) {
            try {
                Db::q("CREATE VIRTUAL TABLE search_docs USING fts5(body, tokenize='trigram')");
            } catch (Throwable $e) {
                // SQLite без FTS5/trigram: тот же запрос, но сканом
                Db::q("CREATE TABLE search_docs (rowid INTEGER PRIMARY KEY, body TEXT NOT NULL DEFAULT '')");
            }
        }
        Db::q("CREATE TABLE IF NOT EXISTS search_dirty (
                   kind INTEGER NOT NULL, ref_id INTEGER NOT NULL,
                   PRIMARY KEY (kind, ref_id)) WITHOUT ROWID");
        self::installTriggers();
        self::markAll();
    }

    /** Every row goes to the queue — the index is rebuilt by refresh(). */
    public static function markAll(): void {
        $k = self::KINDS;
        Db::q("INSERT OR IGNORE INTO search_dirty SELECT {$k['mail']}, id FROM mail_messages");
        Db::q("INSERT OR IGNORE INTO search_dirty SELECT {$k['cp']}, id FROM counterparties");
        Db::q("INSERT OR IGNORE INTO search_dirty SELECT {$k['req']}, id FROM requests");
        Db::q("INSERT OR IGNORE INTO search_dirty SELECT {$k['card']}, id FROM board_cards");
    }

    private static function installTriggers(): void {
        $k = self::KINDS;
        $mark = fn(int $kind, string $id) => "INSERT OR IGNORE INTO search_dirty (kind, ref_id) SELECT $kind, $id WHERE $id IS NOT NULL;";

        // table => [columns whose update matters, [kind, id expression over NEW/OLD as R]...]
        $map = [
            'mail_messages'     => ['subject, from_email, from_name, to_emails, cc_emails, body_text, body_html',
                                    [[$k['mail'], 'R.id']]],
            'attachments'       => ['filename, extracted_text, mail_message_id, request_id',
                                    [[$k['mail'], 'R.mail_message_id'], [$k['req'], 'R.request_id']]],
            'counterparties'    => ['name, legal_title, inn, kpp, ogrn, legal_address, contact_person, contact_email,
                                     contact_phone, email_domain, notes, contract_name, merged_into_id',
                                    [[$k['cp'], 'R.id']]],
            'contacts'          => ['name, email, phone, counterparty_id', [[$k['cp'], 'R.counterparty_id']]],
            'counterparty_orgs' => ['name, inn, kpp, counterparty_id', [[$k['cp'], 'R.counterparty_id']]],
            'orders'            => ['name, ship_track, counterparty_id', [[$k['cp'], 'R.counterparty_id']]],
            'invoices'          => ['name, counterparty_id', [[$k['cp'], 'R.counterparty_id']]],
            'requests'          => ['email_subject, email_from, raw_text', [[$k['req'], 'R.id']]],
            'request_items'     => ['raw_name, product_name, article, request_id', [[$k['req'], 'R.request_id']]],
            'proposal_items'    => ['product_name, proposal_id',
                                    [[$k['req'], '(SELECT request_id FROM proposals WHERE id = R.proposal_id)']]],
            'board_cards'       => ['title, note', [[$k['card'], 'R.id']]],
        ];

        foreach ($map as $table => [$cols, $targets]) {
            if (!Db::hasTable($table)) continue;
            // Колонка, которой нет в этой базе, валит CREATE TRIGGER — берём только живые
            $cols = array_values(array_filter(array_map('trim', explode(',', $cols)),
                fn($c) => Db::hasColumn($table, $c)));
            $body = fn(string $row) => implode(' ', array_map(
                fn($t) => $mark($t[0], str_replace('R.', "$row.", $t[1])), $targets));
            $pdo = Db::pdo();
            foreach (['ins', 'upd', 'del'] as $ev) $pdo->exec("DROP TRIGGER IF EXISTS trg_search_{$table}_$ev");
            $pdo->exec("CREATE TRIGGER trg_search_{$table}_ins AFTER INSERT ON $table BEGIN {$body('NEW')} END");
            if ($cols) {
                $of = implode(', ', $cols);
                // Перенос строки к другому владельцу — переиндексировать обоих
                $pdo->exec("CREATE TRIGGER trg_search_{$table}_upd AFTER UPDATE OF $of ON $table
                            BEGIN {$body('NEW')} {$body('OLD')} END");
            }
            $pdo->exec("CREATE TRIGGER trg_search_{$table}_del AFTER DELETE ON $table BEGIN {$body('OLD')} END");
        }
    }

    /** Lowercase, ё → е, one space; phones once more as bare digits. */
    public static function normalize(string $s): string {
        $s = str_replace('ё', 'е', mb_strtolower($s, 'UTF-8'));
        $extra = [];
        // Номер в пределах строки; соседние числа («ИНН 7701… 8 (916)…») не
        // разделить — поэтому цифры с каждой группы до конца серии
        if (preg_match_all('/\+?\d[\d\h().\-]{5,}\d/u', $s, $m)) {
            foreach ($m[0] as $run) {
                $groups = preg_split('/\D+/', $run, -1, PREG_SPLIT_NO_EMPTY);
                if (count($groups) < 2) continue;
                for ($i = 0, $n = min(count($groups), 12); $i < $n; $i++) {
                    $digits = implode('', array_slice($groups, $i));
                    if (strlen($digits) < 7) break;
                    $extra[] = $digits;
                    if (strlen($digits) >= 11 && $digits[0] === '8') $extra[] = '7' . substr($digits, 1);
                }
            }
        }
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        foreach (explode(' ', $s) as $word) {
            // «89161234567» без разделителей — тоже номер с восьмёркой
            if (preg_match('/^8\d{10}$/', $word)) $extra[] = '7' . substr($word, 1);
        }
        return $extra ? $s . ' ' . implode(' ', array_unique($extra)) : $s;
    }

    /**
     * A phone written with separators («+7 916 123-45-67», «8 916 123 45 67»)
     * becomes one word of digits before the query is split. Plain numbers
     * divided by a space («ИНН КПП») stay separate words.
     */
    public static function joinPhones(string $q): string {
        return (string)preg_replace_callback('/\+?\d[\d\h().\-]{5,}\d/u', function ($m) {
            $run = $m[0];
            $groups = preg_split('/\D+/', $run, -1, PREG_SPLIT_NO_EMPTY);
            $short = array_filter($groups, fn($g) => strlen($g) <= 4);
            if (count($groups) < 2 || (!preg_match('/[()+.\-]/', $run) && !$short)) return $run;
            return implode('', $groups);
        }, $q);
    }

    /** A query word, normalized like the documents; a phone becomes bare digits. */
    public static function normalizeTerm(string $term): string {
        $t = str_replace('ё', 'е', mb_strtolower(trim($term), 'UTF-8'));
        if (preg_match('/^[\d\s()+.\-]+$/', $t)) {
            $digits = preg_replace('/\D/', '', $t);
            if (strlen($digits) >= 5) {
                return (strlen($digits) === 11 && $digits[0] === '8') ? '7' . substr($digits, 1) : $digits;
            }
        }
        return trim((string)preg_replace('/\s+/u', ' ', $t));
    }

    /** Query string → normalized terms, all of which must match. */
    public static function terms(string $q): array {
        require_once __DIR__ . '/mail.php';
        $out = [];
        foreach (MailArchive::searchTerms(self::joinPhones($q)) as $t) {
            $t = self::normalizeTerm($t);
            if ($t !== '') $out[] = $t;
        }
        return array_values(array_unique($out));
    }

    /** `LIKE` pattern for a term; `%` and `_` are literal. */
    public static function like(string $term): string {
        return '%' . strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }

    /** Ids of one kind whose document contains the term — one `?` (like()). */
    public static function hitsSql(string $kind): string {
        $code = self::KINDS[$kind];
        return "SELECT rowid / 4 FROM search_docs WHERE body LIKE ? ESCAPE '\\' AND rowid % 4 = $code";
    }

    /**
     * Condition on a mail_messages row: the letter, its company (merged-away
     * companies included — their letters keep the old id) or its request
     * contains the term.
     * @return array{0:string,1:array}
     */
    public static function messageMatch(string $a, string $term): array {
        $like = self::like($term);
        return [
            "($a.id IN (" . self::hitsSql('mail') . ")
              OR $a.counterparty_id IN (SELECT id FROM counterparties
                                        WHERE id IN (" . self::hitsSql('cp') . ")
                                           OR merged_into_id IN (" . self::hitsSql('cp') . "))
              OR $a.request_id IN (" . self::hitsSql('req') . "))",
            [$like, $like, $like, $like],
        ];
    }

    /** Bring the index up to date before a search; returns keys still waiting. */
    public static function ready(float $budgetSec = 5.0): int {
        try {
            return self::refresh($budgetSec);
        } catch (Throwable $e) {
            Logger::exception('search', $e);
            return 0;
        }
    }

    /**
     * Reindex dirty keys until none left or the budget is spent. One process
     * indexes at a time: a search typed letter by letter fires several requests,
     * and they used to fight for the write lock until one got «database is
     * locked». The others search what is already indexed.
     */
    public static function refresh(float $budgetSec = 20.0): int {
        if (!Db::hasTable('search_dirty')) return 0;
        $lock = self::lock();
        if ($lock === false) return self::pending();
        $deadline = microtime(true) + $budgetSec;
        $pdo = Db::pdo();
        try {
            do {
                $batch = Db::all("SELECT kind, ref_id FROM search_dirty LIMIT " . self::BATCH);
                if (!$batch) return 0;
                // Уже внутри чужой транзакции — пишем в неё же
                $own = !$pdo->inTransaction();
                $began = false;
                try {
                    if ($own) { $pdo->exec('BEGIN IMMEDIATE'); $began = true; }
                    foreach ($batch as $row) {
                        $kind = (int)$row['kind'];
                        $id = (int)$row['ref_id'];
                        $rowid = $id * 4 + $kind;
                        Db::q("DELETE FROM search_docs WHERE rowid=?", [$rowid]);
                        $doc = self::document($kind, $id);
                        if ($doc !== null && $doc !== '') {
                            Db::q("INSERT INTO search_docs (rowid, body) VALUES (?, ?)", [$rowid, $doc]);
                        }
                        Db::q("DELETE FROM search_dirty WHERE kind=? AND ref_id=?", [$kind, $id]);
                    }
                    if ($began) { $pdo->exec('COMMIT'); $began = false; }
                } catch (Throwable $e) {
                    if ($began) $pdo->exec('ROLLBACK');
                    // Писатель чужой (почта, крон) — доиндексируем в следующий раз
                    if ($own && Db::isLocked($e)) return self::pending();
                    throw $e;
                }
            } while (microtime(true) < $deadline);
        } finally {
            if (is_resource($lock)) { @flock($lock, LOCK_UN); @fclose($lock); }
        }
        return self::pending();
    }

    private static function pending(): int {
        return (int)Db::val("SELECT COUNT(*) FROM search_dirty");
    }

    /** @return resource|false|null  null — no lock file possible, index anyway */
    private static function lock() {
        $dir = Db::path() !== '' ? dirname(Db::path()) : sys_get_temp_dir();
        $fh = @fopen($dir . '/.search.lock', 'c');
        if ($fh === false) return null;
        if (!@flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
        return $fh;
    }

    /** Normalized document of one entity; null — the row is gone. */
    public static function document(int $kind, int $id): ?string {
        $parts = match ($kind) {
            self::KINDS['mail'] => self::mailParts($id),
            self::KINDS['cp']   => self::companyParts($id),
            self::KINDS['req']  => self::requestParts($id),
            self::KINDS['card'] => self::cardParts($id),
            default => null,
        };
        if ($parts === null) return null;
        $text = implode("\n", array_filter(array_map('strval', $parts), fn($p) => trim($p) !== ''));
        if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        return self::normalize(mb_substr($text, 0, self::MAX_DOC));
    }

    private static function mailParts(int $id): ?array {
        $m = Db::one("SELECT subject, from_email, from_name, to_emails, cc_emails, body_text, body_html
                      FROM mail_messages WHERE id=?", [$id]);
        if (!$m) return null;
        $body = trim((string)$m['body_text']);
        if ($body === '' && $m['body_html']) {
            $body = html_entity_decode(strip_tags((string)preg_replace(
                '#<(style|script)\b[^>]*>.*?</\1>#is', ' ', (string)$m['body_html'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $parts = [$m['subject'], $m['from_email'], $m['from_name'], $m['to_emails'], $m['cc_emails'], $body];
        if (Db::hasColumn('attachments', 'mail_message_id')) {
            foreach (Db::all("SELECT filename, extracted_text FROM attachments WHERE mail_message_id=?", [$id]) as $a) {
                array_push($parts, $a['filename'], $a['extracted_text']);
            }
        }
        return $parts;
    }

    private static function companyParts(int $id): ?array {
        $c = Db::one("SELECT * FROM counterparties WHERE id=?", [$id]);
        if (!$c) return null;
        $parts = [];
        foreach (['name', 'legal_title', 'inn', 'kpp', 'ogrn', 'legal_address', 'contact_person', 'contact_email',
                  'contact_phone', 'email_domain', 'notes', 'contract_name'] as $f) {
            $parts[] = $c[$f] ?? '';
        }
        $related = [
            'contacts'          => "SELECT name, email, phone FROM contacts WHERE counterparty_id=?",
            'counterparty_orgs' => "SELECT name, inn, kpp FROM counterparty_orgs WHERE counterparty_id=?",
            'orders'            => "SELECT name, ship_track FROM orders WHERE counterparty_id=?",
            'invoices'          => "SELECT name FROM invoices WHERE counterparty_id=?",
        ];
        foreach ($related as $table => $sql) {
            if (!Db::hasTable($table)) continue;
            foreach (Db::all($sql, [$id]) as $r) array_push($parts, ...array_values($r));
        }
        return $parts;
    }

    private static function requestParts(int $id): ?array {
        $r = Db::one("SELECT email_subject, email_from, raw_text FROM requests WHERE id=?", [$id]);
        if (!$r) return null;
        $parts = array_values($r);
        foreach (Db::all("SELECT raw_name, product_name, article FROM request_items WHERE request_id=?", [$id]) as $i) {
            array_push($parts, ...array_values($i));
        }
        foreach (Db::all("SELECT pi.product_name FROM proposal_items pi
                          JOIN proposals p ON p.id = pi.proposal_id WHERE p.request_id=?", [$id]) as $i) {
            $parts[] = $i['product_name'];
        }
        return $parts;
    }

    private static function cardParts(int $id): ?array {
        $c = Db::one("SELECT title, note FROM board_cards WHERE id=?", [$id]);
        return $c ? array_values($c) : null;
    }
}
