# Module 045 — the rest of issue #60: letter wording, delivery in the invoice, the КП page editor, the editor layout

Source: GitHub issue #60. Modules 042 and 044 took the first two batches; an
audit of the issue against the code found requests that were neither done nor
listed in `TODO.md`. This module takes those and most of what `TODO.md` held.

## 1. The letter names availability, not stock numbers; links read «см. на сайте»

Issue: «в письме не надо указывать полное число в наличии, но надо дать ссылку
на сайт в виде текста "см. на сайте", и если мы в КП прописываем скидки, время
ожидания и т.д. — это тоже все надо прописывать в письме».

- `RequestItems::matchedBlock()` (the «what we offer» block of the reply
  prompt) no longer prints `в наличии N`. `RequestItems::stockWords($stock,
  $qty)` gives «в наличии», «в наличии не всё количество — остальное под
  заказ» or «под заказ». It adds the manual discount («цена до скидки X руб.,
  скидка N%») and, under the position, `см. на сайте: <url>` from
  `products_cache.site_url` (`RequestItems::siteUrl()`, variant → parent). The
  block tells the model to keep discounts and wait terms and to copy the link
  line verbatim.
- `Catalog::block()` (`{{catalog}}`): «в наличии» / «нет в наличии», no number.
- The cover letter (`RequestParser::coverLetterLists()` →
  `coverLetterLine()`): each position carries its discount, `Terms::note()`
  (wait terms) and the `см. на сайте` line; the user message says to keep them.
- The КП as letter text (`KpText::render()`): the manual discount is written
  under the line; the product link is `см. на сайте: <url>`.
- `MailText::textToHtml($text)` — one text→HTML conversion for outgoing mail:
  paragraphs, line breaks, `см. на сайте: <url>` → `<a href=url>см. на сайте</a>`,
  other URLs → links. Used by `MailCompose::send()` (text-only letters),
  `proposals.php?action=send`, `KpText::render()`. `App.draftHtml()` does the
  same when a model draft is put into the editor.

## 2. Delivery goes into item prices in the invoice too

Issue: «доставку можно было включить в стоимость товаров при выставлении КП и
счета». Module 042 spread it over the КП lines only, and the invoice created from
a КП ignored delivery entirely — the invoice total differed from the КП total.

`lib/delivery_share.php`, `DeliveryShare`:

- `included(array $proposal): bool` — delivery on, price > 0,
  `KP_DELIVERY_MODE = included`.
- `perUnit(list<{unit,qty}>, float $amount): list<float>` — per-unit increments
  proportional to line sums, rounded to kopecks. The remainder goes to a line
  with quantity 1 when there is one (exact), else to the last line.
- `invoicePositions(array $proposal, array $rows): {positions, skipped,
  delivery_missing}` — the MoySklad positions of a КП. Discount is the total one
  the КП printed (manual AND wait discount — the invoice used to drop the wait
  discount); with delivery included the pre-discount price grows so that the
  unit costs `Terms::price + share`. With delivery as a separate line, a
  service position is added when `MS_DELIVERY_SERVICE_ID` is set, otherwise
  `delivery_missing = true` and the UI says so.

Used by `invoices.php?action=create_from_proposal` and `orders.php?action=create`.
`MoySklad::createOrder()` now passes `discount` too, and both create calls accept
`type = service` on a position (`assortmentMeta($id, $type)`).

The КП file (`PdfGenerator::html()`) and the КП text use the same `perUnit()`:
the share now goes into the **unit** price («Цена за ед.» and «Со скидкой»),
not only the line sum, so price × quantity matches «Сумма». No lines to spread
over → delivery prints as its own row instead of vanishing.

## 3. «Не наша номенклатура» in the КП table

Issue: «если мы отметили что-то как "не наша номенклатура", надо добавить её в
КП жирным серым цветом и прочерк (тире) во всех остальных ячейках».

- `KpContent::outOfScopeRows(array $proposal)` — `RequestItems::outOfScope()` of
  the request, only for the request's FIRST КП (a split request must not list
  the refusal twice), and only with `KP_SHOW_OUT_OF_SCOPE = 1` (default).
- `templates/kp.html`: after the items, `<tr class="out-of-scope">` — «—» in №,
  quantity, price, discount and sum; the client's wording bold grey. Not in
  «Итого». Word: `out-of-scope`, `out-of-scope__name` in `Html2Docx::styleOf()`.
- `KpText`: `— <wording> — не поставляем`.
- The reply prompt still follows `SCOPE_REPLY_MODE` (module 022).

## 4. The MoySklad invoice print form

Issue: «Счёт … в МойСклад есть опция "Печать" → "Счет покупателю с печатью с QR
и с подписью"». `MoySklad::exportInvoicePdf()` used the first template of the
account. New setting `MS_INVOICE_TEMPLATE` (default «Счет покупателю с печатью
с QR и с подписью»); `MoySklad::pickTemplate($rows, $name)` — exact name
(case-, ё- and space-insensitive), then a name containing it, else the first.

## 5. «Открыть» shows an editable A4 page

