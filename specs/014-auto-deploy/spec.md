# Module 014 — Auto-deploy: the update check that runs on every page

**Status:** Implemented
**Files:** `lib/auto_pull.php`, `lib/bootstrap.php`, `lib/settings.php`,
`public/api/admin.php`, `public/assets/js/app.js`, `pull.php`, `pull-config.php`
**Tests:** none of its own — everything worth checking is HTTP (GitHub + `pull.php`);
`tests/deploy_preserves_data.php` still covers what a deploy must not eat.

`lib/auto_pull.php` is vendored from `site_yacloud_openrouter` (spec there:
`/spec/auto_pull.md`). Fixes land upstream first and are re-copied here.

---

## 1. What it is for

During active development the panel's checkbox replaces "open pull.php by hand after
every push". While **Автообновление кода → Проверять обновления при каждом запуске**
is on, every page of the service quietly asks GitHub for the head of the ref
`pull.php` tracks:

- same commit — nothing happens, nothing is printed;
- new commit — `pull.php` deploys it and the browser is sent back (`302`) to the very
  URL it asked for, now answered by the new code.

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

`lib/bootstrap.php`, after the config, DB, logger, session and LLM are up and before
any output — so a redirect is still possible. Skipped: CLI (`cron/*`), any request that
is not a GET, a check younger than `AUTOPULL_INTERVAL`, and 120 seconds after a failed
check. XHR and API calls are checked but never redirected — their answer is data.

## 5. State

`data/auto-pull.json` (mode `0600`, next to `kp.db` — `data/` is what a deploy keeps)
plus `auto-pull.json.lock`: `checked_at`, `head`, `deployed`, `deployed_at`, `note`,
`error`, `output`, `cooldown_until`. The deployed commit is read from `pull-state.json`
when the installed `pull.php` writes one, otherwise from this file. A rollback pinned in
`pull-state.json` stands the automation down; the manual button overrides it.

`flock(LOCK_EX|LOCK_NB)` keeps parallel page views from deploying at once — the losers
just render the current code.

## 6. Limits

- One GitHub API call per page view at `AUTOPULL_INTERVAL = 0` (5000/h with a token).
- The deploy is a second HTTP request to the same host; a host serving one PHP request
  at a time will stall it until the timeout, and the card names the failure.
- Not a cron replacement: nobody opens a page, nothing gets deployed.
