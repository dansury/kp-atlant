<?php
/**
 * Bootstrap: config, DB, LLM, schema, defaults.
 */
define('ROOT', dirname(__DIR__));

// Load config
$configPath = ROOT . '/config.php';
if (!file_exists($configPath)) {
    die('config.php not found. Copy config.example.php to config.php and fill in values.');
}
$cfg = require $configPath;
date_default_timezone_set($cfg['TIMEZONE'] ?? 'Europe/Moscow');

// Autoload composer
$autoload = ROOT . '/vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;

// Load libs
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/auth.php';

// Start the session once, before anything can emit output
startSession();

// Init DB
Db::init($cfg['DB_PATH'] ?? ROOT . '/data/kp.db');

// Init LLM
LLM::init($cfg);

// Create schema if needed
if (!Db::hasTable('managers')) {
    initSchema();
}

// Apply incremental migrations (module 002 and later)
runMigrations();

// Keep managers in sync with config.php (password edits take effect)
syncManagersFromConfig($cfg);

function initSchema(): void {
    $sql = <<<'SQL'
    CREATE TABLE IF NOT EXISTS managers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        login TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        name TEXT NOT NULL,
        email TEXT,
        moysklad_uid TEXT,
        is_admin INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS counterparties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        inn TEXT,
        contact_person TEXT,
        contact_email TEXT,
        contact_phone TEXT,
        moysklad_id TEXT,
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_counterparties_inn ON counterparties(inn);
    CREATE INDEX IF NOT EXISTS idx_counterparties_name ON counterparties(name);

    CREATE TABLE IF NOT EXISTS requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source TEXT NOT NULL CHECK(source IN ('email','manual')),
        raw_text TEXT NOT NULL,
        parsed_json TEXT,
        counterparty_id INTEGER REFERENCES counterparties(id),
        manager_id INTEGER REFERENCES managers(id),
        status TEXT NOT NULL DEFAULT 'new'
            CHECK(status IN ('new','processing','draft_ready','sent','ordered','closed')),
        email_from TEXT,
        email_subject TEXT,
        email_message_id TEXT,
        notified_at TEXT,
        email_notified_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_requests_status ON requests(status);
    CREATE INDEX IF NOT EXISTS idx_requests_manager ON requests(manager_id);

    CREATE TABLE IF NOT EXISTS proposals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL REFERENCES requests(id),
        counterparty_id INTEGER REFERENCES counterparties(id),
        manager_id INTEGER REFERENCES managers(id),
        number TEXT,
        status TEXT NOT NULL DEFAULT 'draft'
            CHECK(status IN ('draft','confirmed','sent','order_created')),
        intro_text TEXT,
        pre_table_text TEXT,
        post_table_text TEXT,
        conditions_text TEXT,
        execution_days INTEGER DEFAULT 30,
        validity_days INTEGER DEFAULT 14,
        vat_rate INTEGER DEFAULT 5,
        show_vat_total INTEGER DEFAULT 0,
        cover_letter TEXT,
        cover_letter_final TEXT,
        pdf_path TEXT,
        moysklad_order_id TEXT,
        sent_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_proposals_request ON proposals(request_id);

    CREATE TABLE IF NOT EXISTS proposal_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        proposal_id INTEGER NOT NULL REFERENCES proposals(id) ON DELETE CASCADE,
        position INTEGER NOT NULL,
        product_name TEXT NOT NULL,
        moysklad_product_id TEXT,
        unit TEXT NOT NULL DEFAULT 'шт.',
        quantity INTEGER NOT NULL DEFAULT 1,
        price REAL NOT NULL,
        vat_rate INTEGER,
        stock_available INTEGER,
        stock_reserved INTEGER,
        match_confidence REAL,
        match_variants TEXT,
        is_confirmed INTEGER NOT NULL DEFAULT 0,
        notes TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_items_proposal ON proposal_items(proposal_id);

    CREATE TABLE IF NOT EXISTS correspondence (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER REFERENCES requests(id),
        counterparty_id INTEGER REFERENCES counterparties(id),
        direction TEXT NOT NULL CHECK(direction IN ('in','out','note')),
        subject TEXT,
        body TEXT NOT NULL,
        email_from TEXT,
        email_to TEXT,
        has_attachment INTEGER DEFAULT 0,
        attachment_path TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_corr_counterparty ON correspondence(counterparty_id);
    CREATE INDEX IF NOT EXISTS idx_corr_request ON correspondence(request_id);

    CREATE TABLE IF NOT EXISTS corrections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER REFERENCES requests(id),
        field TEXT NOT NULL CHECK(field IN ('cover_letter','pre_table','post_table','conditions')),
        auto_text TEXT NOT NULL,
        manager_text TEXT NOT NULL,
        context_json TEXT,
        manager_id INTEGER REFERENCES managers(id),
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        manager_id INTEGER REFERENCES managers(id),
        type TEXT NOT NULL CHECK(type IN ('new_request','followup_ready','system')),
        title TEXT NOT NULL,
        body TEXT,
        ref_type TEXT,
        ref_id INTEGER,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_notif_manager ON notifications(manager_id, is_read);

    CREATE TABLE IF NOT EXISTS legal_entities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        is_active INTEGER NOT NULL DEFAULT 1,
        entity_type TEXT NOT NULL DEFAULT 'ИП',
        full_name TEXT NOT NULL,
        short_name TEXT,
        inn TEXT NOT NULL,
        ogrnip TEXT,
        ogrn TEXT,
        city TEXT NOT NULL DEFAULT 'г. Москва',
        address TEXT,
        signatory_name TEXT,
        signature_path TEXT,
        logo_path TEXT,
        stamp_path TEXT,
        bank_details TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS email_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        content TEXT NOT NULL,
        updated_by INTEGER REFERENCES managers(id),
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS products_cache (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        moysklad_id TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        name_normalized TEXT,
        article TEXT,
        price REAL,
        stock INTEGER,
        reserved INTEGER,
        unit TEXT DEFAULT 'шт.',
        description TEXT,
        category TEXT,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_products_name ON products_cache(name_normalized);
    CREATE INDEX IF NOT EXISTS idx_products_article ON products_cache(article);

    CREATE TABLE IF NOT EXISTS followups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        proposal_id INTEGER NOT NULL REFERENCES proposals(id),
        counterparty_id INTEGER NOT NULL REFERENCES counterparties(id),
        status TEXT NOT NULL DEFAULT 'suggested'
            CHECK(status IN ('suggested','draft_ready','sent','dismissed')),
        draft_text TEXT,
        final_text TEXT,
        sent_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_followups_status ON followups(status);

    CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    );
SQL;

    // Execute multi-statement SQL
    Db::pdo()->exec($sql);

    // Seed defaults
    seedDefaults();
}

// Incremental schema migrations. Safe to run on every request (cheap checks).
function runMigrations(): void {
    // Older DBs may predate the settings table
    Db::q("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
    $current = (int)(Db::val("SELECT value FROM settings WHERE key='schema_version'") ?: 1);

    // v2 — module 002: orders, invoices, attachments, contacts, company chat
    if ($current < 2) {
        // Request type: KP request vs order (FR-023, FR-024)
        Db::ensureColumn('requests', 'type', 'TEXT', "'kp_request'");
        Db::ensureColumn('requests', 'type_source', 'TEXT', "'llm'");

        // Company merge keys and answer tracking (FR-034, FR-038)
        Db::ensureColumn('counterparties', 'email_domain', 'TEXT');
        Db::ensureColumn('counterparties', 'name_normalized', 'TEXT');
        Db::ensureColumn('counterparties', 'merged_into_id', 'INTEGER');
        Db::ensureColumn('counterparties', 'last_inbound_at', 'TEXT');
        Db::ensureColumn('counterparties', 'last_outbound_at', 'TEXT');

        // Chat feed: note author and system events (FR-033, FR-036)
        Db::ensureColumn('correspondence', 'manager_id', 'INTEGER');
        Db::ensureColumn('correspondence', 'event_type', 'TEXT');
        Db::ensureColumn('correspondence', 'meta_json', 'TEXT');

        $sql = <<<'SQL'
        CREATE TABLE IF NOT EXISTS contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            counterparty_id INTEGER NOT NULL REFERENCES counterparties(id),
            name TEXT,
            email TEXT,
            phone TEXT,
            first_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
            last_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
            messages_count INTEGER NOT NULL DEFAULT 0
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_contacts_cp_email ON contacts(counterparty_id, email);

        CREATE TABLE IF NOT EXISTS attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            correspondence_id INTEGER REFERENCES correspondence(id),
            request_id INTEGER REFERENCES requests(id),
            counterparty_id INTEGER REFERENCES counterparties(id),
            filename TEXT NOT NULL,
            path TEXT NOT NULL,
            mime TEXT,
            size INTEGER,
            extracted_text TEXT,
            extract_status TEXT NOT NULL DEFAULT 'pending'
                CHECK(extract_status IN ('pending','ok','ocr','empty','skipped','failed')),
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_attach_request ON attachments(request_id);
        CREATE INDEX IF NOT EXISTS idx_attach_corr ON attachments(correspondence_id);

        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER REFERENCES requests(id),
            proposal_id INTEGER REFERENCES proposals(id),
            counterparty_id INTEGER REFERENCES counterparties(id),
            manager_id INTEGER REFERENCES managers(id),
            moysklad_id TEXT NOT NULL UNIQUE,
            name TEXT,
            moment TEXT,
            sum REAL DEFAULT 0,
            state_name TEXT,
            description TEXT,
            positions_json TEXT,
            moysklad_updated_at TEXT,
            synced_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_orders_cp ON orders(counterparty_id);
        CREATE INDEX IF NOT EXISTS idx_orders_request ON orders(request_id);

        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER REFERENCES orders(id),
            counterparty_id INTEGER REFERENCES counterparties(id),
            moysklad_id TEXT NOT NULL UNIQUE,
            name TEXT,
            moment TEXT,
            sum REAL DEFAULT 0,
            payed_sum REAL DEFAULT 0,
            state_name TEXT,
            pdf_path TEXT,
            moysklad_updated_at TEXT,
            synced_at TEXT,
            sent_at TEXT,
            sent_to TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_invoices_cp ON invoices(counterparty_id);
        CREATE INDEX IF NOT EXISTS idx_invoices_order ON invoices(order_id);

        CREATE TABLE IF NOT EXISTS webhook_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT,
            action TEXT,
            moysklad_id TEXT,
            payload TEXT,
            result TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
SQL;
        Db::pdo()->exec($sql);

        // Webhook secret + module defaults
        $defaults = [
            'moysklad_webhook_secret' => bin2hex(random_bytes(16)),
            'ocr_enabled'             => '1',
            'ocr_max_pages'           => '3',
            'attachment_max_mb'       => '10',
            'unanswered_critical_h'   => '24',
            'invoice_email_subject'   => 'Счёт на оплату от Atlant Armour',
        ];
        foreach ($defaults as $k => $v) {
            Db::q("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)", [$k, $v]);
        }

        // Backfill merge keys for existing counterparties
        foreach (Db::all("SELECT id, name, contact_email FROM counterparties") as $cp) {
            $domain = null;
            if (!empty($cp['contact_email']) && str_contains($cp['contact_email'], '@')) {
                $domain = mb_strtolower(trim(explode('@', $cp['contact_email'])[1]));
                if (in_array($domain, publicEmailDomains(), true)) $domain = null;
            }
            Db::update('counterparties', [
                'email_domain'    => $domain,
                'name_normalized' => normalizeCompanyName($cp['name']),
            ], 'id=?', [$cp['id']]);
        }

        // Storage folders for attachments and invoice printforms
        foreach ([ROOT . '/storage/attachments', ROOT . '/storage/invoices'] as $dir) {
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '2')");
        $current = 2;
    }
}

// Public mail providers never used to merge companies (C-012)
function publicEmailDomains(): array {
    return [
        'mail.ru','inbox.ru','bk.ru','list.ru','internet.ru',
        'yandex.ru','ya.ru','yandex.com',
        'gmail.com','googlemail.com','outlook.com','hotmail.com','live.com',
        'rambler.ru','icloud.com','me.com','proton.me','protonmail.com',
        'bcc.ru','vk.com','sberbank.ru',
    ];
}

// Normalize company name for matching: drop legal form, quotes, case
function normalizeCompanyName(string $name): string {
    $n = mb_strtolower($name);
    $n = preg_replace('/["\x{00AB}\x{00BB}\x{2018}\x{2019}\x{201C}\x{201D}\x{0027}]/u', '', $n);
    $n = preg_replace('/\b(ооо|оао|зао|пао|ао|ип|нко|фгуп|гуп|мбу|гбу|ано|нао)\b/u', '', $n);
    $n = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n);
    return trim(preg_replace('/\s+/u', ' ', $n));
}

function seedDefaults(): void {
    // Default legal entity (ИП Сурков К.А.)
    if (!Db::val("SELECT COUNT(*) FROM legal_entities")) {
        Db::insert('legal_entities', [
            'entity_type' => 'ИП',
            'full_name' => 'ИП Сурков Кирилл Александрович',
            'short_name' => 'ИП Сурков К. А.',
            'inn' => '773135420168',
            'ogrnip' => '320774600452996',
            'city' => 'г. Москва',
            'signatory_name' => 'Сурков Кирилл Александрович',
        ]);
    }

    // Default conditions text
    $defaults = [
        'default_conditions_text' => 'Стоимость включает расходы на упаковку, маркировку, хранение, погрузку и страхование грузов, подготовку и передачу документов.',
        'default_execution_days' => '30',
        'default_validity_days' => '14',
        'default_vat_rate' => '5',
        'app_version' => '1.0.0',
    ];
    foreach ($defaults as $k => $v) {
        Db::q("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)", [$k, $v]);
    }

    // Default EMAILRULES.md (from ToV)
    if (!Db::val("SELECT COUNT(*) FROM email_rules")) {
        $rules = <<<'MD'
# Правила email-коммуникации Atlant Armour

## Тон
- Приземлённый профессионал: спокойно, уверенно, без пафоса
- Факты с цифрами: классы защиты, ГОСТ, массы, размеры
- Уважительно и прямо, без сюсюканья

## Структура follow-up письма
1. Контекст: напомнить о предыдущем запросе/КП (дата, состав)
2. Предложение: обновлённые условия, наличие, новинки по теме запроса
3. Действие: предложить связаться, обновить КП, уточнить сроки

## Запрещённые слова и обороты
- «лучшее в мире», «не имеет аналогов»
- «каждый воин мечтает», «абсолютная защита»
- Любой героический пафос и эмоциональное давление

## Примеры хороших формулировок
- «По Вашему запросу от [дата] — актуальные цены и наличие на складе»
- «Готовы обновить КП с учётом текущих остатков»
- «Если состав заказа изменился — пришлите обновлённый перечень, подготовим новое КП в течение дня»
MD;
        Db::insert('email_rules', ['content' => $rules]);
    }

    // Seed managers from config
    global $cfg;
    if (!empty($cfg['MANAGERS'])) {
        foreach ($cfg['MANAGERS'] as $login => $info) {
            if (Db::one("SELECT id FROM managers WHERE login=?", [$login])) continue;
            Db::insert('managers', [
                'login'         => $login,
                'password_hash' => Auth::hashPassword($info[0]),
                'name'          => $info[1],
                'email'         => $info[2] ?? null,
                'is_admin'      => !empty($info[3]) ? 1 : 0,
            ]);
        }
    }
}

// Sync managers from config.php into the DB. config.php is the single source
// of truth for logins/passwords: an edit there takes effect on the next
// request. A per-login fingerprint keeps this cheap (no bcrypt when unchanged).
function syncManagersFromConfig(array $cfg): void {
    $managers = $cfg['MANAGERS'] ?? [];
    if (!$managers) return;

    foreach ($managers as $login => $info) {
        $login = trim((string)$login);
        if ($login === '') continue;

        $pass    = (string)($info[0] ?? '');
        $name    = $info[1] ?? $login;
        $email   = $info[2] ?? null;
        $isAdmin = !empty($info[3]) ? 1 : 0;
        if ($pass === '') continue;

        $fpKey = 'manager_fp_' . $login;
        $fp    = hash('sha256', serialize([$pass, $name, $email, $isAdmin]));
        $row   = Db::one("SELECT id FROM managers WHERE login=?", [$login]);

        // Nothing changed and the row still exists -> skip
        if ($row && Db::val("SELECT value FROM settings WHERE key=?", [$fpKey]) === $fp) continue;

        $data = [
            'password_hash' => Auth::hashPassword($pass),
            'name'          => $name,
            'email'         => $email,
            'is_admin'      => $isAdmin,
        ];
        if ($row) {
            Db::update('managers', $data, 'id=?', [$row['id']]);
        } else {
            Db::insert('managers', $data + ['login' => $login]);
        }
        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)", [$fpKey, $fp]);
    }
}

// Start the PHP session with consistent cookie flags. Safe to call repeatedly.
function startSession(): void {
    if (PHP_SAPI === 'cli') return;                       // cron/CLI has no session
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (headers_sent()) return;
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => $GLOBALS['cfg']['SESSION_LIFETIME'] ?? 86400,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,   // must be false on plain HTTP, or the cookie is dropped
        'samesite' => 'Lax',    // Strict drops the cookie on external return links
    ]);
    session_start();
}

// Auth helper: get current manager from session
function currentManager(): ?array {
    startSession();
    $id = $_SESSION['manager_id'] ?? null;
    if (!$id) return null;
    return Db::one("SELECT id, login, name, email, is_admin, moysklad_uid FROM managers WHERE id=?", [$id]);
}

// JSON response helpers
function jsonOk(array $data = []): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg, 'code' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonData(mixed $data): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireAuth(): array {
    $m = currentManager();
    if (!$m) jsonError('Unauthorized', 401);
    return $m;
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?? []) : [];
}
