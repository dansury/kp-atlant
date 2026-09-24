<?php
/**
 * Bootstrap: config, DB, LLM, schema, defaults.
 */
define('ROOT', dirname(__DIR__));

// Own cookie name. A leftover PHPSESSID from an earlier deploy can be stuck in
// the browser (a Secure cookie set over HTTPS is never overwritten over plain
// HTTP), which makes every login bounce back to the form while incognito works.
define('SESSION_COOKIE', 'ATLANTSID');

// Load config. The file is OPTIONAL: it seeds the defaults, while the values
// edited in the admin panel live in the DB and win over it. Delete config.php
// from the server and the service keeps running on what the panel holds.
$configPath = ROOT . '/config.php';
$fileCfg = file_exists($configPath) ? (array)(require $configPath) : [];

// Autoload composer
$autoload = ROOT . '/vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;

// Load libs
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypt.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/domains.php';
require_once __DIR__ . '/prompts.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/knowledge.php';
require_once __DIR__ . '/triage.php';
require_once __DIR__ . '/tov.php';
require_once __DIR__ . '/learning.php';
require_once __DIR__ . '/content_log.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/auto_pull.php';
require_once __DIR__ . '/kp_terms.php';
require_once __DIR__ . '/search.php';

// Init DB before the settings layer — the overrides live in it
Db::init($fileCfg['DB_PATH'] ?? ROOT . '/data/kp.db');

// Create schema if needed
Settings::boot($fileCfg);
if (!Db::hasTable('managers')) {
    initSchema();
}

// The timezone is set before the migrations: they convert stored timestamps and
// must already know which zone the service works in
date_default_timezone_set((string)(Settings::get('TIMEZONE', 'Europe/Moscow') ?: 'Europe/Moscow'));

// Apply incremental migrations (module 002 and later)
runMigrations();

// Effective configuration: DB override → config.php → built-in default
Settings::boot($fileCfg);
$cfg = Settings::effective();
date_default_timezone_set((string)($cfg['TIMEZONE'] ?? 'Europe/Moscow'));

// Every PHP error, warning and uncaught exception goes to the log the admin sees
Logger::install();

// Start the session once, before anything can emit output
startSession();

// Init LLM
LLM::init($cfg);

// Keep managers in sync with config.php (panel edits are not overwritten)
syncManagersFromConfig($cfg);

// Автообновление кода (модуль 014). Пока в настройках стоит галочка, каждое
// открытие страницы тихо спрашивает у GitHub head отслеживаемой ссылки: тот же
// коммит — не происходит ничего, новый — pull.php выкладывает его и браузер
// возвращается на ту же страницу, уже на новом коде. Креды берутся из
// pull-config.php рядом с pull.php (он же корень проекта), состояние — в data/,
// которое деплой не трогает.
AutoPull::run(AutoPull::options($cfg, [
    'root'      => ROOT,
    'state_dir' => dirname((string)($cfg['DB_PATH'] ?? ROOT . '/data/kp.db')),
]));

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
        -- Список типов живёт в `Notifier::PUSH_KINDS`, а не в схеме: он растёт
        -- с каждым модулем, и проверка здесь молча отбивала всё новое (модуль 029)
        type TEXT NOT NULL,
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
        phone TEXT,
        email TEXT,
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

    // v3 — module 003: product cards, photos and upsell in the KP
    if ($current < 3) {
        // Contact block in the KP header (FR-041)
        Db::ensureColumn('legal_entities', 'phone', 'TEXT');
        Db::ensureColumn('legal_entities', 'email', 'TEXT');

        // Rich product content pulled from MoySklad and cached (FR-040, FR-042)
        Db::ensureColumn('products_cache', 'images_json', 'TEXT');
        Db::ensureColumn('products_cache', 'images_synced_at', 'TEXT');
        Db::ensureColumn('products_cache', 'specs_text', 'TEXT');
        Db::ensureColumn('products_cache', 'included_text', 'TEXT');
        Db::ensureColumn('products_cache', 'is_addon', 'INTEGER', '0');

        // Per-item card: description, specs, kit contents, photos (FR-040, FR-042, FR-045)
        Db::ensureColumn('proposal_items', 'description_text', 'TEXT');
        Db::ensureColumn('proposal_items', 'specs_text', 'TEXT');
        Db::ensureColumn('proposal_items', 'included_text', 'TEXT');
        Db::ensureColumn('proposal_items', 'images_json', 'TEXT');
        Db::ensureColumn('proposal_items', 'show_images', 'INTEGER', '1');
        Db::ensureColumn('proposal_items', 'price_from', 'INTEGER', '0');
        Db::ensureColumn('proposal_items', 'qty_from', 'INTEGER', '0');

        // Document-level blocks (FR-043, FR-044)
        Db::ensureColumn('proposals', 'warranty_text', 'TEXT');
        Db::ensureColumn('proposals', 'images_note', 'TEXT');
        Db::ensureColumn('proposals', 'show_images', 'INTEGER', '1');
        Db::ensureColumn('proposals', 'show_upsell', 'INTEGER', '1');
        Db::ensureColumn('proposals', 'upsell_intro', 'TEXT');
        Db::ensureColumn('proposals', 'upsell_note', 'TEXT');

        // Upsell rows: modules the client can add now or later (FR-044)
        $sql = <<<'SQL'
        CREATE TABLE IF NOT EXISTS proposal_addons (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            proposal_id INTEGER NOT NULL REFERENCES proposals(id) ON DELETE CASCADE,
            position INTEGER NOT NULL DEFAULT 0,
            product_name TEXT NOT NULL,
            moysklad_product_id TEXT,
            unit TEXT NOT NULL DEFAULT 'шт.',
            price REAL NOT NULL DEFAULT 0,
            notes TEXT,
            is_selected INTEGER NOT NULL DEFAULT 1
        );
        CREATE INDEX IF NOT EXISTS idx_addons_proposal ON proposal_addons(proposal_id);
SQL;
        Db::pdo()->exec($sql);

        $defaults = [
            // Sample KP wording — editable per proposal in the UI
            'kp_images_note'   => 'Изображения продукции приведены для примера и могут отличаться от финального изделия, т.к. производитель постоянно дорабатывает продукцию, а финальные требования согласовываются с заказчиком.',
            'kp_upsell_intro'  => 'Изделие допускает доукомплектование модулями в любой момент — сразу или в будущем, по мере необходимости:',
            'kp_upsell_note'   => 'Изображение полной комплектации, которую можно доукомплектовать в будущем при необходимости:',
            'default_warranty_text' => 'Поставщик несёт гарантию в течение года эксплуатации, а также обеспечивает обслуживание своей продукции в течение всего срока эксплуатации.',
            // MoySklad folder whose products are offered as upsell modules
            'addon_category'   => 'Модули для бронежилетов',
            'kp_show_images'   => '1',
            'kp_show_upsell'   => '1',
            'kp_max_images_per_item' => '5',
        ];
        foreach ($defaults as $k => $v) {
            Db::q("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)", [$k, $v]);
        }

        // Flag cached products that belong to the addon folder
        $addonCategory = Db::val("SELECT value FROM settings WHERE key='addon_category'") ?: '';
        if ($addonCategory !== '') {
            Db::q("UPDATE products_cache SET is_addon=1 WHERE category=?", [$addonCategory]);
        }

        if (!is_dir(ROOT . '/storage/product_images')) @mkdir(ROOT . '/storage/product_images', 0755, true);

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '3')");
        $current = 3;
    }

    // v4 — module 004: admin console, error log, mail overlay, editable prompts
    if ($current < 4) {
        $sql = <<<'SQL'
        CREATE TABLE IF NOT EXISTS app_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            level TEXT NOT NULL DEFAULT 'info',
            channel TEXT NOT NULL DEFAULT 'app',
            message TEXT NOT NULL,
            context TEXT,
            source TEXT,
            manager_id INTEGER,
            request_uri TEXT,
            repeat_count INTEGER NOT NULL DEFAULT 1,
            last_at TEXT,
            -- Logger writes this itself, in the timezone of the settings
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_log_level ON app_log(level, id);
        CREATE INDEX IF NOT EXISTS idx_log_channel ON app_log(channel, id);

        CREATE TABLE IF NOT EXISTS mailboxes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            is_default INTEGER NOT NULL DEFAULT 0,
            manager_id INTEGER REFERENCES managers(id),
            create_requests INTEGER NOT NULL DEFAULT 1,
            sync_sent INTEGER NOT NULL DEFAULT 1,
            imap_host TEXT, imap_port INTEGER DEFAULT 993, imap_encryption TEXT DEFAULT 'ssl',
            imap_user TEXT, imap_password TEXT,
            imap_folder_in TEXT DEFAULT 'INBOX', imap_folder_sent TEXT DEFAULT 'INBOX.Sent',
            smtp_host TEXT, smtp_port INTEGER DEFAULT 465, smtp_encryption TEXT DEFAULT 'ssl',
            smtp_user TEXT, smtp_password TEXT,
            from_name TEXT, from_email TEXT,
            last_uid_in INTEGER NOT NULL DEFAULT 0,
            last_uid_sent INTEGER NOT NULL DEFAULT 0,
            last_check_at TEXT,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS mail_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mailbox_id INTEGER REFERENCES mailboxes(id),
            direction TEXT NOT NULL CHECK(direction IN ('in','out')),
            folder TEXT NOT NULL DEFAULT 'INBOX',
            uid INTEGER NOT NULL DEFAULT 0,
            message_id TEXT,
            in_reply_to TEXT,
            subject TEXT,
            from_email TEXT, from_name TEXT,
            to_emails TEXT, cc_emails TEXT,
            body_text TEXT, body_html TEXT,
            size INTEGER DEFAULT 0,
            has_attachment INTEGER NOT NULL DEFAULT 0,
            attachments_json TEXT,
            counterparty_id INTEGER REFERENCES counterparties(id),
            request_id INTEGER REFERENCES requests(id),
            correspondence_id INTEGER REFERENCES correspondence(id),
            manager_id INTEGER REFERENCES managers(id),
            is_read INTEGER NOT NULL DEFAULT 0,
            processed_at TEXT,
            error TEXT,
            date_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_mail_box ON mail_messages(mailbox_id, direction, id);
        CREATE INDEX IF NOT EXISTS idx_mail_uid ON mail_messages(mailbox_id, folder, uid);
        CREATE INDEX IF NOT EXISTS idx_mail_cp ON mail_messages(counterparty_id);

        CREATE TABLE IF NOT EXISTS prompts (
            key TEXT PRIMARY KEY,
            content TEXT NOT NULL,
            updated_at TEXT,
            updated_by INTEGER REFERENCES managers(id)
        );

        CREATE TABLE IF NOT EXISTS prompt_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key TEXT NOT NULL,
            content TEXT NOT NULL,
            manager_id INTEGER REFERENCES managers(id),
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_prompt_history_key ON prompt_history(key, id);
SQL;
        Db::pdo()->exec($sql);

        // Attachments now also hang off an archived mail message
        Db::ensureColumn('attachments', 'mail_message_id', 'INTEGER');

        // Managers: several people, editable from the panel, config sync opt-out
        Db::ensureColumn('managers', 'is_active', 'INTEGER', '1');
        Db::ensureColumn('managers', 'ui_managed', 'INTEGER', '0');
        Db::ensureColumn('managers', 'phone', 'TEXT');
        Db::ensureColumn('managers', 'updated_at', 'TEXT');

        // The mailbox from config.php becomes the first mailbox of the panel
        seedMailboxFromConfig();

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '4')");
        $current = 4;
    }

    // v5 — module 004: mail provider presets (Yandex & co) and the full archive download
    if ($current < 5) {
        // Which service the mailbox lives on — drives the presets and the app-password hint
        Db::ensureColumn('mailboxes', 'provider', 'TEXT', "'custom'");

        // Cursors of «скачать весь архив»: how far back each folder has been walked
        Db::ensureColumn('mailboxes', 'backfill_uid_in', 'INTEGER', '0');
        Db::ensureColumn('mailboxes', 'backfill_uid_sent', 'INTEGER', '0');
        Db::ensureColumn('mailboxes', 'backfill_max_in', 'INTEGER', '0');
        Db::ensureColumn('mailboxes', 'backfill_max_sent', 'INTEGER', '0');
        Db::ensureColumn('mailboxes', 'backfill_done_in', 'INTEGER', '0');
        Db::ensureColumn('mailboxes', 'backfill_done_sent', 'INTEGER', '0');
        Db::ensureColumn('mailboxes', 'backfill_started_at', 'TEXT');
        Db::ensureColumn('mailboxes', 'backfill_finished_at', 'TEXT');

        // Existing mailboxes get their provider guessed from the address
        require_once ROOT . '/lib/mail.php';
        foreach (Db::all("SELECT id, email, imap_user FROM mailboxes") as $box) {
            $email = (string)($box['email'] ?: $box['imap_user']);
            Db::update('mailboxes', ['provider' => MailProviders::detect($email)], 'id=?', [$box['id']]);
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '5')");
        $current = 5;
    }

    // v6 — module 005: local copy of the company wiki (GitHub GRAPH/wiki)
    if ($current < 6) {
        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS knowledge_docs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            path TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL DEFAULT '',
            tags TEXT NOT NULL DEFAULT '',
            content TEXT NOT NULL,
            sha TEXT NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
SQL);
        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '6')");
        $current = 6;
    }

    // v7 — the log collapses repeats and keeps local time
    if ($current < 7) {
        Db::ensureColumn('app_log', 'repeat_count', 'INTEGER', '1');
        Db::ensureColumn('app_log', 'last_at', 'TEXT');
        // Rows written before this migration hold UTC — shift them once by the
        // offset of TIMEZONE, so the whole log reads in one timezone.
        // SQLite's own 'localtime' is the server's OS zone, which is not it.
        $offset = (new DateTimeZone(date_default_timezone_get()))
            ->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        if ($offset !== 0) {
            Db::q("UPDATE app_log SET created_at = datetime(created_at, ?) WHERE created_at IS NOT NULL",
                  [sprintf('%+d seconds', $offset)]);
        }
        Db::q("UPDATE app_log SET last_at = created_at WHERE last_at IS NULL");
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_log_dedup ON app_log(level, channel, last_at)");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '7')");
        $current = 7;
    }

    // v8 — module 006: triage of incoming mail + FTS5 index over the wiki
    if ($current < 8) {
        Db::ensureColumn('requests', 'category', 'TEXT');
        Db::ensureColumn('requests', 'category_confidence', 'REAL');
        Db::ensureColumn('requests', 'category_reason', 'TEXT');
        Db::ensureColumn('requests', 'category_source', 'TEXT');
        Db::ensureColumn('mail_messages', 'category', 'TEXT');
        Db::ensureColumn('mail_messages', 'triage_reason', 'TEXT');
        // The prefilter reads List-Unsubscribe / Precedence / Auto-Submitted from these
        Db::ensureColumn('mail_messages', 'headers', 'TEXT');
        // TRIAGE_AUTO_DRAFT parks a ready answer here, so «Создать ответ» opens
        // it instantly instead of paying for a second generation
        Db::ensureColumn('mail_messages', 'draft_text', 'TEXT');
        Db::ensureColumn('mail_messages', 'draft_at', 'TEXT');
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_requests_category ON requests(category)");
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_mail_category ON mail_messages(direction, category)");

        // Wiki sections + their full-text index. The virtual table is optional:
        // a SQLite built without FTS5 keeps working on the PHP scan (module 005).
        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS knowledge_sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            doc_path TEXT NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            heading TEXT NOT NULL DEFAULT '',
            tags TEXT NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            chars INTEGER NOT NULL DEFAULT 0
        );
