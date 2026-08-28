# Implementation Plan — 002 Orders, Invoice Sync & Company Chat

**Spec**: `specs/002-orders-crm-chat/spec.md`
**Stack**: unchanged — PHP 8.1+ / SQLite / vanilla JS, shared hosting, no Docker.

---

## Architecture decisions

### D-101: MoySklad is the source of truth for documents
Orders and invoices are edited in MoySklad; the CRM keeps a **read-only local
projection** (`orders`, `invoices`) refreshed by webhooks, by card open, and by
cron. No two-way document editing — that would need conflict resolution the
manager does not want.

### D-102: Three-layer freshness
1. **Webhook** (`/api/moysklad_hook.php`) — sub-second, the normal path.
2. **On card open + `visibilitychange`** — covers the target scenario "manager
   comes back to the tab"; also covers a missed or unregistered webhook.
3. **`cron/sync_moysklad.php` every 5 min** — background catch-up for companies
   touched in the last 30 days.
Client-side background syncs are throttled to one per 15 s per company.

### D-103: Attachment text feeds the same LLM call
`Attachments::textForRequest()` is appended to the parser prompt under a
`--- Вложение: <file> ---` header. Classification (`request_type`) and item
extraction happen in one LLM call — no extra provider round-trip, and positions
that exist only in a спецификация are found.

### D-104: pdfparser vendored outside composer
The host has no SSH and no composer, and `vendor/` is committed. Rebuilding
`vendor/` on a newer PHP risks silently bumping mpdf. So `smalot/pdfparser`
lives in `lib/pdfparser/` with a 6-line PSR-0 autoloader in
`lib/attachments.php`. `vendor/` is untouched.

### D-105: Company identity is a resolver, not a constraint
`Crm::resolveCounterparty()` applies ИНН → корпоративный домен → нормализованное
название. Public mail domains are excluded from gluing. Merges are soft:
`merged_into_id` points to the survivor and `Crm::rootId()` resolves it, so no
history is destroyed and a wrong glue can be split by contact.

### D-106: Answer state is denormalized
`counterparties.last_inbound_at` / `last_outbound_at` are maintained by
`Crm::logEvent()`, so list views need no aggregate query. Notes and system
events deliberately do not count as answers. Every outbound path (КП, счёт,
follow-up) goes through `Crm::logEvent` — that is what keeps highlighting honest.

### D-107: Webhook endpoint acks first, works after
The receiver validates the secret, answers 200, flushes
(`fastcgi_finish_request`), and only then pulls documents and printforms. Every
event is recorded in `webhook_log` with its outcome; the log self-trims at 30 days.

---

## Schema (migration v2, `runMigrations()` in `lib/bootstrap.php`)

| Change | Purpose |
|---|---|
| `requests.type`, `requests.type_source` | KP request vs order + who decided |
| `counterparties.email_domain`, `name_normalized`, `merged_into_id` | identity resolution and merges |
| `counterparties.last_inbound_at`, `last_outbound_at` | unanswered highlighting |
| `correspondence.manager_id`, `event_type`, `meta_json` | note authors and system events in the feed |
| `contacts` | people per company |
| `attachments` | stored files + extracted text + status |
| `orders`, `invoices` | MoySklad projections |
| `webhook_log` | delivery diagnostics |

Migrations are additive (`ALTER TABLE ADD COLUMN` / `CREATE TABLE IF NOT EXISTS`)
and run on every request behind a cheap `schema_version` check.

---

## Files

**New**: `lib/attachments.php`, `lib/crm.php`, `lib/sync.php`,
`lib/pdfparser/`, `public/api/invoices.php`, `public/api/moysklad_hook.php`,
`cron/sync_moysklad.php`.

**Changed**: `lib/bootstrap.php` (migrations, helpers), `lib/email.php`
(MIME walk + attachments), `lib/parser.php` (classification),
`lib/moysklad.php` (orders/invoices/webhooks/raw requests),
`public/api/{requests,orders,counterparties,settings,proposals,followups}.php`,
`public/assets/js/app.js`, `public/assets/css/app.css`.

---

## Risks

| Risk | Mitigation |
|---|---|
| Токен МойСклад без прав на вебхуки | Деградация до подтяжки при открытии + cron; статус виден в настройках |
| Печатная форма счёта формируется асинхронно | Повтор по `Location` до 4 попыток, кэш в `storage/invoices/` |
| OCR стоит денег | Выключается в настройках; лимит страниц на файл |
| Ложная склейка компаний по домену | Публичные домены исключены; ручное «Отделить» по контакту |
| Тело письма попадает в HTML ленты | Весь пользовательский текст экранируется `App.esc()` / `App.jsStr()` |