Issue: «по нажатии кнопки "Открыть" надо чтобы открывался предпросмотр в html
A4, который можно править, и потом экспортировать в PDF/Word».

- `proposals.html_override` / `html_override_at` (migration v41). While set,
  `PdfGenerator::html()` returns it (images restored) — so PDF, Word and the
  attachment all come from the hand-edited page.
- `lib/kp_editor.php`, `KpEditor`: `page($id)` — the document with its data:
  images swapped for `proposals.php?action=kp_img&h=<sha1>` (files in
  `data/kp_img/`), so the editor round-trips kilobytes; `internalize()` swaps
  them back; `sanitize()` strips scripts, frames, forms, event handlers,
  `contenteditable`, `javascript:` links and the editor's own style;
  `save()` (≤ 2 MB, must have `<body>`), `reset()`; `editable()` — not for a
  `sent` / `order_created` КП.
- API: `html` (GET), `html_save` (POST `{html}`), `html_reset` (POST), `kp_img`.
- UI (`App.openKp()`): «📝 Страница A4» (default) / «PDF». The page is an
  `iframe sandbox="allow-same-origin"` (no scripts) with `designMode = on`,
  shown as an A4 sheet. «💾 Сохранить правки»; «↺ Вернуть автоматическую
  сборку» once an edit exists; ⬇ Word / ⬇ PDF save a dirty page first.
- The hand edit stays until reset, and the bar says that changes in the match
  table will not reach it. Saving the field-by-field text editor
  (`doc_text_save`) or the full КП editor (`update`, anything besides the cover
  letter) rebuilds the document and clears the override.
- Fixes on the way: `proposals.php` never defined `$input`, so `doc_text_save`
  always answered «Нечего сохранять»; `Object.assign(div, {dataset: …})` threw
  (dataset is read-only), so «Открыть» never opened the КП under the letter —
  now `App.dataDiv(key)`.
- The delivery row of the match table says «включена в стоимость товаров» or
  «отдельная строка КП» after `KP_DELIVERY_MODE` (`settings.php?action=ui` →
  `delivery_mode`).

## 6. The match panel sits in the left column, above the letter

Issue: «перенести подбор товаров в левую колонку, над полем составления
письма… пусть подбор сворачивается после того как отправлено первое письмо с
КП, но всегда остаётся над полем письма».

- Letter page: `[data-thread-items]` moved from `.letter__side` to
  `.letter__main`, between the correspondence and the composer.
- Company card: `#cpItems` is moved into the open thread, right before its
  composer (`App.setCompanyItems()`), and parked back in the side column
  (`App.parkCompanyItems()`) when the thread closes or is redrawn.
- Fold: `host.dataset.folded` — set once per request from its КП: any КП
  `sent`/`order_created` → folded. `.card--folded` hides everything but the
  title; «▸ Развернуть (позиций: N)» / «▾ Свернуть» (`App.toggleMatchFold()`).
  Rows stay in the DOM, autosave keeps working.

## 7. The row controls where the issue put them

- 🚫 «не наша номенклатура» — right of «из письма: … · совпадение …% · …»
  (`.match-row__src`; a hand-added row shows «добавлено вручную»).
- × — right of the product-name input (`.match-row__nameline`, which also holds
  the suggest list).
- ⠿ ↑ ↓ — at the bottom of the row (`.match-row__tools--bottom`).

## 8. Support: button in sight, tickets on the board

- Header: «✉ Написать в поддержку» button, top right of the sticky header
  (icon only on a phone).
- «Письма» board, admins only: `App.loadBoardSupport()` lists the tickets
  awaiting review (`support.php?action=list&status=new`), up to five, linking
  to `#settings/support`. Nothing to review — no strip.

## 9. Correspondence rows like Gmail

`App.companyThreadRow()`: one line — sender (with 📥/📤 and the letter count),
subject — preview, date; chips below. Unread rows bold. Archive / delete /
«вернуть в работу» appear in place of the date on hover (always on a phone);
the separate tools row under every conversation is gone.

## 10. Lazy loading outside the match table

`App.htmlPreviewFrame()`: the letter iframe has `loading="lazy"` and every
`<img>` of the body gets `loading="lazy" decoding="async"`
(`App.lazyImages()`); support-ticket thumbnails too. Collapsed letters were
already mounted only when opened.

## Settings

| Key | Group | Default | Meaning |
|---|---|---|---|
| `MS_INVOICE_TEMPLATE` | moysklad | Счет покупателю с печатью с QR и с подписью | print form of the invoice |
| `MS_DELIVERY_SERVICE_ID` | moysklad | — | MoySklad service billed for delivery in `line` mode |
| `KP_SHOW_OUT_OF_SCOPE` | kp | 1 | print «не наша номенклатура» rows in the КП |

## Tests

`php tests/module_045.php`: `perUnit()` sums and kopecks, invoice positions
(included / line / service / missing, wait discount kept), the КП table with the
share in the unit price, out-of-scope rows (first КП only, setting off), letter
blocks without stock numbers and with «см. на сайте», `textToHtml()`, template
pick, the editor round trip (externalize → save → PDF source), sanitising, reset.
