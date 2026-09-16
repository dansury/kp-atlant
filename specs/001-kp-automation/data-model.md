# Data Model — Atlant Armour КП Automation

**Created**: 2026-08-25  
**Storage**: SQLite 3 (WAL mode, foreign keys ON)  
**Ref**: spec.md Key Entities, plan.md D-002

---

## Schema

```sql
-- Managers (US3, FR-010)
CREATE TABLE managers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    login TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    name TEXT NOT NULL,                    -- display name
    email TEXT,                            -- for 24h fallback notifications
    moysklad_uid TEXT,                     -- MoySklad employee UUID (US5)
    is_admin INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Counterparties (US3, FR-011)
CREATE TABLE counterparties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    inn TEXT,                              -- ИНН, unique when present
    contact_person TEXT,
    contact_email TEXT,
    contact_phone TEXT,
    moysklad_id TEXT,                      -- MoySklad counterparty UUID
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_counterparties_inn ON counterparties(inn);
CREATE INDEX idx_counterparties_name ON counterparties(name);

-- Requests (US1, US2, FR-001, FR-002)
CREATE TABLE requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL CHECK(source IN ('email', 'manual')),
    raw_text TEXT NOT NULL,                -- original email/pasted text
    parsed_json TEXT,                      -- LLM extraction result (JSON)
    counterparty_id INTEGER REFERENCES counterparties(id),
    manager_id INTEGER REFERENCES managers(id),  -- assigned manager (null = pool)
    status TEXT NOT NULL DEFAULT 'new'
        CHECK(status IN ('new','processing','draft_ready','sent','ordered','closed')),
    email_from TEXT,                       -- sender email (if source=email)
    email_subject TEXT,
    email_message_id TEXT,                 -- IMAP Message-ID for threading
    notified_at TEXT,                      -- when push notification sent
    email_notified_at TEXT,                -- when 24h fallback email sent
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_requests_status ON requests(status);
CREATE INDEX idx_requests_manager ON requests(manager_id);

-- Proposals / КП (US1, FR-005, FR-006)
CREATE TABLE proposals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL REFERENCES requests(id),
    counterparty_id INTEGER REFERENCES counterparties(id),
    manager_id INTEGER REFERENCES managers(id),
    number TEXT,                           -- КП number (auto-generated)
    status TEXT NOT NULL DEFAULT 'draft'
        CHECK(status IN ('draft','confirmed','sent','order_created')),
    intro_text TEXT,                       -- text before table
    pre_table_text TEXT,                   -- optional block (delivery terms)
    post_table_text TEXT,                  -- optional block (bundled items)
    conditions_text TEXT,                  -- delivery conditions
    execution_days INTEGER DEFAULT 30,    -- 10 or 30
    validity_days INTEGER DEFAULT 14,     -- price validity
    vat_rate INTEGER DEFAULT 5,           -- default VAT % (5, 0, 20)
    show_vat_total INTEGER DEFAULT 0,     -- legacy, module 029: НДС печатается всегда
    vat_mode TEXT,                        -- included | added; пусто — настройка KP_VAT_MODE
    cover_letter TEXT,                     -- LLM-generated cover letter draft
    cover_letter_final TEXT,              -- manager-edited version
    pdf_path TEXT,                         -- path to generated PDF
    moysklad_order_id TEXT,               -- MoySklad order UUID (US5)
    sent_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_proposals_request ON proposals(request_id);

-- Proposal Items (FR-005, FR-019)
CREATE TABLE proposal_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proposal_id INTEGER NOT NULL REFERENCES proposals(id) ON DELETE CASCADE,
    position INTEGER NOT NULL,            -- row number in table
    product_name TEXT NOT NULL,           -- display name
    moysklad_product_id TEXT,            -- MoySklad product UUID
    unit TEXT NOT NULL DEFAULT 'шт.',
    quantity INTEGER NOT NULL DEFAULT 1,
    price REAL NOT NULL,                  -- price per unit (with VAT)
    vat_rate INTEGER,                     -- per-item VAT override (null = use proposal default)
    stock_available INTEGER,              -- from MoySklad at time of KP
    stock_reserved INTEGER,
    match_confidence REAL,                -- fuzzy match score 0..1
    match_variants TEXT,                  -- JSON array of alternative matches
    is_confirmed INTEGER NOT NULL DEFAULT 0,  -- manager confirmed this match
    notes TEXT                            -- "под заказ" etc.
);
CREATE INDEX idx_items_proposal ON proposal_items(proposal_id);

-- Correspondence (US3, FR-011)
CREATE TABLE correspondence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER REFERENCES requests(id),
    counterparty_id INTEGER REFERENCES counterparties(id),
    direction TEXT NOT NULL CHECK(direction IN ('in', 'out', 'note')),
    subject TEXT,
    body TEXT NOT NULL,
    email_from TEXT,
    email_to TEXT,
    has_attachment INTEGER DEFAULT 0,
    attachment_path TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_corr_counterparty ON correspondence(counterparty_id);
CREATE INDEX idx_corr_request ON correspondence(request_id);

-- Corrections / self-learning pairs (US4, FR-012)
CREATE TABLE corrections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER REFERENCES requests(id),
    field TEXT NOT NULL CHECK(field IN ('cover_letter', 'pre_table', 'post_table', 'conditions')),
    auto_text TEXT NOT NULL,              -- system-generated text
    manager_text TEXT NOT NULL,           -- manager's version
    context_json TEXT,                    -- {counterparty_type, product_categories, ...}
    manager_id INTEGER REFERENCES managers(id),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Notifications (FR-008, FR-009)
CREATE TABLE notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    manager_id INTEGER REFERENCES managers(id),
    type TEXT NOT NULL CHECK(type IN ('new_request', 'followup_ready', 'system')),
    title TEXT NOT NULL,
    body TEXT,
    ref_type TEXT,                        -- 'request', 'proposal', etc.
    ref_id INTEGER,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_notif_manager ON notifications(manager_id, is_read);

-- Legal Entity settings (FR-015, Constitution VIII)
CREATE TABLE legal_entities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    is_active INTEGER NOT NULL DEFAULT 1,
    entity_type TEXT NOT NULL DEFAULT 'ИП',  -- ИП / ООО
    full_name TEXT NOT NULL,               -- "ИП Сурков Кирилл Александрович"
    short_name TEXT,                       -- "ИП Сурков К. А."
    inn TEXT NOT NULL,
    ogrnip TEXT,                           -- ОГРНИП (for ИП)
    ogrn TEXT,                             -- ОГРН (for ООО)
    city TEXT NOT NULL DEFAULT 'г. Москва',
    address TEXT,
    signatory_name TEXT,                   -- "Сурков Кирилл Александрович"
    signature_path TEXT,                   -- path to signature PNG
    logo_path TEXT,                        -- path to logo image
    stamp_path TEXT,                       -- optional stamp image
    bank_details TEXT,                     -- free-form bank info
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Email Rules (US6, FR-014, FR-018)
CREATE TABLE email_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content TEXT NOT NULL,                 -- EMAILRULES.md content
    updated_by INTEGER REFERENCES managers(id),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- MoySklad product cache (D-005)
CREATE TABLE products_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    moysklad_id TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    name_normalized TEXT,                  -- lowercase, cleaned for matching
    article TEXT,                          -- артикул
    price REAL,
    stock INTEGER,
    reserved INTEGER,
    unit TEXT DEFAULT 'шт.',
    description TEXT,
    category TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_products_name ON products_cache(name_normalized);
CREATE INDEX idx_products_article ON products_cache(article);

-- Follow-up tracking (US6, FR-014)
CREATE TABLE followups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proposal_id INTEGER NOT NULL REFERENCES proposals(id),
    counterparty_id INTEGER NOT NULL REFERENCES counterparties(id),
    status TEXT NOT NULL DEFAULT 'suggested'
        CHECK(status IN ('suggested', 'draft_ready', 'sent', 'dismissed')),
    draft_text TEXT,                       -- LLM-generated follow-up text
    final_text TEXT,                       -- manager-edited
    sent_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_followups_status ON followups(status);

-- App settings (key-value store for misc config)
CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
-- Defaults: default_conditions_text, default_execution_days, default_validity_days,
-- imap_last_uid, app_version, etc.
```

---

## Entity Relationships

```
managers ──1:N──> requests (assigned manager)
managers ──1:N──> proposals
managers ──1:N──> corrections
managers ──1:N──> notifications

counterparties ──1:N──> requests
counterparties ──1:N──> proposals
counterparties ──1:N──> correspondence
counterparties ──1:N──> followups

requests ──1:N──> proposals (usually 1:1, but revisions possible)
requests ──1:N──> correspondence

proposals ──1:N──> proposal_items
proposals ──1:N──> followups

legal_entities ──(active one)──> proposals (PDF uses active entity)
```

---

## Notes

- All `created_at` / `updated_at` use ISO 8601 (`datetime('now')` in SQLite = UTC).
- `parsed_json` in requests stores LLM extraction: `{items: [{name, qty, raw_text}], org_name, contact, delivery_terms}`.
- `context_json` in corrections stores searchable metadata for few-shot retrieval.
- `products_cache` is refreshed on each KP generation (Constitution II) but persists for search UI.
- No migration files — schema evolution via NeuroPro `PRAGMA table_info` + `ALTER TABLE` pattern (D-002).
