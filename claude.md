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
The categories cover the WHOLE deal, not its first letter: delivery, ЭДО, closing documents,
the договор, a tender and ГОЗ each answer from their own facts, and `other` is a failure to
classify, not a place to put the second half of a sale.
Never call the model twice for one letter, and never let a draft invent a price, a stock
level or an order status — those come from `Catalog`, not from the wiki.

## Inbound channels
A letter that arrived from the site form is the VISITOR's letter (module 015):
`SiteForm::unwrap()` runs inside `MailArchive::storeIncoming()` — before the thread key, before
the sender is stored — so the party, the company card and the reply address are the client's,
never ours. Two thirds of what that form sends is a bot, and `SiteForm::spamReason()` says so
in plain PHP inside `Triage::prefilter()`: a form submission must never reach the model to be
recognised as spam. A lead with a phone and no address is category `callback` — it has no reply
prompt on purpose, because there is nothing to answer to; it is a call, and the phone goes in
the notification. Never resolve a counterparty from one of our own addresses — `Crm::isOurAddress()`
is what keeps a company card for `atlant-armour.ru` from existing.

A delivery report is not service mail to file away: `Bounce::applyTo()` marks the outgoing
letter that failed (`sent_state = bounced`), and the manager hears about it. An answer that
silently never arrived is a client who thinks he was ignored.

Text that goes INTO a prompt goes through `MailText::forAnalysis()` — no quoted history, no
gateway banner; the archive keeps the full body. HTML becomes text ONLY through
`MailText::fromHtml()`: `strip_tags()` alone keeps the CSS inside `<style>`, which is most of a
modern letter.

Every answer leaves from the address `MAIL_OUTGOING_FROM` names — `Mailboxes::outgoing()` is the
only way to pick a sending mailbox, and it beats whichever mailbox the manager opened.

## The КП document
The КП exists ONCE, as `PdfGenerator::html()`. `PdfGenerator` prints it, `DocxGenerator` converts
the same HTML to Word (module 016) — never write the document a second time in another format,
or a client ends up with two different offers. 35 of the 37 КП in the archive left as `.docx`,
so Word is the default attachment (`KP_ATTACH_FORMAT`); the PDF stays for whoever asks for it.
Word validates the ORDER of `w:rPr` and `w:pPr` children, not just their presence — the
sequence in `Html2Docx` is the schema's, and changing it is what makes Word offer to repair the
file.

## Catalog
`products_cache` has two sources and must keep working on either: `MoySklad::refreshProductCache()`
through the API, and `CatalogImport::run()` from a МойСклад Excel export (module 008). Never make a
KP, a match or a draft depend on the API being reachable — a dead token must degrade to the imported
catalog, not to an error. Stock never comes from the file. Prices and names for a client-facing
document come from `products_cache`, never from a model.

## Matching positions
A line of a letter gets its catalog row by itself — `RequestItems::ensure()` runs on the
first open of the card and costs no model call. What must never happen by itself is a choice
between equally good candidates: `ProductMatcher` marks such a line `needs_choice` and the
card asks. Do not lower that to «берём первый». Semantic search (`Embeddings`, Yandex Cloud)
is an optional second opinion: every code path must give the same answer with no key, no
index and a dead API, only worse. Indexing is always batched (`curl_multi`), time-budgeted
and resumable through `text_hash` — never write a loop that embeds the whole catalog in one
request.

## Mail
Letters live in threads, not rows: `MailThreads::keyFor()` groups them by the subject with
«Re:»/«Fwd:» stripped **and the party the letter is with** (`MailThreads::party()` — the
corporate domain, or the whole address on a free mailbox), so an answer sent from Gmail
belongs to the Yandex conversation while «Запрос КП» from two different companies stays two
conversations. The party of a letter WE send is its addressee, never us. A thread's request
and company are the whole conversation's (`MAX(request_id)`), never the newest letter's — a
client's «спасибо» carries neither.
Anything that archives a letter must set `thread_key`, and an answer must inherit the thread
of the letter it answers, whichever mailbox it leaves from. A copy in the IMAP
«Отправленные» is not optional and not silent: `Mailer::send()` resolves the real folder and
records the outcome in `mail_messages.sent_state` — a failure is `Logger::error()` and a
visible warning, never a swallowed warning.

## Interface
The interface is light: a white page, blocks drawn with a hairline border and a soft shadow,
and the shop's red as the only accent — a tint means something (waiting, warning, a stage of
the board), never decoration. Red text goes through `--accent-ink`; `--primary` is a fill and
a border colour. Keep every text/background pair at WCAG AA and say so in the CSS comment.

