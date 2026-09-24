# Module 051 — WYSIWYG КП editor, pages on the sheet, optional signature, slow models

Source: the request of 2026-09-24 (five points): bold unanswered rows in the
list, page breaks and sizing of the A4 view, a real WYSIWYG editor that keeps
texts for the next КП, the LLM timeout during mail sync, the signature under
the КП.

## 1. «Список»: new and unanswered rows are bold

A row of `#mail/list` is bold (`.grow--unread`: company, subject, date on a
white background) when the card has unread letters **or** is unanswered
(`card.unread || card.unanswered` — the board's own «ждёт ответа» rule, which
«Прочитано» clears through `seen_at`). The sidebar counter counts the same
cards («новых и неотвеченных»).

## 2. The КП window

- The opened КП (`.kp-open`) is a plain block, not `.card--inline` (a wrapping
  flex row): the sheet always takes the full width of the column. Before this
  the width followed the length of the status line, so the first edit
  («Есть несохранённые правки.») collapsed the sheet into a narrow column.
- Height: a grip under the frames (`.kp-grip`, pointer drag, keyboard ↑/↓)
  resizes the A4 sheet and the PDF frame together; the height (vh) is
  remembered on this device (`localStorage.kpHeight`, as before). The range
  slider stays as a second way. During a drag the frames ignore the pointer,
  otherwise the iframe swallows the mouse and the drag stops.
- PDF view: the same width as the A4 view (100 % of the block) and opened at
  the sheet's zoom (`#zoom=<A4 zoom × 100>` for the browser's PDF viewer), so
  one page has the same width in both views.

## 3. «Редактировать вручную» — the WYSIWYG editor

The button «📝 Страница A4» becomes **«✎ Редактировать вручную»**; «PDF» stays
the second view. «✎ Текст по полям» and its API (`doc_text`, `doc_text_save`)
are removed: the editor covers it and keeps the texts for the next КП (§3.3).

### 3.1 Toolbar

A toolbar over the sheet (`.kp-tb`, `role=toolbar`, sticky), acting on the
frame document (`designMode`, `execCommand`, `styleWithCSS = false` so the
output is tags Html2Docx and mPDF understand):

| Group | Buttons |
|---|---|
| History | ↶ Отменить (Ctrl+Z), ↷ Повторить (Ctrl+Y) |
| Paragraph | select: Обычный текст, Заголовок 1, Заголовок 2, Заголовок 3 |
| Text | **Ж** (Ctrl+B), *К* (Ctrl+I), Ч (Ctrl+U), ~~З~~ |
| Colour | Чёрный, Красный `#C00000`, Серый `#777777` |
| Align | влево, по центру, вправо, по ширине |
| Lists | маркированный, нумерованный |
| Insert | 🔗 ссылка / убрать ссылку, ⤓ разрыв страницы |
| Clean | ⌫ очистить оформление |

Buttons reflect the state of the selection (`aria-pressed`,
`queryCommandState`) on `selectionchange`. Ctrl+S saves. Paste is cleaned:
HTML from Word/sites keeps only `p, br, b, strong, i, em, u, s, ul, ol, li,
h1–h3, a[href]` (no classes, styles, fonts); everything else becomes text.

A page break is `<div class="kp-page-break" style="page-break-before:always">`:
mPDF breaks on the inline style, Html2Docx on the class (like `appendix`,
`card--break`); in the editor it is an atom (`contenteditable=false`, added at
load and stripped on save) drawn as a dashed line.

Html2Docx additionally understands `text-align: justify` (`w:jc both`) and a
text colour from `<font color>` / `style="color:…"` (hex or `rgb()`).

### 3.2 Pages on the sheet

The A4 view shows the document as pages: after every layout change (load,
images loaded, typing — debounced) the editor walks the flow and inserts
spacers (`[data-kp-editor-ui]`, `contenteditable=false`) where mPDF would
break a page:

- page = A4 with mPDF margins: content height 297 − 10 − 15 = 272 mm;
- units: a block without block children, a table row, a list item; a unit
  that crosses the page bottom moves to the next page whole (a unit taller
  than a third of a page is split by lines in the PDF — it stays and the
  count goes on);
- forced breaks: `.appendix`, `.card--break`, `.kp-page-break`, or a computed
  `page-break-before/break-before: page`;
- a spacer draws the rest of the page, the bottom margin, a grey gap with
  «стр. N из M», and the next page's top margin; in a table it is a row with
  one cell over all columns;
- the sheet's minimum height is a whole number of pages.

