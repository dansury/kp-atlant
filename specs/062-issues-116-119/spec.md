# 062 — Issues #116–#119: the exact product and its size, stages by themselves, documents in the conversation, focus and phone windows

## 1. #118 — «плита Бр3, размер XL» finds the plate, its XL, and never «нет в наличии»

The letter asked for «5 плит для бронежилета Бр3, размер XL». The card offered
«Боковая плита … (Размер: M)» as an analogue of «Плита для бронежилета Бр3»,
«нет в наличии». Three separate faults:

### 1.1 Stock of a product with modifications
`rematchReport()` wrote `Alternatives::freeStock($best)` — the parent's OWN
stock, always 0 in МойСклад — and `fillAlternatives()` read `request_items.stock`
as it was stored. Now:
- `rematchReport()` stores `Variants::freeStock($best)` (sum of the modifications);
- `fillAlternatives()` takes the modifications' free stock (`Variants::stockFor`)
  for every row whose product has modifications. A product with modifications
  in stock is never up for an analogue.
- A row the client named a size for and that stands on that modification
  (`variant_label` set, `match_source = 'модификация'`) is never replaced by an
  analogue either: out of stock it stays «под заказ» — another product or
  another size is not what was asked.

### 1.2 One size is a modification too
`Variants::expand()` split only lists («р.S-5шт, р.M-13шт»). A single size now
becomes the line's label:
- `Variants::sizeLabel(string $text): ?string` — one size with an explicit hint
  (`SIZE_PREFIX`: «размер», «р.», «р-р», «рост») AND a closed-set token
  (`SIZE_TOKENS`) or a numeric size («52», «52-54»). Two different sizes → null.
- `Variants::stripSize(string $name): string` — the name without «размер XL».
- `Variants::mentions(string $text, string $name): bool` — the piece of the
  letter is about this product: ≥ 60 % of the name's words (≥ 4 letters) occur
  by stem. Replaces the «first 10 characters» check, which missed «5 плит для
  бронежилета» against «плита для бронежилета».
- `expand()`: no list → label from the name, else from `raw_text` if it
  mentions the name. The line gets `variant_label`, `variant_kind = size`,
  `name/base_name = stripSize(name)`, `raw_name = base + " (размер XL)"`.
- `rematchReport()` («Подобрать по каталогу / нейросетью») finds the size for a
  row that has no label: from `raw_name`, else from the letter line
  (`requirementText()`, which now also falls back to `mentions()`), and stores
  `variant_label`. `resolveRow()` then moves the row onto the modification.

### 1.3 Ranking like a full-text search
`ProductMatcher::rankedCandidates()`:
- the query is compared without the size (`stripSize`), the product by its name
  without brackets (`base_text`: «(Размер: XL)» is not part of the name);
- **exact**: every key word of the query (content words ≥ 4 letters + markers
  like «бр3») is in the name AND every key word of the name is in the query →
  score floor `EXACT_PRODUCT = 0.97` for a product, `EXACT_VARIANT = 0.9` for a
  modification (below the product by more than `MATCH_EQUAL_DELTA`, so the
  product wins and the size is picked by `resolveRow()`);
- otherwise a NAME hit is multiplied by `0.9 + 0.1 × share of the name's words
  the query contains` — «Боковая» is a word the client did not write.
  Description hits are not touched (they rank below by `rank` anyway).

### 1.4 The «начните печатать» search
`products.php?action=search` → `ProductMatcher::search(string $q, int $limit)`.
SQLite `lower()` folds ASCII only, so «плита» never found «Плита». The new
search folds case and «ё» in PHP; every query word must occur (as a substring)
in the name, article, code, characteristics or description; order: found in
name/article first, then by the share of the name's words the query covers,
then in stock, then shorter name. The description is read only for rows whose
name does not already hold every word.

## 2. #119 — the card moves by itself

`Boards::advance(?int $cpId, ?string $threadKey, string $kind)` covers the whole
deal. Rank: `work` 1, `kp_sent` 2, `payment` 3, `assembly` 4, `shipped` 5;
inbox, closed and custom columns 0. Forward only; a dismissed card comes back.
`work` moves a card only out of the intake column (a column the manager dragged
it into, or «Закрыто», is left alone — module 033).

| Event | Stage | Where |
|---|---|---|
| draft saved | `work` «В работе» | `Boards::draftCard()` (module 033) |
| match started: `items_save`, `items_choose`, `items_rematch`, `mail.php?action=make_request` | `work` | `Boards::workStarted(int $requestId)` |
| letter with a КП attached sent | `kp_sent` | module 056 |
| new invoice (our button, a sync, a webhook) with `moment` ≤ `MsSync::FRESH_DAYS` (14) days old | `payment` | `MsSync::upsertInvoice()` |
| invoice sent | `payment` | module 056 |
| paid (МойСклад `payed_sum`, Т-Банк payment) | `assembly` | `Fulfillment::toAssembly()` → `advance()` (was `addCard`, which also moved cards back) |
| new МойСклад shipment ≤ 14 days old | `shipped` «Отправлено» | `Fulfillment::demand()` |

