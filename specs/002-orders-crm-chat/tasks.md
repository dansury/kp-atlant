# Tasks — 002 Orders, Invoice Sync & Company Chat

## Phase 1: Schema

- [x] T101 `runMigrations()` + `schema_version` in `lib/bootstrap.php`
- [x] T102 Tables `contacts`, `attachments`, `orders`, `invoices`, `webhook_log`
- [x] T103 Columns: `requests.type/type_source`, counterparty identity + answer timestamps, `correspondence.manager_id/event_type/meta_json`
- [x] T104 Helpers `publicEmailDomains()`, `normalizeCompanyName()` + backfill for existing cards

**Checkpoint**: existing installs migrate on first request without data loss.

---

## Phase 2: User Story 8 — order emails (P1)

- [x] T105 Vendor `smalot/pdfparser` into `lib/pdfparser/` with a PSR-0 autoloader
- [x] T106 `lib/attachments.php` — storage + PDF/DOCX/XLSX/TXT extraction + Yandex Vision OCR
- [x] T107 `lib/email.php` — recursive MIME walk, attachment fetch, charset handling, HTML fallback
- [x] T108 `lib/parser.php` — `request_type` classification with attachment text
- [x] T109 `cron/check_mail.php` — attachments → parse → company card → feed
- [x] T110 `requests.php` — `type` in list/get, `set_type` override, `attachment` download
- [x] T111 `orders.php` — `create_from_request`, `link_counterparty`, org id resolution
- [x] T112 UI — type switch, attachment list, «Создать заказ» opening MoySklad in a new tab

**Checkpoint**: an order email produces a MoySklad order in one click.

---

## Phase 3: User Story 9 — invoice sync (P1)

- [x] T113 `lib/moysklad.php` — `getOrder`, invoices by order/counterparty, `exportInvoicePdf`, organizations, webhook CRUD, raw PUT/DELETE
- [x] T114 `lib/sync.php` — order/invoice projection, printform cache, `syncCompany`, `ensureWebhooks`
- [x] T115 `public/api/moysklad_hook.php` — secret check, fast ack, idempotent upserts, `webhook_log`
- [x] T116 `public/api/invoices.php` — list, sync, pdf, send
- [x] T117 `cron/sync_moysklad.php` — 5-minute catch-up
- [x] T118 UI — invoice block, «Отправить счёт», refresh on `visibilitychange`
- [x] T119 Settings — MoySklad permissions, webhook registration and status

**Checkpoint**: invoice issued in MoySklad shows up in the card without any manual export.

---

## Phase 4: User Story 10 — company chat (P1)

- [x] T120 `lib/crm.php` — identity resolution, feed, contacts, notes, merge/split, answer state
- [x] T121 `counterparties.php` — `recent`, `chat`, `note`, `contacts`, `merge`, `split`, enriched `get`
- [x] T122 UI — chat feed with attachments, contacts panel, note composer
- [x] T123 Route all outbound sends (КП, счёт, follow-up) through `Crm::logEvent`

**Checkpoint**: messages from different people at one company land in one feed.

---

## Phase 5: User Story 11 — unanswered highlighting (P2)

- [x] T124 `Crm::answerState()` + denormalized timestamps
- [x] T125 Highlighting in the requests list and the company list, unanswered first
- [x] T126 Configurable critical threshold in settings

---

## Phase 6: Verification

- [x] T127 `php -l` on every changed file, `node --check` on `app.js`
- [x] T128 Harness: migrations, INN/domain/name gluing, public-domain exclusion, contacts, feed, note-is-not-an-answer, merge, split
- [x] T129 Attachment extraction on DOCX / XLSX / cp1251 TXT fixtures
- [x] T130 XSS review of the feed — all client-supplied text escaped
- [x] T131 Update `graphify-out/` and `DEPLOY.md`
- [ ] T132 Live check against the real MoySklad account: order creation, webhook delivery, invoice printform
- [ ] T133 Live check of IMAP attachment fetch on a real inbox (message with PDF + XLSX)
- [ ] T134 OCR check on a scanned заявка (Yandex Vision quota and quality)

**Note**: T132–T134 need live credentials and cannot be run from the dev machine.
