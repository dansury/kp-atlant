# 057 — Send delay (undo send), fold controls everywhere, «Обновить из МойСклад» that reports back

## 1. Send delay («тайм-аут отправки»)

A letter sent from the reply box or the compose window does not leave at once:
it waits N seconds, and a toast «Письмо уйдёт через N с · Отменить · Отправить
сейчас» counts down. «Отменить» keeps the text in the field.

- Per manager: `managers.send_delay_sec INTEGER NULL` (schema v49). `NULL` —
  default `MailSchedule::DEFAULT_DELAY` = 20 s; `0` — no delay; max 120.
- `settings.php?action=my_send_delay` — GET `{delay, default}`, POST `{delay}`
  (empty → default). UI: card «Задержка отправки» in «Настройки → Подпись»,
  under «Подпись в письмах».
- `mail.php?action=send` without `send_at`: when the manager's delay > 0 the
  letter goes into `mail_scheduled` with `send_at = now + delay` and the answer
  is `{delayed: {id, send_at, seconds}}`. `send_at` given → the delayed-send
  path of module 044, unchanged.
- `mail.php?action=send_now` `{id}` — the countdown ran out (or «Отправить
  сейчас»): `MailSchedule::sendNow(id, managerId)` sends it right away and
  answers like `send` (`sent_folder`, `warning`). Already sent by cron →
  `{already: 'sent'}`; being sent → `{already: 'sending'}`; cancelled → 400.
- The tab closed during the countdown — the letter is still in the queue and
  `MailSchedule::run()` (cron) sends it at the next pass.
- No double send: every sender claims the row first —
  `UPDATE mail_scheduled SET status='sending' WHERE id=? AND status='pending'`;
  only the one that changed the row sends. `cancel()` is the same conditional
  update to `cancelled`, so a letter already claimed cannot be «cancelled».
  A failed attempt returns the row to `pending` (or `failed` after 3).
- Client: `App.sendMail(body)` wraps both send paths (`threadSend`,
  `mailSend`); resolves with the send result, or `null` when cancelled. The
  send button stays disabled during the countdown.

## 2. Folding: a visible control on every foldable thing, tooltips instead of words

Folding used to be either a word button («▾ Свернуть», «▸ Развернуть
(позиций: N)») or a click on the title with nothing showing it is clickable.
Now every foldable component has BOTH: a click on its header/title, and a small
arrow button (`▾` open / `▸` folded) whose meaning is in its tooltip (`title`,
`aria-label`, `aria-expanded`).

| Component | Arrow button | Header click |
|---|---|---|
| «Подходящие позиции» (match table) | `.card__fold` — «Свернуть подбор» / «Развернуть подбор (позиций: N)»; the count also shows as a muted «· N» next to the title | the title text |
| Match row | `.match-row__fold` (exists) | the «из письма: …» line and the folded summary |
| Conversation in the company card (`.conv`) | `.conv__caret` at the row start | the row (exists) |
| Letter in a conversation (`.lmsg`) | `.lmsg__caret` in the header | the header (exists) |
| Blocks with `data-block` («Письма», «МойСклад», …) | `.block-fold` (exists) | the block head text |
| Open КП | `▴` icon — «Свернуть КП» | — |
| Invoice preview | `▴` icon — «Свернуть счёт» when open | — |

Collapse all at once:
- Match table toolbar: `⇈` «Свернуть все позиции» ↔ `⇊` «Развернуть все позиции»
  (`App.foldAllRows(btn)`); «Свернуть подобранные» becomes the icon `✓⇈` with
  the same tooltip as before.
- Letters block head: `⇈`/`⇊` «Свернуть все письма» / «Развернуть все письма»
  (`App.foldAllLetters(btn)`).

Clicks on inputs, links, buttons and hints inside a header never fold.

## 3. «Обновить из МойСклад» on the company card

The ⋯ menu item refreshed orders and invoices silently: «Данные обновлены»
said nothing about what happened, and a company not linked to MoySklad got the
same words.

- Tooltip: «Подтянуть из МойСклад реквизиты компании, её заказы и счета».
- `invoices.php?action=sync&full=1` also runs `Requisites::syncCounterparty()`
  (legal title, KPP, OGRN, address, contract) and answers
  `{orders, invoices, linked, requisites}`.
- The toast says what changed: «МойСклад: реквизиты обновлены · заказов 2 ·
  счетов 3», or «Компания не связана с МойСклад — …» when `linked = false`.
  «Обновляем из МойСклад…» stays on screen until the answer; a second click
  while it runs does nothing.