There is ONE «Настройки» item in the header: everything lives under `#settings/<tab>`, admin-only
tabs hidden from a plain manager. Do not add a second top-level entry for a settings screen.

The screen wears the shop's own skin (atlant-armour.ru): a light chrome bar carrying the red
wordmark — never a red bar — white blocks standing on a warm charcoal stage, one vivid brand red,
and corners that are all but square. Because the page is dark and the blocks are white, ink is a
token, not a constant: `--text`, `--text-muted`, `--border` and `--accent-ink` are declared for the
stage in `:root` and restated on every light block, which must also restate `color: var(--text)` —
redeclaring the property alone leaves `color` inherited from `body`. A new block that has its own
light background belongs in that selector list. Red TEXT always goes through `--accent-ink`;
`--primary` is for fills, borders and rules only, and putting it on text breaks contrast on both
the white and the dark side.

## Board
Everything a letter needs is IN the letter (module 012): the «Подходящие позиции» table and
the reply box are drawn under the conversation, so a КП is priced and an answer is written
without leaving the company card. The positions table is scoped to its host block
(`[data-match-host]`) because several can be open at once — never go back to page-wide
element ids for it. One reply box per conversation: do not add a second button that opens
another way to answer.

«Письма» is ONE board and a card on it is a COMPANY (module 011) — its letters, its requests
and its КП are things you open the card to see, never a second list beside it. New mail puts
itself there: `Boards::sync()` runs on every open of the board, so nothing waits for a manager
to press «в доску». Do not add a screen that lists letters, requests or companies as a sibling
of the board, and do not make a card carry one letter again. A card with an unanswered letter
is bold and rises inside its column; an answered one dims and keeps the order it was dragged
into — the column itself is the manager's decision and code never changes it.

## Analogues, the shape of a request, and the КП document
A position with no FREE remainder (`stock - reserved`) is never left blank: `Alternatives`
answers it with something we can ship — by name through `Synonyms` (built-in groups plus
`MATCH_SYNONYMS`, never replaced), then by description against the requirements read out of
the client's own sentence — and the КП NAMES the requirements it meets, quoting OUR text as
proof. A claim our own description does not support never reaches the document, whoever
made it. Every path here must give the same answer with no model key, only flatter: the
model pass sharpens the choice, it is not what finds it. A line with no analogue in stock
stays «под заказ» — it never becomes a question to the manager or to the client.

Whether a request arrived as a table is decided by `RequestShape` from the letter alone,
stored once on `requests.shape`, and never asked or inferred by a model — a table-shaped
request opens its КП with the correspondence table, a text one does not. The manager's
switch on the КП beats the setting, which beats the shape.

НДС, реквизиты, адреса, банк and the договор come from `Requisites` (the организация and
the договор in МойСклад) and are FROZEN onto the proposal in `proposals.requisites_json`
when it is generated. Never regenerate that block on reprint, and never let a model near
it. A link to the shop goes through `Bitrix::productUrl()`, which verifies before it caches:
a 404 in a signed document is worse than no link.

## Prompts and the model's discipline
Every system prompt gets the discipline block appended by `Prompts::render()` — do the whole
job, no abbreviation, no invented facts, and a clarifying question ONLY when the answer is
needed to price something or issue a document. Put a new behavioural rule there, not in a
thirteenth copy inside one prompt. A generation that must be reproducible (a КП, a match)
calls the model at temperature 0 and treats its answer as a CHOICE among things the code
verified, never as a source of names, prices or stock.

## Configuration
Never read `config.php` directly. `config.php` holds DEFAULTS only and is optional; the effective value is `Settings::get('KEY')` (DB override → config.php → built-in default), and `$cfg` from bootstrap is already that merged array. A new setting must be declared in `Settings::SPEC` so the admin panel can show and override it. Secrets go through `Crypt` and never reach the browser.

## Errors
Report failures with `Logger::error()` / `Logger::exception()` (channel + context), not `error_log()` — the admin reads them in «Настройки → Логи».

## Language
Code comments in English. UI in Russian. Specs in English.

## Deploy
`data/` and `storage/` hold everything the service has learned — corrections, edited prompts,
the knowledge cache, the embedding index, settings, the signature, attachments — and none of
it is in git. They are in `pull.php`'s `ALWAYS_KEEP` together with `config.php`, and that is
load-bearing: `data/.gitkeep` IS in the repository, so a purge without that list walks in and
deletes the database. Never make a deploy protection depend on a field an operator has to
fill in. `tests/deploy_preserves_data.php` runs pull.php's own code and must stay green.

## Graphify
Use graph to understand the project and update it after new implementations.


