# Module 044 — photos follow the product, settings explain themselves, mail leaves on a schedule

Source: GitHub issue #60 — the same large wishlist module 042 took its first
pass at. The remainder was parked in `TODO.md` per CLAUDE.md §3; this module
takes the next batch of it. What is still left is back in `TODO.md`.

## 1. The photos of the previous product (issue: «при изменении подобранного
   товара фото остаются от предыдущего»)

Three separate defects added up to one symptom.

**1.1 The choice was wiped by every table save.** `App.collectMatchedItems()`
never collects `selected_images` — the keys live in the photo strip, not in a
`[data-field]` input. `RequestItems::save()` ran every row through
`imageChoice()`, which returns `null` for a row that carries no
`selected_images` key at all, and wrote that `null` over the choice the manager
had just made with the checkboxes.

Fix: `imageChoice()` is no longer consulted when the editor did not send the
field. `RequestItems::save()` now keeps the stored value for a row whose
product did not change, and the row keeps what `item_images_save` wrote.

**1.2 The choice outlived the product.** `selected_images` holds MoySklad image
KEYS. Put a different product on the line and the keys still point at the old
one's photos: they match nothing in the new product's list (the strip shows
"0 из N" and the КП prints none), and any already-built КП keeps printing the
old product's pictures because `proposal_items.selected_images` was copied from
it.

