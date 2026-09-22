# Module 046 — issue #67: the КП document, the letter's blocks on a phone, Bitrix descriptions

Source: GitHub issue #67 (titled «61») and the follow-up request «предпросмотр
можно масштабировать пальцами или колёсиком мышки».

## 1. The КП document (PDF and Word — one HTML, `templates/kp.html`)

PDF and Word are built from the same HTML (`PdfGenerator::html()` →
mPDF / `Html2Docx`), so every rule below holds in both.

- **Money without «,00»**: `KpContent::rub(float)` — whole roubles print
  without kopecks («1 800 руб.»), otherwise two decimals («500,50 руб.»).
  Used for every price, sum, delivery, «Итого»/НДС lines, addons, and the КП
  letter text (`KpText::money()`).
- **Price range** «от X до Y» only when the line is a general product whose
  modifications cost differently: `price_max > price` by at least a kopeck
  (`KpContent::hasRange()`). `price_max == price` (a single product, or
  modifications at one price) prints one price. `price_from` («от» by hand)
  still prints «от X».
- **Discount**: «Цена за ед.» shows the original price struck through
  (`<span class="was">`) when the line has a discount; «Со скидкой» shows the
  actual price. The discount itself is never struck — it is grey
  (`.disc`, `#8a8a8a`):
  - one discount for every discounted line → header «Со скидкой N%», cells
    hold the price only;
  - different discounts → header «Со скидкой», each cell `-N%` (grey) on its
    own line above the price.
  - no discounted line at all → the column is not printed.
  `PdfGenerator::discountColumn(items)` → `{show, uniform}`.
- **VAT label** follows the setting: a VAT-paying organisation whose
  catalogue rows carry `vat = 0` (MoySklad `vatEnabled: false` — «not set on
  the product») is treated as «catalogue silent», so the rate comes from
  `default_vat_rate` and the column reads «в т.ч. НДС N%» under
  `KP_VAT_MODE = included`. Sync prefers `effectiveVat` / `effectiveVatEnabled`
  (the rate inherited from the product group) when MoySklad sends them.
- **Intro**: «{seller} по запросу {buyer} имеет возможность поставить следующее
  вещевое имущество:» — seller is the short name of the organisation
  (MoySklad snapshot, then `legal_entities`), buyer is the client's legal name
  from the snapshot (`buyer.legal_title`, then `buyer.name`). No buyer →
  «{seller} по Вашему запросу имеет возможность…». A hand-written
  `intro_text` still wins.
- **No «Покупатель» block** at the end; only the contract line remains.
- **Header**: a borderless layout table — logo cell left, seller requisites
  right-aligned (`table.header-grid`). mPDF does not flow lines beside a
  floated image, which left a visual gap after the first header line. Word
  prints `table.header-grid` without borders (`Html2Docx` class `layout`).
- **Photos**: the gallery floats right with a 20 px margin to the text and
  8 px between photos.
- **Word parity**: `Html2Docx` prints `table.layout` without borders (the
  header keeps its bottom rule), `<s>`/`.was` struck through, `.disc` grey;
  logo and QR are inline images in their own cells.
- **QR and site link**: after the card text, one empty line, then a layout
  table: QR on the left, «Подробнее на сайте: URL» to its right.

## 2. «Не наша номенклатура» — a toggle of the КП, rows in the match table's order

- `proposals.show_out_of_scope` (INTEGER, NULL = follow the setting; migration
  v42): the checkbox «Показать в КП отсутствующую номенклатуру (N)» above the
  A4 page of the КП (`App.kpToggleOutOfScope()` → `proposals.php?action=update`,
  which rebuilds the document and drops a hand edit). Shown only when the
  request has such rows. `KP_SHOW_OUT_OF_SCOPE` (default 0) is the value for a
  КП whose checkbox was never touched — and then only the request's first КП.
- `KpContent::outOfScopeRows($proposal)` → `{requested, quantity, unit, position}`.
- `KpContent::interleave($items, $outOfScope)` → `[{kind: item|out, row}]`: each
  КП line is placed by the position of its request row (`request_item_id`);
  out-of-scope rows go before the first line whose position is greater, the
  rest at the end. The template numbers only real lines.
- Printed: name grey italic (`<em class="out-of-scope__name">`), «—» in every
  other cell, not in «Итого». Word: `out-of-scope__name` → italic, `8A8A8A`.
- `proposals.php?action=html` returns `out_of_scope: {count, shown}`.

## 3. The letter's blocks: collapsible, remembered, tinted

- `[data-block="<name>"]` marks a block: `thread` (the letters of a
  conversation, head «Письма · N»), `items` (the match panel), `composer`,
  `kp` (the opened КП), `info` («Информация» on the company card, the facts
  card on the letter page), `events` («Заметки, заказы и счета»), `ms` (the
  MoySklad invoices under the composer).
