# Module 013 — Analogues, the correspondence table, and requisites from МойСклад

**Status:** Implemented
**Files:** `lib/alternatives.php`, `lib/synonyms.php`, `lib/request_shape.php`,
`lib/requisites.php`, `lib/bitrix.php`, `lib/request_items.php`, `lib/kp_content.php`,
`lib/prompts.php`, `lib/pdf.php`, `lib/moysklad.php`, `templates/kp.html`,
`public/api/proposals.php`, `public/api/admin.php`, `cron/sync_moysklad.php`,
`public/assets/js/app.js`, `pull.php`
**Tests:** `tests/module_013.php`, `tests/deploy_preserves_data.php`

Five things a КП was getting wrong, plus the deploy bug that would have eaten the
answer to all five.

---

## 1. A position we cannot ship is answered, not left blank

**Before.** A line whose catalog row had nothing on the shelf went into the КП with
«под заказ» next to it, or — when the catalog held nothing at all — with the
client's own words and no price. The client read a document with a hole in it.

**Now.** `Alternatives::suggest()` answers such a line with something we CAN ship,
in the order the manager described:

1. **The name**, through the synonyms of the trade. `Synonyms` collapses «броник»,
   «бж» and «бронежилет» to one token before anything is compared, by STEM rather
   than by whole word, because «бронежилеты» and «бронежилета» are the same request.
   The table is built in (`Synonyms::BUILTIN`) and extended, never replaced, by
   `MATCH_SYNONYMS` in the panel. Every candidate goes through the existing
   `ProductMatcher`, so the vector index still has its say where it is available.
2. **The shelf.** A candidate with no FREE remainder (`stock − reserved`) is dropped
   before it is considered. An analogue that is itself out of stock answers nothing.
3. **The description.** `Alternatives::requirements()` reads the client's own
   requirements off their own sentence — the fragments carrying a number or an
   attribute word, so «класс защиты Бр5, площадь 40 дм2, 10 шт» yields two
   requirements and drops the quantity. Each is then checked against the
   candidate's name, characteristics and description.
4. **The verdict, out loud.** The КП names each requirement the position was
   proved to meet and QUOTES our own description as the proof — not the client's
   wording repeated back at them as though it were a fact about our product. What
   it does not meet is printed too, under «отличается».

**Degradation.** With no model key the analogue is still found, still checked and
still explained — `localPick()` does the whole job on local text. The model pass
(one call for every line of the letter, temperature 0) only sharpens the choice,
and its answer is filtered: a claim our own description does not support is
dropped before it reaches a document we sign. `ALT_USE_LLM` off is a supported
configuration, not a broken one.

**Reversibility.** The analogue REPLACES the line — a КП prices one position per
request line, not two — but nothing is hidden. `request_items.alt_of` keeps what
was asked for, `alt_specs_json` keeps the proof, and the position we could not
ship goes back into `match_variants`, so «выбрать другую» offers it again in one
click. The row is marked `needs_choice`: a swap is looked at before it ships.

**What never happens.** The line is never left as a question to the manager and
never becomes a question to the client. No analogue in stock → the line stays as
it was and the КП says «под заказ».

### Which rows are eligible

`is_confirmed` on a freshly matched row means «уверенное совпадение по названию»,
set by the score — not «менеджер это утвердил». An exact name match on an empty
shelf is exactly the line an analogue exists for, so `fillAlternatives()` takes an
explicit list of the rows an automatic match just wrote. Called without one (from
the UI), it leaves every confirmed row alone: there the flag is the manager's.

---

## 2. A request that arrived as a table is answered as a table

`RequestShape::detect()` decides, from the letter alone:

- a spreadsheet attachment (`xlsx`, `xls`, `csv`, `ods`) — decisive;
- an attachment whose extracted text is tab-separated rows (a спецификация inside
  a PDF or a DOCX is still a table);
- a body with two or more rows of three or more cells, three or more numbered
  lines ending in a quantity, or a header line carrying two of «Наименование»,
  «Кол-во», «Артикул», «п/п»…

The verdict is stored once on `requests.shape`. It is never a model call and never
a question, so the same letter produces the same document on every regeneration.

A table-shaped request opens its КП with **«Таблица соответствия запросу»**:
the client's line on the left, ours on the right, with артикул, «в наличии» /
«под заказ», and — where the position is an analogue — the requirements it meets.
A text request gets no such table.

Precedence: the manager's own switch in the КП editor > `KP_MATCH_TABLE`
(`auto` / `always` / `never`) > the shape of the request.

---

## 3. After the table: the description, the first photo, the link

In both cases — table or text — each position's card carries:

