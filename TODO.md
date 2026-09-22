# TODO

Items from GitHub issue #60 still not implemented. That issue listed 30+
distinct requests in one pass; modules 042 and 044 took the first two batches
(see `specs/042-scope-price-delivery-and-board-fixes/spec.md` and
`specs/044-photos-settings-hints-scheduled-send/spec.md` for what each one
did). This is the remainder, grouped by area.

## КП editor UX (bigger UI reorganisation — needs design review)
- Move the product-selection panel into the LEFT column, above the letter
  compose field; collapse it once the first КП has been sent, but keep it
  always visible above the letter field. (The buttons *inside* the panel were
  reordered in module 044 — «Подобрать по каталогу»/«нейросетью» now sit above
  the bulk conditions table — but the panel itself still lives in the right
  column.)
- Move the drag-handle (⠿) and ↑/↓ buttons to the bottom of the КП draft
  card; move the «🚫 не наша номенклатура» button to sit to the right of
  «из письма: ... · совпадение ...% · по описанию»; move the «×» remove
  button to sit to the right of the product-name input.

## КП document
- Word header: the seller block is now right-aligned and the logo left-aligned
  (module 044), but this was never checked against a real Word round-trip —
  needs visual QA on a machine with Word.
- «Открыть» should open an editable HTML A4 preview (not just a read-only
  one) that can then export to PDF/Word. A significant new feature (a live
  in-browser document editor), not a small fix — needs its own design pass.
- Check in Word that the selected photos come out in the chosen NUMBER, not
  one per position: module 042 fixed them lying on top of each other, and
  nobody has opened the file in Word since.

## Delivery
- Verify the invoice generation path (MoySklad order/invoice sync) doesn't
  need a matching adjustment now that КП document prices can include a
  distributed delivery share (currently the distribution is display-only in
  the printed/emailed КП; `proposal_items.price` in the DB is untouched, so
  MoySklad sync should already be unaffected — but this wasn't verified
  against a live MoySklad instance).

## Correspondence UI
- Make the correspondence card list look and behave more like Gmail.

## Photos / lazy loading
- General lazy-loading beyond the match table's photo strip (module 044
  covers that one): mail attachments and board card thumbnails still load in
  bulk.

## MoySklad
- Use MoySklad's own «Печать → Счет покупателю с печатью с QR и с подписью»
  option for the invoice attached to outgoing mail, instead of (or as an
  option alongside) the current custom invoice generation.

## Bugs mentioned in issue #60 not yet confirmed fixed
- «не наша номенклатура» data-loss bug is fixed for the specific path
  described. The same *class* of bug (full-table re-render discarding unsaved
  edits) is now much less likely — module 044 autosaves the match table — but
  a broader audit of handlers that call `renderMatchedItems()` with server
  data is still worth doing if it recurs.

## Left for a live check (modules 043–044)
- Run «Проверить каталог Yandex» against the real cloud: the OpenAI-compatible
  route is learned from the provider's own 400, but which open models actually
  answer there was never verified without network access.
- If MoySklad keeps answering 429 after the retry fix, add a client-side rate
  guard (45 requests / 3 s per token) instead of relying on the backoff alone.
- `php tests/module_026.php` has one failing check («новое письмо поднимает
  карточку обратно») that predates module 042 — still not investigated.
- Scheduled sending (module 044) needs `cron/send_scheduled.php` in the host's
  crontab for minute precision; without it letters go out with
  `cron/check_mail.php`. Worth saying so in `DEPLOY.md` once someone sets it
  up on the live host.
