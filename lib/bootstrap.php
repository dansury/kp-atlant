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

// Init DB
Db::init($cfg['DB_PATH'] ?? ROOT . '/data/kp.db');

// Init LLM
LLM::init($cfg);

// Create schema if needed
if (!Db::hasTable('managers')) {
    initSchema();
}

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

// Auth helper: get current manager from session
function currentManager(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => $GLOBALS['cfg']['SESSION_LIFETIME'] ?? 86400,
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
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
