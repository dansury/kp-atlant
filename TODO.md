# TODO

Items from GitHub issue #60 not implemented in modules 042 and 043 (see
`specs/042-scope-price-delivery-and-board-fixes/spec.md` and
`specs/043-session-safety-and-sound/spec.md` for what *was* done from that
issue). Issue #60 listed 30+ distinct requests in one pass; this is the
remainder, grouped by area, roughly in the order they appeared.

## Photos / lazy loading
- General lazy-loading for interface elements/photos beyond product photos
  (issue only specifically asked for sequential photo loading; investigate
  where else images load in bulk — mail attachments, board card thumbnails).

## Board
- Per-board (not just per-column) settings surfaced more prominently in the
  settings UI, with explanations.

## КП editor UX (bigger UI reorganisation — needs design review)
- Move product-selection panel into the LEFT column, above the letter
  compose field; collapse it once the first КП has been sent, but keep it
  always visible above the letter field.
- Move "Подобрать по каталогу" / "Подобрать нейросетью" buttons to sit
  directly above the "Цены и условия — на все позиции" table.
- Add a "количество фото — на все позиции" field next to that table,
  overriding the per-item default and picking the first N photos.
- Move the drag-handle (⠿) and ↑/↓ buttons to the bottom of the КП draft
  card; move the "🚫 не наша номенклатура" button to sit to the right of
  "из письма: ... · совпадение ...% · по описанию"; move the "×" remove
  button to sit to the right of the product-name input.
- Replace "Собрать КП в Word" / "…в PDF" buttons with "⬇Word"/"⬇PDF" links
  that appear only after "Собрать КП" has been clicked; replace "Собрать КП
  заново" with a "🔄" icon button.
- Remove the "Сохранить" button entirely; autosave on every field change
  (debounced). Requires auditing every current explicit-save call site
  (`saveMatchedItems`, `saveKpEditor`, `saveProposal`) for what a debounced
  autosave would need to preserve (error surfacing, race conditions between
  autosave and manual re-match).
- Hide "Позиции запроса" (the unassigned-items pool in the КП drag board)
  until "+ Ещё одно КП" is clicked for a second document; currently it shows
  automatically whenever a request has zero proposals yet.

## КП document (cosmetic / layout — needs visual QA, can't verify without a
   browser+Word round-trip in this pass)
- Word header: sender text right-aligned, logo left-aligned; add a text line
  before "Коммерческое предложение".
- Photo padding/margin in the PDF gallery — photos currently sit flush
  against surrounding text ("слиплись").
- Text alignment should always be left (audit `.card__qr`, `.card__link`,
  and any other `text-align: right/center` rules against this).
- "Открыть" button should open an editable HTML A4 preview (not just a
  read-only preview) that can then export to PDF/Word. This is a
  significant new feature (a live in-browser document editor), not a small
  fix — needs its own design pass.

## Delivery
- Verify the invoice generation path (MoySklad order/invoice sync) doesn't
  need a matching adjustment now that КП document prices can include a
  distributed delivery share (currently the distribution is display-only in
  the printed/emailed КП; `proposal_items.price` in the DB is untouched, so
  MoySklad sync should already be unaffected — but this wasn't verified
  against a live MoySklad instance).

## Scheduled sending
- Delayed send with a date/time picker and quick presets ("Завтра в 09:00",
  "В понедельник в 09:00"), plus a custom time.

## Correspondence UI
- Make the correspondence card list look and behave more like Gmail.

## Plugins
- Make the "Re:palin" plugin toggleable and swappable for different code —
  investigate what this plugin currently is/does before deciding on an
  interface for it.

## Settings UI
- Add an explanation + "where does this come from" + (where applicable) a
  direct link to generate the needed token (GitHub, MoySklad, Yandex,
  OpenRouter, etc.) to every settings field, in both the setup wizard and
  the regular settings screens.

## MoySklad
- Use MoySklad's own "Печать → Счет покупателю с печатью с QR и с подписью"
  option for the invoice attached to outgoing mail, instead of (or as an
  option alongside) the current custom invoice generation.

## Bugs mentioned in issue #60 not yet confirmed fixed
- "не наша номенклатура" data-loss bug is fixed for the specific path
  described (marking scope, applying bulk conditions, choosing an already-
  saved item's equal variant). The same *class* of bug (full-table
  re-render discarding unsaved edits) might exist in other action handlers
  that call `renderMatchedItems()` with server data — worth a broader audit
  if it recurs.
