# Module 008 — Catalog, matched positions, model picker

**Status:** Implemented
**Files:** `lib/catalog_import.php`, `lib/request_items.php`, `lib/matcher.php`, `lib/kp_content.php`,
`lib/llm.php`, `public/api/products.php`, `public/api/requests.php`, `public/api/proposals.php`,
`public/assets/js/app.js`

Five things that were in the operator's way, fixed together because they share one data
path — from the catalog through the request card into the KP.

## 1. One «Настройки» in the menu

The header used to carry two items whose names could not be told apart: a personal
«Настройки» and an admin «Админ», the second of which had its own «Настройки» tab.
There is now **one** menu item. Everything lives under `#settings/<tab>`; tabs an
ordinary manager may not use are hidden from them:

| Tab | Who sees it | What is there |
|---|---|---|
| `overview` | admin | state, connection tests, «куда уходит запрос» |
| `catalog` | everyone | what the product base holds, refresh from МойСклад, import from Excel, search check |
| `moysklad` | admin | token, access, webhooks |
| `llm` | admin | providers, models, proxy, OpenRouter catalog |
| `mail` | admin | mailboxes |
| `processing` | admin | OCR, attachments, thresholds, encoding repair |
| `kp` | admin | KP defaults: VAT, terms, warranty, photo limit |
| `knowledge`, `managers`, `prompts`, `logs` | admin | as before |
| `device` | everyone | push and the installable app on THIS device |
| `all` | admin | every key of `Settings::SPEC` with its source |

`#admin/...` redirects to `#settings/...`, so old links keep working.

## 2. Reaching a model API from a filtered network

**Symptom.** OpenRouter answers `HTTP 403 {"success": false, "error": "Access denied by
security policy."}` while the key is valid. No model API speaks that sentence: the reply
is written by something on the way — the hosting WAF or national filtering — not by the
provider.

**What the code does.**
- `LLM::looksIntercepted()` tells a provider's own refusal from an interceptor's
  (an HTTP body that is HTML, empty, or carries «security policy» / «access denied»), and
  `LLM::explain()` says so in the error the operator reads instead of the raw dump.
- `LLM_PROXY` (+ `LLM_PROXY_AUTH`) routes every model request through a proxy outside the
  filtering. `socks5h://` resolves DNS on the proxy side.
- `OPENROUTER_BASE_URL` points the client at a mirror of the API.
- `admin.php?action=llm_diagnose` probes the route and prints the HTTP code, the first line
  of the answer, the route taken and the verdict — «отвечает провайдер» or «отвечает фильтр».

There is no way to make a blocked direct request succeed from PHP alone; the honest fix is
a route that is not blocked, and the panel now says which of the two problems it is.

## 3. Model catalog and picking a model per reply

- `LLM::CATALOG` is grouped (`group` per row) and rendered as `<optgroup>`; «— своя
  модель —» still lets any slug be typed.
- «Обновить каталог OpenRouter» (`openrouter_models_refresh`) downloads `/models` once and
  caches the rows in `settings.openrouter_models`. Every later request merges them into the
  picker without touching the network. Free models get their own group.
- `Settings::SPEC` types `model:yandex` / `model:openrouter` render the same picker on the
  «Все параметры» tab.
- The reply window carries a «нейросеть» dropdown (`LLM_MODEL_PICKER`, on by default).
  A picked model holds **for that one request** and answers alone: `LLM::useModelSpec()`
  sets a one-shot override and `LLM::call()` skips the fallback chain, because silently
  answering with a different model would ignore the choice just made.

## 4. Catalog: МойСклад API **or** an Excel export

`CatalogImport::run()` reads the standard «Товары → Экспорт» file (`.xlsx` via ZipArchive +
XMLReader, or `.csv`) and upserts `products_cache`.

- Columns read: UUID, Тип, Код, Наименование, Артикул, Единица измерения, Описание, Группы,
  Изображение, Архивный, «UUID/Код товара модификации», every `Характеристика:*`.
- The price column is a setting (`CATALOG_PRICE_COLUMN`, default «Цена: Опт безнал») with
  fallbacks to «Опт» and «Розница».
- A **модификация** row has no name of its own; it is named `«Продукт (Размер: L)»` from its
  parent plus its characteristics. The export links it by **«Код товара модификации»** — the
  UUID printed there is *not* the UUID of the product's own row, so the code is the only key
  that joins. The resolved parent's real id goes into `products_cache.parent_id`, which is
  what lets a variant borrow its product's photos and description.
- **Stock is not imported.** The export carries «Неснижаемый остаток» — a threshold, not what
  is on the shelf. Stock keeps coming from the API sync, and the UI says so.