The editor loads DejaVu Sans (regular, bold, italics) from the mPDF font
folder (`proposals.php?action=font&f=…`, whitelist, cached for a year), so
lines wrap as in the PDF. Positions are measured in the sheet's own pixels
(divided by the current zoom), so the zoom never changes the result.

Spacers never reach the server: the client strips `[data-kp-editor-ui]`
before saving, `KpEditor::sanitize()` strips them again.

### 3.3 Texts for the next КП

The editor page is rendered with field marks (`PdfGenerator::html($id, true)`;
the PDF/Word path renders exactly as before):

| `data-kp-field` | Printed as | Stored in the КП | Becomes the default for the next КП |
|---|---|---|---|
| `intro_text` | intro | `proposals.intro_text` | `settings.kp_intro_template` |
| `pre_table_text` | text before the table | `proposals.pre_table_text` | `corrections` (`pre_table`), read by `KpSet::create()` |
| `post_table_text` | text after the table | `proposals.post_table_text` | `corrections` (`post_table`) |
| `terms_text` | terms | `proposals.terms_text` | `settings.default_terms_text` (`KpTerms::remember`) |
| `unmatched_note` | note over unmatched positions | — | `KP_UNMATCHED_NOTE` |
| `images_note` | note under photos (every card) | `proposals.images_note` | `settings.kp_images_note` |
| `upsell_intro` / `upsell_note` | upsell texts | `proposals.upsell_*` | `settings.kp_upsell_*` |

Empty text fields are still printed in the editor (with a grey hint via CSS
`:empty::before`) so the manager can type into their place; the PDF/Word
path drops empty marked fields (`KpEditor::internalize()`).

Placeholders survive editing: a filled value is a
`<span data-kp-var="name" data-kp-val="value">value</span>` (an empty value
holds a zero-width space, stripped before print). On save
`KpFields::extract()` reads every field back to text (`<br>` and blocks →
newlines); a var span whose text still equals `data-kp-val` becomes
`{name}` again, an edited one stays literal text.

Vars: terms — `{execution_term}`, `{execution_days}`, `{validity_days}`,
`{delivery_in_price}`, `{delivery_separate_clause}` (`KpTerms::vars()`);
intro — `{seller}`, `{by_request}` («по запросу <покупатель>» or «по Вашему
запросу»). The intro template default:
`{seller} {by_request} имеет возможность поставить следующее вещевое имущество:`
(the same text `PdfGenerator::defaultIntro()` printed; it is now filled from
the template).

`KpFields::apply($proposalId, $fields, $managerId)` compares each extracted
text with what was printed (the КП's own value or the default); only a
changed field is written to the КП and remembered as the default. The save
answers `learned: [labels]`, and the toast names them. The override page is
stored as before, so the sheet prints exactly what was typed; «Вернуть
автоматическую сборку» now rebuilds with the edited texts.

## 4. Slow model ≠ filter

`LLM_TIMEOUT_SEC` default 30 → 90 (migration v46 lifts a stored 30 — the old
default saved by the form — to 90). A connection limit of its own
(`CURLOPT_CONNECTTIMEOUT`, ≤ 15 s) keeps a dead route failing fast.

A timeout after the request was sent (`CURLE_OPERATION_TIMEDOUT` with
`CURLINFO_PRETRANSFER_TIME > 0`) is explained as «модель не успела ответить
за N с — запрос дошёл, это не фильтр; увеличьте «Таймаут запроса» или
выберите модель быстрее», never as a filter on the way. A connection that
never happened stays the filter/proxy explanation.

Qwen3 models (slug contains `qwen3`) get `/no_think` at the end of the system
prompt: their default reasoning costs tens of seconds before the first byte
and is stripped from the answer anyway.

## 5. Signature under the КП

`managers.kp_signature_mode`: `none` (default) | `own` | `company`.

| Mode | Image | Name |
|---|---|---|
| `none` | — | — (the line is the date alone) |
| `own` | the manager's own | own «Расшифровка», else the manager's name |
| `company` | the company image | `Signatures::companyName()` |

No more fallback from the manager to the company: nothing chosen → no
signature. A КП without a manager is `none`. Migration v46: a manager who
already has an own name or image gets `own`, everyone else `none`.

The `_____________________________` line is removed from the template, the
settings preview and the texts. Printed line: `<date> [image] <name>`.

«Настройки → Подпись → Подпись под КП»: a radio group «Без подписи / Моя
подпись / Подпись организации», then the «Расшифровка» field and the image
(for «Моя подпись»), and the preview. API:
`settings.php?action=signature_mode` (POST `{mode}`), `Signatures::describe()`
returns `mode`.
