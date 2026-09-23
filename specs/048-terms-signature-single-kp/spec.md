# Module 048 — КП terms and signature order, signature settings, one КП per request

Source: the request of 2026-09-23 (four points: the terms block, the signature
settings, Word/PDF parity, multiple КП).

## 1. Terms block: delivery placeholders and the order of the ending

### Placeholders

`KpTerms::FACTORY_TEXT` carries two delivery placeholders, filled by
`KpTerms::fill()` from the КП's delivery mode (`DeliveryShare::mode()`, module 049; `separate` has its own clause there):

| Placeholder | `included` (delivery in the goods price) | `line` (separate) |
|---|---|---|
| `{delivery_in_price}` | `доставку, ` | empty |
| `{delivery_separate_clause}` | empty | `Доставка в стоимость не включена и оплачивается при получении по тарифам СДЭК.` + newline |

Texts stored before the placeholders existed (the default in `settings` and
every unsent КП) still said «погрузку, … Доставка в стоимость не включена и
считается отдельно(й строкой).» — sometimes on one line, sometimes split, so
the word-for-word migrations of v33/v38 missed them.
`KpTerms::upgradeLegacy(string): string` rewrites them by pattern:

- `хранение, погрузку, подготовку` → `хранение, {delivery_in_price}подготовку`;
- any sentence `Доставка в стоимость не включена и …` (the old «считается
  отдельно», «считается отдельной строкой», or the fixed СДЭК wording) →
  `{delivery_separate_clause}` at the start of its own line.

Migration v44 applies it to `settings.default_terms_text` and to
`proposals.terms_text` of every КП not in `sent` / `order_created`. A sent
document is never rewritten.

### Order of the ending (template and Word)

```
<terms block>
[Договор …]                        (when printed)
[Дополнительные модули …]          (when printed)

Более подробное описание товаров приведено в приложении №1.   (only with an appendix)

<date> <signature image> _____________________________ <signatory>
```

The date and signature line is the LAST line before the appendix. The blank
lines are real empty paragraphs (`p.title-gap`) — Word drops CSS margins.
`.docx` is built from the same HTML, so the order is the same in Word.

A КП edited by hand on the A4 page (`html_override`) prints what was saved;
«Вернуть автоматическую сборку» brings the new order in.

## 2. Signature settings: the «Подпись» tab

`Настройки → Подпись` (open to every manager) holds, each card loaded on its
own so one failing request never hides the others:

1. **Подпись под КП** (mine): «Расшифровка» field, image upload/remove, and a
   preview of the printed line: `23.09.2026г. [image] _____ <name>`.
2. **Подпись организации** (admin only): the name and the image used when a
   manager has none of their own.
   - name → `settings.kp_signatory_name`; image → `legal_entities.signature_path`
     (`storage/signatures/signature.<ext>`);
   - admin API: `admin.php?action=company_signature` (GET describe / POST
     `{signatory_name}`), `company_signature_reset` (drops the image),
     `settings.php?action=company_signature_image` (admin only).
3. **Подпись в письмах** — unchanged (module 039), moved here.

«Мой звук уведомления» moves to «Это устройство». «Оформление КП» links to the
new tab instead of carrying the card.

`Signatures::forManager()` resolves the name in this order: the manager's own →
`kp_signatory_name` → the MoySklad signatory (`legal_entities.signatory_name`) →
`Signatures::DEFAULT_NAME`. The image: the manager's own → the company one.
`kp_signatory_name` is a separate setting because the requisites sync
overwrites `legal_entities.signatory_name` from MoySklad.

## 3. Word and PDF look the same

- «Не наша номенклатура» row: the client's name in grey (`#8a8a8a`), no italics,
  in both formats (`<span class="out-of-scope__name">`; Html2Docx: colour only).
- Every product card of «Приложение №1» starts on its own page in both formats:
  the first card follows the «Приложение №1» title (which itself starts a
  page), every next card has `card--break` (PDF: `page-break-before: always`;
  Word: a page-break run). The `KP_PAGE_BREAK` setting is removed — the
  break is no longer optional.

## 4. One КП per request

A request has ONE working КП — the newest one (`ORDER BY id DESC LIMIT 1`).
The КП board (columns, drag-and-drop, «+ Ещё одно КП», rename, the pool of
unplaced positions) is removed with its API (`board`, `add`, `move_item`,
`add_item`, `rename`) and `KpSet::board/moveItem/addFromRequest/removeItem/
rename/title/place/nextPosition`. `proposals.label` stays in the table (old
data), nothing reads or writes it.

Buttons under the match table:

- no КП yet: **Сформировать КП**;
- a КП exists: **🔄 Пересобрать · Открыть · ⬇ Word · ⬇ PDF · 🧾 Счёт · Убрать**;
  existing invoices of the КП are listed under the buttons (name, sum, PDF).

`proposals.php?action=rebuild&id=N` — «🔄 Пересобрать»: the КП's positions are
rebuilt from the match table (`KpSet::rebuildItems()`), delivery is taken from
the table, a hand edit of the A4 page is dropped (the page is built from the
data again), the PDF is regenerated. A КП already sent or turned into an order
is not rewritten: a new КП is created from the table instead
(`KpSet::create()` + items) and becomes the working one; the sent one stays in
the database as it was signed.

«Убрать» = `proposals.php?action=delete` (unchanged rules: not sent, no
invoice). After it the line shows «Сформировать КП» again.

`KpContent::outOfScopeRows()` no longer limits «не наша номенклатура» to the
request's first КП — there is only one working КП.

## 5. Addon folder («Папка модулей в МойСклад»)

The setting names a MoySklad product folder (default «Модули для
бронежилетов»). Products of that folder get `products_cache.is_addon = 1`; a
new КП is seeded with up to 12 of them (cheapest first, minus what is already
in the КП) as the «Дополнительные модули и доукомплектование» table, printed
when «Допродажа» is on for the КП.

`MoySklad::refreshProductCache()` / the variant sync read the folder name from
a map of `/entity/productfolder` (id → name), since list requests return
`productFolder` as a bare reference without its name; before this the folder
name was always empty and nothing was flagged from MoySklad (only the Excel
import set `category`).

Saving «Оформление КП» (`settings.php?action=general`, PUT) re-flags the cache
at once when `addon_category` changes (`is_addon = category = <folder>`), and
writes only the keys of `generalSettings()` — any other key in the body is
ignored (before this a manager could write any `settings` row, admin `cfg.*`
included).
