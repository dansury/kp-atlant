# Module 043 — login safety, per-manager sound, photos open by default

Source: GitHub issue #60 (continued from module 042) and `TODO.md`. This
module implements three more self-contained, low-risk items from that
backlog; the rest stays in `TODO.md`.

## 1. Login keeps dropping — admin visibility and a way to reset it

Issue #60: *«постоянно слетает авторизация. нужно чтобы кукиз работали год
(настройка), а админу на уже залогиненные аккаунты приходили уведомления о
новых логинах, и чтобы админ мог обнулить логин»*.

- **Cookie lifetime up to a year** was already a configurable setting
  (`SESSION_LIFETIME`, seconds, `lib/settings.php`) — nothing capped it below
  a year. What was missing: PHP's own session garbage collector
  (`session.gc_maxlifetime`) is independent of that setting and defaults to
  ~24 minutes on some hosts, so a session file could be swept before its
  cookie expired. `startSession()` (`lib/bootstrap.php`) now sets
  `session.gc_maxlifetime` to at least `SESSION_LIFETIME`. The setting's hint
  is updated to say it accepts up to a year (`31536000`).
- **Notify admins of a new login while the account is already logged in.**
  `managers` gets a `session_last_login_at` column. `Auth::login()` checks
  whether the previous login is still within the `SESSION_LIFETIME` window;
  if so, every *other* active admin gets an in-app + push notification
  ("Новый вход: «Имя»") via `Notifier::notify()`, linking to
  `#settings/managers`. A routine login (no overlapping previous session)
  notifies nobody.
- **Admin can force-reset a manager's login.** `managers` gets a
  `session_epoch` integer column (default 0). `Auth::login()` stores the
  manager's current epoch in `$_SESSION['session_epoch']`. `currentManager()`
  compares it against the manager's current DB epoch on every request; a
  mismatch destroys the session (treated as logged out). A session that
  predates this migration has no `session_epoch` in `$_SESSION` yet — it is
  trusted once and backfilled, so deploying this does not log everyone out.
  `Auth::kickSession($managerId)` bumps the epoch by 1, which invalidates
  every active session for that manager (all devices) on their next request.
  Exposed as `admin.php?action=manager_kick_session` (admin-only, like the
  rest of the managers screen) and a "Сбросить вход" button next to
  "Удалить" in `editManager()` (`public/assets/js/app.js`).

Deliberately NOT done: normal login does not itself kick other active
sessions for the same account — two legitimate devices logged into the same
account is normal (a manager on desktop and phone), so a second login only
notifies, never force-logs-out; only the explicit admin action does that.

## 2. Per-manager notification sound

Issue #60: *«звук уведомления надо чтобы каждый пользователь мог выбрать
себе сам»*. `MAIL_SOUND`/`MAIL_SOUND_VOLUME` (`lib/settings.php`) stay as the
company-wide default. `managers` gets `notification_sound` and
`notification_sound_volume` columns — same shape as `email_signature`
(module 039): empty means "use the company default", following exactly.

New `lib/notification_sound.php` (`NotificationSound::forManager()`,
`::save()`) mirrors `lib/mail_signature.php`'s precedence idiom. Exposed as
`settings.php?action=notification_sound` (GET reads own+effective value,
POST saves). `settings.php?action=ui` — read by every manager on login,
already the source of `this.ui.mail_sound`/`mail_sound_volume` that
`App.playMailSound()` plays — now overlays the manager's own choice over the
global default, so no client-side change is needed for the sound to
actually play differently per manager.

UI: a new "Звук уведомлений" section under "Моя подпись"
(`App.loadSignature()`), reusing the same sound `<select>` + "▶ Послушать"
pattern as the global setting field (`App.loadSoundOptions`,
`App.playSoundPreview` — the latter now takes an optional `volumeId` so it
can read either the global settings field or this personal one).

## 3. Photos open by default in the match-row picker

Issue #60: *«Фото надо чтобы сразу были открыты»*. The per-row photo picker
(`App.matchRowExtra()` → `.match-extra__photos`) was collapsed behind the
"🖼 Фото в КП" button until clicked. For any row that already has an `id`
(saved), the photo box is no longer rendered `hidden`, and `matchRowExtra()`
now also fires `App.autoLoadMatchPhotos(i.id)` — a fire-and-forget fetch of
`requests.php?action=item_images` that fills the box once the row's markup
has actually reached the DOM (the same "call the async loader before the
caller's `innerHTML` assignment, let it resolve after" pattern already used
by `loadSoundOptions`/`loadOrganizations`). Unsaved rows (no `id` yet) still
show nothing — there is nothing to fetch.

`App.toggleMatchPhotos()` (the button's click handler) and the new
`App.autoLoadMatchPhotos()` now share one rendering routine,
`App.renderMatchPhotoBox(box, itemId, data)`, instead of duplicating the
photo-grid markup. The photo box keeps its `data-match-photos` marker and
gains a `id="mp_<item id>"` so the auto-loader can find it without a button
reference.

The recommended-vs-mandatory photo limit and the "photos chosen once stay
chosen in the next КП" behaviour are unrelated existing behaviour and are
untouched here.

## Schema (`lib/bootstrap.php`, `if ($current < 39)`)

- `managers.session_epoch` INTEGER DEFAULT 0
- `managers.session_last_login_at` TEXT
- `managers.notification_sound` TEXT
- `managers.notification_sound_volume` INTEGER

## Files

- `lib/bootstrap.php` — migration v39; `startSession()` sets
  `session.gc_maxlifetime`; `currentManager()` checks `session_epoch`.
- `lib/auth.php` — `Auth::login()` records the login and notifies admins on
  overlap; `Auth::kickSession()`.
- `lib/notification_sound.php` — new.
- `lib/settings.php` — `SESSION_LIFETIME` hint.
- `public/api/settings.php` — `ui` overlays the manager's sound;
  `notification_sound` action.
- `public/api/admin.php` — `manager_kick_session` action.
- `public/assets/js/app.js` — `loadSignature()` sound section,
  `saveNotificationSound()`, `playSoundPreview(selectId, volumeId)`,
  `editManager()` "Сбросить вход" button, `kickManagerSession()`,
  `matchRowExtra()`/`toggleMatchPhotos()`/`autoLoadMatchPhotos()`/
  `renderMatchPhotoBox()`.
