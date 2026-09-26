# Module 014 — Auto-deploy: the update check that runs on every page

**Status:** Implemented
**Files:** `lib/auto_pull.php`, `lib/bootstrap.php`, `public/api/notifications.php`,
`lib/settings.php`, `public/api/admin.php`, `public/assets/js/app.js`, `pull.php`, `pull-config.php`
**Tests:** none of its own — everything worth checking is HTTP (GitHub + `pull.php`);
`tests/deploy_preserves_data.php` still covers what a deploy must not eat.

`lib/auto_pull.php` is vendored from `site_yacloud_openrouter` (spec there:
`/spec/auto_pull.md`). Fixes land upstream first and are re-copied here.

---

## 1. What it is for

During active development the panel's checkbox replaces "open pull.php by hand after
every push". While **Автообновление кода → Проверять обновления сами** is on, the
background poll of every open tab (`notifications.php?action=poll`, every 30 s)
quietly asks GitHub for the head of the ref `pull.php` tracks:

- same commit — nothing happens, nothing is printed;
- new commit — `pull.php` deploys it; the tab picks up the new code on its next load.
  A non-XHR GET that runs the check (the library still supports one) is sent back
  (`302`) to the very URL it asked for.

Nothing is ever printed to the page: the outcome lives in the state file and in the
admin card.

## 2. Credentials

None of its own. `pull-config.php` next to `pull.php` (the project root is the web root,
see `DEPLOY.md`) gives `repo`, `branch` / `pr_number`, `gh_token` and `password_hash`.
`GITHUB_TOKEN` in ENV wins over `gh_token`, exactly as in `pull.php`.

The `pull.php` password is stored hashed, so the deploy request carries the cookie
`pull.php` issues itself: `pull_auth = <expires>|HMAC-SHA256('pull-auth|<expires>',
key = password_hash)`. The plaintext password is never needed and never stored.

## 3. Settings (`Settings::SPEC`, group `deploy`)

| Key | Type | Default | Meaning |
|---|---|---|---|
| `AUTOPULL_ENABLED` | bool | `0` | check on every page view |
| `AUTOPULL_INTERVAL` | int | `0` | minimum seconds between checks; `0` = every page view |
| `AUTOPULL_URL` | text | `` | explicit `pull.php` URL; empty = derived from the script directory |

The panel renders them from `SPEC` like any other group. The card also shows what is
tracked, when the last check ran and how it ended, plus **Проверить и обновить сейчас**
→ `POST admin.php?action=autopull_check` (`AutoPull::check($opts, true)`) — a one-off
check regardless of the checkbox, admin-only like the rest of `admin.php`.

## 4. Where it runs

`autoPullCheck()` (`lib/bootstrap.php`) — called ONLY by `notifications.php?action=poll`,
after `requireAuth()` has released the session (module 066). It used to run inside
EVERY GET to the API with the session still locked: `auth.php?action=me` at the start
of the page waited for GitHub and for the deploy itself — up to 20 s — and every other
request of the tab queued behind the session lock. No request a person waits for pays
for the check any more. Skipped: CLI (`cron/*`), any request that is not a GET, a check
younger than `AUTOPULL_INTERVAL`, and 120 seconds after a failed check. XHR and API
calls are never redirected — their answer is data.

## 5. State

`data/auto-pull.json` (mode `0600`, next to `kp.db` — `data/` is what a deploy keeps)
plus `auto-pull.json.lock`: `checked_at`, `head`, `deployed`, `deployed_at`, `note`,
`error`, `output`, `cooldown_until`. The deployed commit is read from `pull-state.json`
when the installed `pull.php` writes one, otherwise from this file. A rollback pinned in
`pull-state.json` stands the automation down; the manual button overrides it.

`flock(LOCK_EX|LOCK_NB)` keeps parallel page views from deploying at once — the losers
just render the current code.

## 6. Limits

- One GitHub API call per poll of an open tab at `AUTOPULL_INTERVAL = 0` (every 30 s;
  5000/h with a token).
- The deploy is a second HTTP request to the same host. Where the host serves one PHP
  request at a time it cannot answer while this page is being served: after 20 seconds of
  silence the wait is dropped, the page renders the old code, and `pull.php` finishes the
  deploy on its own (`ignore_user_abort(true)`) — the next page view is on the new code.
- Not a cron replacement: nobody opens a page, nothing gets deployed.
- `pull.php` replaces a file by writing it next to the target and renaming it over
  (module 066) and leaves unchanged files alone; it is in `ALWAYS_KEEP`, so the copy
  on the server is updated by hand.
