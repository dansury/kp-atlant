# 061 — Issues #97–#114: counters, notices on cards, shipments, board paging, log copy, mobile pass

## 1. #114 / #102 — warnings and «database is locked»

- `WebPush::send()` calls `openssl_pkey_derive($peer, $server)` without the
  deprecated key length; `Speech::post()` no longer calls `curl_close()`;
  `RequestItems` reads `$prices[(string)($row['moysklad_product_id'] ?? '')]`.
- `SearchIndex::refresh()` is single-writer: a non-blocking `flock` on
  `<db dir>/.search.lock`. A request that does not get the lock searches the
  index as it is and returns the pending count. Each batch is written in
  `BEGIN IMMEDIATE`; a «database is locked» from another writer ends the pass
  quietly (`Db::isLocked()`), the rest is indexed next time.
  `SearchIndex::ready()` defaults to a 5 s budget (cron passes 60 s).
- `Db::path()` returns the open database file.

## 2. #113 — a log entry in one tap, the whole log as a file, the log in a ticket

- `Logger::entryText(array $row): string` — the canonical text of an entry:
  `level · channel · created [· повторилось N раз, последний …]`, message,
  source, request URI, context as pretty JSON, blank lines between.
  `App.logText()` prints the same format in the browser.
- `Logger::export(array $filter): string` — every entry matching the list
  filter (≤ 5000), header line with export time and filter, entries separated.
- `admin.php?action=log_get&id=` → `{item, text}`; `admin.php?action=logs_export`
  → `text/plain` attachment `atlant-log-YYYYmmdd-HHMMSS.txt` (admin only).
- The error notification links `#settings/logs/error/<id>`; `pageSettings(tab,
  arg, arg2)` opens that entry. Every row has a «⧉» button, the entry dialog has
  «⧉ Скопировать всё» and «📎 В поддержку с журналом». `App.copyText()` uses the
  Clipboard API and falls back to `execCommand('copy')` (http, old WebViews).
- The logs toolbar has «⬇ Скачать журнал» (same filter) and «📎 В поддержку».
- `supportModal(kind, {attachLog, title, body})`; an admin sees the checkbox
  «Приложить журнал…». `Support::submit()` with `attach_log` stores
  `Logger::export(['level' => 'warning'])` as a ticket file
  (`Support::attachText()`); `support.php` drops `attach_log` for non-admins.

## 3. #105 — support images, mobile layout, sending a letter

