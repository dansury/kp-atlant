# TODO

What is left of GitHub issue #60 after modules 042, 044 and 045 (see
`specs/045-issue60-remainder/spec.md`). Everything here needs a live system
(Word, MoySklad, Yandex, the production host) that the build environment does
not have.

## Needs a check on a machine with Word
- Word header: seller block right-aligned, logo left-aligned (module 044).
- Selected photos come out in the chosen NUMBER, not one per position (module 042
  fixed the stacking; nobody has opened the file in Word since).
- «Не наша номенклатура» rows: grey, bold wording, dashes (module 045).
- A КП edited on the A4 page (module 045) → ⬇ Word: the edited HTML goes through
  `Html2Docx`, which knows only the template's own tags. Check that an edit made
  in the browser (pasted text, a new paragraph) survives the conversion.

## Needs a live MoySklad account
- `MS_INVOICE_TEMPLATE`: confirm the print form «Счет покупателю с печатью с QR и
  с подписью» exists under that name in the account (otherwise set the exact
  name in the settings — the fallback is the first template).
- Delivery included in the invoice (module 045): create one invoice from a КП
  with delivery and a discount, compare its total with the КП total.
- `MS_DELIVERY_SERVICE_ID` for `KP_DELIVERY_MODE = line`: set it to the delivery
  service id and check the service position lands in the invoice.
- If MoySklad keeps answering 429 after the retry fix, add a client-side rate
  guard (45 requests / 3 s per token) instead of relying on the backoff alone.

## Left for a live check (modules 043–044)
- Run «Проверить каталог Yandex» against the real cloud: the OpenAI-compatible
  route is learned from the provider's own 400, but which open models actually
  answer there was never verified without network access.
- Scheduled sending (module 044) needs `cron/send_scheduled.php` in the host's
  crontab for minute precision; without it letters go out with
  `cron/check_mail.php`. Worth saying so in `DEPLOY.md` once someone sets it
  up on the live host.

## Issue #67 (module 046) — needs a live system
- Open a КП in real Word: the header is now a borderless table (logo | requisites)
  and the QR sits in a table cell left of the link — check both look right.
  LibreOffice in the build container does not start, so the .docx was checked
  by its XML only.
- Bitrix module 1.1.0 on the live site: press «Экспорт товаров в Excel» and
  check the modifications column (offer properties differ per shop); reinstall
  the module if `kp.php?action=export_xlsx` is needed. Descriptions from the
  site arrive with the next «sync from site» run.
- `kp_requirements`: the parse prompt asks for it, but a prompt already
  overridden in «Админ → Промпты» keeps the old text — re-save it from the
  default there. Check one real letter with «укажите в КП …» end to end.

## Open
- `php tests/module_026.php` has one failing check («новое письмо поднимает
  карточку обратно») that predates module 042 — still not investigated.
- The «не наша номенклатура» data-loss bug is fixed and the match table
  autosaves; if it ever recurs, audit every handler that calls
  `renderMatchedItems()` with server data.