`STAGES['shipped'] = ['Отправлено', '#3d7bd9', '/^(отправлен|отгружен)/iu']`; a
new column is inserted after `assembly`, before `closed`. `saveColumn()` knows
the kind. Documents older than 14 days (first sync of an old company) move
nothing. Manual moves stay: stage buttons on the card and drag on the board; a
later event of a higher rank moves the card on from wherever it was put.

## 3. #119 — the company card

- The side tabs «Информация» and «Заметки, заказы и счета» are gone.
  «Информация» (ИНН, domain, МойСклад, organisations for the invoice, contacts)
  opens in a window by clicking the company name in the header
  (`App.companyInfo()`, `companyInfoHtml()`, `refreshCompanyInfo()`).
- «Заметка на доске» is gone everywhere: the company card, the board card, the
  list row and the card menu. `board_cards.note` stays in the schema, unused.
- Removed: the «Переписка (?)» heading, «✉ Написать новое письмо» and «Переписка
  выше разворачивается нажатием». With no conversation the composer still
  stands open (`newLetterHtml`).
- Notes for colleagues: «⋯ → 📝 Заметка для коллег» (`App.noteForm()`), shown in
  the timeline.
- The stage bar has a «?» (`App.HINTS.stage`) that lists the automatic rules.

### Documents in the timeline
`Crm::documents()` returns orders, invoices, shipments (`order_demands` joined
to their order: title «Отгрузка N», track and service in `state_name`) and
incoming payments (`bank_payments` with a МойСклад payment). Each row:
`doc, id, title, sum, state_name, url (МойСклад), pdf_url?, request_id, created_at`.

`App.loadCompanyFeed(id)` reads `counterparties.php?action=chat` (documents +
notes + deal events, minus mail events), `App.placeFeed()` places the rows:
a row whose `request_id` belongs to a conversation goes INSIDE it between the
letters by date (`[data-tmsg][data-date]`), the rest goes between conversations
by their last date (`.conv[data-last]`). Rows are re-placed, conversations are
not re-rendered. `feedRow()`: link to МойСклад (editing only there), sum, date,
status; 👁 and «📎 В письмо» for order, invoice and shipment.

- `MoySklad::exportPdf(string $entity, string $id, string $wantedTemplate = '')`
  — any document's print form (`exportInvoicePdf()` calls it). A МойСклад that
  does not answer returns null with `lastExportError()`, never throws.
- `MsSync::docPdf(string $doc, int $id): array{path,name,error}` — invoice via
  `ensureInvoicePdf()`, order/shipment cached in `storage/docs/<doc>-<msid>.pdf`.
  `MsSync::$fetchPdf` replaces the export in tests.
- `counterparties.php?action=doc_pdf&doc=&id=` — the PDF inline; no form →
  an HTML page with the reason (it is shown in a frame).
- `mail.php?action=attach_doc` accepts `order` and `demand`.
- `App.previewDoc(doc, id, title)` — the PDF in a wide window with «📎 В письмо».

### The invoice button next to «Сформировать КП»
`requests.php?action=get` returns `ms` = `Crm::moyskladHint()` (no network),
`linked` also true when an organisation of the card is linked. The thread's
match block keeps it in `kp.ms`; `setKpButtons()` preserves it.

`App.invoiceButton()` — «🧾 Выставить счёт», or «🧾 Завести контрагента и
выставить счёт» when `kp.ms.linked` is false. `App.invoiceFromMatch()`:
1. not linked → the «Контрагент в МойСклад» window (`msCreateForm`), and after
   it the button becomes «Выставить счёт» (it does not issue by itself);
2. linked → the КП is built if missing (`generateKP(…, {open: false})`, returns
   the id), then `kpInvoice()`. The invoice appears under the КП buttons with
   its МойСклад link, 👁, ⬇ PDF and «📎 В письмо»; the timeline reloads.

## 4. #116 — deleting a letter and where the card opens

- `deleteMail(id)` always goes to `#mail/board` after a delete.
- `App.focusOnOpen(cpId, key)`: the first time this browser opens the company —
  the last letter (`focusLastLetter`); again — the reply editor of the newest
  conversation (`focusReply`). Visited ids live in `localStorage.cpSeen`
  (≤ 300, a per-viewer convenience; private mode = always «first time»).
- `App.pinScroll(el, block)`: blocks drawn above later (stage bar, notices,
  МойСклад sync) used to push the target off screen. A `ResizeObserver` on
  `#app` re-scrolls it for 4 s or until the first wheel/touch/key/mouse.

## 5. #117 — windows on a phone

A `position: fixed` window is laid out on the layout viewport; with the page
zoomed (pinched, or zoomed by the browser) it sat right of and below what the
phone shows. `App.fitModal()` places `#modal` on `visualViewport` (offset and
size) and counter-scales it (`scale(1 / vv.scale)`), on open and on every
`visualViewport` resize/scroll; unzoomed it clears its inline style.
`.modal__box` max height is `85%` of the window, not `85vh`. The model select
(`#cmpModel`, `#rethinkModel`) no longer widens its row.

## Tests
`php tests/module_062.php`.