Fix: `RequestItems::save()` compares `moysklad_product_id` against what the row
held before. Changed → `selected_images` is reset to `NULL` ("choice not made,
print all of them") for the request item AND for every `proposal_items` row of
this request that belongs to an unsent КП — the same cascade
`item_images_save` already does.

**1.3 The strip on screen was never reloaded.** The picker only fetched on the
click that opened it, so after picking another product it still displayed the
previous one's photos until the card was re-opened.

Fix: `App.pickSuggest()` (which `App.pickVariant()` also goes through) and
`App.chooseMatch()`'s unsaved-row branch call `App.reloadMatchPhotos(row)`.
It does NOT re-fetch on the spot — the new product is so far only on screen,
and the server would answer with the previous one's photos. The strip is
marked `data-state="stale"`, the row is queued for an immediate autosave
(`App.touchMatch()`), and the strip reloads from `autosaveMatch()` once the
new product is actually stored.

The same reset runs server-side wherever a row's product changes without going
through `save()` — `RequestItems::choose()` (equal-variant pick),
the out-of-stock analogue swap and `rematch()` — via
`RequestItems::resetImagesOnProductChange()`.

## 2. Photos are open, and they load one at a time

`App.matchRowExtra()` drew the strip behind a `🖼 Фото в КП` button
(`hidden`). The issue asks for photos open by default and loaded lazily, one
after another, because a match table of twenty positions otherwise fires a
hundred image requests at once.

- The strip renders open on every row that has a saved item id; the button
  stays as a collapse toggle (`🖼 Фото в КП ▾`), so a long table can still be
  folded.
- `App.loadMatchPhotos()` is called from a shared `IntersectionObserver`
  (`App.watchMatchPhotos()`, 300 px ahead of the viewport): a strip fetches
  its list only when it is about to be scrolled into view.
- Inside a strip, `App.chainPhotos(box)` loads the thumbnails **sequentially**:
  every `<img>` starts with `data-src`, and the next one's `src` is only set
  once the previous fired `load` or `error`. No parallel burst, and the first
  photo is on screen while the rest are still coming.

## 3. «Фото — на все позиции» in the bulk conditions panel

New field in `App.conditionsPanel()` (the «Цены и условия — на все позиции»
block), stored in the same per-manager conditions blob as price type and
discount: `photos` — how many photos per position this КП prints.

- Empty — the setting `KP_MAX_IMAGES_PER_ITEM` decides, as before. Zero is a
  decision ("no photos in this КП"), not emptiness — `Terms::photoLimit()`
  keeps the two apart.
- A number — `items_conditions&apply=1` writes the FIRST N keys of every row's
  available photos into `request_items.selected_images` (and cascades to the
  unsent КП of the request), which is exactly "overrides the default and picks
  the first photo(s) of what is selected".

Server: `RequestItems::applyPhotoLimit(int $requestId, ?int $limit): int`.

## 4. Every setting explains itself, and says where to get the token

The issue asks for an explanation on every settings field and, where the value
is a token from another service, a direct link to the page that issues it —
in the setup wizard as well as in the settings screen.

- All 35 keys of `Settings::SPEC` that carried an empty hint now carry one.
- New `Settings::LINKS` — `key => [url, label]` for every value fetched from
  somewhere else (GitHub, MoySklad, Yandex Cloud, OpenRouter, IMAP/SMTP of the
  hoster, Bitrix, VAPID, the timezone list). `Settings::describe()` returns it
  as `link`, and `SetupWizard::field()` passes the same `link` through.
- `App.adminSettings()` and `App.setupStep()` render it as
  `↗ <label>` under the field.
- `tests/module_044.php` fails if any key has an empty hint, or if a `secret`
  key has no link: a new integration cannot be added without saying where its
  key comes from.

## 5. The support widget can be switched off and replaced

Replain was pasted into `public/index.php` and `index.html` as a fixed snippet
with a hard-coded account id ("плагин Re:palin надо чтобы можно было отключить
и вообще заменить код").

- `SUPPORT_WIDGET` (bool, on) and `SUPPORT_WIDGET_CODE` (textarea) — the
  default of the second is the current Replain snippet, so nothing changes
  until someone edits it.
- `public/index.php` prints `SUPPORT_WIDGET_CODE` when `SUPPORT_WIDGET` is on
  and nothing at all when it is off. The code is written by the administrator
  and printed as it is — it is a `<script>` block by nature; nobody else can
  reach the field.

## 6. The notification sound is the manager's own

`MAIL_SOUND`/`MAIL_SOUND_VOLUME` were one choice for everybody. New
`managers.notify_sound` and `managers.notify_volume` (both nullable — "as the
company setting says"), edited in «Моя подпись» next to the mail signature,
where the rest of the per-manager preferences already live.

- `settings.php?action=my_sound` — GET returns `{sound, volume, common,
  effective}`; POST saves.
- `settings.php?action=ui` (what `App.ui` is filled from) answers with the
  manager's own sound and volume when they set one, the company setting
  otherwise. `admin.php?action=sounds` (the list of files in `sounds/`) moved
  into `MANAGER_ACTIONS`, since everybody now picks from it.

## 7. The session stops falling over (issue: «постоянно слетает авторизация»)

- `SESSION_LIFETIME` keeps its name but is now documented in the panel and
  accepts up to a year (`sessionLifetime()` clamps to 300 s…1 year, so a typo
  in the field cannot lock everybody out); the cookie is **renewed as the
  session is used** — `renewSessionCookie()` re-sends it at most once a day —
  so a manager who works daily is never logged out.
- `session.gc_maxlifetime` is raised to the same value — a long cookie with
  PHP's 24-minute default garbage collection was the actual reason a session
  died while the cookie was still there.
- Every login writes `manager_logins` (manager, time, IP, user agent) and, when
  the manager already had a live session from a different address,
  `Notifier::notify('new_login', …)` reaches the administrators: "Яна вошла с
  нового устройства".
- `admin.php?action=manager_logout` (admin only) bumps
  `managers.session_epoch`; `currentManager()` compares it with the epoch
  written into the session and drops the session when it differs. That is the
  "админ мог обнулить логин" of the issue. `admin.php?action=manager_logins`
  lists the last 20 logins of a manager next to the button.

## 8. Delayed send (issue: «отложенная отправка в заданное время и день»)

- New table `mail_scheduled`: the whole compose payload as JSON, `manager_id`,
  `send_at`, `status` (`pending`/`sent`/`failed`/`cancelled`), `error`,
  `attempts`.
- `case 'send'` of `public/api/mail.php` moved to `MailCompose::send(array
  $input, int $managerId)` in `lib/mail_compose.php` — one path for both the
  button and the schedule, so a delayed letter is the same letter. Failures
  there are exceptions, not `jsonError()`: cron has no HTTP response to answer with.
  `?action=send` with `send_at` stores instead of sending and answers
  `{scheduled: {...}}`.
- `MailSchedule::due()` / `::run()` — `cron/send_scheduled.php` every minute,
  and the same call piggybacks on `cron/check_mail.php` so a host with one cron
  entry still sends. Three failures stop the retries and notify the manager.
- `Outbox::sweep()` no longer deletes attachments of a pending scheduled
  letter: a letter scheduled for next Monday outlived the 48-hour sweep.
- UI: «⏱ Отложить» next to «Отправить» opens the presets «Завтра в 09:00»,
  «В понедельник в 09:00», «Через час» and a `datetime-local` field;
  `mail.php?action=scheduled` lists what is queued, with «Отменить».

## 9. КП editor: fewer buttons, and none that lie

- «⬇ Собрать КП в Word» / «⬇ …в PDF» → `⬇Word` / `⬇PDF` links that appear
  ONLY once the request has a КП; «Собрать КП заново» → a `🔄` icon button.
- «Сохранить» is gone. `App.bindMatchAutosave()` — a 1.2 s debounce on
  `input`/`change` over the whole match block, 0.4 s when a product is picked
  from a list — saves what changed and shows «сохранено HH:MM» where the
  button used to be. It deliberately does NOT re-render: the cursor stays
  where it was, and `App.adoptItemIds()` fills in the ids the server assigned
  to new rows (by position, and only when the counts agree). Every explicit
  `items_save` call site (scope change, bulk conditions, re-match) is
  unchanged: they still save synchronously before an action that re-renders.
- «Подобрать по каталогу» / «Подобрать нейросетью» moved above the
  «Цены и условия — на все позиции» panel, where the issue asks for them.
- The КП board with its «Позиции запроса» column was removed in module 048
  (one КП per request).

## 10. The document: header, first line, photo margins, alignment

- The Word file is built from the same HTML as the PDF, and `styleOf()` never
  read `text-align` off a class — so the seller block, right-aligned in the
  PDF, came out left-aligned in Word. `.header`/`.entity-name` now carry
  `align => right` explicitly (the logo already anchored left with square
  wrapping), and an inline `text-align: left|right|center` on any element now
  reaches Word too, not only `center`.
- A real empty paragraph (`<p class="title-gap">`) stands before
  «Коммерческое предложение»: CSS margins collapse to nothing in Word, and the
  header ran straight into the title.
- `.gallery img` gets `padding: 4px` and the card gallery
  `margin: 0 0 12px 16px`, so photos no longer touch the text around them.
- `.appendix__title` lost its `text-align: right` and `.card__desc` its
  `justify`: body text is left-aligned throughout. The two exceptions are the
  sender block in the header (the issue asks for it right-aligned) and the
  money columns of the price table, which are read as numbers.

## Tests

`php tests/module_044.php` — new: the photo cascade on a product change, the
photo choice surviving a save, `applyPhotoLimit`, the hint/link completeness of
`Settings::SPEC`, the widget setting reaching `index.php`, the per-manager
sound, the session epoch invalidating a session, a scheduled letter being
picked up by `MailSchedule::due()` and not swept from the outbox, and the
document template's header/alignment rules.
