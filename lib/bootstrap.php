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
require_once __DIR__ . '/prompts.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/knowledge.php';
require_once __DIR__ . '/triage.php';
require_once __DIR__ . '/auth.php';

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

// Start the PHP session with consistent cookie flags. Safe to call repeatedly.
function startSession(): void {
    if (PHP_SAPI === 'cli') return;                       // cron/CLI has no session
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (headers_sent()) return;
    $https = isHttps();
    // Never adopt an id we did not issue: after the session storage is wiped a
    // stale cookie would otherwise keep an unwritable session alive.
    ini_set('session.use_strict_mode', '1');
    session_name(SESSION_COOKIE);
    session_set_cookie_params([
        'lifetime' => (int)($GLOBALS['cfg']['SESSION_LIFETIME'] ?? 86400),
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,   // must be false on plain HTTP, or the cookie is dropped
        'samesite' => 'Lax',    // Strict drops the cookie on external return links
    ]);
    session_start();
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
    return Db::one(
        "SELECT id, login, name, email, phone, is_admin, moysklad_uid FROM managers
         WHERE id=? AND COALESCE(is_active, 1) = 1",
        [$id]
    );
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

function jsonError(string $msg, int $code = 400): never {
    if (class_exists('Logger')) {
        Logger::log($code >= 500 ? 'error' : 'warning', 'api', $msg, ['code' => $code]);
    }
    http_response_code($code);
    jsonHeaders();
    echo jsonBody(['error' => $msg, 'code' => $code]);
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