- Foldable: `thread`, `info`, `events`, `ms` — `App.bindBlocks()` adds a ▾/▸
  button to the block head (`[data-block-head]` or `.card__title`); a
  `MutationObserver` (`App.watchBlocks()`) binds blocks as they are drawn.
  `.is-folded` hides everything but the head. `items` keeps its own fold
  (`toggleMatchFold`, auto-fold after a sent КП) and stores it the same way; a
  stored state wins over the auto-fold.
- State per letter: `localStorage['kp.fold.<key>']` = `{name: 0|1}`; key = the
  thread key on the letter page and of the open conversation on the company
  card (`App.setFoldKey()` re-applies on switching), `cp:<id>` before one is
  open. Unavailable storage → defaults.
- `App.foldGet('info')` is always «folded» on a phone (≤ 640 px).
- Phone order: the one-column layout already reads correspondence → match
  panel → composer → КП → information → notes; the stage row
  (`#threadPlacement` / `#cardPlacement`) moves to the very bottom
  (`#app:has(> .letter)` becomes a flex column, the stage gets `order: 10`).
- Tints (`--tint-*` tokens; the app has a light theme only): a 4 px left
  stripe on the block and a 9 % tint of the head — thread blue, items green,
  composer amber, КП violet, information cyan, notes slate, MoySklad pink.

## 4. Photo picker: reset

«Сбросить выбор» next to «В КП пойдут отмеченные (N из M)» on a match row and in
the full КП editor (`App.resetPhotos()`): unticks every photo. On a match row
the empty selection is saved at once (`item_images_save`, cascades to unsent
КП) — the КП prints no photo for that line until the manager ticks the needed
ones. `App.photoCount()` keeps the counter and the button state live.

## 5. «Открыть» and the preview

- «Открыть» opens the A4 page under the letter; on a phone it opens in the
  match panel's own slot, right under the КП board where the button is.
- Zoom of the A4 page: Ctrl/⌘ + wheel (a trackpad pinch is the same event in
  Chrome/Firefox/Edge), Safari `gesturechange`, a two-finger pinch on touch
  screens, buttons «−» / «N%» (fit width ⇄ 100 %) / «+». Range 30–300 %.
  CSS `zoom` on the page's root inside the frame; removed before saving, so it
  never reaches the document. Default — fit to width (≤ 100 %); the last zoom
  is kept per device (`localStorage.kpZoom`). A plain wheel keeps scrolling.

## 6. Descriptions from Bitrix

- The site module's `export` rows carry `DESCRIPTION` (`DETAIL_TEXT`, else
  `PREVIEW_TEXT`; an offer without one takes its product's). `Bitrix::parseExport()`
  returns it, `applyExported()` stores it as Markdown in
  `products_cache.site_description` (migration v42).
- `KP_DESCRIPTION_SOURCE`: `moysklad_first` (default) | `bitrix_first`.
  `KpContent::pickDescription($moysklad, $site)` — the first non-empty one in
  that order. Used by `RequestItems::catalogDescriptions()` (the match panel
  and manual picks) and `KpContent::enrichItems()` (the КП card); a variant
  inherits both texts from its product.

## 7. Bitrix module: Excel export (module 1.1.0)

- Module settings page: «Экспорт товаров в Excel» — downloads `.xlsx`
  (`Atlant\KpSync\Export::download()`), columns: Внешний код (`XML_ID`),
  Название товара, Модификации и характеристики (one offer per line:
  «Название — Свойство: значение; …», file/link properties skipped), Описание
  (plain text), Ссылка на сайте (absolute). Same iblocks and «только активные».
- `kp.php?token=…&action=export_xlsx` — the same file (needs a module reinstall
  to copy the new endpoint into `/bitrix/tools`).
- `Atlant\KpSync\Xlsx::write($head, $rows, $widths)` — a pure writer
  (`ZipArchive`, inline strings, bold frozen header, wrapped cells).

## 8. Client requirements in the КП text

- `parse_request` / `classify_request` return `kp_requirements`: short phrases
  of what the client asked to be STATED in the КП (country of origin, warranty,
  certificates, a deadline…). Stored with the rest in `requests.parsed_json`.
- `KpRequirements::of($requestId)` — the list, trimmed, deduplicated, ≤ 12.
- `KpRequirements::apply($proposalId)` on «Сформировать КП», after the lines
  are in: the `kp_requirements` prompt (knowledge base task of the same name)
  gets the requirements, the КП terms and the lines and returns
  `{text}` — one sentence per requirement from those facts only, otherwise
  «… — уточним и сообщим дополнительно». Appended to `post_table_text` (the
  block under the table, editable on the A4 page and in «Текст по полям»). A
  model failure is logged and the КП is built without the paragraph.
- The A4 page bar lists «Клиент просил указать в КП: …» so the manager checks
  the paragraph (`proposals.php?action=html` → `requirements`).
