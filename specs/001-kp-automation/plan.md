# Plan — Atlant Armour КП Automation

**Created**: 2026-08-25  
**Spec**: `specs/001-kp-automation/spec.md`  
**Status**: Final

---

## Technical Context

| Aspect | Decision |
|---|---|
| Language | PHP 8.1+ |
| Database | SQLite 3 (WAL mode, foreign keys ON) |
| Frontend | Vanilla JS + CSS (no frameworks) |
| PDF | mPDF (HTML→PDF) |
| LLM | Dual-provider wrapper adapted from NeuroPro `lib/llm.php` (OpenRouter + Yandex FM) |
| Email in | IMAP polling (`smtp.spaceweb.ru:465` SSL), every 2 min via cron |
| Email out | SMTP (`smtp.spaceweb.ru:465` SSL; fallback 25/2525 STARTTLS) |
| External API | MoySklad JSON API 1.2 |
| Notifications | JS polling `/api/notifications.php` every 30s + email fallback 24h |
| Deploy target | Shared hosting (PHP 8.1+, no Docker, no root) |
| Scale | ≤10 managers, ≤50 KP/day, ≤10 items per KP |

---

## Constitution Check

| Principle | How the plan complies |
|---|---|
| I. Manager confirms | Every send goes through explicit `status=confirmed` transition. No auto-send paths exist. |
| II. MoySklad = source of truth | `MoySklad` wrapper fetches fresh prices/stock on every KP generation. Local cache TTL = session only. |
| III. ToV from tov.md | LLM system prompts include ToV rules. EMAILRULES.md seeded from tov.md on first deploy. |
| IV. Knowledge accumulates | `corrections` table stores every manager edit as (auto→final) pair. Top-5 relevant pairs injected as few-shot. |
| V. Secrets server-side | All keys in `config.php` (gitignored), loaded via `$_ENV` or constants. Never exposed to JS or logs. |
| VI. Branded PDF | mPDF template: logo, legal entity block, item table, conditions, signature image. |
| VII. One stack, simple deploy | PHP + SQLite + vanilla JS. Single `composer install` for mPDF. No build step. |
| VIII. Legal entity configurable | `legal_entities` table + settings UI. PDF template reads from DB, not hardcoded. |

**Violations**: None.

---

## Project Structure

```
atlant-kp/
├── public/                     # Web root (point Apache/nginx here)
│   ├── index.php               # SPA entry point
│   ├── api/                    # API endpoints
│   │   ├── requests.php        # CRUD requests
│   │   ├── proposals.php       # KP generation, preview, send
│   │   ├── products.php        # MoySklad product search
│   │   ├── counterparties.php  # Counterparty CRUD + MoySklad lookup
│   │   ├── notifications.php   # Polling endpoint
│   │   ├── corrections.php     # Self-learning pairs
│   │   ├── settings.php        # Legal entity, email rules, signature
│   │   ├── auth.php            # Login/logout/session
│   │   ├── orders.php          # MoySklad order creation
│   │   ├── followups.php       # Follow-up suggestions
│   │   └── deals.php           # Deal status from MoySklad
│   ├── assets/
│   │   ├── css/
│   │   │   └── app.css
│   │   ├── js/
│   │   │   └── app.js
│   │   └── img/
│   │       └── logo.png        # Atlant Armour logo for PDF
│   └── uploads/
│       └── signatures/         # Uploaded signature images
├── lib/                        # Core libraries (not web-accessible)
│   ├── bootstrap.php           # Config load, DB init, LLM init
│   ├── db.php                  # SQLite wrapper (adapted from NeuroPro)
│   ├── llm.php                 # LLM wrapper (adapted from NeuroPro)
│   ├── moysklad.php            # MoySklad API wrapper
│   ├── email.php               # IMAP reader + SMTP sender
│   ├── pdf.php                 # mPDF KP generator
│   ├── parser.php              # Request text → structured items (LLM)
│   ├── matcher.php             # Fuzzy product matching
│   ├── notifier.php            # Notification logic (polling + email fallback)
│   └── auth.php                # Session/auth helpers
├── templates/
│   └── kp.html                 # mPDF HTML template for KP
├── cron/
│   ├── check_mail.php          # IMAP poll (every 2 min)
│   ├── check_followups.php     # Follow-up scanner (daily)
│   └── check_notifications.php # 24h email fallback (hourly)
├── data/                       # SQLite DB + generated files
│   ├── .gitkeep
│   └── kp.db                   # (created at runtime)
├── vendor/                     # Composer (mPDF)
├── config.example.php          # Template with all env vars
├── config.php                  # Actual config (gitignored)
├── composer.json
├── .htaccess                   # Rewrite rules, deny access to lib/
└── specs/                      # Spec-kit artifacts (this file, etc.)
```

