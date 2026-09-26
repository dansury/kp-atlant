# 064 — Issues #123–#129: light and dark theme, the analogue that outlived its reason, our own requisites, the phone screens, notes on top

## 1. #123 — two themes, and a light theme that is light everywhere

The phone's own dark mode (Chrome «Тёмная тема для сайтов») darkened what the
page did not opt out of: the letter bodies are `srcdoc` iframes, and an
iframe does not inherit `color-scheme: only light` from the page — the body
of every letter came out black on a white screen.

- `html[data-theme]` = `light` | `dark`, chosen per device:
  `localStorage['theme']` = `light` | `dark` | `auto` (default `light`;
  `auto` follows `prefers-color-scheme`). An inline script in `index.php`
  applies it before the stylesheet paints — no white flash on a dark phone.
- Header button `#themeBtn` cycles light → dark → auto (☀ / 🌙 / 🌓), with the
  current state in its `title`. `App.setTheme(mode)` writes the store (wrapped
  in try/catch: a private window still switches, it just does not remember).
- `:root` keeps the light tokens and `color-scheme: only light` (the keyword
  that opts out of the browser's forced darkening). `html[data-theme="dark"]`
  redefines the same tokens (surfaces, ink, borders, tints of meaning) and
  `color-scheme: dark`; the hard-coded light tints of the stylesheet
  (unread rows, notices, badges, board highlights) get dark counterparts in the
  same block. Contrast is AA on both sides and stated in the CSS comment.
- Every iframe the app builds (`htmlPreviewFrame`, the КП sheet) declares
  `<meta name="color-scheme" content="only light">` and
  `:root{color-scheme:only light}`: a letter and a КП are PAPER — white in both
  themes, never darkened by the browser. In the dark theme the frame keeps its
  white page, framed by the dark card.
- `<meta name="theme-color">` follows the theme.

## 2. #124 — an analogue is dropped when the line stands on what the client asked for

Request #97 was matched before module 062: «Плита для бронежилета Бр3» was
judged out of stock (its own zero) and swapped for «Боковая плита … (Размер:
M)». The manager then put the row back on «Плита для бронежилета Бр3 (Размер:
XL)» — but `choose()` never touched the analogue fields and `save()` copied
`is_alternative` back from the browser, so the row kept saying «Аналог. Нет в
наличии» over the very product that was asked for, in stock.

- `RequestItems::clearAnalogue(): array` — the fields that make a row an
  analogue, emptied: `is_alternative = 0`, `alt_of = null`,
  `alt_specs_json = null`, and `notes` without the machine's «аналог: …» /
  «аналог из наличия».
- `choose()` (a product picked from the candidates) applies it: a product the
  manager picked is their choice, not our swap. The «аналог» checkbox can
  still be ticked again by hand.
- `save()`: when the product of a row CHANGED and the stored row carried a
  machine analogue (`alt_specs_json` set), the analogue fields are cleared
  whatever the browser sent.
- `RequestItems::healAnalogues(int $requestId): int`, run by `ensure()` on
  every open of the card, repairs rows written before this fix: a row with
  `is_alternative = 1` and `alt_specs_json` whose product is the ORIGINAL one
  (the `match_variants` entry «было подобрано, нет в наличии») or a
  modification of it is not an analogue — cleared. A row not confirmed by
  the manager whose original product (by its modifications, `Variants`) now
  has free stock goes back onto the original, on the client's size when the
  row has `variant_label` (`Variants::resolveRow()`).

## 3. #128 — our own requisites never identify a client

Yana answered «Вот сюда можете направить ваши реквизиты» with OUR requisites.
The draft card (`MailDrafts::facts()`) read the ИНН out of the letter body —
ours — and `Crm::findCounterparty()` matched it to a company card with our
ИНН: the conversation of `kk@dressie.ai` became «ООО 'АТЛАНТ АРМОР'».

- `Crm::ourInns(): string[]` — ИНН of every `legal_entities` row (cached per
  request).
- `Crm::requisitesFromText()` takes the first ИНН of the text that is NOT ours
  (all ИНН matches are walked; КПП/ОГРН keep their first match).
- `Crm::identityHints()` drops our ИНН and our own company name
  (`companyFromText()` already skipped it; a name passed in explicitly is
  checked too) — so neither `findCounterparty()` nor `resolveCounterparty()`
  can ever land on our own organisation.
- `find_inn` (the model's answer) and `moyskladHint()` never offer our ИНН.
- Healing (schema v54): `Crm::releaseOwnCards()` — a counterparty card whose
  ИНН is ours is our organisation, not a client. Its letters, requests and
  board cards are detached (`counterparty_id = NULL`; a board card becomes a
  thread card on the newest thread it held), so each conversation falls back
  to its own sender and can be linked to the right company. The card itself is
  kept (it may be linked to МойСклад) but is never matched again. Run once by
  the migration and again after `Requisites` syncs the организация.

## 4. #125 — the phone never stays zoomed out

After the support form was sent from a phone, Chrome left the page at a scale
below 1 — the whole app a third of the screen wide. The page is never wider
than the screen (checked: `scrollWidth = innerWidth`), the scale was left
over from the keyboard of the closed modal.

- viewport: `width=device-width, initial-scale=1, minimum-scale=1,
  viewport-fit=cover` — zooming IN still works, zooming out past the page
  does not exist.
- `App.closeModal()` blurs the focused field first: the keyboard goes away
  while the field still exists, not after it was removed from under it.

## 5. #126 — a PDF is shown inside the window on a phone

Chrome on Android cannot draw a PDF in an `<iframe>`: it shows a grey «Открыть»
placeholder, and tapping it leaves the app for a full-screen viewer.

- `App.pdfInto(host, url, title)` — one way to show a PDF in a box. Where the
  browser has a PDF viewer (`navigator.pdfViewerEnabled`) it is the iframe as
  before; otherwise pdf.js (`pdfjs-dist@3.11.174`, jsDelivr, loaded once by
  `loadLib()`) renders every page into a `<canvas>` the width of the box, one
  under another, scrolled inside `.preview-body`. Pages render lazily as they
  scroll into view. A file pdf.js cannot read (a server error page) falls back
  to the iframe and the «Открыть в новой вкладке» link stays under it.
- Used by the attachment preview, the МойСклад document preview (👁) and the
  КП preview.

## 6. #127 — «В работе» above «Входящие» on a phone

On a phone the board is one column per screen, swiped. The two columns a
manager lives in — what they are writing and what just came in — are now one
screen: on a phone (`max-width: 700px`) the `work` column stands directly
above the `inbox` column in the first slide.

- Columns carry `bcol--<kind>`. `boardColumns` wraps the work and the inbox
  column into `.bcol-pair` (work first) at the position of the inbox column.
- Desktop: `.bcol-pair { display: contents }` with `order` keeping the inbox
  column first — the strip reads exactly as before.
- Phone: `.bcol-pair` is one 84vw slide, a vertical stack; the cards of the
  work column scroll inside it (at most 42vh), so the inbox is on the same
  screen however many letters are being written.
- Drag and drop and the group move are untouched: the columns keep their
  `data-col` and `data-drop`.

## 7. #129 — the header of a letter, and menus that stay on the screen

- `menuHtml` menus position themselves on open (`toggle` event, capture):
  the list opens to the right of its button when there is no room on the left,
  is clamped to the viewport width minus 16px and never leaves the screen.
- The letter page and the company card head (`pagehead--card`): back link;
  the title with the actions to its right on the same line (the buttons do not
  wrap under the text and the «⋯» sits at the right edge); then the sender,
  the subject and one quiet meta line. The meta chips wrap as a group.

## 8. #129 — notes for colleagues, on top, open until deleted

- A notes block (`.notes`) stands at the top of the company card and of the
  letter page, right under the head: an always-visible field «Заметка для
  коллег или себя…» with «Добавить», then every note FULLY OPEN — author,
  date, text (line breaks kept) and 🗑. Nothing folds a note: it stays open
  until somebody deletes it. Newest first.
- Notes leave the timeline (`placeFeed()` skips them): a note shown twice is
  two notes to read.
- Storage: `correspondence` (`direction = 'note'`), as before, plus a
  `thread_key` column (schema v54): a note on a letter with no company card
  is kept by its thread, and `Crm::attachThread()` moves such notes onto the
  company the conversation is attached to.
- `Notes` (`lib/notes.php`): `forCard(?int $cpId, string $threadKey): array`
  (company notes when there is a company, the thread's own notes otherwise and
  in addition), `add(?int $cpId, string $threadKey, int $managerId, string
  $text): int`, `delete(int $id): bool` (only `direction = 'note'` without
  `event_type`). API `api/notes.php`: `list` (`cp`, `thread_key`), `add`
  (POST `{cp, thread_key, text}`), `delete` (POST `{id}`).
- The «📝 Заметка для коллег» menu item and its modal are gone: the field is
  already on the screen.
- The field is a `<textarea id="noteText">` — kept by module 063 while typed,
  cleared on a successful add.
