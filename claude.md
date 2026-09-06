# Project Rules — Atlant Armour КП Automation

## Spec-driven workflow
Always start with spec.md — thin navigation index (file → spec mapping). Read it first to find the relevant module spec. Then open ONE /specs/<module>/spec.md. Never read the whole /specs/ folder. Never preload multiple specs "just in case". For reference details open exactly one /specs/<module>/spec.md that matches the module you are editing. If the detail is missing there, read the source file — do NOT pull another /specs/<module>/spec.md unless the task genuinely spans modules.

## Stack
PHP 8.1+ / SQLite / vanilla JS. No heavy frameworks. Deploy on shared hosting.

## LLM integration
Use OpenRouter + Yandex Foundation Models wrapper from NeuroPro (lib/llm.php pattern). Dual-provider fallback chain. Providers, models, keys and all technical prompts are edited in the admin panel — read prompts through `Prompts::render()`, never inline a system prompt in code.

## Knowledge base
The company wiki lives in a separate repo (`dansury/Atlant`, `GRAPH/wiki`). Never inline its facts into code or prompts — the local copy is synced by `Knowledge::sync()` and injected into a generation with `Knowledge::augment($promptKey, $vars, $query)`, which picks only the wiki sections that match the text at hand. A new generation that may need company facts must go through `Knowledge::augment()` and declare its prompt key in `Knowledge::TASKS`.

## Incoming mail
Every inbound letter goes through `Triage` (module 006), never straight to the parser.
`Triage::prefilter()` decides for free whether a letter is service mail; `Triage::classify()`
does parsing and classification in ONE model call; `Triage::route()` maps the category to a
reply prompt and its fact sources. A new request category must be added to `Triage::CATEGORIES`
together with its prompt in `Prompts::registry()` and its budget in `Knowledge::TASKS`.
Never call the model twice for one letter, and never let a draft invent a price, a stock
level or an order status — those come from `Catalog`, not from the wiki.

## Catalog
`products_cache` has two sources and must keep working on either: `MoySklad::refreshProductCache()`
through the API, and `CatalogImport::run()` from a МойСклад Excel export (module 008). Never make a
KP, a match or a draft depend on the API being reachable — a dead token must degrade to the imported
catalog, not to an error. Stock never comes from the file. Prices and names for a client-facing
document come from `products_cache`, never from a model.

## Interface
There is ONE «Настройки» item in the header: everything lives under `#settings/<tab>`, admin-only
tabs hidden from a plain manager. Do not add a second top-level entry for a settings screen.

## Configuration
Never read `config.php` directly. `config.php` holds DEFAULTS only and is optional; the effective value is `Settings::get('KEY')` (DB override → config.php → built-in default), and `$cfg` from bootstrap is already that merged array. A new setting must be declared in `Settings::SPEC` so the admin panel can show and override it. Secrets go through `Crypt` and never reach the browser.

## Errors
Report failures with `Logger::error()` / `Logger::exception()` (channel + context), not `error_log()` — the admin reads them in «Настройки → Логи».

## Language
Code comments in English. UI in Russian. Specs in English.

## Graphify
Use graph to understand the project and update it after new implementations.


