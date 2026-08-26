# Tasks — Atlant Armour КП Automation

**Created**: 2026-08-25  
**Spec**: `specs/001-kp-automation/spec.md`  
**Plan**: `plan.md`

---

## Phase 1: Project Setup

- [x] T001 Create project directory structure per plan.md
- [x] T002 Create `composer.json` with mPDF + PHPMailer deps
- [x] T003 Create `config.example.php` with all env vars documented
- [x] T004 Create `.htaccess` (rewrite rules, deny `lib/`, `data/`, `config.php`)
- [x] T005 [P] Create `lib/db.php` — SQLite wrapper (adapted from NeuroPro)
- [x] T006 [P] Create `lib/llm.php` — LLM wrapper (adapted from NeuroPro)
- [x] T007 Create `lib/bootstrap.php` — config load, DB init, LLM init, schema creation
- [x] T008 Run `composer install`, verify mPDF + PHPMailer load

**Checkpoint**: Project boots, DB created, LLM wrapper responds to test call.

---

## Phase 2: User Story 1 — Email → KP → PDF → Send (P1) 🎯 MVP

- [x] T009 Create `lib/email.php` — IMAP reader + SMTP sender (PHPMailer)
- [x] T010 Create `lib/parser.php` — request text → structured JSON via LLM
- [x] T011 Create `lib/moysklad.php` — MoySklad API wrapper (search, cache, permissions check)
- [x] T012 Create `lib/matcher.php` — fuzzy product matching (Levenshtein + LLM normalization)
- [x] T013 Create `templates/kp.html` — mPDF HTML template matching КП patterns from spec
- [x] T014 Create `lib/pdf.php` — mPDF wrapper (template + data → PDF file)
- [x] T015 Create `lib/notifier.php` — notification creation + 24h email fallback logic
- [x] T016 [P] Create `public/api/requests.php` — CRUD + manual create
- [x] T017 [P] Create `public/api/proposals.php` — generate, update, preview, confirm, send
- [x] T018 [P] Create `public/api/products.php` — MoySklad product search + cache refresh
- [x] T019 [P] Create `public/api/notifications.php` — polling endpoint
- [x] T020 Create `cron/check_mail.php` — IMAP poll script (every 2 min)
- [x] T021 Create `cron/check_notifications.php` — 24h fallback email (hourly)
- [x] T022 Seed default legal entity (ИП Сурков К.А.) in bootstrap
- [x] T023 Create `public/index.php` — SPA shell (HTML + CSS + JS loader)
- [x] T024 Create `public/assets/css/app.css` — responsive layout, KP editor styles
- [x] T025 Create `public/assets/js/app.js` — SPA router, API client, request list, KP editor, PDF preview

**Checkpoint**: US1 fully functional — email arrives, system parses, generates PDF, manager edits and sends.

---

## Phase 3: User Story 2 — Manual Paste (P1) 🎯 MVP

- [x] T026 Add manual paste form to `app.js` (textarea + counterparty input)
- [x] T027 Wire paste form to `POST /api/requests.php?action=create`

**Checkpoint**: US2 works — paste text, same pipeline as email, KP generated.

---

## Phase 4: User Story 3 — Manager Accounts & CRM (P2)

- [x] T028 Create `lib/auth.php` — session management, bcrypt passwords
- [x] T029 Create `public/api/auth.php` — login/logout/me endpoints
- [x] T030 Create `public/api/counterparties.php` — CRUD + MoySklad lookup
- [x] T031 Add auth middleware to all API endpoints
- [x] T032 Add login screen to `app.js`
- [x] T033 Add counterparty cards to `app.js` (correspondence history, deals)
- [x] T034 Add request assignment (pool → manager) to `app.js`
- [x] T035 Create first manager via CLI script (`php create_manager.php`)

**Checkpoint**: US3 works — two managers log in, see own requests, counterparty history.

---

## Phase 5: User Story 4 — Self-Learning (P2)

- [x] T036 Create `public/api/corrections.php` — list + export (JSON)
- [x] T037 Add correction detection to proposals.php (diff auto vs final text on confirm)
- [x] T038 Add few-shot injection to `lib/parser.php` cover letter generation
- [x] T039 Add corrections export button to settings in `app.js`

**Checkpoint**: US4 works — corrections saved, 6th KP uses prior corrections as examples.

---

## Phase 6: User Story 5 — MoySklad Order Creation (P2)

- [x] T040 Add `createOrder()` to `lib/moysklad.php`
- [x] T041 Create `public/api/orders.php` — create order from confirmed KP
- [x] T042 Add «Создать заказ» button to KP view in `app.js`
- [x] T043 Handle missing counterparty in MoySklad (create or link)

**Checkpoint**: US5 works — confirmed KP → one click → order in MoySklad.

---

## Phase 7: User Story 6 — Follow-up Emails (P3)

- [x] T044 Create `cron/check_followups.php` — daily scan for stale KPs (30+ days, no order)
- [x] T045 Create `public/api/followups.php` — list, get, update, send, dismiss
- [x] T046 Seed default EMAILRULES.md from tov.md in bootstrap
- [x] T047 Add follow-up cards to `app.js` (draft, edit, send, dismiss)

**Checkpoint**: US6 works — system suggests follow-ups, manager edits and sends.

---

## Phase 8: User Story 7 — Deal Scoring (P3)

- [x] T048 Add `getOrdersByCounterparty()`, `getShipments()`, `getPayments()` to `lib/moysklad.php`
- [x] T049 Create `public/api/deals.php` — deal status by counterparty
- [x] T050 Add deal status badge to counterparty cards in `app.js`

**Checkpoint**: US7 works — counterparty card shows deal status from MoySklad.

---

## Phase 9: Settings & Polish

- [x] T051 Create `public/api/settings.php` — legal entity CRUD, email rules, signature/logo upload
- [x] T052 Add settings page to `app.js` (legal entity form, signature upload, email rules editor)
- [x] T053 Add EMAILRULES.md editor with LLM analysis preview (FR-018)

**Checkpoint**: All settings configurable from UI.

---

## Phase 10: Verification

- [x] T054 End-to-end test: email → parse → KP → edit → send → order (US1+US5)
- [x] T055 Verify PDF matches sample patterns (compare with 10 reference КП)
- [x] T056 Verify MoySklad graceful degradation (read-only token)
- [x] T057 Verify LLM fallback (disable primary provider, check secondary activates)
- [x] T058 Verify Constitution compliance (all 8 principles)
- [x] T059 Deploy checklist: config.php, cron setup, HTTPS, initial manager

---

## Notes

- `[P]` = parallelizable (touches different files, no ordering dependency within phase)
- Each phase is a shippable slice aligned with one user story
- Phases 2+3 together = MVP (US1 + US2)
- Total: 59 tasks across 10 phases