---

## Architectural Decisions

### D-001: Adapted NeuroPro LLM Wrapper

**Decision**: Fork `lib/llm.php` from NeuroPro, strip project-specific parts, keep dual-provider fallback and json-mode.

**Rationale**: NeuroPro's wrapper already handles OpenRouter + Yandex FM with retry, fallback chains, JSON parsing, and `<think>` tag stripping. Rewriting from scratch would duplicate tested logic. The adapter keeps: `LLM::init()`, `LLM::chatText()`, `LLM::chatJson()`, curl-based HTTP, provider priority. Removed: prompt versioning system, `dispatchPair`, session tracking (not needed for KP).

**Alternatives rejected**: Raw cURL per endpoint (no fallback), third-party PHP SDK (doesn't exist for OpenRouter).

### D-002: SQLite with NeuroPro DB Pattern

**Decision**: Use PDO SQLite wrapper adapted from NeuroPro `lib/db.php`. WAL mode, foreign keys ON, auto-migration via `PRAGMA table_info`.

**Rationale**: SQLite fits the scale (≤10 users, ≤50 KP/day). No external DB server needed on shared hosting. NeuroPro's pattern handles schema evolution cleanly — check if column exists, ALTER TABLE if not. No migration files to manage.

**Alternatives rejected**: MySQL (overkill, needs hosting config), flat files (no relations, no queries).

### D-003: mPDF for KP Generation

**Decision**: mPDF library, HTML template in `templates/kp.html`.

**Rationale**: HTML→PDF lets us design the KP layout with HTML/CSS — fast iteration, easy Cyrillic, good table support. The template is a single HTML file with PHP variables for dynamic content. mPDF handles: page breaks for 50+ items, embedded logo as base64, custom fonts, НДС calculations in the table. Installed via Composer, no system dependencies.

**Ref**: C-001 in spec.md.

### D-004: IMAP Polling via Cron

**Decision**: `cron/check_mail.php` runs every 2 minutes via hosting cron. Uses PHP `imap_*` functions.

**Rationale**: Shared hosting can't run persistent processes. IMAP extension is common on PHP hosts. The cron script: connects to `smtp.spaceweb.ru:465` SSL, checks UNSEEN messages, processes each (parse → create Request → notify), marks as SEEN. If IMAP extension unavailable, fallback to manual paste (US2) only.

**Ref**: C-006 in spec.md.

### D-005: MoySklad Wrapper with Caching + Retry

**Decision**: `lib/moysklad.php` — thin wrapper over JSON API 1.2. Caches product catalog in SQLite (`products_cache` table, TTL = per-KP-generation refresh). Retry on 429/5xx with exponential backoff.

**Rationale**: MoySklad is the god node (6 cross-community edges). Outage must not crash the system. Cache allows offline product search for draft KPs. Retry handles rate limits (5 req/sec for JSON API 1.2). Runtime scope check on boot: if `customerorder` endpoint returns 403, disable US5/US7 gracefully.

**Ref**: C-004 in spec.md, Graph Report god nodes.

### D-006: Fuzzy Matching — Levenshtein + LLM Normalization

**Decision**: Two-stage matching. Stage 1: LLM normalizes product names from request text (remove abbreviations, transliterations). Stage 2: Levenshtein distance against MoySklad product names, threshold >60%, max 3 candidates.

**Rationale**: Pure string matching fails on «бж» → «бронежилет», «kat» → «CAT (жгут)». LLM pre-normalization handles semantic expansion. Levenshtein handles typos and word order. >90% auto-picks, >60% shows options, <60% manual search.

**Ref**: C-003 in spec.md.

### D-007: SSE/Polling Notifications

**Decision**: JS polls `/api/notifications.php` every 30 seconds. Returns JSON array of unread notifications. Hourly cron checks for 24h-stale unacknowledged requests → sends email to `atlant.armour@yandex.ru`.

**Rationale**: No Service Worker or WebSocket infrastructure on shared hosting. Polling is simple, reliable, works in any browser. 30s interval = low server load (SQLite query by manager_id + is_read flag).

**Ref**: C-002 in spec.md.

### D-008: Single-Page App with Vanilla JS

**Decision**: `public/index.php` serves HTML shell. `app.js` handles routing (hash-based), API calls (fetch), DOM manipulation. No build step, no bundler.

**Rationale**: Constitution VII mandates no heavy frameworks. Vanilla JS is sufficient for: request list, KP editor, PDF preview (iframe), counterparty cards, settings forms. CSS via single `app.css` file. Upload via FormData API. Mobile-responsive via CSS media queries.

### D-009: Auth — PHP Sessions + bcrypt

**Decision**: Standard PHP sessions. Passwords hashed with `password_hash()` (bcrypt). No JWT, no OAuth. First manager created via CLI script.

**Rationale**: Shared hosting has native PHP session support. ≤10 users don't need token infrastructure. Session cookie with `httponly`, `secure`, `samesite=strict`.

### D-010: Self-Learning via Few-Shot Injection

**Decision**: `corrections` table stores `(request_context, auto_text, manager_text, created_at)`. On new KP generation, retrieve top-5 corrections by similarity (same counterparty type + overlapping product categories). Inject as few-shot examples in LLM prompt.

**Rationale**: Constitution IV requires corrections to influence future generations. Few-shot is the simplest effective approach — no fine-tuning, no vector DB. Similarity = keyword overlap in request context + recency bias. Exportable as JSON (NFR-008).

### D-011: PDF Template Structure

**Decision**: Single HTML template (`templates/kp.html`) with PHP-injected variables. Structure matches the 10-sample pattern from spec:

1. Header: legal entity (from `legal_entities` table)  
2. Title: «КОММЕРЧЕСКОЕ ПРЕДЛОЖЕНИЕ»  
3. Intro text (configurable per KP)  
4. Optional pre-table block (delivery conditions)  
5. Item table (№, name, unit, qty, price w/ VAT, total)  
6. Totals + optional VAT line  
7. Optional post-table block (item details, bundled accessories)  
8. Standard delivery conditions (editable in settings)  
9. Execution timeline (10/30 days, selectable)  
10. Price validity (14 days, configurable)  
11. Date + signature (PNG or text fallback)  

**Ref**: «Паттерны КП» section in spec.md, C-008.

### D-012: Email Sending — PHPMailer

**Decision**: Use PHPMailer (Composer package) for SMTP. Supports SSL/STARTTLS, attachments, HTML body.

**Rationale**: Native PHP `mail()` is unreliable on shared hosting. PHPMailer handles: SMTP auth, SSL on port 465, PDF attachment, HTML + plain text body, proper headers. Battle-tested, widely deployed on shared hosting.

---

## Complexity Tracking

| Item | Complexity | Justification |
|---|---|---|
| mPDF via Composer | Low | Single `composer require mpdf/mpdf`. No system deps. |
| PHPMailer via Composer | Low | `composer require phpmailer/phpmailer`. Standard. |
| IMAP extension | Medium | May not be on all hosts. Graceful degradation to manual-only. |
| MoySklad API rate limits | Medium | 5 req/sec. Batched product lookups + retry handle this. |
| LLM dual-provider | Medium | Adapted from proven NeuroPro code. |

No Constitution violations. No unnecessary complexity introduced.

---

## Phasing (from Graph Analysis)

| Phase | Communities | User Stories | Deliverable |
|---|---|---|---|
| **MVP** | 1 (Core Pipeline) | US1 + US2 | Email→KP→PDF→Send + manual paste |
| **P2** | 2 (CRM), 3 (LLM Knowledge), 4 (MoySklad Orders) | US3, US4, US5 | Accounts, self-learning, order creation |
| **P3** | 5 (Follow-up) | US6, US7 | Follow-up emails, deal scoring |

MVP ships first. Each phase is independently testable. Follow-up (community 5) is fully decoupled — ships last.
