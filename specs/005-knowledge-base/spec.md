# 005 — Knowledge base: the company wiki inside generations

## Problem
Everything the company knows about itself — products, protection classes, sizes, delivery,
warranty, tone of voice — lives in a separate repository (`dansury/Atlant`, folder `GRAPH/wiki`,
32 markdown pages, ~100 KB). The КП service used to see only `reference/tov.md`, so a letter
about a helmet model or a delivery term was written from the model's imagination.

The wiki keeps changing. The service must notice a new version by itself, and a token must be
configurable for a private repo.

## Scope
- Local copy of the wiki in SQLite (`knowledge_docs`), refreshed from the GitHub REST API.
- Version check before every generation, throttled by TTL.
- Relevance-based injection: only the wiki sections that match the text at hand.
- Admin page: state, manual sync, retrieval preview, document list.

## When the wiki is used (FR-051)
Injection is per generation task, `Knowledge::TASKS`, switchable in `KNOWLEDGE_TASKS`:

| Task (prompt key) | Why it needs company facts | Budget |
|---|---|---|
| `mail_reply` | The client asks about products, sizes, classes, delivery, warranty — the answer must be factual | 100% |
| `cover_letter` | The cover letter describes what we ship and why it is ours | 60% |
| `followup` | A reminder leans on positioning and current offers | 40% |
| `normalize_names` | Model names (Атом, Протон, Егерь…) map a request onto the catalog | 50% |

Deliberately **not** used:
- `parse_request` — pure JSON extraction from a letter; wiki text only adds noise and tokens.
- PDF assembly, MoySklad sync, invoices — no free text is generated there.

Inside an enabled task the base still stays out unless it is relevant: a section is a candidate
only when it shares at least `KNOWLEDGE_MIN_HITS` distinctive terms with the request. An
off-topic letter («пришлите реквизиты») gets no knowledge block and costs no extra tokens.

## Sync (FR-052)
1. `GET /repos/{repo}/commits?sha={branch}&path={path}&per_page=1` — the head commit that
   touched the wiki folder. Equal to the stored one → nothing to do (one cheap call).
2. Otherwise `GET /repos/{repo}/contents/{path}` (recursive, up to 3 levels) gives per-file
   blob SHAs; only files whose SHA changed are downloaded via `GET /git/blobs/{sha}`.
3. Files that disappeared upstream are deleted locally.

`Knowledge::ensureFresh()` runs at the start of every knowledge-using generation and is
throttled by `KNOWLEDGE_SYNC_TTL_SEC` (0 = check every time). `cron/sync_knowledge.php` keeps
the copy warm. A GitHub failure is logged in channel `knowledge` and never blocks a generation —
the cached copy stays in use.

## Retrieval (FR-053)
Documents are split into `##` sections. Query and sections are reduced to 6-char stems
(crude but enough for Russian forms), scored by idf-weighted overlap with a x2 boost for a hit
in the page title, tags or heading. The best sections fill `KNOWLEDGE_MAX_CHARS × task share`,
each clipped to 2500 characters. The block is wrapped in
`===== БАЗА ЗНАНИЙ ATLANT ARMOUR =====` with an explicit "do not invent what is missing" rule.

## Prompts
`cover_letter`, `mail_reply`, `followup`, `normalize_names` gained a `{{knowledge}}` placeholder,
editable in «Админ → Промпты». A prompt overridden before this module has no placeholder — the
block is appended to the end of the system prompt instead of being dropped.

## Settings (FR-054)
All in group `knowledge`, so they work both from `config.php` and from «Админ → Настройки»
(DB override wins, secrets encrypted, never sent to the browser):

`KNOWLEDGE_ENABLED`, `KNOWLEDGE_REPO`, `KNOWLEDGE_BRANCH`, `KNOWLEDGE_PATH`, `GITHUB_TOKEN`,
`KNOWLEDGE_SYNC_TTL_SEC`, `KNOWLEDGE_TIMEOUT_SEC`, `KNOWLEDGE_TASKS`, `KNOWLEDGE_MAX_CHARS`,
`KNOWLEDGE_MIN_HITS`.

The token needs only `Contents: Read` on the wiki repository. A public repo works without one
(60 GitHub API calls/hour — enough at the default TTL).

## Admin panel
«Админ → База знаний»: source and version (commit, checked / downloaded at), token state,
«Проверить и обновить» / «Перечитать всё заново», per-task on/off with its budget, a retrieval
preview (paste a letter → see which sections would be injected), and the document list.
The overview tab shows a short state card.

## Data
```
knowledge_docs(id, path UNIQUE, title, tags, content, sha, size, updated_at)
settings: knowledge.commit_sha | commit_at | checked_at | synced_at | last_error
```
Schema version 6.

## Files
`lib/knowledge.php`, `cron/sync_knowledge.php`, prompts and settings registries,
`lib/parser.php` call sites, `public/api/admin.php` (`knowledge`, `knowledge_sync`,
`knowledge_preview`), «База знаний» tab in `public/assets/js/app.js`.

## Sections found by meaning (module 009)

The wiki is searched by words first — FTS5, or the PHP scan where SQLite has none. Vectors are
a second opinion on top of that answer: `Knowledge::search()` asks `Embeddings::searchKnowledge()`
for sections the words did not reach and adds at most `KNOWLEDGE_VECTOR_TOP` of them, each above
`KNOWLEDGE_VECTOR_MIN` and only while the character budget allows. «Сколько ждать заказ» finds
«Сроки поставки» with no term in common; nothing is ever REMOVED from the lexical pick.

`knowledge_sections` is therefore built on every reindex even when this SQLite has no FTS5 — it
is what the vector index points at — and every section carries `text_hash`, the md5 of
`Knowledge::vectorText()` (title, heading, tags, body). Vectors live in `knowledge_vectors` keyed
by that hash, so re-reading the wiki from GitHub keeps the embeddings of every section whose text
did not move.

| Key | Meaning |
|---|---|
| `KNOWLEDGE_VECTORS` | use the vector index over the wiki at all (on) |
| `KNOWLEDGE_VECTOR_TOP` | how many sections meaning may add on top of the words (3) |
| `KNOWLEDGE_VECTOR_MIN` | cosine similarity below which a section is not added (0.55) |

«Настройки → База знаний» carries the progress card and the «Векторизовать базу знаний» button,
which drives the same stepped loop as the catalog; `cron/index_vectors.php` indexes both.
With no Yandex key, the switch off or an empty index, the wiki block is exactly the lexical one.