- `Support::approve()` builds the issue body from the ticket re-read AFTER the
  files were pushed — the stale row carried no `remote_url`, so issues had no
  pictures. `Support::stableUrl()` stores `html_url?raw=true` (a private
  repository's `download_url` carries an expiring token);
  `Support::displayUrl()` rewrites old `raw.githubusercontent.com` links.
- Mobile: the page declares `color-scheme: only light` (Chrome's auto-dark
  turned inputs into grey bars) and `text-size-adjust: 100%`; `.modal__box` has
  `min-width: 0` (a flex child grew to its widest field); the list's status
  strip is `position: relative` (its `.sr-only` spans escaped the scroller and
  made the page 1132 px wide); the logs table stacks into cards; a
  conversation row shows subject, then a two-line preview.
- Composer: category + «✨ Сгенерировать ответ» stand ABOVE the editor
  (`.composer__gen`); `.composer__actions` holds «Отправить», «⏱ Отправить
  позже», «📎» and is `position: sticky` above the bottom nav on a phone.

## 4. #104 — a notification with an absolute URL

`Push::appUrl()` passes `http(s)://…` through unchanged; the service worker
opens an address of another origin with `openWindow()`; `App.notifLink()` opens
it in a new tab.

## 5. #103 — notifications mark cards

- `Notifier::CARD_TYPES` = order_paid, order_shipped, mail_bounced,
  reserve_hold, followup, invoice, new_order (a new letter is already bold).
- `Notifier::cardNotices(int $managerId, ?int $cpId = null)` — unread notices
  of those types resolved to a company root (ref counterparty / mail / request /
  proposal) or, for a card without a company, to a thread key.
- `Boards::decorateAll()` sets `card.notices` for the viewing manager; a card
  with notices is `hot` (rises in its column) and is drawn `.bcard--notice` /
  `.grow--notice` with the first title.
- `notifications.php?action=for_company&id=` feeds a strip above the company
  card; «✓ Сделано» marks the notification read and the card stops glowing.
  Opening the card or tapping the push does not clear it.

## 6. #112 — shipments (отгрузки)

- Table `order_demands(order_id, moysklad_id UNIQUE, name, moment, ship_service,
  ship_track, seen_at, notified_at)` (schema v52).
- `MoySklad::getDemandsByOrder($msOrderId)` — `/entity/demand?filter=customerOrder=…`
  with attributes.
- `Fulfillment::checkShipments($limit, ?int $onlyCp)` checks orders not yet
  notified and those notified within 30 days. Each demand is stored once; its
  track is the demand's own attribute, or the order's when the order has one
  demand. A new demand without a track → an `order_shipped` notice (marks the
  card); a track not yet announced for the order → `shipped()`.
- `Fulfillment::shipped()` writes the track INTO the manager's existing draft
  of that conversation (or company) — `trackParagraph()` appended once —
  and only otherwise creates a `shipment` draft.
- «Обновить из МойСклад» on a company (`invoices.php?action=sync&full=1`) runs
  `checkShipments(30, $cpId)`; the report says «новых отгрузок», «трек в черновике».

## 7. #111 — link a МойСклад order / invoice to a card by number

- `MoySklad::findByName($entity, $number)`: exact `name=`, then zero-padded to
  5 digits, then `search=` matched by digits.
- `MsSync::linkDocuments($cpId, $orderNo, $invoiceNo, $requestId, $managerId)`
  upserts the order (and its invoices) / the invoice (and its order) and sets
  their `counterparty_id` to the card's company — a manual link beats the
  automatic one; a feed note records it.
- `invoices.php?action=link` (POST `counterparty_id`, `order`, `invoice`,
  `request_id`); company menu «Привязать заказ или счёт МойСклад…».

## 8. #110 — column limit: load more, search reaches hidden cards

- `Boards::get()` returns `column.total`; `cards` is cut to `card_limit`.
- `Boards::columnCards($colId, $offset, $limit)` → next batch (same order);
  `boards.php?action=column_cards`.
- `Boards::searchCards($q)` → decorated cards found by `Boards::search()`;
  `boards.php?action=search_cards`. The client puts found cards into their
  columns with `found = <query>`; the local filter keeps them; clearing the
  search drops them.
- Under a limited column «▾ ещё N» loads the next `card_limit` cards; the list
  view has «▾ ещё письма». Loaded batches survive a redraw from a server answer
  (`App.boardShown`, `boardRestoreShown()`).

## 9. #109 — the card opens on its last letter

The last letter of a conversation is always expanded (ours included);
`App.focusLastLetter()` scrolls to it and focuses it on opening a company.

## 10. #108 — «Отправить позже»

The button reads «⏱ Отправить позже». The panel: title, preset tiles (label +
date), «День» (`type=date`, min today) and «Время» (`type=time`, step 5 min)
as separate fields, «Отправить в это время»; a time in the past is refused.

## 11. #107 — header logo

`Branding::headerKind()`: the first uploaded of app → favicon → kp. A square
mark sits beside the wordmark text; the КП logo (wide, carries its own text)
replaces it. Nothing uploaded → the bundled `icon-192.png`, never the hand-drawn SVG.

## 12. #106 — «Открыть» becomes «Закрыть»

The КП bar button carries `data-kp-toggle`; `App.kpIsOpen()` /
`App.kpToggleLabels()` keep its label in step with the open document.

## 13. #101 — installing the app

`App.installGlowHtml()` puts a glowing «📲 Установить приложение» in the mail
toolbar while this browser has not installed it (`localStorage.pwaInstalled`,
set on `appinstalled` / standalone launch, cleared on `beforeinstallprompt`)
and the browser can install (prompt available, Chromium/Yandex, iOS). The
button uses the prompt or shows per-browser instructions; after install it
offers push.

## 14. #99 — manual request in the list view

Renamed everywhere to «+ Составить КП по ручному запросу»; the list view shows
it above the status strip (`.mside__new`).

## 15. #98 — SpeechKit model

Settings `SPEECH_MODEL` (`general`, `general:rc`, `general:deprecated`),
`SPEECH_LANG`, `SPEECH_PROFANITY` in «Нейросети» (own card).
`Speech::query($format)` builds the STT v1 parameters (`topic`, `lang`,
`profanityFilter`).

## 16. #97 — unread counter

- `MailThreads::unreadCount(?array $manager)` counts only letters the manager
  can see: not archived, not answered later in the thread, not on a card
  dismissed after the letter, and — for a non-admin — from his mailboxes.
- `MailSync::syncSeen()` (setting `MAIL_SYNC_SEEN`, on): on every inbox pull
  the \Seen flags of our last 300 unread letters are read
  (`EmailReader::seenUids()`) and letters read in webmail become read here.
- `mail.php?action=unread`; `App.refreshMailBadge()` runs 1.5 s after every
  route change, not only every 30 s.
