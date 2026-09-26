# 007 — The panel as a phone app: PWA and web push

## Problem

Requests arrive around the clock, but the bell in the header rings only while the tab
is open. The administrator learns about an urgent letter when they sit down at the
computer — by then the letter «I'm leaving on a business trip, when will you ship the
helmet» has been waiting for a day.

`kraskiweb` has solved this: the panel installs on the «Home» screen and sends push
even with the browser closed, in plain PHP without a single dependency. This module
ports the same technology instead of inventing its own.

## Scope

- Installing the panel as an app (PWA): manifest, service worker, icons.
- Web push to administrators: per-device subscription, muting by type, a test.
- Push is sent from the same events that already create notifications in the panel.

Out of scope: push to the client's managers, scheduled notifications, offline work
with data (only the shell is cached, the API — never).

## Installation (FR-070)

- `public/manifest.webmanifest` — `display: standalone`, icons 192/512 and maskable,
  shortcuts to «Запросы» and «Почта», dark theme colour `#17181c`.
- `public/sw.js` — the shell cache, `network-first` for navigation AND for code
  (`.js`, `.css`: the cached copy is only for offline), `cache-first` for pictures and
  icons. Only a complete `200` of our own origin is ever stored. Code served from a
  cache nobody re-checks once kept a truncated `app.js` under the page's own URL on
  every reload (module 066). **Requests to `/api/` are never cached**: a manager must
  not act on a stale list of requests.
- `.htaccess` serves `.webmanifest` with the right type and `sw.js` with `no-cache`
  and `Service-Worker-Allowed: /`, otherwise the browser pins an old worker.
- «Настройки → Приложение на телефоне»: a verdict in Russian, the install button
  (`beforeinstallprompt`), instructions for iOS and copyable diagnostics.

## Push (FR-071)

`lib/webpush.php` — RFC 8291 (`aes128gcm`) + RFC 8292 (VAPID) in plain PHP:
`openssl` (ECDH via `openssl_pkey_derive`, ES256 via `openssl_sign`), `hash_hkdf`,
`openssl_encrypt('aes-128-gcm')`. No Composer needed.

The VAPID pair is generated once and lives in the settings (`PUSH_VAPID_PUBLIC` /
`PUSH_VAPID_PRIVATE`; the private key is encrypted with `Crypt` and never reaches the
browser).

`lib/push.php` — subscriptions, mutes, delivery:

| Type | What it sends |
|---|---|
| `new_request` | new requests and letters |
| `order` | orders and invoices |
| `followup` | КП reminders |
| `system` | errors and service events |

A row in `push_mutes` mutes one type; `kind = NULL` — all of them. Sending goes
through `Notifier::notify()`, i.e. automatically from every place that already
notifies a manager. A push failure never breaks the mail sync: it is logged to the
`push` channel. A 404/410 answer deletes the dead subscription.

## Where a tap leads (FR-073)

A push that opens just «the panel» makes the manager look for the letter by hand —
exactly what this module exists to remove. The target of a notification is stored,
not guessed:

- `notifications.url` — the address both the push and the «Открыть» button in the
  notification list lead to. `Notifier::notify()` takes it as the last argument; when
  the caller is silent, the address is derived from `ref_type` (`request` →
  `#mail/request/<id>`, `mail` → `#mail/msg/<id>`, `proposal`, `counterparty`).
- The mail sync knows the exact letter and passes `#mail/msg/<id>`: «a new letter
  arrived» opens that very letter, and the request created from it is one link away.
- The push `tag` is per target (`kind:<hash of the address>`), not per type:
  otherwise a second letter silently replaced the first and the tap opened the wrong
  one.
- On a tap `sw.js` steers an already open tab to the address: `client.navigate()`
  first, and if the worker does not control that tab — a `postMessage` the client
  handles by changing the hash. A closed panel is opened by `clients.openWindow()`.

## Enabling from «Уведомления» (FR-074)

The push card (enable on this device, «Проверить», types, diagnostics) also stands
on the «Уведомления» page — where a manager ends up when notifications do not
arrive — not only in «Настройки → Это устройство». It is the same `renderPushCard()`,
not a second implementation.

## API (FR-072)

All require a login:

- `GET  /api/push.php?action=key` → `{key}` — the public VAPID key;
- `POST /api/push.php?action=subscribe` `{subscription:{endpoint,keys:{p256dh,auth}}}`;
- `POST /api/push.php?action=unsubscribe` `{endpoint}`;
- `GET  /api/push.php?action=prefs` → types, muted ones, device count, availability;
- `POST /api/push.php?action=mute` `{kind?, muted}`;
- `POST /api/push.php?action=test` → `{sent}` — a check of the whole chain from this
  device.

## Diagnostics

«Notifications do not arrive» cannot be answered without facts, so both the client and
the server lay them out:

- the client (in «Настройки»): the protocol, `isSecureContext`, presence of
  `serviceWorker` / `PushManager` / `Notification`, the granted permission, the worker
  registration error, the last subscription error, the User-Agent;
- the server («Админ → Обзор»): whether push is on, why it is unavailable
  (`openssl_pkey_derive`, `curl`, switched off in the settings), the number of
  subscribers and devices.

The most common cause is HTTP instead of HTTPS: without a secure context the browser
gives neither a service worker, nor installation, nor push. This is said in plain
words.

## Data

```
push_subscriptions(id, manager_id, endpoint UNIQUE, p256dh, auth, user_agent, last_used_at, created_at)
push_mutes(id, manager_id, kind, created_at)   -- kind NULL = all types
notifications.url                              -- where a tap leads (schema v14)
```

Schema version 9; the notification address was added in v14.

## Settings

Group `push`: `PUSH_ENABLED`, `PUSH_VAPID_PUBLIC`, `PUSH_VAPID_PRIVATE` (secret),
`PUSH_VAPID_SUBJECT`.

## Files

`lib/webpush.php`, `lib/push.php`, `public/api/push.php`, `public/sw.js`,
`public/manifest.webmanifest`, `public/assets/icons/*`, `public/index.php`,
`public/assets/js/app.js`, `lib/notifier.php`, `lib/settings.php`,
`lib/bootstrap.php` (migration v9), `.htaccess`.

## Origin

A port of `src/WebPush.php`, `src/Push.php`, `sw.js` and the client block from
`dansury/kraskiweb` (see `spec/notifications.md` there). Differences: subscriptions
belong to `managers`, not `users`; muting is by type only (there are no companies
here); the VAPID keys live in `Settings`, not in `app_state`.