- its **description from МойСклад** (a variant borrows its product's, as before);
- its **first photo** (`KP_CARD_PHOTOS`, default 1). A manager who ticked photos by
  hand meant those, and that choice is not capped;
- a **link to the product page on atlant-armour.ru**.

### The link (1С-Битрикс)

Nothing joins МойСклад to the shop automatically, so `Bitrix::productUrl()` tries
three routes and stops at the first that answers:

| Route | Setting | When it applies |
|---|---|---|
| Ask the site | `BITRIX_WEBHOOK_URL` | An inbound REST hook answering `?article=&code=&name=` with `{"url":…}`, `{"result":{"url":…}}` or `{"result":[{"DETAIL_PAGE_URL":…}]}`. The only source that knows the real `DETAIL_PAGE_URL`. |
| A URL template | `BITRIX_URL_TEMPLATE` | `/catalog/{article}/` — also `{code}`, `{slug}`, `{id}`. Works when the shop's URLs are built from the same артикул as МойСклад. |
| Search | `BITRIX_SEARCH_TEMPLATE` | The honest fallback: always opens something relevant. |

A resolved link is verified (`BITRIX_VERIFY_URL`, on by default) before it is
cached: a 404 inside a signed commercial document is worse than no link. The
verdict — a URL, or an empty string meaning «проверено, страницы нет» — is cached
on `products_cache.site_url` and warmed by cron, so a КП never waits on the site.
With `BITRIX_ENABLED` off nothing is printed and nothing breaks.

---

## 4. НДС, реквизиты, адреса, банк and the договор — from МойСклад, frozen

These are the part of a КП that has to be RIGHT, not plausible, so no model ever
touches them.

- `Requisites::syncOrganization()` pulls the организация (`expand=accounts`) into
  `legal_entities`: ИНН, КПП, ОГРН/ОГРНИП, ОКПО, юридический and фактический
  address, phone, email, the default bank account, and **`payerVat`**. The
  signature, the logo and the stamp are ours and are never overwritten.
- `Requisites::syncCounterparty()` does the same for the buyer, and
  `syncContract()` records the newest non-archived договор between the two.
- **VAT is decided, not guessed.** `payerVat: false` → the КП says «НДС не
  облагается» (`KP_VAT_EXEMPT_NOTE`) and no VAT amount is printed. Otherwise the
  rate comes from the `vat` МойСклад keeps on the very products of this КП, and
  the настройка «НДС по умолчанию» is only reached for when the catalog is silent.
  The snapshot records which of the three it was, in words.
- **The amount is always printed** (module 029), and in one of two shapes chosen by
  `KP_VAT_MODE`: `included` — the catalog price already carries the tax and the
  document takes it out of the total; `added` — the price is net and the tax is
  added to it, so the client pays more than the table sums to. The shape is
  presentation, not a МойСклад fact, so it is NOT frozen into the snapshot: the
  rate and `payerVat` print as signed, the shape prints as set today, and one КП
  may override it in `proposals.vat_mode`. `Requisites::vatTotals()` is the one
  place that does the arithmetic and words the lines — the PDF, the Word file and
  the letter body print the same ones. A счёт or заказ made from such a КП carries
  `vatEnabled`/`vatIncluded` to match (`Requisites::msVatFlags()`).
- **`proposals.requisites_json` is a SNAPSHOT**, written once when the КП is
  generated. A КП reprinted six months later carries the requisites it was signed
  with, not today's bank account. `Requisites::forProposal()` falls back to a fresh
  snapshot only for a КП made before this module existed.

A dead МойСклад token leaves the last synced copy in charge. A КП is never blocked
by the API being unreachable.

---

## 5. The model does the work and stops asking

The complaint was «модель ленится и задаёт лишние дурацкие вопросы»: a draft that
comes back as «уточните, пожалуйста, какие именно позиции вас интересуют» is worse
than no draft, because the manager now has to write the letter AND delete the
question.

`Prompts::render()` appends one **discipline block** to every system prompt, so the
rules live in one place instead of being repeated in thirteen: do the whole job,
do not abbreviate («и так далее» is forbidden), obey the format exactly, invent no
facts, do not apologise or talk about yourself, and — the point of it — ask a
clarifying question ONLY when the answer is needed to price something or to issue
a document, once, as the last line. «Что вас интересует» and «верно ли я понял»
are never asked.

It is editable (`LLM_DISCIPLINE_TEXT`) and can be switched off (`LLM_DISCIPLINE`)
without touching a single prompt.

`reply_kp` additionally now requires that an out-of-stock position be answered
with an analogue and that the reasons be named. The cover letter is handed the
proved requirements per swap and may repeat those and nothing else.

---

## 6. A redeploy no longer eats what the service has learned

**The bug.** `pull.php` preserved only `pull.php` and `pull-config.php` by name;
everything else came from the operator-typed `keep_files`. But `data/.gitkeep` and
`storage/*/.gitkeep` **are** in the repository, so `purgeExtra()` walked into those
directories, found `kp.db`, `signature.png` and the attachments with no counterpart
in the archive, and deleted them. With `keep_files` left empty — which is what a
form field nobody read the docs for looks like — **every deploy wiped the database**:
the manager's corrections, the edited prompts, the knowledge cache, the embedding
index and every setting made in the panel. `config.php`, with the real keys, went
with it.

**The fix.** `ALWAYS_KEEP` now carries `config.php`, `data` and `storage`. It is not
advice in DEPLOY.md that somebody has to remember; it is the code refusing to do it.
The run banner prints what purge will not touch, and the setup form says so too.

`tests/deploy_preserves_data.php` runs pull.php's own `copyTree` / `purgeExtra`
against a simulated server with `keep_files` deliberately empty, and asserts both
halves: the database, the signature, the attachments and `config.php` survive, and
the deploy still happens (code updated, files dropped from the repo removed). The
functions are lifted out of the real `pull.php` at run time rather than copied, so
the test cannot drift from what actually deploys.

Note what was NOT at risk: prompts and settings are read DB-first
(`Prompts::text()`, `Settings::get()`), so a code deploy never overwrites an edit
made in the panel. The only danger was the filesystem purge, and it is closed.

---

## Settings added

| Key | Group | Meaning |
|---|---|---|
| `MATCH_SYNONYMS` | match | Own synonym groups, one per line, first word canonical |
| `ALT_ENABLED` | match | Offer an analogue when a position is out of stock |
| `ALT_USE_LLM` | match | Check descriptions with the model (off still finds and explains) |
| `KP_MATCH_TABLE` | kp | `auto` / `always` / `never` for the correspondence table |
| `KP_CARD_PHOTOS` | kp | Photos in a product card; 1 = the first one |
| `KP_SHOW_SITE_LINK` | kp | Print the link to the product page |
| `KP_VAT_EXEMPT_NOTE` | kp | Wording when the организация does not charge VAT |
| `KP_REQUISITES_BLOCK` | kp | Print the requisites block |
| `REQUISITES_AUTOSYNC` | moysklad | Pull requisites, VAT and the договор from МойСклад |
| `LLM_DISCIPLINE` | llm | Append the discipline block to every prompt |
| `LLM_DISCIPLINE_TEXT` | llm | Rewrite that block |
| `BITRIX_ENABLED`, `BITRIX_SITE_URL`, `BITRIX_WEBHOOK_URL`, `BITRIX_URL_TEMPLATE`, `BITRIX_SEARCH_TEMPLATE`, `BITRIX_VERIFY_URL`, `BITRIX_CACHE_DAYS`, `BITRIX_TIMEOUT_SEC` | bitrix | The shop on 1С-Битрикс |

New admin actions: `requisites_sync`, `bitrix_diagnose`, `bitrix_refresh_urls`.
New prompt: `kp_alternatives` (JSON only; chooses among ids we verified and may
supply no name, price or stock of its own).

## Schema v17

- `products_cache`: `vat`, `site_url`, `site_url_synced_at`
- `requests`: `shape`
- `request_items`: `is_alternative`, `alt_of`, `alt_specs_json`
- `proposal_items`: `is_alternative`, `alt_reason`, `alt_specs_json`, `site_url`
- `proposals`: `show_match_table`, `match_table_note`, `requisites_json`
- `legal_entities`: `moysklad_id`, `kpp`, `okpo`, `legal_address`, `pays_vat`,
  `bank_name`, `bank_bic`, `bank_account`, `bank_corr`, `synced_at`
- `counterparties`: `legal_title`, `legal_address`, `kpp`, `ogrn`,
  `contract_moysklad_id`, `contract_name`, `contract_date`

## Not done here

- `Bitrix` reads the shop, it does not write to it. Publishing a КП or a price to
  the site is a separate job.
- The синоним table is deliberately about the NAMES OF A CATEGORY, the words
  clients type. Facts about our products stay in the wiki (`Knowledge`) and are
  never inlined into code.

## «Настройки → Оформление КП» shows what will be printed

The tab used to hold typed-in numbers alone, so the VAT, the ИНН and the addresses of a КП
lived in two places at once — МойСклад, and somebody's memory. It now opens with the
организация as МойСклад has it: full and short name, ИНН/КПП, ОГРН(ИП), ОКПО, the legal and
the actual address, phone, e-mail, the signatory, the bank line, whether we charge VAT at all,
and when all of it was last pulled. «Обновить из МойСклад» runs the same
`Requisites::syncOrganization()` a КП runs, so the tab and the document can never disagree.

The ID of the организация is printed IN FULL — it is not a secret, and an operator comparing
two МойСклад accounts needs to read it.

The typed-in fields below are explicitly what applies where МойСклад is silent. «НДС по
умолчанию» states the rate the catalog actually carries (`Requisites::catalogVat()` — the
prevailing rate among non-archived positions, with its share) and offers to fill it in; the
field is disabled outright when the организация is not a VAT payer, because the document then
prints `KP_VAT_EXEMPT_NOTE` and no rate at all. The order of precedence is unchanged and now
visible on screen: the position's own rate, then this default.

| Action | Meaning |
|---|---|
| `settings.php?action=kp` | manual defaults plus the requisites and the VAT МойСклад holds |
| `settings.php?action=kp_requisites_sync` | pull the организация from МойСклад now |