- Optional «удалить позиции, которых нет в файле» spares anything already quoted in a KP.

Measured on the customer's real export: 4 130 rows, 1 187 live positions (920 variants),
731 with photo links, ~1 s, ~30 MB peak.

## 5. «Подходящие позиции» on the request card

Beside «Распознанные позиции» (what the letter says, verbatim) there is now an editable
table of what those lines mean in our catalog — `request_items`.

- Built once, locally, when the card is first opened: **no model call** to open a request.
  «Подобрать нейросетью» is a separate button and costs one call.
- Each line: name with autocomplete over the local base, quantity, unit, price, note and an
  «ок» flag. A confirmed line is never re-picked by the automatic match — and, since
  module 018, is never sent to the model either: the query list is built from the open
  rows alone. The table is not blanked while the button runs, and the toast says what
  the run actually did («подобрано: 1, без совпадений: 1, подтверждённых не тронуто: 1»).
- The KP is generated **from this table** (`RequestItems::toProposalItems()`), so a wrong
  guess is corrected once, on the request, instead of in every proposal after it.
- `MoySklad::refreshProductCache()` before a KP is now best-effort: with a dead token the
  catalog imported from Excel still stands.

### Matching bug fixed on the way

`ProductMatcher::normalize()` ran `preg_replace('/[\s\-"\'«»()\[\]]+/', …)` **without `/u`**.
`«` and `»` are two bytes each, and the second byte of `»` (0xBB) is also the second byte of
`л` — so the class ate that byte out of every `л`, in both the query and the catalog.
«аптечка большая полевая» scored 0.26 against «Большая полевая аптечка» and did not match at
all. With `/u` it scores 0.78. The same regex in `MoySklad::refreshProductCache()` had the
same bug. Separately, `similarity()` divided a **byte** Levenshtein distance by a
**character** length, which roughly doubled the ratio on Cyrillic and could go negative;
`editSimilarity()` now compares like with like and clamps at 0.

## 6. Photos in the KP, chosen one by one

- A product's photos come from two places: files downloaded through the API
  (`products_cache.images_json`) and the public CDN links of the Excel export
  (`products_cache.image_urls`). `KpContent::productImageList()` merges them and gives each
  a stable key (`local:0`, `url:2`); a variant with no photos of its own borrows its
  parent's.
- The KP editor shows thumbnails per position and the manager ticks the ones this KP
  carries — stored in `proposal_items.selected_images`. **No stored choice means «все, что
  нашлись»**, exactly the behaviour before the picker; an explicitly empty list means «без
  фото».
- The browser never sees a CDN URL: images are streamed through
  `products.php?action=image`, and CDN files are cached on disk so a PDF rebuild does not
  re-download them.

## 7. Broken subjects in the archive

**Symptom.** A letter's subject shows as a row of `?`/boxes: `……\3 …… 04.09.26_…`.

`EmailReader::decodeMime()` used `imap_mime_header_decode()` and concatenated the chunks
**raw**. That function unwraps base64/QP but leaves every chunk in its own charset and
reports it separately, so a header split into an ASCII part and a windows-1251 part produced
a string that was neither — it survived neither the archive nor `json_encode()`. Each chunk
is now converted before it is joined.

`utf8Text()` made it worse: it decided «UTF-8 with a few broken bytes» from the presence of
one valid-looking byte pair, and cp1251 `«` right after a capital Cyrillic letter is exactly
such a pair — so whole Russian subjects went through `//IGNORE` and came out empty. It now
keeps whichever reading preserves more of the string.

«Настройки → Обработка писем → Перечитать кодировку» (`mail_repair_encoding`) re-reads rows
that still hold their original bytes. A subject the old code had already stripped to nothing
cannot be recovered — only re-downloading the mailbox brings it back, and the UI says that.

## Settings added

| Key | Group | Meaning |
|---|---|---|
| `LLM_MODEL_PICKER` | llm | show the model dropdown in the reply window |
| `LLM_PROXY`, `LLM_PROXY_AUTH` | llm | outbound proxy for every model request |
| `OPENROUTER_BASE_URL` | llm | mirror of the OpenRouter API |
| `CATALOG_PRICE_COLUMN` | moysklad | which «Цена: …» column of the export becomes the KP price |
| `CATALOG_IMPORT_ARCHIVED` | moysklad | import rows marked «Архивный: да» |

## Schema v10

- `products_cache`: `code`, `product_type`, `parent_id`, `characteristics`, `image_urls`,
  `is_archived`, `source`, `imported_at`
- `request_items` — the matched-positions table
- `proposal_items.selected_images`