SQL);
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_ksections_doc ON knowledge_sections(doc_path)");
        try {
            Db::pdo()->exec("CREATE VIRTUAL TABLE IF NOT EXISTS knowledge_fts
                USING fts5(title, heading, tags, body, tokenize='unicode61 remove_diacritics 2')");
        } catch (Throwable $e) {
            Logger::warning('knowledge', 'SQLite без FTS5 — поиск по вики останется на переборе в PHP',
                            ['error' => $e->getMessage()]);
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '8')");
        $current = 8;
    }

    // v9 — module 007: installable admin app (PWA) and web-push subscriptions
    if ($current < 9) {
        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            manager_id INTEGER NOT NULL REFERENCES managers(id),
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            user_agent TEXT,
            last_used_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_push_manager ON push_subscriptions(manager_id);

        CREATE TABLE IF NOT EXISTS push_mutes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            manager_id INTEGER NOT NULL REFERENCES managers(id),
            kind TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_push_mute_uniq ON push_mutes(manager_id, ifnull(kind,''));
SQL);
        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '9')");
        $current = 9;
    }

    // v10 — module 008: catalog from an Excel export, matched positions on the
    // request card, photos chosen per KP
    if ($current < 10) {
        // What the MoySklad export carries beyond name and price
        Db::ensureColumn('products_cache', 'code', 'TEXT');
        Db::ensureColumn('products_cache', 'product_type', 'TEXT', "'product'");
        Db::ensureColumn('products_cache', 'parent_id', 'TEXT');
        Db::ensureColumn('products_cache', 'characteristics', 'TEXT');
        // Public CDN links from the export — no token needed to fetch them
        Db::ensureColumn('products_cache', 'image_urls', 'TEXT');
        Db::ensureColumn('products_cache', 'is_archived', 'INTEGER', '0');
        Db::ensureColumn('products_cache', 'source', 'TEXT', "'moysklad'");
        Db::ensureColumn('products_cache', 'imported_at', 'TEXT');
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_products_code ON products_cache(code)");
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_products_parent ON products_cache(parent_id)");

        // «Подходящие позиции» of a request: what the manager confirmed the client
        // asked for. The KP is built from these rows, not from a fresh guess.
        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS request_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
            position INTEGER NOT NULL DEFAULT 0,
            raw_name TEXT NOT NULL DEFAULT '',
            quantity REAL NOT NULL DEFAULT 1,
            moysklad_product_id TEXT,
            product_name TEXT,
            article TEXT,
            unit TEXT NOT NULL DEFAULT 'шт.',
            price REAL NOT NULL DEFAULT 0,
            stock INTEGER,
            match_confidence REAL,
            match_variants TEXT,
            is_confirmed INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_request_items ON request_items(request_id, position);
SQL);

        // Which photos of a product go into this KP (FR-046)
        Db::ensureColumn('proposal_items', 'selected_images', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '10')");
        $current = 10;
    }

    // v11 — modules 009 and 010: catalog vectors, an equal-candidates choice on
    // the request card, mail threads across mailboxes and the kanban boards
    if ($current < 11) {
        // One embedding per catalog row. The vector is stored normalized as
        // packed float32, so a cosine score is a plain dot product and 1 200
        // positions weigh about 1 MB instead of 12 MB of JSON.
        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS product_vectors (
            product_id TEXT PRIMARY KEY,
            model TEXT NOT NULL DEFAULT '',
            dim INTEGER NOT NULL DEFAULT 0,
            text_hash TEXT NOT NULL DEFAULT '',
            vec BLOB NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_pvec_hash ON product_vectors(text_hash);
SQL);

        // A line whose best candidates are equally good is not a match — it is a
        // question for the manager, and the card has to say so instead of
        // silently picking the first row.
        Db::ensureColumn('request_items', 'needs_choice', 'INTEGER', '0');
        Db::ensureColumn('request_items', 'match_source', 'TEXT');

        // Mail threads: everything with the same subject after «Re:»/«Fwd:» is
        // one conversation, whichever mailbox it arrived in or was answered from
        Db::ensureColumn('mail_messages', 'thread_key', 'TEXT');
        Db::ensureColumn('mail_messages', 'thread_subject', 'TEXT');
        // Whether the copy actually landed in the IMAP «Отправленные» folder
        Db::ensureColumn('mail_messages', 'sent_state', 'TEXT');
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_mail_thread ON mail_messages(thread_key, date_at)");

        // Kanban: boards of columns, a card points at a mail thread
        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS boards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS board_columns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            board_id INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
            title TEXT NOT NULL,
            color TEXT,
            position INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_bcolumns ON board_columns(board_id, position);
        CREATE TABLE IF NOT EXISTS board_cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            column_id INTEGER NOT NULL REFERENCES board_columns(id) ON DELETE CASCADE,
            position INTEGER NOT NULL DEFAULT 0,
            thread_key TEXT,
            mail_message_id INTEGER REFERENCES mail_messages(id) ON DELETE SET NULL,
            request_id INTEGER REFERENCES requests(id) ON DELETE SET NULL,
            title TEXT NOT NULL DEFAULT '',
            note TEXT,
            manager_id INTEGER REFERENCES managers(id),
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            moved_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_bcards ON board_cards(column_id, position);
        CREATE INDEX IF NOT EXISTS idx_bcards_thread ON board_cards(thread_key);
SQL);

        // Existing archive: give every letter its thread key at once, so the mail
        // page opens threaded on the first run after the upgrade
        require_once __DIR__ . '/mail_threads.php';
        MailThreads::backfill();

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '11')");
        $current = 11;
    }

    // v12 — multiple MoySklad price types, per-counterparty price default, spam marking
    if ($current < 12) {
        // Every sale price MoySklad knows for a product, so a manager can pick
        // one instead of being stuck with whichever the sync happened to grab
        Db::ensureColumn('products_cache', 'prices_json', 'TEXT');

        // Which of those price types this counterparty gets by default — falls
        // back to CATALOG_DEFAULT_PRICE_TYPE (settings) when unset
        Db::ensureColumn('counterparties', 'default_price_type', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '12')");
        $current = 12;
    }

    // v13 — module 011: one board of company cards. A card is the company, not
    // a letter, and every conversation the company ever sent hangs on it.
    if ($current < 13) {
        // The card points at a company; thread_key stays for a letter that has
        // no company yet (an unknown sender lands on the board all the same)
        Db::ensureColumn('board_cards', 'counterparty_id', 'INTEGER REFERENCES counterparties(id)');
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_bcards_cp ON board_cards(counterparty_id)");

        // Which column new mail falls into. Named rather than «the first one»,
        // so renaming or reordering the columns cannot break the intake.
        Db::ensureColumn('board_columns', 'kind', 'TEXT');
        Db::q("UPDATE board_columns SET kind='inbox' WHERE kind IS NULL AND title='Входящие'");
        // A board whose intake column was renamed away: the leftmost one takes over
        foreach (Db::all("SELECT id FROM boards") as $b) {
            if (Db::val("SELECT COUNT(*) FROM board_columns WHERE board_id=? AND kind='inbox'", [$b['id']])) continue;
            $first = Db::one("SELECT id FROM board_columns WHERE board_id=? ORDER BY position, id LIMIT 1", [$b['id']]);
            if ($first) Db::update('board_columns', ['kind' => 'inbox'], 'id=?', [$first['id']]);
        }

        // What the client asked for, next to what we actually offered — the
        // pair a substituted analogue is learned from
        Db::ensureColumn('proposal_items', 'requested_name', 'TEXT');

        // What the manager changed by hand is what the model has to learn. The
        // v1 CHECK knew four fields; an analogue offered instead of the asked-for
        // brand is a fifth, so the constraint is rebuilt rather than worked around.
        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS corrections_v13 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER REFERENCES requests(id),
            field TEXT NOT NULL CHECK(field IN ('cover_letter','pre_table','post_table','conditions','item_substitution','reply')),
            auto_text TEXT NOT NULL,
            manager_text TEXT NOT NULL,
            context_json TEXT,
            manager_id INTEGER REFERENCES managers(id),
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
SQL);
        Db::q("INSERT INTO corrections_v13 (id, request_id, field, auto_text, manager_text, context_json, manager_id, created_at)
               SELECT id, request_id, field, auto_text, manager_text, context_json, manager_id, created_at FROM corrections");
        Db::q("DROP TABLE corrections");
        Db::pdo()->exec("ALTER TABLE corrections_v13 RENAME TO corrections");
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_corrections_field ON corrections(field, id)");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '13')");
        $current = 13;
    }

    // v14 — «Удалить письмо». The row leaves the archive for good, so a tombstone
    // remembers which UID / Message-ID was thrown away: without it the next sync
    // would happily download the same letter again the minute it is deleted.
    if ($current < 14) {
        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS mail_deleted (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mailbox_id INTEGER,
            folder TEXT NOT NULL DEFAULT '',
            uid INTEGER NOT NULL DEFAULT 0,
            message_id TEXT,
            direction TEXT,
            subject TEXT,
            from_email TEXT,
            date_at TEXT,
            manager_id INTEGER REFERENCES managers(id),
            server_state TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_mail_deleted_uid ON mail_deleted(mailbox_id, folder, uid);
        CREATE INDEX IF NOT EXISTS idx_mail_deleted_mid ON mail_deleted(mailbox_id, message_id);
SQL);

        // Where a notification leads. Until now the target was guessed from
        // ref_type, so a new letter could only open the request built from it —
        // a push about mail now opens the letter itself.
        Db::ensureColumn('notifications', 'url', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '14')");
        $current = 14;
    }

    // v15 — the saved Yandex model is a bare slug now: the version segment is
    // added when the request is built (LLM::yandexModelUri). A value saved with
    // its own «/latest» stays valid but no longer matches a catalog row, so the
    // picker would show it as «своя модель» — strip it. gpt-oss is not served by
    // the folder at all (HTTP 404 «unknown model»), so that pick goes back to the
    // default instead of failing every letter.
    if ($current < 15) {
        $model = (string)(Db::val("SELECT value FROM settings WHERE key='cfg.YANDEX_MODEL'") ?? '');
        if ($model !== '') {
            $slug = preg_replace('~/latest$~', '', trim($model, " /"));   // «/rc» is a deliberate pick — keep it
            if (str_starts_with($slug, 'gpt-oss')) $slug = 'yandexgpt';
            if ($slug !== '' && $slug !== $model) {
                Db::q("UPDATE settings SET value=? WHERE key='cfg.YANDEX_MODEL'", [$slug]);
            }
        }
        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '15')");
        $current = 15;
    }

    // v16 — a conversation is a subject WITH A PARTY: «Запрос КП» from three
    // different companies stopped being one thread (module 012)
    if ($current < 16) {
        require_once __DIR__ . '/mail_threads.php';

        // A card points at a thread by its key, and every key is about to
        // change. Remember one letter per card first, so the card can be
        // pointed at whatever that letter's conversation is called afterwards.
        $cardKeys = [];
        foreach (Db::all("SELECT id, thread_key FROM board_cards WHERE thread_key IS NOT NULL") as $card) {
            $mid = Db::val("SELECT id FROM mail_messages WHERE thread_key=? ORDER BY date_at, id LIMIT 1", [$card['thread_key']]);
            if ($mid) $cardKeys[(int)$card['id']] = (int)$mid;
        }

        MailThreads::backfill(true);

        foreach ($cardKeys as $cardId => $messageId) {
            $key = Db::val("SELECT thread_key FROM mail_messages WHERE id=?", [$messageId]);
            if ($key) Db::update('board_cards', ['thread_key' => $key], 'id=?', [$cardId]);
        }
        // Re-splitting the archive can leave a company on the board twice over
        // the same conversation; the intake decides again on the next open
        Db::q("DELETE FROM settings WHERE key='board_sync_sig'");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '16')");
        $current = 16;
    }

    // v17 — module 013: an analogue for what we cannot ship, the correspondence
    // table of a request that arrived as a table, the product's page on the
    // shop, and the requisites frozen onto the КП they were signed with.
    if ($current < 17) {
        // The catalog now remembers the product's own VAT rate (МойСклад decides
        // the rate of a КП line, not a house default) and its page on the site
        Db::ensureColumn('products_cache', 'vat', 'INTEGER');
        Db::ensureColumn('products_cache', 'site_url', 'TEXT');
        Db::ensureColumn('products_cache', 'site_url_synced_at', 'TEXT');

        // Was the request a table or prose? Decided from the letter, stored once
        Db::ensureColumn('requests', 'shape', 'TEXT');

        // A matched line may be an analogue of something we could not ship, and
        // it carries the proof: which of the client's requirements it meets
        Db::ensureColumn('request_items', 'is_alternative', 'INTEGER', '0');
        Db::ensureColumn('request_items', 'alt_of', 'TEXT');
        Db::ensureColumn('request_items', 'alt_specs_json', 'TEXT');

        Db::ensureColumn('proposal_items', 'is_alternative', 'INTEGER', '0');
        Db::ensureColumn('proposal_items', 'alt_reason', 'TEXT');
        Db::ensureColumn('proposal_items', 'alt_specs_json', 'TEXT');
        Db::ensureColumn('proposal_items', 'site_url', 'TEXT');

        // The correspondence table and the requisites the document was signed
        // with. `requisites_json` is a SNAPSHOT: reprinting a КП from March must
        // not silently stamp today's bank account on it.
        Db::ensureColumn('proposals', 'show_match_table', 'INTEGER');
        Db::ensureColumn('proposals', 'match_table_note', 'TEXT');
        Db::ensureColumn('proposals', 'requisites_json', 'TEXT');

        // Our own legal facts, as МойСклад holds them
        Db::ensureColumn('legal_entities', 'moysklad_id', 'TEXT');
        Db::ensureColumn('legal_entities', 'kpp', 'TEXT');
        Db::ensureColumn('legal_entities', 'okpo', 'TEXT');
        Db::ensureColumn('legal_entities', 'legal_address', 'TEXT');
        Db::ensureColumn('legal_entities', 'pays_vat', 'INTEGER', '1');
        Db::ensureColumn('legal_entities', 'bank_name', 'TEXT');
        Db::ensureColumn('legal_entities', 'bank_bic', 'TEXT');
        Db::ensureColumn('legal_entities', 'bank_account', 'TEXT');
        Db::ensureColumn('legal_entities', 'bank_corr', 'TEXT');
        Db::ensureColumn('legal_entities', 'synced_at', 'TEXT');

        // The buyer's, and the договор the КП is issued under
        Db::ensureColumn('counterparties', 'legal_title', 'TEXT');
        Db::ensureColumn('counterparties', 'legal_address', 'TEXT');
        Db::ensureColumn('counterparties', 'kpp', 'TEXT');
        Db::ensureColumn('counterparties', 'ogrn', 'TEXT');
        Db::ensureColumn('counterparties', 'contract_moysklad_id', 'TEXT');
        Db::ensureColumn('counterparties', 'contract_name', 'TEXT');
        Db::ensureColumn('counterparties', 'contract_date', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '17')");
        $current = 17;
    }

    // v18 — module 015: a letter from the site form is the visitor's letter, an
    // undelivered answer says so, and a КП can leave as a Word file.
    if ($current < 18) {
        require_once __DIR__ . '/site_forms.php';
        require_once __DIR__ . '/mail_threads.php';

        // Where the letter really came from, and what the form said
        Db::ensureColumn('mail_messages', 'source_channel', 'TEXT', "'email'");
        Db::ensureColumn('mail_messages', 'form_json', 'TEXT');
        Db::ensureColumn('mail_messages', 'needs_call', 'INTEGER', '0');

        // ЭДО as the client states it, and whether he still needs paper
        Db::ensureColumn('counterparties', 'edo_operator', 'TEXT');
        Db::ensureColumn('counterparties', 'edo_id', 'TEXT');
        Db::ensureColumn('counterparties', 'needs_paper_docs', 'INTEGER', '0');

        // The КП as a Word file lives beside its PDF — both are printed from the
        // same document, and a reprint must find whichever the manager sent.
        Db::ensureColumn('proposals', 'docx_path', 'TEXT');

        // Everything already in the archive: 923 form letters whose sender is
        // our own address, all in ONE thread and ONE company card. They are
        // rewritten here, and the threads recomputed afterwards — a migration
        // that leaves them as they are leaves the board wrong for good.
        $touched = 0;
        foreach (Db::all("SELECT id, subject, body_text, from_email, from_name FROM mail_messages
                          WHERE direction='in' AND body_text LIKE '%Заполнена форма%'") as $row) {
            $fields = SiteForm::parse((string)$row['body_text']);
            if (!$fields) continue;
            $spam = SiteForm::spamReason($fields);
            $upd = [
                'source_channel' => 'site_form',
                'form_json'      => json_encode($fields + ['spam_reason' => $spam], JSON_UNESCAPED_UNICODE),
                'needs_call'     => ($fields['email'] === '' && $fields['phone'] !== '' && !$spam) ? 1 : 0,
            ];
            if ($spam) {
                $upd['category'] = 'spam';
                $upd['triage_reason'] = 'Форма сайта: ' . $spam;
            } else {
                if ($fields['email'] !== '') {
                    $upd['from_email'] = $fields['email'];
                    $upd['from_name']  = $fields['name'] !== '' ? $fields['name'] : $fields['email'];
                }
                $upd['subject'] = SiteForm::subject($fields, (string)$row['subject']);
            }
            Db::update('mail_messages', $upd, 'id=?', [$row['id']]);
            $touched++;
        }
        if ($touched) {
            MailThreads::backfill(true);
            // The board decides again: those letters were one company and are now many
            Db::q("DELETE FROM settings WHERE key='board_sync_sig'");
            Logger::info('mail', "Миграция v18: писем с форм сайта разобрано — $touched");
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '18')");
        $current = 18;
    }

    // v19 — modules 005 + 009: the wiki is vectorized the way the catalog is
    if ($current < 19) {
        // Vectors are keyed by the TEXT, not by the section id: the section
        // table is rebuilt from scratch on every reindex, and a wiki re-read
        // from GitHub must not throw away the embeddings of sections whose text
        // did not move.
        Db::pdo()->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS knowledge_vectors (
            text_hash TEXT PRIMARY KEY,
            model TEXT NOT NULL DEFAULT '',
            dim INTEGER NOT NULL DEFAULT 0,
            vec BLOB NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
SQL);
        Db::ensureColumn('knowledge_sections', 'text_hash', 'TEXT', "''");
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_ksections_hash ON knowledge_sections(text_hash)");

        // Sections indexed before this migration carry no hash and would never
        // be picked up by the queue — rebuild them from the cached wiki.
        try {
            if ((int)Db::val("SELECT COUNT(*) FROM knowledge_docs")) Knowledge::reindex();
        } catch (Throwable $e) {
            Logger::exception('knowledge', $e, ['stage' => 'migration_19']);
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '19')");
        $current = 19;
    }

    // v20 — module 018: «подтверждено» becomes a statement about the document
    if ($current < 20) {
        // Which positions the manager knowingly signed off without a price, and
        // who did it. Empty means «КП с ценами» — the guard then has nothing to
        // ask about (module 018).
        Db::ensureColumn('proposals', 'no_price_ack_json', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '20')");
        $current = 20;
    }

    // v21 — модуль 019: письмо уходит с экрана, а не из ящика; ящик выключают,
    // а не удаляют, и его письма уходят с экрана вместе с ним.
    if ($current < 21) {
        // Когда письмо убрали с экрана и почему: 'not_our_profile' — менеджер
        // сказал «не наш профиль», 'mailbox_off' — ящик выключили или удалили.
        // NULL — письмо в работе, как и было до миграции.
        Db::ensureColumn('mail_messages', 'archived_at', 'TEXT');
        Db::ensureColumn('mail_messages', 'archived_reason', 'TEXT');
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_mail_archived ON mail_messages(archived_at)");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '21')");
        $current = 21;
    }

    // v22 — модуль 020: переписка читается на карточке, а не в заметках;
    // описание товара печатается разметкой, а не её тегами; позицию, которой
    // у нас нет, можно свернуть, и КП об этом говорит вслух.
    if ($current < 22) {
        require_once __DIR__ . '/markup.php';

        // Позиция, свёрнутая менеджером: в таблицу, карточки и «Итого» она не
        // попадает, а в блок «нужно уточнение» — попадает. 0 — как было.
        Db::ensureColumn('proposal_items', 'is_excluded', 'INTEGER', '0');

        // Описания, которые уже лежат в КП размеченными. Их писали в визуальном
        // редакторе МойСклад, и до этой миграции `<ul><li>` уходили в PDF ровно
        // так, как выглядят. Переписываем один раз, в Markdown: дальше карточка
        // читается и правится как текст.
        foreach (Db::all("SELECT id, description_text, specs_text, included_text FROM proposal_items") as $row) {
            $upd = [];
            foreach (['description_text', 'specs_text', 'included_text'] as $field) {
                $was = (string)($row[$field] ?? '');
                if ($was === '' || !Markup::looksLikeHtml($was)) continue;
                $upd[$field] = Markup::toMarkdown($was);
            }
            if ($upd) Db::update('proposal_items', $upd, 'id=?', [$row['id']]);
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '22')");
        $current = 22;
    }

    // v23 — модуль 021: импорт переписки из mbox и дедупликация писем со всех
    // ящиков. Отпечаток письма (отправитель, получатели, тема, текст, файлы) —
    // то, чем одно и то же письмо узнаётся, когда Message-ID переписан шлюзом
    // или его вовсе нет.
    if ($current < 23) {
        Db::ensureColumn('mail_messages', 'dedup_hash', 'TEXT');
        Db::ensureColumn('mail_messages', 'import_id', 'INTEGER');
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_mail_dedup ON mail_messages(dedup_hash)");
        // Дедупликация ищет Message-ID по всем ящикам сразу — без этого индекса
        // это полный проход по архиву на каждое входящее письмо
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_mail_message_id ON mail_messages(message_id)");
        Db::ensureColumn('attachments', 'content_hash', 'TEXT');
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_attach_hash ON attachments(mail_message_id, content_hash)");

        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS mbox_imports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            byte_offset INTEGER NOT NULL DEFAULT 0,
            mailbox_id INTEGER REFERENCES mailboxes(id),
            create_companies INTEGER NOT NULL DEFAULT 0,
            scanned INTEGER NOT NULL DEFAULT 0,
            imported INTEGER NOT NULL DEFAULT 0,
            duplicates INTEGER NOT NULL DEFAULT 0,
            failed INTEGER NOT NULL DEFAULT 0,
            done INTEGER NOT NULL DEFAULT 0,
            error TEXT,
            manager_id INTEGER REFERENCES managers(id),
            started_at TEXT,
            finished_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_mbox_imports_file ON mbox_imports(filename, id);
SQL);

        // Отпечатки писем, которые уже лежат в архиве. На маленькой базе это
        // делается здесь и сейчас; большую досчитывают шаги импорта и кнопка в
        // «Настройки → Почта» — до тех пор старые письма дедуплицируются по
        // Message-ID, как и раньше.
        require_once __DIR__ . '/mail.php';
        MailArchive::backfillFingerprints(2000, 10.0);

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '23')");
        $current = 23;
    }

    // v24 — модуль 022: модификации товара, «не наша номенклатура», подпись
    // менеджера, правки, на которых сервис учится, и лента изменений текстов.
    if ($current < 24) {
        // Размер и цвет, которые просила строка письма. Метка живёт на строке,
        // а не в названии: подбор ищет товар-родителя, а модификацию выбирает
        // по ней (модуль 022).
        Db::ensureColumn('request_items', 'variant_label', 'TEXT');
        Db::ensureColumn('request_items', 'variant_kind', 'TEXT');

        // Третье состояние строки: не «нашли» и не «уточняем», а «это не к нам».
        // Такая строка не уходит ни в КП, ни в ответ клиенту.
        Db::ensureColumn('request_items', 'is_out_of_scope', 'INTEGER', '0');
        Db::ensureColumn('request_items', 'out_of_scope_reason', 'TEXT');

        // Подпись у каждого менеджера своя; пусто — подписант организации
        Db::ensureColumn('managers', 'signatory_name', 'TEXT');
        Db::ensureColumn('managers', 'signature_path', 'TEXT');

        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS learning_samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            subject TEXT,
            question TEXT,
            auto_answer TEXT,
            correct_answer TEXT,
            comment TEXT,
            context_json TEXT,
            manager_id INTEGER REFERENCES managers(id),
            exported_at TEXT,
            export_batch TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_learning_kind ON learning_samples(kind, id);
        CREATE INDEX IF NOT EXISTS idx_learning_new ON learning_samples(exported_at);

        CREATE TABLE IF NOT EXISTS content_changes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            area TEXT NOT NULL,
            item_key TEXT,
            title TEXT,
            manager_id INTEGER REFERENCES managers(id),
            before_len INTEGER NOT NULL DEFAULT 0,
            after_len INTEGER NOT NULL DEFAULT 0,
            excerpt TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_content_changes_at ON content_changes(id DESC);
SQL);

        // Tone of Voice жил файлом в репозитории — деплой его перезаписывал, и
        // правка менеджера исчезала на следующем обновлении кода. Переносим в
        // storage/, один раз (модуль 022).
        $tovFile = ROOT . '/reference/tov.md';
        if (!is_file(ROOT . '/storage/tov.md') && is_file($tovFile)) {
            @mkdir(ROOT . '/storage', 0755, true);
            @copy($tovFile, ROOT . '/storage/tov.md');
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '24')");
        $current = 24;
    }

    // v25 — модуль 023: позиция, которой нет на складе, получает срок ожидания,
    // скидку за ожидание и долю предоплаты; цену и скидку можно поставить
    // руками; под каждой строкой подбора живёт развёрнутый комментарий; письмо,
    // отмеченное спамом, уходит с доски; сквозной поиск по почте.
    if ($current < 25) {
        // «Под заказ» перестаёт быть словом в примечании и становится тремя
        // величинами, которые печатаются и складываются в цену (модуль 023).
        // NULL — «не заполняли», и тогда работают значения из настроек.
        foreach (['proposal_items', 'request_items'] as $table) {
            Db::ensureColumn($table, 'wait_on', 'INTEGER', '0');
            Db::ensureColumn($table, 'wait_months', 'INTEGER');
            Db::ensureColumn($table, 'wait_discount', 'REAL');
            Db::ensureColumn($table, 'wait_prepay', 'INTEGER');
            // Ручная скидка на позицию и признак «цену поставил человек»:
            // пересборка КП и повторный подбор такую цену не перетирают
            Db::ensureColumn($table, 'discount_percent', 'REAL', '0');
            Db::ensureColumn($table, 'price_is_manual', 'INTEGER', '0');
            // Развёрнутый комментарий под позицией. Поле показывается
            // описанием из МойСклад, хранит слова менеджера и печатается
            // описанием карточки КП; в письмо клиенту не уходит (модуль 032).
            Db::ensureColumn($table, 'comment_text', 'TEXT');
        }

        // Сколько фотографий печатать в ЭТОМ КП. Пусто — общая настройка.
        Db::ensureColumn('proposals', 'photos_per_item', 'INTEGER');

        // Черновик ответа, который менеджер не отправил. Живёт у письма, а не
        // в браузере: вкладку закрыли — текст остался (модуль 023).
        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS mail_drafts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mail_message_id INTEGER REFERENCES mail_messages(id) ON DELETE CASCADE,
            thread_key TEXT,
            manager_id INTEGER REFERENCES managers(id),
            body TEXT NOT NULL DEFAULT '',
            subject TEXT,
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_mail_drafts_one ON mail_drafts(mail_message_id, manager_id);
SQL);

        // Поиск идёт и по именам файлов, и по тому, что из них вытащили
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_attach_name ON attachments(filename)");

        // «Загрузить заново, не считаясь с дубликатами»: импорт, который кладёт
        // письма даже поверх дедупликации — иначе потерянный вместе с ящиком
        // архив уже не вернуть из того же самого файла
        Db::ensureColumn('mbox_imports', 'force', 'INTEGER', '0');

        // Письма ящиков, которых больше нет, — обратно на экран (модуль 023)
        require_once __DIR__ . '/mail.php';
        MailArchive::restoreOrphaned();

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '25')");
        $current = 25;
    }

    // v26 — модуль 025: домен-ретранслятор больше не склеивает компании.
    // Заявки приходят через сервис, где у каждой свой адрес и общий домен, —
    // и тринадцать покупателей оказывались в одной карточке. Домен теперь
    // считается признаком компании только там, где он ею и является.
    if ($current < 26) {
        Db::pdo()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS shared_domains (
            domain TEXT PRIMARY KEY,
            reason TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
SQL);
        MailDomains::reset();

        require_once __DIR__ . '/crm.php';
        require_once __DIR__ . '/mail_threads.php';

        // Уже слипшееся разбирается сразу: иначе список доменов вырос, а
        // карточка как была общей, так и осталась
        $cards = 0;
        foreach (Db::all("SELECT id, email_domain FROM counterparties
                          WHERE email_domain IS NOT NULL AND email_domain <> '' AND merged_into_id IS NULL") as $cp) {
            if (!MailDomains::isShared((string)$cp['email_domain'])) continue;
            try {
                $cards += count(Crm::splitBySender((int)$cp['id'], false));
            } catch (Throwable $e) {
                Logger::warning('crm', 'Миграция v26: карточка ' . $cp['id'] . ' не разделилась — ' . $e->getMessage());
            }
        }

        // Ключ переписки считается теперь от адреса, а не от домена
        MailThreads::backfill(true);
        Logger::info('crm', "Миграция v26: карточек отделено по отправителю — $cards");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '26')");
        $current = 26;
    }

    // v27 — модуль 026: доска не держит пустых карточек, условия КП правятся
    // и запоминаются, счёт создаётся вместе с заказом в резерве.
    if ($current < 27) {
        // «Прочитано» на карточке доски: письма прочитаны и карточка разобрана.
        // Без этой отметки карточка оставалась жирной — жирность даёт «ждёт
        // ответа», а его групповое «Прочитано» не снимало.
        Db::ensureColumn('board_cards', 'seen_at', 'TEXT');

        // Условия КП — одним правимым блоком вместо четырёх зашитых фраз.
        Db::ensureColumn('proposals', 'terms_text', 'TEXT');

        // Доставка отдельной строкой: её цена не спрятана в цене товара
        Db::ensureColumn('proposals', 'delivery_on', 'INTEGER', '0');
        Db::ensureColumn('proposals', 'delivery_name', 'TEXT');
        Db::ensureColumn('proposals', 'delivery_price', 'REAL', '0');

        // Резерв под неоплаченный счёт: до какого числа держим и напомнили ли
        Db::ensureColumn('orders', 'reserve_until', 'TEXT');
        Db::ensureColumn('orders', 'reserve_reminded_at', 'TEXT');
        Db::ensureColumn('orders', 'reserve_released_at', 'TEXT');
        Db::ensureColumn('orders', 'applicable', 'INTEGER', '1');

        // Оговорка под фотографиями убрана по просьбе. Чужой текст не трогаем:
        // чистится только фраза, которую поставили мы сами.
        $oldNote = 'Изображения продукции приведены для примера и могут отличаться от финального изделия, т.к. производитель постоянно дорабатывает продукцию, а финальные требования согласовываются с заказчиком.';
        Db::q("UPDATE settings SET value='' WHERE key='kp_images_note' AND value=?", [$oldNote]);
        Db::q("INSERT OR IGNORE INTO settings (key, value) VALUES ('kp_images_note', '')");

        // Условия по умолчанию: без годовой гарантии и без доставки в стоимости
        Db::q("INSERT OR IGNORE INTO settings (key, value) VALUES ('default_terms_text', ?)",
              [KpTerms::FACTORY_TEXT]);

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '27')");
        $current = 27;
    }

    // v28 — модуль 027: у запроса может быть несколько КП, позиции между ними
    // перетаскиваются, счёт выставляется по конкретному КП.
    if ($current < 28) {
        // Имя КП, которое пишет менеджер: «Шлемы», «Вторая партия». Пусто —
        // КП называется своим номером.
        Db::ensureColumn('proposals', 'label', 'TEXT');

        // Из какой строки запроса выросла позиция КП. Без этой связи нельзя
        // сказать, какие позиции запроса ещё не разложены по КП.
        Db::ensureColumn('proposal_items', 'request_item_id', 'INTEGER');
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_items_request_item ON proposal_items(request_item_id)");

        // Счёт знает, по какому КП он выставлен: счетов у одного КП может быть
        // несколько, и на карточке они стоят под своим КП, а не общей кучей.
        Db::ensureColumn('invoices', 'proposal_id', 'INTEGER');
        Db::q("UPDATE invoices SET proposal_id = (SELECT o.proposal_id FROM orders o WHERE o.id = invoices.order_id)
               WHERE proposal_id IS NULL AND order_id IS NOT NULL");

        // Уже собранные КП связываем со строками запроса по названию, которое
        // пришло из письма: это то же поле, по которому их и клали в КП.
        Db::q(
            "UPDATE proposal_items SET request_item_id = (
                 SELECT ri.id FROM request_items ri
                 JOIN proposals p ON p.id = proposal_items.proposal_id
                 WHERE ri.request_id = p.request_id
                   AND ri.raw_name = proposal_items.requested_name
                 ORDER BY ri.position LIMIT 1)
             WHERE request_item_id IS NULL AND requested_name IS NOT NULL AND requested_name <> ''"
        );

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '28')");
        $current = 28;
    }

    // v29 — модуль 029: в одном письме просят счёт на две организации, и обе
    // должны жить в карточке, как живут несколько счетов.
    if ($current < 29) {
        Db::pdo()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS counterparty_orgs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                counterparty_id INTEGER NOT NULL REFERENCES counterparties(id),
                name TEXT NOT NULL,
                inn TEXT,
                kpp TEXT,
                moysklad_id TEXT,
                edo_id TEXT,
                note TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_cp_orgs_cp ON counterparty_orgs(counterparty_id);
SQL);

        // На какую организацию выставлен счёт: пусто — на саму карточку
        Db::ensureColumn('invoices', 'org_id', 'INTEGER');
        Db::ensureColumn('orders', 'org_id', 'INTEGER');

        // `notifications.type` был перечислением из трёх значений первого
        // модуля — `new_request`, `followup_ready`, `system`. С тех пор типов
        // стало вдвое больше (заказ, счёт, недоставленный ответ, резерв, а
        // теперь и ошибка сервиса), и КАЖДЫЙ из них база молча отбивала
        // проверкой: уведомление не создавалось вовсе. Ограничение снимаем —
        // список типов живёт в `Notifier::PUSH_KINDS`, а не в схеме, и
        // расширять его не должно значить «переписать таблицу».
        if (str_contains((string)Db::val(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='notifications'"),
            "CHECK(type IN ('new_request','followup_ready','system'))")) {
            Db::pdo()->exec(<<<'SQL'
                CREATE TABLE notifications_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    manager_id INTEGER REFERENCES managers(id),
                    type TEXT NOT NULL,
                    title TEXT NOT NULL,
                    body TEXT,
                    ref_type TEXT,
                    ref_id INTEGER,
                    url TEXT,
                    is_read INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
                INSERT INTO notifications_new (id, manager_id, type, title, body, ref_type, ref_id, url, is_read, created_at)
                    SELECT id, manager_id, type, title, body, ref_type, ref_id, url, is_read, created_at FROM notifications;
                DROP TABLE notifications;
                ALTER TABLE notifications_new RENAME TO notifications;
                CREATE INDEX IF NOT EXISTS idx_notif_manager ON notifications(manager_id, is_read);
SQL);
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '29')");
        $current = 29;
    }

    // v30 — модуль 030: НДС печатается в КП всегда, а цены — в выбранном виде.
    if ($current < 30) {
        // Как печатать цену в ЭТОМ КП: included — уже с НДС, added — налог
        // сверху. Пусто — как в настройке KP_VAT_MODE.
        Db::ensureColumn('proposals', 'vat_mode', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '30')");
        $current = 30;
    }

    // v31 — модуль 031: «убрать с доски» перестало отменяться следующим же
    // заходом на доску.
    if ($current < 31) {
        // Снятая с доски карточка НЕ удаляется: строка остаётся с отметкой и
        // помнит свою колонку. Без этого `Boards::sync()` заводил карточку
        // заново — во «Входящих», — и разобранная доска сваливалась обратно.
        Db::ensureColumn('board_cards', 'dismissed_at', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '31')");
        $current = 31;
    }

    // v32 — модуль 033: письмо, которое пишут, живёт на доске карточкой в
    // «В работе», а нераспознанный контрагент заводится в МойСклад по ИНН.
    if ($current < 32) {
        // Черновик знает, кому пишут и о какой компании речь: без этого первое
        // письмо компании вообще не сохранялось — у него нет ни письма-ответа,
        // ни цепочки, и API отказывал «Не указано письмо».
        Db::ensureColumn('mail_drafts', 'counterparty_id', 'INTEGER REFERENCES counterparties(id)');
        Db::ensureColumn('mail_drafts', 'to_email', 'TEXT');
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_mail_drafts_cp ON mail_drafts(counterparty_id, manager_id)");

        // Карточка черновика на доске
        Db::ensureColumn('board_cards', 'draft_id', 'INTEGER REFERENCES mail_drafts(id) ON DELETE SET NULL');
        Db::pdo()->exec("CREATE INDEX IF NOT EXISTS idx_bcards_draft ON board_cards(draft_id)");

        // «В работе» — такая же названная колонка, как «Входящие»: черновик
        // ложится в неё, а не в «вторую слева», которую могли переставить
        Db::q("UPDATE board_columns SET kind='work' WHERE kind IS NULL AND title='В работе'");
        foreach (Db::all("SELECT id FROM boards") as $b) {
            if (Db::val("SELECT COUNT(*) FROM board_columns WHERE board_id=? AND kind='work'", [$b['id']])) continue;
            $next = Db::one("SELECT id FROM board_columns WHERE board_id=? AND (kind IS NULL OR kind<>'inbox')
                             ORDER BY position, id LIMIT 1", [$b['id']]);
            if ($next) Db::update('board_columns', ['kind' => 'work'], 'id=?', [$next['id']]);
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '32')");
        $current = 32;
    }

    // v33 — модуль 034: доставка считается строкой ПОДБОРА, а не полем в
    // дальнем редакторе КП.
    if ($current < 33) {
        // Доставка живёт на запросе и едет в каждое его КП. Включена по
        // умолчанию: её почти всегда считают, а убрать строку крестиком — одно
        // движение, вспомнить про забытую доставку после отправки КП — нет.
        Db::ensureColumn('requests', 'delivery_on', 'INTEGER', '1');
        Db::ensureColumn('requests', 'delivery_name', 'TEXT');
        Db::ensureColumn('requests', 'delivery_price', 'REAL', '0');

        // Как ссылка на товар была найдена: вебхук сайта, шаблон адреса или
        // поиск по артикулу. Поисковую ссылку модуль сайта должен перебить —
        // до этого она держалась в кэше 30 дней и уезжала в КП (модуль 034).
        Db::ensureColumn('products_cache', 'site_url_source', 'TEXT');

        // «Доставка считается отдельной строкой» → «считается отдельно», и срок
        // исполнения словами вместо жёстких «календарных дней». Правится ТОЛЬКО
        // текст, который никто не трогал руками: заготовка для новых КП и
        // черновики, слово в слово совпадающие с прежней заводской фразой.
        // Отправленный документ не переписывается ни при каких условиях — он
        // обязан выглядеть так, как его подписали.
        $oldTerms = "Стоимость включает расходы на упаковку, маркировку, хранение, погрузку, "
                  . "подготовку и передачу документов. Доставка в стоимость не включена и считается отдельной строкой.\n"
                  . "Сроки выполнения условий договора {execution_days} календарных дней с момента получения предоплаты.\n"
                  . "Предлагаемая цена продукции является твёрдой и не подлежит изменению в течение "
                  . "{validity_days} дней с даты настоящего предложения.";
        Db::q("UPDATE settings SET value=? WHERE key='default_terms_text' AND value=?",
              [KpTerms::FACTORY_TEXT, $oldTerms]);
        Db::q("UPDATE proposals SET terms_text=? WHERE terms_text=? AND status NOT IN ('sent','order_created')",
              [KpTerms::FACTORY_TEXT, $oldTerms]);

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '33')");
        $current = 33;
    }

    // v34 — модуль 036: вилка цен по модификациям, аналог словами клиента и
    // общие условия КП, которые запоминаются за менеджером.
    if ($current < 34) {
        // Верх вилки: у общего товара своей цены нет, она стоит на модификациях
        // и они стоят по-разному. Ноль или цена строки — вилки нет.
        Db::ensureColumn('proposal_items', 'price_max', 'REAL', '0');

        // Чем именно клиент называл то, вместо чего стоит наша позиция. На
        // строке запроса поле уже было (модуль 013) — теперь оно едет в КП и
        // печатается над названием нашего товара.
        Db::ensureColumn('proposal_items', 'alt_of', 'TEXT');

        // Тип цены, скидка и условия ожидания, выбранные над таблицей подбора.
        // Живут за МЕНЕДЖЕРОМ: следующее КП открывается тем же, чем закрылось
        // предыдущее, а двое за одной доской не переписывают привычки друг другу.
        Db::ensureColumn('managers', 'kp_terms_json', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '34')");
        $current = 34;
    }

    // v35 — модуль 037: письмо пересылается из сервиса, и адрес пересылки
    // запоминается, чтобы во второй раз его выбирали, а не набирали.
    if ($current < 35) {
        Db::q("
        CREATE TABLE IF NOT EXISTS forward_addresses (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            email        TEXT NOT NULL UNIQUE,
            name         TEXT,
            uses         INTEGER DEFAULT 0,
            last_used_at TEXT,
            created_at   TEXT
        )");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '35')");
        $current = 35;
    }

    // v36 — модуль 038: обращение в поддержку с файлами, мастер настройки и
    // проверочный запрос, по которому оценивают качество КП и письма.
    if ($current < 36) {
        // Жалоба менеджера. В GitHub она уходит только после ревью админа,
        // поэтому у строки есть и своё состояние, и номер заведённого issue.
        Db::pdo()->exec("
        CREATE TABLE IF NOT EXISTS support_tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            manager_id INTEGER REFERENCES managers(id),
            kind TEXT NOT NULL DEFAULT 'bug',
            title TEXT NOT NULL,
            body TEXT,
            page TEXT,
            rating TEXT,
            model TEXT,
            status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','approved','declined')),
            reviewed_by INTEGER REFERENCES managers(id),
            reviewed_at TEXT,
            review_note TEXT,
            issue_number INTEGER,
            issue_url TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_support_status ON support_tickets(status, id);

        CREATE TABLE IF NOT EXISTS support_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
            filename TEXT NOT NULL,
            path TEXT NOT NULL,
            mime TEXT,
            size INTEGER,
            remote_url TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_support_files ON support_files(ticket_id);
        ");

        // Проверочный запрос мастера. Отметка нужна, чтобы «Ромашка» из примера
        // не выглядела клиентом, которому забыли ответить.
        Db::ensureColumn('requests', 'is_trial', 'INTEGER', '0');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '36')");
        $current = 36;
    }

    // v37 — модули 039–041: подбор по любой переписке и подпись менеджера,
    // фотографии в таблице подбора, корзина писем и обучение промптов.
    if ($current < 37) {
        // Подпись в письмах — своя у каждого. Пусто — общая подпись компании
        // из настроек, а её нет — имя и телефон из карточки менеджера.
        Db::ensureColumn('managers', 'email_signature', 'TEXT');

        // Выбор фотографий переехал в таблицу подбора (модуль 040): картинки
        // выбирают сразу после того, как определились с товаром, и КП уносит
        // этот выбор с собой. NULL — «выбор не делали», в КП идут все фото.
        Db::ensureColumn('request_items', 'selected_images', 'TEXT');

        // Сколько раз модель не смогла разобрать письмо: после трёх неудач
        // запрос заводится правилами, а не теряется молча (модуль 040)
        Db::ensureColumn('mail_messages', 'triage_attempts', 'INTEGER', '0');

        /**
         * Корзина писем (модуль 040).
         *
         * Удаление было безвозвратным: строка письма исчезала, файлы стирались
         * с диска. Удалить можно ЛЮБОЕ письмо — и вернуть его тоже, пока
         * корзину не очистили. Письмо лежит здесь целиком, вместе со списком
         * своих файлов: восстановление — это та же строка обратно в архив.
         */
        Db::q("
        CREATE TABLE IF NOT EXISTS mail_trash (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_key   TEXT,
            subject      TEXT,
            from_email   TEXT,
            to_emails    TEXT,
            direction    TEXT,
            date_at      TEXT,
            deleted_at   TEXT NOT NULL,
            deleted_by   INTEGER,
            server_state TEXT,
            payload_json TEXT NOT NULL,
            files_json   TEXT
        )");
        Db::q("CREATE INDEX IF NOT EXISTS idx_mail_trash_deleted ON mail_trash(deleted_at)");

        // Чем ответила модель на это письмо: отправленное письмо встаёт с этим
        // в пару в «Исправлениях» и кормит промпты (модуль 041)
        Db::ensureColumn('mail_messages', 'model_draft_text', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '37')");
        $current = 37;
    }

    // v38 — issue #60: доска не грузит письма закрытых карточек, лимит карточек
    // на колонку, условия подбора запоминаются и за контрагентом.
    if ($current < 38) {
        // «Закрыто» — такая же названная колонка, как «Входящие»/«В работе»:
        // по ней узнают колонку, чьи карточки не стоит грузить целиком.
        Db::ensureColumn('board_columns', 'kind', 'TEXT');
        Db::q("UPDATE board_columns SET kind='closed' WHERE kind IS NULL AND title='Закрыто'");

        // Сколько карточек показывать в колонке — не грузить лишнее, если
        // менеджеру нужны только последние N (issue #60)
        Db::ensureColumn('board_columns', 'card_limit', 'INTEGER');

        // «Цены и условия — на все позиции» запоминаются и за контрагентом —
        // это приоритет перед просто последними условиями менеджера (issue #60)
        Db::ensureColumn('counterparties', 'kp_terms_json', 'TEXT');

        // Доставка по умолчанию включается в цену товара, а не печатается
        // отдельной строкой — заводской текст условий переписывается тем же
        // способом, что и в v33: только там, где его никто не трогал руками.
        // Отправленный документ не переписывается ни при каких условиях.
        $oldTerms37 = "Стоимость включает расходы на упаковку, маркировку, хранение, погрузку, "
                    . "подготовку и передачу документов. Доставка в стоимость не включена и считается отдельно.\n"
                    . "Сроки выполнения условий договора {execution_term} с момента получения предоплаты.\n"
                    . "Предлагаемая цена продукции является твёрдой и не подлежит изменению в течение "
                    . "{validity_days} дней с даты настоящего предложения.";
        Db::q("UPDATE settings SET value=? WHERE key='default_terms_text' AND value=?",
              [KpTerms::FACTORY_TEXT, $oldTerms37]);
        Db::q("UPDATE proposals SET terms_text=? WHERE terms_text=? AND status NOT IN ('sent','order_created')",
              [KpTerms::FACTORY_TEXT, $oldTerms37]);

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '38')");
        $current = 38;
    }

    // v39 — module 043: a webhook event that failed is kept and repeated.
    // MoySklad delivers each event once; one lost to a rate limit used to leave
    // the order unsynced until someone opened the card by hand.
    if ($current < 39) {
        Db::ensureColumn('webhook_log', 'status', 'TEXT', "'ok'");
        Db::ensureColumn('webhook_log', 'attempts', 'INTEGER', '1');
        // The one event, apart from the whole delivered body: that is what a
        // repeat needs — a body can carry several events at once
        Db::ensureColumn('webhook_log', 'event_json', 'TEXT');
        // Rows written before this migration have nothing to repeat — count them done
        Db::q("UPDATE webhook_log SET status='ok' WHERE status IS NULL");
        Db::q("CREATE INDEX IF NOT EXISTS idx_webhook_retry ON webhook_log(status, id)");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '39')");
        $current = 39;
    }

    // v40 — модуль 044 (issue #60): свой звук уведомления, вход под контролем,
    // отложенная отправка письма.
    if ($current < 40) {
        // Звук нового письма и его громкость — у каждого свои. NULL — «как в
        // настройках сервиса»: общий звук остаётся значением по умолчанию
        Db::ensureColumn('managers', 'notify_sound', 'TEXT');
        Db::ensureColumn('managers', 'notify_volume', 'INTEGER');

        /**
         * «Постоянно слетает авторизация» (issue #60).
         *
         * Кука жила ровно `SESSION_LIFETIME` от входа и не продлевалась, а
         * сборщик мусора PHP убирал файл сессии через свои 24 минуты. Теперь
         * кука продлевается на каждом заходе, а администратор может обнулить
         * чужой вход: `session_epoch` растёт, и сессии со старым номером
         * перестают открываться.
         */
        Db::ensureColumn('managers', 'session_epoch', 'INTEGER', '0');

        // Входы: кто, когда и откуда. По ним же видно вход с нового адреса —
        // о нём администратор получает уведомление
        Db::q("CREATE TABLE IF NOT EXISTS manager_logins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            manager_id INTEGER NOT NULL REFERENCES managers(id),
            ip TEXT,
            user_agent TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        Db::q("CREATE INDEX IF NOT EXISTS idx_manager_logins ON manager_logins(manager_id, id)");

        /**
         * Отложенная отправка (issue #60).
         *
         * Письмо, которому назначили время, лежит здесь целиком — тем самым
         * телом, которое собрал менеджер. Отправляет его тот же код, что и
         * кнопка «Отправить»: отложенное письмо не должно отличаться от
         * обычного ничем, кроме минуты отправки.
         */
        Db::q("CREATE TABLE IF NOT EXISTS mail_scheduled (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            manager_id INTEGER NOT NULL REFERENCES managers(id),
            send_at TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            subject TEXT,
            to_addr TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            error TEXT,
            sent_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        Db::q("CREATE INDEX IF NOT EXISTS idx_mail_scheduled_due ON mail_scheduled(status, send_at)");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '40')");
        $current = 40;
    }

    // v41 — модуль 045 (issue #60): КП, поправленное руками в предпросмотре.
    // Пока поле не пусто, PDF и Word собираются из него, а не из шаблона.
    if ($current < 41) {
        Db::ensureColumn('proposals', 'html_override', 'TEXT');
        Db::ensureColumn('proposals', 'html_override_at', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '41')");
        $current = 41;
    }

    // v42 — модуль 046 (issue #67): «Показать в КП отсутствующую номенклатуру»
    // у каждого КП; NULL — как в настройке KP_SHOW_OUT_OF_SCOPE
    if ($current < 42) {
        Db::ensureColumn('proposals', 'show_out_of_scope', 'INTEGER');
        // Описание товара с сайта — запасной источник к МойСклад (issue #67)
        Db::ensureColumn('products_cache', 'site_description', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '42')");
        $current = 42;
    }

    // v43 — модуль 047: оплаты из Т-Банка, колонка «Сборка», письмо об отправке
    if ($current < 43) {
        Db::q("
        CREATE TABLE IF NOT EXISTS bank_payments (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            operation_id        TEXT NOT NULL UNIQUE,
            account             TEXT,
            operation_date      TEXT,
            amount              REAL NOT NULL DEFAULT 0,
            payer_inn           TEXT,
            payer_name          TEXT,
            purpose             TEXT,
            doc_number          TEXT,
            invoice_id          INTEGER REFERENCES invoices(id),
            order_id            INTEGER REFERENCES orders(id),
            counterparty_id     INTEGER REFERENCES counterparties(id),
            moysklad_payment_id TEXT,
            status              TEXT NOT NULL DEFAULT 'unmatched',
            error               TEXT,
            created_at          TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        Db::q("CREATE INDEX IF NOT EXISTS idx_bank_payments_invoice ON bank_payments(invoice_id)");

        // Оплачен, отправлен, чем и под каким номером
        Db::ensureColumn('orders', 'paid_at', 'TEXT');
        Db::ensureColumn('invoices', 'paid_at', 'TEXT');
        Db::ensureColumn('orders', 'ship_service', 'TEXT');
        Db::ensureColumn('orders', 'ship_track', 'TEXT');
        Db::ensureColumn('orders', 'shipped_notified_at', 'TEXT');
        // Черновик «заказ отправлен» подсвечивает карточку на доске
        Db::ensureColumn('mail_drafts', 'kind', 'TEXT');

        // «Сборка» — после «Ждём оплату» на каждой доске
        require_once __DIR__ . '/boards.php';
        foreach (Db::all("SELECT id FROM boards") as $b) Boards::assemblyColumn((int)$b['id']);

        // Уже отвеченные переписки больше не горят жирным: входящие письма,
        // после которых в той же переписке есть наше, — прочитаны
        Db::q("UPDATE mail_messages SET is_read=1
               WHERE direction='in' AND is_read=0 AND thread_key IS NOT NULL
                 AND EXISTS (SELECT 1 FROM mail_messages o WHERE o.thread_key = mail_messages.thread_key
                             AND o.direction='out' AND o.date_at >= mail_messages.date_at)");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '43')");
        $current = 43;
    }

    // v44 — module 048: old terms texts get the delivery placeholders
    // (edited texts too, matched by pattern); a sent КП is never rewritten.
    // Each product card always starts a page — the switch is gone.
    if ($current < 44) {
        require_once __DIR__ . '/kp_terms.php';
        $stored = Db::val("SELECT value FROM settings WHERE key='default_terms_text'");
        if ($stored !== null) {
            Db::q("UPDATE settings SET value=? WHERE key='default_terms_text'",
                  [KpTerms::upgradeLegacy((string)$stored)]);
        }
        foreach (Db::all("SELECT id, terms_text FROM proposals
                          WHERE terms_text IS NOT NULL AND status NOT IN ('sent','order_created')") as $p) {
            $up = KpTerms::upgradeLegacy((string)$p['terms_text']);
            if ($up !== $p['terms_text']) Db::update('proposals', ['terms_text' => $up], 'id=?', [(int)$p['id']]);
        }
        Db::q("DELETE FROM settings WHERE key='cfg.KP_PAGE_BREAK'");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '44')");
        $current = 44;
    }

    // v45 — module 049: delivery mode per КП (NULL = the KP_DELIVERY_MODE setting)
    if ($current < 45) {
        Db::ensureColumn('requests', 'delivery_mode', 'TEXT');
        Db::ensureColumn('proposals', 'delivery_mode', 'TEXT');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '45')");
        $current = 45;
    }

    // v46 — module 051: the signature under a КП is the manager's choice, none by default;
    // a stored LLM timeout of 30 s (the old default saved by the form) becomes 90 s
    if ($current < 46) {
        Db::ensureColumn('managers', 'kp_signature_mode', 'TEXT');
        Db::q("UPDATE managers SET kp_signature_mode='own'
               WHERE kp_signature_mode IS NULL
                 AND (TRIM(COALESCE(signatory_name,''))<>'' OR TRIM(COALESCE(signature_path,''))<>'')");
        Db::q("UPDATE managers SET kp_signature_mode='none' WHERE kp_signature_mode IS NULL");
        Db::q("UPDATE settings SET value='90' WHERE key='cfg.LLM_TIMEOUT_SEC' AND TRIM(value)='30'");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '46')");
        $current = 46;
    }

    // v47 — module 055: full-text search index; every row queued, built on first search / cron
    if ($current < 47) {
        require_once __DIR__ . '/search.php';
        SearchIndex::install();

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '47')");
        $current = 47;
    }

    // v48 — module 056: stage columns by kind, what documents a letter carries
    if ($current < 48) {
        Db::q("UPDATE board_columns SET kind='kp_sent' WHERE kind IS NULL
               AND (LOWER(title) LIKE 'кп отправлен%' OR LOWER(title) LIKE 'кп выслан%'
                    OR title LIKE 'КП отправлен%' OR title LIKE 'КП выслан%')");
        Db::q("UPDATE board_columns SET kind='payment' WHERE kind IS NULL
               AND (title LIKE 'Ждём оплат%' OR title LIKE 'Ждем оплат%'
                    OR LOWER(title) LIKE 'ждём оплат%' OR LOWER(title) LIKE 'ждем оплат%')");
        Db::q("
        CREATE TABLE IF NOT EXISTS outbox_docs (
            name       TEXT PRIMARY KEY,
            manager_id INTEGER NOT NULL,
            kind       TEXT NOT NULL,
            doc_id     INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '48')");
        $current = 48;
    }

    // v49 — module 057: per-manager send delay (NULL = default 20 s, 0 = off)
    if ($current < 49) {
        Db::ensureColumn('managers', 'send_delay_sec', 'INTEGER');

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '49')");
        $current = 49;
    }

    // v50 — module 058: add-on modules fit one product, not every vest
    if ($current < 50) {
        Db::q("INSERT OR IGNORE INTO settings (key, value) VALUES ('addon_hosts', ?)",
              ['Бронежилет Атлант базовый Бр2 (без доп.модулей, без бронеплит)']);

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '50')");
        $current = 50;
    }
}

/** First run after the upgrade: config.php IMAP/SMTP becomes mailbox #1. */
function seedMailboxFromConfig(): void {
    if (Db::val("SELECT COUNT(*) FROM mailboxes")) return;
    $file = Settings::fileConfig();
    $imapHost = (string)($file['IMAP_HOST'] ?? '');
    $smtpHost = (string)($file['SMTP_HOST'] ?? '');
    if ($imapHost === '' && $smtpHost === '') return;

    $email = (string)($file['IMAP_USER'] ?? ($file['SMTP_FROM_EMAIL'] ?? ($file['SMTP_USER'] ?? '')));
    Db::insert('mailboxes', [
        'name'             => $email !== '' ? $email : 'Основной ящик',
        'email'            => $email,
        'is_active'        => 1,
        'is_default'       => 1,
        'create_requests'  => 1,
        'sync_sent'        => 1,
        'imap_host'        => $imapHost,
        'imap_port'        => (int)($file['IMAP_PORT'] ?? 993),
        'imap_encryption'  => (string)($file['IMAP_ENCRYPTION'] ?? 'ssl'),
        'imap_user'        => (string)($file['IMAP_USER'] ?? ''),
        'imap_password'    => Crypt::encrypt((string)($file['IMAP_PASSWORD'] ?? '')),
        'imap_folder_in'   => 'INBOX',
        'imap_folder_sent' => 'INBOX.Sent',
        'smtp_host'        => $smtpHost,
        'smtp_port'        => (int)($file['SMTP_PORT'] ?? 465),
        'smtp_encryption'  => (string)($file['SMTP_ENCRYPTION'] ?? 'ssl'),
        'smtp_user'        => (string)($file['SMTP_USER'] ?? ''),
        'smtp_password'    => Crypt::encrypt((string)($file['SMTP_PASSWORD'] ?? '')),
        'from_name'        => (string)($file['SMTP_FROM_NAME'] ?? 'Atlant Armour'),
        'from_email'       => (string)($file['SMTP_FROM_EMAIL'] ?? ''),
    ]);
}

// Домены, по которым компании не объединяются (C-012).
// Список один на весь сервис и живёт в MailDomains — раньше их было два,
// и почта расходилась с карточками: там склеено, тут разделено.
function publicEmailDomains(): array {
    return array_values(array_unique(array_merge(
        MailDomains::builtin(), MailDomains::configured(), MailDomains::learned()
    )));
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
            'address' => 'Проезд Березовой рощи, 12',
            'phone' => '+7 977 152-73-67',
            'email' => 'atlantarmourmed@gmail.com',
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

    // Seed managers from config.php
    $file = Settings::fileConfig();
    if (!empty($file['MANAGERS'])) {
        foreach ($file['MANAGERS'] as $login => $info) {
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
    $managers = Settings::fileConfig()['MANAGERS'] ?? ($cfg['MANAGERS'] ?? []);
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
        $row   = Db::one("SELECT id, ui_managed FROM managers WHERE login=?", [$login]);

        // Edited in the panel — the panel wins, config.php is only the default
        if ($row && !empty($row['ui_managed'])) continue;

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

/**
 * Сколько живёт вход, в секундах (issue #60).
 *
 * Настройка `SESSION_LIFETIME` читается и до того, как `$GLOBALS['cfg']`
 * собран: сессия стартует раньше многих вещей. Потолок — год, как и просили;
 * меньше пяти минут не бывает, иначе опечатка в поле выкидывает всех.
 */
function sessionLifetime(): int {
    $raw = $GLOBALS['cfg']['SESSION_LIFETIME'] ?? null;
    if ($raw === null && class_exists('Settings')) $raw = Settings::get('SESSION_LIFETIME', 86400);
    return max(300, min(31536000, (int)($raw ?? 86400)));
}

// Start the PHP session with consistent cookie flags. Safe to call repeatedly.
function startSession(): void {
    if (PHP_SAPI === 'cli') return;                       // cron/CLI has no session
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (headers_sent()) return;
    $https = isHttps();
    $lifetime = sessionLifetime();
    // Never adopt an id we did not issue: after the session storage is wiped a
    // stale cookie would otherwise keep an unwritable session alive.
    ini_set('session.use_strict_mode', '1');
    // «Постоянно слетает авторизация» (issue #60): кука жила месяц, а файл
    // сессии убирал сборщик мусора PHP через свои 24 минуты. Живут они теперь
    // одинаково долго.
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_name(SESSION_COOKIE);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,   // must be false on plain HTTP, or the cookie is dropped
        'samesite' => 'Lax',    // Strict drops the cookie on external return links
    ]);
    session_start();
    renewSessionCookie($lifetime, $https);
}

/**
 * Продлить куку входа — не чаще раза в сутки.
 *
 * Без этого «месяц» означал месяц ОТ ПЕРВОГО ВХОДА: менеджер, работающий
 * каждый день, всё равно в один день оказывался на форме входа. Продление
 * стоит денег ровно в один заголовок, и его незачем слать при каждом запросе.
 */
function renewSessionCookie(int $lifetime, bool $https): void {
    if (empty($_SESSION['manager_id']) || headers_sent()) return;
    $last = (int)($_SESSION['cookie_renewed'] ?? 0);
    if ($last > time() - 86400) return;
    $_SESSION['cookie_renewed'] = time();
    setcookie(session_name(), session_id(), [
        'expires'  => time() + $lifetime,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
}

// Request came over TLS (directly or through a proxy)
function isHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

// Drop session cookies from older builds so the browser keeps exactly one
function clearLegacySessionCookies(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    foreach (['PHPSESSID'] as $name) {
        if (!isset($_COOKIE[$name])) continue;
        foreach (['/', '/api/'] as $path) {
            setcookie($name, '', ['expires' => time() - 3600, 'path' => $path]);
        }
        unset($_COOKIE[$name]);
    }
}

// Auth helper: get current manager from session
function currentManager(): ?array {
    startSession();
    $id = $_SESSION['manager_id'] ?? null;
    if (!$id) return null;
    $m = Db::one(
        "SELECT id, login, name, email, phone, is_admin, moysklad_uid, session_epoch FROM managers
         WHERE id=? AND COALESCE(is_active, 1) = 1",
        [$id]
    );
    if (!$m) return null;

    // Администратор обнулил вход (issue #60): номер поколения в карточке
    // ушёл вперёд, и сессии, выданные до этого, больше не открываются
    if ((int)($m['session_epoch'] ?? 0) !== (int)($_SESSION['epoch'] ?? 0)) {
        $_SESSION = [];
        return null;
    }
    unset($m['session_epoch']);
    return $m;
}

/**
 * Repair text of unknown origin: mail from the 2000s, a filename inside a MIME
 * header, a PDF with no encoding declared. Invalid UTF-8 breaks json_encode(),
 * the /u regex flag and mb_* alike, so nothing gets stored before it goes through here.
 */
function utf8Text(string $s): string {
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;

    // Deciding between «UTF-8 with a few broken bytes» and «windows-1251» by the
    // presence of one valid pair was wrong: cp1251 «№» right after a capital
    // Cyrillic letter forms exactly such a pair, so whole Russian subjects were
    // run through //IGNORE and came out as a row of «?». Keep what survives more.
    $stripped = (string)@iconv('UTF-8', 'UTF-8//IGNORE', $s);
    if ($stripped !== '' && strlen($stripped) >= (int)(strlen($s) * 0.9)) return $stripped;

    $cp1251 = (string)@mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
    return mb_check_encoding($cp1251, 'UTF-8') && $cp1251 !== '' ? $cp1251 : $stripped;
}

// JSON response helpers
// API answers must never come from the browser cache: a cached "me" response
// would show a logged-out screen right after a successful login.
function jsonHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

/**
 * One letter with a broken charset used to break a whole page: json_encode()
 * returns false on invalid UTF-8, the answer was empty and the browser sat on
 * «Загрузка...» forever. Bad bytes are replaced, and a failure still answers JSON.
 */
function jsonBody(mixed $data): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $out = json_encode($data, $flags);
    if ($out === false) {
        if (class_exists('Logger')) Logger::error('api', 'Ответ не сериализуется в JSON: ' . json_last_error_msg());
        $out = json_encode(['error' => 'Ответ сервера не удалось сформировать', 'code' => 500], $flags) ?: '{}';
    }
    return $out;
}

function jsonOk(array $data = []): never {
    jsonHeaders();
    echo jsonBody(array_merge(['ok' => true], $data));
    exit;
}

/**
 * $extra — machine-readable detail the browser acts on rather than just shows.
 * «КП без цены» sends the positions back with the refusal, so the editor can
 * name them in the question it asks instead of repeating the message (module 018).
 */
function jsonError(string $msg, int $code = 400, array $extra = []): never {
    // A 401 is an open tab whose session ran out — ordinary traffic, not an
    // incident. Logging it buried the journal under hundreds of «Unauthorized».
    if (class_exists('Logger') && $code !== 401) {
        Logger::log($code >= 500 ? 'error' : 'warning', 'api', $msg, ['code' => $code]);
    }
    http_response_code($code);
    jsonHeaders();
    // `error` and `code` are the contract and win over anything $extra carries
    echo jsonBody(['error' => $msg, 'code' => $code] + $extra);
    exit;
}

function jsonData(mixed $data): never {
    jsonHeaders();
    echo jsonBody($data);
    exit;
}

function requireAuth(): array {
    $m = currentManager();
    if (!$m) jsonError('Unauthorized', 401);
    // Сессия дальше только читается: снять блокировку, иначе долгий запрос
    // («Сформировать КП» — до минуты) держит в очереди все остальные этой
    // вкладки, и фото подбора висели до конца сборки (модуль 047)
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    return $m;
}

// Admin-only endpoints: settings, mailboxes, managers, prompts, logs
function requireAdmin(): array {
    $m = requireAuth();
    if (empty($m['is_admin'])) jsonError('Доступ только для администратора', 403);
    return $m;
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?? []) : [];
}
