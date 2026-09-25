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
- `kp_requirements`: the parse prompt asks for it, but a prompt already
  overridden in «Админ → Промпты» keeps the old text — re-save it from the
  default there. Check one real letter with «укажите в КП …» end to end.

## Module 047 — needs a live T-Bank and MoySklad
- Put a T-API token and the account number into «Банк (Т-Банк)», press
  «Настройки → МойСклад → Проверить оплаты сейчас»: the statement endpoint
  (`/api/v1/statement`) and its field names were taken from the public docs,
  not from a live answer.
- One real payment: check that the «Входящий платёж» created in MoySklad is
  linked to both the invoice and the order (the code falls back to the invoice
  alone if MoySklad refuses the pair).
- Add `cron/check_payments.php` (every 10 min) to the host's crontab.

## Module 048 — needs a live system
- Open a КП in real Word: the ending is terms → «Более подробное описание…» →
  date + signature, with empty lines between; each product card of
  «Приложение №1» on its own page. Checked by the .docx XML only.
- A КП edited by hand on the A4 page keeps its old ending until «Вернуть
  автоматическую сборку» or «🔄 Пересобрать».
- «Папка модулей в МойСклад»: after the next catalog sync check that products
  of the folder get `is_addon = 1` (the folder names now come from
  `/entity/productfolder`, never called against a live account).

## Open
- The «не наша номенклатура» data-loss bug is fixed and the match table
  autosaves; if it ever recurs, audit every handler that calls
  `renderMatchedItems()` with server data.

## Module 050 — check on a live board
- The list view (`#mail/list`) sorts by the date of the last letter. Columns
  with a «Лимит карточек» still cut their cards in the list too — if managers
  expect the list to show every conversation, the limit must become board-only.

## Module 051 — check on a live system
- Real Word: a page break inserted with «⤓» and text coloured / justified in
  the editor come out in the .docx (checked by the XML only).
- The PDF view opens at the sheet's zoom via `#zoom=` — Chrome and Firefox
  viewers honour it; Safari ignores it (the frame is still full width).
- Pages on the sheet were checked against mPDF on a synthetic КП (17 = 17);
  compare the break points on a real КП with photos.
- Yandex `qwen3-235b-a22b-fp8`: confirm `/no_think` makes mail triage answer
  well under `LLM_TIMEOUT_SEC` (90 s) and the JSON still parses.

## Module 052 — check on a live MoySklad
- «Счёт в МойСклад»: the invoice gets «СОТРУДНИК». Check which type the field
  has in the account (string / employee / dictionary). For «employee» fill
  «UID в МойСклад» in the manager card if the name lookup does not find them.
- «Счёт в МойСклад» on a company without MoySklad: the creation window opens,
  after «Создать» the invoice is issued without reloading the page.

## Module 053 — check on the live site
- Install Bitrix module 1.2.0 from `bitrix-module/atlant.kpsync.zip` (Удалить →
  Установить): «Сервисы → Атлант: экспорт товаров в Excel» appears; the file's
  modifications column is right for this shop's offer properties.
- КП → «Каталог товаров → Сайт (Битрикс)»: «Проверить связь» says the version
  is the latest; «Загрузить ссылки и описания с сайта» fills the description
  counter; a product without a MoySklad description gets the site one in the КП.
- The «Bitrix module zip» workflow commits the rebuilt zip to `main`; if branch
  protection rejects the bot push, allow GitHub Actions or rebuild locally.

## Module 054 — needs a live MoySklad
- Issue an invoice: check the order is in «Резерв», on the chosen store, the goods
  are reserved there, and «Сотрудник» (owner) is the manager. If the token may not
  assign other employees, the note «сотрудник МойСклад … (МойСклад не дал назначить)»
  appears — then give the token's employee that right.
- «👁 Просмотреть счёт» / «📎 Прикрепить счёт»: the print-form download no longer
  sends the token to the file storage. If it still fails, the error now names the
  reason (HTTP code + MoySklad text) — check the journal and `MS_INVOICE_TEMPLATE`.

## Module 056 — check on the live board
- Send a letter with «📎 В письмо» КП → the card goes to «КП отправлено»; send the
  invoice → «Ждём оплату»; send another КП after that → the card stays.

## Module 057 — check on a live system
- Send delay: send one real letter, let the 20 s countdown run out — the letter
  leaves via `send_now` with a real SMTP; close the tab mid-countdown once and
  check cron sends it within a minute.
- «Обновить из МойСклад» on a linked company: the toast lists requisites,
  orders and invoices (checked without a live account).

## Module 058 — needs the user / a live system
- КП buttons on a folded «Подходящие позиции» (request of 2026-09-24): in the
  current code they stay visible (module 054). Checked in headless Chromium
  on the letter screen and the company card, at 1300 px and 412 px. Waiting
  for the user to say which screen still hides them.
- Open a КП with a price range in real Word and check the breakdown lines
  under the price (the .docx is built from the same HTML).

## Module 061 — check on a live system
- МойСклад: `/entity/demand?filter=customerOrder=…` and the track attribute on a
  real отгрузка (only the order attribute was ever seen live); `findByName()` on
  a real order / invoice number («123» vs «00123»).
- IMAP `\Seen` sync (`MAIL_SYNC_SEEN`): read a letter in Yandex webmail, press
  «Забрать почту» — the counter must drop.
- Desktop install in Chrome and Yandex Browser: the glowing button, the
  install prompt, then push from the installed window.
- A support ticket with a screenshot → issue: the picture must render
  (`blob/…?raw=true`) for a signed-in reader of the private repository.
- Android Chrome with «тёмная тема для сайтов» on: the page must stay light.

## Module 062 (issues #116–#119) — needs a live system
- «🧾 Выставить счёт» from the match block with no КП yet: the КП is built
  silently, then the invoice. Check one real invoice end to end (МойСклад
  unreachable in the build container).
- 👁 / «📎 В письмо» for an order and a shipment use the FIRST print template of
  `customerorder` / `demand` in МойСклад — check the form is the one the
  managers want (if not, a setting like `MS_INVOICE_TEMPLATE` is needed).
- #117 was reproduced with a zoomed page in emulation; check the support
  window on the real Android phone from the issue.
