# Module 066 — The interface always loads

**Status:** Implemented
**Files:** `public/sw.js`, `public/index.php`, `public/assets/js/app.js`, `lib/app_build.php`,
`pull.php`, `lib/bootstrap.php`, `public/api/notifications.php`, `lib/support.php`,
`lib/settings.php`, `.htaccess`
**Tests:** `php tests/module_066.php`

## 1. What happened

On 2026-09-26 the panel on a manager's phone stopped at «Загрузка...» for good: the
header was drawn by PHP, the menu never appeared, and reloading changed nothing.

The chain, reproduced in headless Chromium:

1. Every file of a support ticket is committed into the deployed branch (`main`), so
   every file is a new commit, and the auto-deploy (module 014) redeploys the whole
   repository for it — 20 MB of archive for one screenshot.
2. `pull.php` copied every file over the live one with `copy()`: the target is
   truncated first and written afterwards. A request that arrives in between gets half
   of `app.js` — `SyntaxError: Unexpected end of input`, `App` is never defined.
3. The service worker served static files CACHE-FIRST and cached ANY response under
   the full URL. `app.js?v=<mtime>` keeps its `mtime` stamp when the copy finishes in
   the same second, so the half file stayed in the cache under the very URL the page
   asks for — on every reload, until the next deploy changed the stamp.

A second, slower road to the same screen: the auto-deploy check ran inside EVERY GET
to the API, `auth.php?action=me` included, with the PHP session still locked. A check
that found a new commit waited up to 20 s for `pull.php` (longer while it streamed its
output), and every other request of that browser queued behind the session lock.

## 2. The rules

- **Code is never served from a cache that nobody checks.** The service worker takes
  scripts and styles from the NETWORK and keeps a copy only for offline use; only a
  complete `200` of our own origin is ever stored. Images and icons stay cache-first,
  under the same rule. `VERSION` is bumped whenever the caching rules change — the
  activation of a new worker deletes every older cache, which is what heals a device
  that already holds a broken copy.
- **The page never waits forever.** `index.php` carries an inline boot watchdog
  (ES5, before `app.js`): when `app.js` does not load, does not define `App`, or the
  boot throws before anything is drawn, it clears this device's service workers and
  caches, re-fetches the two assets past the HTTP cache and reloads — ONCE per tab
  (`sessionStorage['kp.bootRetry']`). A second failure shows what failed and a
  «Перезагрузить» button instead of «Загрузка...». `App.init()` clears the flag when
  it runs, so the next failure is again healed by itself.
- **A failed boot is not a login screen.** `App.init()` treats only a `401` from
  `auth.php?action=me` as «not logged in». A timeout (45 s), a dead network, a `5xx`
  or a PHP fatal draw «Интерфейс не загрузился» with the reason and «Повторить»; after
  6 s of waiting the placeholder says the server is slow. A helper that throws while
  binding its listeners is logged and skipped — it never keeps the page blank.
- **The asset stamp is content-shaped.** `AppBuild::stamp()` (`lib/app_build.php`) is
  `mtime` AND size of `app.js` and `app.css`: a page rendered while a file is being
  written names a URL that nothing else will ever ask for.
- **A deploy never leaves half a file.** `pull.php`'s `copyTree()` writes each file
  next to its target (`.<name>.pull-<pid>`) and `rename()`s it over — atomic on the
  same filesystem. A file whose size and sha1 already match is not touched at all:
  its `mtime` (the browser's cache key) survives and nothing is rewritten under a live
  request. `pull.php` is in `ALWAYS_KEEP`, so the server copy is updated by hand once
  (see `DEPLOY.md`).
- **No request a person waits for pays for the auto-deploy.** `AutoPull::run()` is no
  longer called by `bootstrap.php`; `autoPullCheck()` runs it from
  `notifications.php?action=poll` — the background poll every open tab makes every
  30 s — AFTER `requireAuth()` has released the session. A deploy that takes a minute
  holds one poll, not the manager's screen.
- **Ticket files never reach the deployed branch.** `SUPPORT_ASSETS_BRANCH` defaults to
  `support-assets`. `Support::ensureBranch()` creates it on first use as an ORPHAN
  branch (a tree with one README, a commit with no parents, a ref): it shares no
  history with the code, so it can never be merged or deployed by mistake. An empty
  setting keeps the old behaviour — the repository's default branch. A branch that
  cannot be created is a warning in the log and the default branch, never a lost
  ticket.
- **Ticket files are not public.** `.htaccess` refuses `^support/`: the screenshots
  already deployed into the web root carry client data.

## 3. Healing a device that is already broken

The first deploy of this module changes the asset stamp format, so the page asks for
new URLs the old worker has never cached; the new `sw.js` (fetched on navigation,
`no-cache`) activates and deletes the old cache. Nothing has to be done on the phone.

## 4. Tests

`tests/module_066.php`:

- `copyTree()` replaces a changed file atomically (no temp file left), leaves an
  unchanged file with its old `mtime`, still honours `ALWAYS_KEEP`;
- `AppBuild::stamp()` changes when a file's size changes at the same `mtime`;
- `Support::ensureBranch()` against a stub transport: an existing branch is reused
  without writes, a missing one is created as tree → commit (no parents) → ref, a
  `422 Reference already exists` counts as created, a refused creation falls back to
  the default branch; `pushFile()` puts the file on that branch;
- `bootstrap.php` does not run `AutoPull::run()`; the poll does, after `requireAuth()`;
- UI by source: `sw.js` network-first for code, no caching of non-`ok`, bumped
  `VERSION`; `index.php` watchdog (once per tab, cache purge, visible error);
  `App.init()` 401-only login, timeout, boot error.

By hand, in headless Chromium at phone width: a server that answers ONE truncated
`app.js` must not leave the page on «Загрузка...» — the watchdog reloads once and the
board is drawn; with the watchdog's retry spent, the page names the error and offers
«Перезагрузить».
