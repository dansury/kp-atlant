# Module 055 — Full-text search over every entity

Source: the request of 2026-09-24: «поиск писем по словам не работает — компания
называется «Легион», поиск не выдаёт ничего. Искать по любому слову в названии,
переписке, товару, ИНН, телефону — по любой сущности».

## 1. Why the old search missed

The database is SQLite. Its `LIKE` folds case for ASCII only: `%легион%` does
not match «Легион» or «ЛЕГИОН». Module 023 searched the letter fields with
`LIKE` and nothing else — the company card, its INN, phones, contacts, the
positions of a request were never looked at.

## 2. The index — `lib/search.php`, class `SearchIndex`

One table of normalized documents, one row per entity:

| kind | code | document (normalized text) |
|---|---|---|
| `mail` | 0 | `mail_messages`: subject, from_email, from_name, to_emails, cc_emails, body_text (stripped body_html when body_text is empty); `attachments` of the letter: filename, extracted_text |
| `cp` | 1 | `counterparties`: name, legal_title, inn, kpp, ogrn, legal_address, contact_person, contact_email, contact_phone, email_domain, notes, contract_name; `contacts` (name, email, phone); `counterparty_orgs` (name, inn, kpp); `orders.name`, `orders.ship_track`, `invoices.name` of the company |
| `req` | 2 | `requests`: email_subject, email_from, raw_text; `request_items` (raw_name, product_name, article); `proposal_items.product_name` of its КП |
| `card` | 3 | `board_cards`: title, note |

* Table `search_docs`: FTS5 virtual table `fts5(body, tokenize='trigram')`;
  when FTS5/trigram is not compiled into SQLite — a plain table
  `search_docs(rowid INTEGER PRIMARY KEY, body TEXT)`. The query is the same
  for both: `body LIKE ?` (trigram serves `LIKE` from the index for patterns of
  3+ characters, a shorter one is a scan).
* `rowid = ref_id * 4 + kind code` — a row is replaced / deleted by its key.
* Each document is capped at 200 000 characters.

### 2.1 Normalization — `SearchIndex::normalize(string): string`

Lowercase (`mb_strtolower`), `ё` → `е`, whitespace collapsed. Every phone-like
run (`+`, digits, spaces, `-`, `(`, `)`, `.` with 7+ digits) is also appended
as bare digits, and an 11-digit number starting with `8` once more with `7` —
so «8 (916) 123-45-67» is found by `9161234567`, `+79161234567`,
`89161234567`.

`SearchIndex::normalizeTerm(string): string` — the same for a query word; a
word made of digits and phone punctuation only (5+ digits) becomes bare
digits, 11 digits starting with `8` → `7…`.

### 2.2 Freshness — triggers + `search_dirty`

`search_dirty(kind INTEGER, ref_id INTEGER, PRIMARY KEY(kind, ref_id))`.
SQLite triggers (`trg_search_*`) put the key there on every insert, update of
an indexed column, and delete of: `mail_messages`, `attachments`
(→ its letter and its request), `counterparties`, `contacts`,
`counterparty_orgs`, `orders`, `invoices` (→ the company), `requests`,
`request_items`, `proposal_items` (→ the request of the proposal),
`board_cards`. Every write path is covered without touching it.

`SearchIndex::refresh(float $budgetSec = 20.0): int` — reindexes dirty keys
in batches of 200 inside a transaction, until none left or the budget is
spent; returns how many are still dirty. A key whose row is gone is deleted
from the index. Called:
* before every search (`SearchIndex::ready()`);
* by `cron/check_mail.php` after the sync (60 s budget).

Migration v47 creates the tables and triggers and marks every existing row
dirty; the first search / cron run builds the index.

## 3. Query — `SearchIndex::terms()` and the SQL helpers

Terms come from `MailArchive::searchTerms()` (quotes keep a phrase, up to 8
words), each normalized by `normalizeTerm()`. **All** terms must match; each
term may match in any entity.

* `SearchIndex::hitsSql(string $kind): string` —
  `SELECT rowid / 4 FROM search_docs WHERE body LIKE ? AND rowid % 4 = <code>`
  (one `?`). `%` and `_` in a term are escaped (`ESCAPE '\'`).
* `SearchIndex::messageMatch(string $alias, string $term): array{0:string,1:array}`
  — a condition on a `mail_messages` row: the letter itself, its company
  (with merged-away companies: `id` or `merged_into_id` in the hits), or its
  request matched.

Used by:

| Screen | Code | A row is found when, for every term |
|---|---|---|
| Mail threads, board «Найдено в почте» | `MailThreads::query` | any letter of the thread matches `messageMatch` |
| Mail list | `MailArchive::query` | the letter matches `messageMatch` |
| Board search | `Boards::search` | the card itself, its company, its request, or any letter of its thread / company matches |
| Requests list | `requests.php?action=list&q=` | the request or its company matches |
| Company search | `counterparties.php?action=search` | the company (or one merged into it) matches |

`MailThreads::query` returns `indexing: N` — keys still waiting after the
budget; the board search shows «Индекс поиска ещё строится — найдено не всё»
while N > 0.

The client-side board filter compares with `ё` → `е` on both sides.
