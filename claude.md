# Project Rules — Atlant Armour КП Automation

## Spec-driven workflow
Always start with spec.md — thin navigation index (file → spec mapping). Read it first to find the relevant module spec. Then open ONE /specs/<module>/spec.md. Never read the whole /specs/ folder. Never preload multiple specs "just in case". For reference details open exactly one /specs/<module>/spec.md that matches the module you are editing. If the detail is missing there, read the source file — do NOT pull another /specs/<module>/spec.md unless the task genuinely spans modules.

## Stack
PHP 8.1+ / SQLite / vanilla JS. No heavy frameworks. Deploy on shared hosting.

## LLM integration
Use OpenRouter + Yandex Foundation Models wrapper from NeuroPro (lib/llm.php pattern). Dual-provider fallback chain. Providers, models, keys and all technical prompts are edited in the admin panel — read prompts through `Prompts::render()`, never inline a system prompt in code.

## Knowledge base
The company wiki lives in a separate repo (`dansury/Atlant`, `GRAPH/wiki`). Never inline its facts into code or prompts — the local copy is synced by `Knowledge::sync()` and injected into a generation with `Knowledge::augment($promptKey, $vars, $query)`, which picks only the wiki sections that match the text at hand. A new generation that may need company facts must go through `Knowledge::augment()` and declare its prompt key in `Knowledge::TASKS`. The wiki is retrieved by WORDS first (FTS5, or the PHP scan); `Embeddings::indexKnowledge()` vectorizes the same `knowledge_sections` and only ADDS sections the words did not reach. A wiki vector is keyed by `knowledge_sections.text_hash`, never by the section id — `Knowledge::reindex()` rebuilds that table on every sync, and an index keyed by the id would be thrown away with it.

## Incoming mail
Every inbound letter goes through `Triage` (module 006), never straight to the parser.
`Triage::prefilter()` decides for free whether a letter is service mail; `Triage::classify()`
does parsing and classification in ONE model call; `Triage::route()` maps the category to a
reply prompt and its fact sources. A new request category must be added to `Triage::CATEGORIES`
together with its prompt in `Prompts::registry()` and its budget in `Knowledge::TASKS`.
The categories cover the WHOLE deal, not its first letter: delivery, ЭДО, closing documents,
the договор, a tender and ГОЗ each answer from their own facts, and `other` is a failure to
classify, not a place to put the second half of a sale.
The classifier is not the last word: the manager picks the category in a dropdown BEFORE
«Сгенерировать ответ», and a change there is a correction, not just a fix to one letter —
`Triage::correct()` stores it and `Triage::learned()` feeds the last such corrections back into
the classifier prompt as examples (module 022). A letter asking what a product CAN DO
(«можно ли отстегнуть слой») is `product_question`; answering it with «уточняем наличие, цену и
сроки» is the mistake this exists to stop.
Never call the model twice for one letter, and never let a draft invent a price, a stock
level or an order status — those come from `Catalog`, not from the wiki.

### Finding a letter
Search covers the WHOLE letter and the whole archive, not four columns of the working list
(module 023): subject, body, HTML body, sender, sender name, recipients, Cc, attachment file
names and the text extracted from them — with `archived = 'all'` so a letter does not hide
because somebody filed it. Words are ANDed (`MailArchive::searchTerms()`, quotes keep a phrase
whole); a search that silently ORs turns «иванов счёт» into every letter from an Ivanov. One
term parser, used by the mail list, the thread list and the board — three search boxes that
disagree are three bugs waiting.

### Taking a letter off the screen
«Спам» and «не наш профиль» do the SAME thing to the screen: archive the letter and delete the
board card built around it. A verb that only writes a category leaves the letter exactly where
it was, and the manager presses it again. A mailbox that is switched off hides its letters and
gives them back when it is switched on; a mailbox that is DELETED can never be switched on, so
its letters stay visible — otherwise deleting a mailbox silently eats the correspondence
imported into it (module 023).

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

Card text is Markdown on the way in and HTML on the way out, and `Markup` is the only door
between them (module 020). `markdownToHtml()` normalizes HTML it is handed anyway (module 022):
printing is the last door, and there is nowhere left to check afterwards — a field that still
holds `<ul><li>` must print a list, not its tags. `htmlToMarkdown()` also runs without
`ext-dom`: on a host without it `new DOMDocument()` is a fatal error in the middle of a КП. МойСклад descriptions are written in a visual editor and arrive as
`<ul><li>…`, so everything that copies product text into `proposal_items` — `enrichItems()`,
`splitDescription()`, the editor's own save — runs it through `Markup::toMarkdown()`, and the
template prints it with `Markup::markdownToHtml()`. Never put raw МойСклад HTML into the
document and never `htmlspecialchars()` a card field into it: the first ships a script into a
signed offer, the second ships the tags themselves. `toMarkdown()` is idempotent, so calling it
on a field a manager has already edited is safe.

Описание товара у позиции ОДНО (модуль 032). В поле под строкой подбора стоит описание из
`products_cache` — уже синхронизированное, за ним не ходят в МойСклад на каждый показ, — и
менеджер правит его там, где видит. Нетронутое на строке не хранится (`RequestItems::ownComment()`):
подбор поставит другой товар — поменяется и описание, а копия прежнего осталась бы врать.
Карточка КП печатает `comment_text`, если менеджер его написал, иначе `description_text`: два
поля растут из одного текста МойСклад, и напечатанные подряд читаются как повтор. «Характеристики»
и «Комплектация» — свои блоки, поэтому в поле попадает только описательная часть
(`KpContent::splitDescription()`). В промпт ответа клиенту описание не идёт вовсе: письмо называет
позиции, цены и сроки, а товар читается в КП.

A position the manager folds as «нет в наличии» (`proposal_items.is_excluded`) leaves the
priced table, the cards and «Итого» — `KpContent::printedItems()` is the ONE place that
decides what the document prints — but it never leaves the document: `unmatchedRows()` picks
it up and the КП names it in the client's own words, and the correspondence table answers
«уточняем» on its line. Dropping such a line silently is the hole module 018 exists to close.

A line marked «не наша номенклатура» is the ONE exception to that, and it is a different
statement (module 022): not «we could not price this» but «we do not sell this». `Scope` marks
it — from the `CATALOG_OUT_OF_SCOPE` rule list, or from the manager's own button, which adds
the line's words to that list — and it then leaves the document ENTIRELY: no priced row, no
«уточняем» line, no analogue, and `outOfScopeBlock()` tells the model to say nothing about it.
It never leaves the SCREEN: the row stays on the card, struck through, because silently
dropping a client's line is exactly what the manager must be able to see.

Требования клиента к размеру и цвету — это РАЗНЫЕ строки, а не одна на сумму количеств.
`Variants::expand()` splits «(р.S-5шт, р.M-13шт, р.L-7шт)» before the catalog is touched and
without a model call — an error in a quantity is an error in money — and `resolveRow()` moves
each line onto its own `products_cache` row (`product_type = 'variant'`, `parent_id`), with its
own article, price and stock. The catalog is searched by the PARENT name; the label lives on
the row (`request_items.variant_label`), never inside the name. A size token is accepted only
with an explicit hint («р.», «размер») or from a CLOSED set — otherwise «Рукав 5ELEM - 1 шт»
becomes a size.

The КП carries the supplier's requisites ONCE, in the header. The block at the end prints only
what the header does not have — bank, договор, buyer. The signature line has no blank rule on
it: a space left «под роспись» in a signed document reads as an unfilled form. The signature
belongs to the MANAGER (`Signatures::forProposal()`) — their own image and their own name, with
the организация's signatory as the default. The file is named `PdfGenerator::fileName()` and
both formats share it: `КП_Атлант_Армор_для_{кому}_от_{дата}` — `KP-2026-002.pdf` is a name
nobody finds again in their own downloads folder.

The КП carries a logo top left. `$legal['logo_path']` is an empty string on a fresh install,
not NULL, so resolve it by trying paths in order and taking the first that EXISTS — an
uploaded logo, then the bundled one. That was only half of it: **mPDF renders a TRANSPARENT PNG
only through GD**, and without it drops the image from the document silently
(`showImageErrors = false`) — which is why the JPEG photo and the grey QR printed and the logo
never did. Transparency is therefore removed BEFORE mPDF: `Branding::documentImage()` →
`Png::flatten()`, through GD when it exists and in plain PHP when it does not. Never hand mPDF
a picture straight from disk, and never read «загружен» as «печатается» —
`Branding::documentWarning()` is what tells them apart. `Branding` (module 021) owns that order and every other
place a logo appears: the КП, the app icon and the tab favicon are THREE kinds, all uploaded
through «Настройки → Логотипы» and all stored in `storage/logo/`, outside the repository,
because a deploy overwrites `public/assets/`. `PdfGenerator::bundledLogos()` reads `Branding`
and is the one place that decides what the document prints — never write a second list of
paths. `api/branding.php` serves the picture WITHOUT auth on purpose: the browser asks for the
favicon and the manifest icons before anyone has logged in. A КП that prints without a logo
reads as a draft.

### What the document does not say
A field with no data prints NOTHING — never a placeholder. «Покупатель уточняется» in a signed
КП reads as carelessness, not as honesty, so an unknown buyer means the whole «Покупатель»
block is absent (module 023). The same rule made the QR caption a setting, empty by default:
«Наведите камеру телефона» explains a QR code to someone who has been using one for ten years.
Every product card starts on a new page (`KP_PAGE_BREAK`) — a description torn in half across a
page break is a description nobody reads.

The document has a THIRD form besides Word and PDF: the same positions, prices, terms and
comments as plain text in the letter body (`KpText`, format `text`) — everything the file has
except the QR, which has nothing to encode when the link is already clickable. A preview that
cannot find its PDF on disk REBUILDS it: the document is fully described by the database, and
`{"error":"PDF not found"}` is a wall in front of a manager for a file a deploy deleted.

## Catalog
`products_cache` has two sources and must keep working on either: `MoySklad::refreshProductCache()`
through the API, and `CatalogImport::run()` from a МойСклад Excel export (module 008). Never make a
KP, a match or a draft depend on the API being reachable — a dead token must degrade to the imported
catalog, not to an error. Stock never comes from the file. Prices and names for a client-facing
document come from `products_cache`, never from a model. Stock comes from
`MoySklad::refreshStock()` — the `/report/stock/all` report, over the warehouses
`MOYSKLAD_STORES` names (empty = all). Pick only the ones you actually ship from: a остаток of
the shop window or of rejects, printed in a КП, is a promise nobody can keep. `reserve` is
subtracted everywhere downstream (`Alternatives::freeStock`) — promising reserved stock twice
is a missed deadline, not optimism. Modifications (`/entity/variant`) land in the same table in
the same shape the Excel import writes them: both roads into the catalog must answer alike.
The Excel import reads EVERY «Цена: …» column into `prices_json` under the МойСклад type name,
so a catalog loaded from a file knows as many price types as one synced through the API. The
import column and `CATALOG_DEFAULT_PRICE_TYPE` are ONE choice, not two settings: the import
falls back to the setting and writes the column it actually found back into it. Two separate
knobs meant a default price type that matched no price in the base and silently did nothing.

## Matching positions
A line of a letter gets its catalog row by itself — `RequestItems::ensure()` runs on the
first open of the card and costs no model call. What must never happen by itself is a choice
between equally good candidates: `ProductMatcher` marks such a line `needs_choice` and the
card asks. Do not lower that to «берём первый». Semantic search (`Embeddings`, Yandex Cloud)
is an optional second opinion: every code path must give the same answer with no key, no
index and a dead API, only worse. When an embedding does not arrive, the log and the panel
must name the HTTP code and what the API said — one line per batch, never one per position. Indexing is always batched (`curl_multi`), time-budgeted
and resumable through `text_hash` — never write a loop that embeds the whole catalog in one
request.

A position is matched by its NAME first and by its DESCRIPTION second (module 023,
`MATCH_DESC_WEIGHT`): «монокуляр» is not in the name of «Прибор ночного видения Филин», it is
in its description, and that is still the product the client asked for. The description score
is a CONTAINMENT (how many of the query's words the text holds), not a Jaccard — a description
is ten times longer than a query and any measure dividing by the union is zero for it — and it
is capped below a name match by construction, so a description hit is offered and never wins.
The row then says `source = description`: a line nobody can connect to the query needs to
explain itself.

## Money on a position
The price a КП prints is computed in ONE place — `Terms::price()` — and nowhere else. A
position has a manual discount and, when it is not in stock, a waiting term, a waiting discount
and a prepayment share (module 023). Discounts MULTIPLY, they do not add: 5% by hand and 10%
for waiting is 14.5%, and a КП that says 15% is a КП that undercharges. The three waiting
values are written onto a row READY BUT OFF (`KP_WAIT_AUTO = 0`): a discount granted on the
manager's behalf is the manager's money given away without asking, and only a human turns it
on. A price the manager typed carries `price_is_manual` and a re-match must never overwrite
it — it may refine the name, the article and the stock, never the number.

A variant with no price of its own inherits the product's — BY PRICE TYPE, not «whichever is
there»: size L has its own «Розница» while only the product has «Опт безнал», and a КП billed
at wholesale must take the product's wholesale price.

Цена строки каталога считается ВИЛКОЙ — `Catalog::priceRange()`, и `priceFor()` отдаёт её низ
(модуль 036). У общего товара своей цены часто нет вовсе: она стоит на модификациях, и стоит
там по-разному — такой товар уходил в КП нулём. Отвечают его модификации: `min` — с чего
начинается, `max` — чем кончается; цены совпали — вилки нет, и документ печатает одну цену, а
не «от 1 200 до 1 200». Модификация сама за модификациями не ходит: своих у неё нет.
Вилка живёт, пока цена строки и есть её низ: вписанное руками число отменяет её целиком.
Печатается она одинаково в PDF, в Word и в тексте письма, а «Итого» при ней называется «от»:
сумма низов и есть то, с чего начинается предложение. Складывать верх с низом нельзя — это
третья сумма, которой в предложении нет.

Тип цены, скидка и условия «под заказ» — решение на ВСЁ КП, а не на строку, и стоит оно над
таблицей подбора (модуль 036). Выбор запоминается за МЕНЕДЖЕРОМ (`Terms::conditions()` /
`Terms::remember()`, `managers.kp_terms_json`), а не в настройках сервиса: двое за одной доской
работают с разными покупателями. Проставляет его строкам сервер
(`RequestItems::applyConditions()`) — только он знает, что модификация без цены берёт цену
товара, а товар без цены берёт низ вилки. Строку с ценой, вписанной руками, общий выбор не
трогает, а условия ожидания получают ТОЛЬКО позиции, которых нет на складе: «под заказ» на том,
что лежит на полке, — это скидка ни за что.

НДС стоит под итогом КП ВСЕГДА — выделенным из цены, прибавленным к ней или формулировкой
неплательщика (модуль 030). До него сумма налога печаталась только по галочке
`show_vat_total`, выключенной по умолчанию, и клиент получал итог, про который непонятно,
что в нём есть. Галочки нет; колонка осталась в старых строках и ничего не решает.

Каким налог печатается, решает `KP_VAT_MODE`: `included` — цена каталога уже с налогом, и
документ выделяет его из итога («Цена за ед., в т.ч. НДС 5%» · «Итого» · «в т.ч. НДС»);
`added` — цена без налога, и он прибавляется к итогу («Цена за ед., без НДС» · «Итого без
НДС» · «НДС 5%» · «Итого с НДС»). Это ОФОРМЛЕНИЕ, а не факт из МойСклад, поэтому в снимок
реквизитов оно не замораживается: ставка и `payerVat` печатаются те, с которыми КП
подписывали, а вид цены — сегодняшний, и переключатель действует на все КП разом. У
отдельного КП может стоять своё (`proposals.vat_mode`); пусто — «как в настройках».

Считается налог в ОДНОМ месте — `Requisites::vatTotals()` — и оттуда же приходят СЛОВА под
итогом: PDF, Word и текст письма (`KpText`) печатают одни и те же строки, а доска КП
(`KpSet::board()`) показывает тот же итог и ту же оговорку про налог — при «цене + НДС»
сумма строк ещё не то, что заплатит клиент. Сумма налога, посчитанная второй раз другой
формулой, — это вторая сумма в одном предложении.

`added` меняет то, что клиент платит, поэтому счёт и заказ обязаны сказать то же самое:
`Requisites::msVatFlags()` отдаёт `vatEnabled`/`vatIncluded`, а `invoices.php` и `orders.php`
кладут их в документ МойСклад. Счёт «в т.ч. НДС» по КП «цена + НДС» — это скидка размером в
налог. В самом МойСклад «Цены включают НДС» должно стоять так же: за аккаунт сервис этого не
решает, и настройка об этом прямо предупреждает.

## Mail
Letters live in threads, not rows: `MailThreads::keyFor()` groups them by the subject with
«Re:»/«Fwd:» stripped **and the party the letter is with** (`MailThreads::party()` — the
corporate domain, or the whole address on a free mailbox), so an answer sent from Gmail
belongs to the Yandex conversation while «Запрос КП» from two different companies stays two
conversations. The party of a letter WE send is its addressee, never us. A thread's request
and company are the whole conversation's (`MAX(request_id)`), never the newest letter's — a
client's «спасибо» carries neither.
A letter that is not ours to answer leaves the SCREEN, not the mailbox (module 019):
`MailSync::archiveMessage()` stamps `archived_at`/`archived_reason` and moves the letter into
the account's own «Архив», and every list — threads, letters, the unread counter, the company
card, `Boards::sync()` and `MailThreads::messages()` — drops `archived_at IS NOT NULL` unless it
was asked for it. That last one was missed for a module and a half, and it is the one place
letters are actually SHOWN: a mailbox switched off kept displaying its letters inside the
conversation, which reads as «ящик отключила, а письмо осталось». Adding a
query over `mail_messages` that forgets that filter puts «не наш профиль» back in the manager's
face. The category `not_our_profile` is set BY HAND and is not in `classify_request`: it is a
verdict, not a classification.
A mailbox is switched off (`Mailboxes::setActive()`), not deleted, and its letters go off the
screen with it — reversibly, under `archived_reason = 'mailbox_off'`. Deleting one must say what
happens to its archive: every letter points at the mailbox by a foreign key, so `DELETE FROM
mailboxes` alone is the `FOREIGN KEY constraint failed` this module exists to end.

Reading a letter is something a MANAGER does, never something the screen does for him
(module 020). `mail.php?action=thread` marks a conversation read only when asked — `read=1` —
because the company card unfolds the newest waiting conversation by itself, and that unfolding
used to clear the unread count of letters nobody had looked at. Any new place that shows a
thread must decide which it is: an explicit open (`read=1`, or `mail.php?action=thread_read`
afterwards) or a preview that leaves the count alone.

The company feed («Заметки и события») carries NOTES and MILESTONES, not letters (module 020).
`Crm::chat()` drops rows that are plain correspondence; a letter is read and answered in
«Переписка», where it has its thread, its attachments and the one reply box. `Crm::logEvent()`
still records every letter — `last_inbound_at`/`last_outbound_at` and the unanswered highlight
depend on it — so narrow what is SHOWN, never what is written. Inside a conversation the
folding is a mail client's: our own answers and letters already read collapse to one line with
the beginning of their text, and what is open is the unread and the client's last letter.

One letter is ONE row of the archive (module 021). `MailArchive::exists()` is the only place
that decides a letter is already here, and with `MAIL_DEDUP` — on out of the box — it looks
across EVERY mailbox: the same letter addressed to two of our boxes, pulled back out of
«Отправленные» and then imported from a Gmail mbox is one conversation entry, not four.
The Message-ID is the first key; `dedup_hash` — sender, recipients, subject, normalised body
and the sha1 of every attachment — is the second, for a gateway that rewrote the Message-ID.
A letter shorter than 40 characters is NEVER matched by content: two «Спасибо!» in one thread
are two letters. Any new way into the archive goes through `storeIncoming()`, or it brings the
duplicates back.

Письмо, написанное самим себе, — ДВА факта, а не один: оно и отправлено, и получено (модуль
036). Так проверяют почту, и входящую копию съедала копия из «Отправленных». У такой входящей
копии дубликатом считается только другая ВХОДЯЩАЯ, и только когда письмо пришло с нашего адреса
и адресовано ТОЛЬКО нашим (`MailArchive::writtenToOurselves()`). Копия себе в «Копию» письма
клиенту под это не подходит — в «Кому» там стоит клиент, — и ответ остаётся в переписке один.

History is not a new request. An mbox import (`MboxImport`, `storage/mbox/`) and «Скачать весь
архив» store letters `processed_at` and `is_read = 1`, resume from a saved cursor — a byte
offset, never mid-letter — and attach the letter to the company it belongs to through
`MailArchive::linkCounterparty()`: nothing else would ever set `counterparty_id` on a letter
that skipped the request pipeline, and the imported conversation would live in the archive and
nowhere else. It matches EXISTING cards only (`Crm::findCounterparty()`) unless the operator
asked otherwise — a three-year archive would open a company card per newsletter — and it never
calls `Crm::logEvent()`, which would stamp `last_inbound_at = now` and make a 2022 letter look
like a client waiting for an answer today. An mbox is parsed by `Mime`, in plain PHP: importing
one is exactly what an operator does when the host has no `ext/imap` at all. The file itself arrives in PIECES: a Gmail export is hundreds of megabytes, nginx answers `413` with an HTML page before PHP is reached, and Google will not cut the export below a gigabyte — so the browser cuts it (`mbox_upload_init` / `_chunk` / `_finish`, `storage/mbox/.parts/`), halves the piece on every refusal and resumes from the byte the server actually holds. A piece the file already has is never appended twice and a piece out of place is refused: a letter cut in half is worse than a failed upload.

Anything that archives a letter must set `thread_key`, and an answer must inherit the thread
of the letter it answers, whichever mailbox it leaves from. A copy in the IMAP
«Отправленные» is not optional and not silent: `Mailer::send()` resolves the real folder and
records the outcome in `mail_messages.sent_state` — a failure is `Logger::error()` and a
visible warning, never a swallowed warning.

«Отправленные» is pulled by every sync — the button, the page opening, the cron — and not by
the cron alone: a manager answers from the phone and the board must know it. The folder NAME is
a guess (Yandex says «Отправленные», cPanel «INBOX.Sent»), so `MailSync::syncSent()` asks the
server for its own list when the configured name does not open, remembers what works
(`Mailboxes::rememberSentFolder()`, which also zeroes the UID counter — it belonged to the other
folder) and never lets that folder take the INBOX down with it: a sent-folder failure is
`sent_error` in the report, and the inbound letters still become requests. A folder nobody has
pulled yet starts from its LAST letters, not its oldest — four years of «Отправленные» would
otherwise reach today's answer in a hundred syncs; history is «Скачать весь архив», which walks
its own cursor. A letter that comes back out of «Отправленные» and is not already in the archive
was written past the service, so `MailSync::registerOutbound()` puts it on the company card and
logs it as an answer WITH THE LETTER'S OWN DATE (`Crm::logEvent($opts['at'])`, which only ever
moves `last_inbound_at`/`last_outbound_at` forward) — otherwise the card keeps burning «клиент
ждёт ответа» after it was answered, and an old letter pulled late would look like today's.

## Interface
The interface is light: a white page, blocks drawn with a hairline border and a soft shadow,
and the shop's red as the only accent — a tint means something (waiting, warning, a stage of
the board), never decoration. Red text goes through `--accent-ink`; `--primary` is a fill and
a border colour. Keep every text/background pair at WCAG AA and say so in the CSS comment.

There is ONE «Настройки» item in the header: everything lives under `#settings/<tab>`, admin-only
tabs hidden from a plain manager. Do not add a second top-level entry for a settings screen.
«Ликбез» is a tab like any other (module 021) — the manual for the SCREEN, and it stays there,
never in the company wiki: the wiki is product knowledge that goes into prompts, and an
instruction about a button inside a reply to a client is noise in the context. But the tab is no
longer where it is READ (module 022): the explanation stands next to the block it explains, as a
«?» that opens on a CLICK — there is no hover on a phone, so a hint living in `title` does not
exist there at all. Every hint text lives once, in `App.HINTS`, and the «Ликбез» page is built
from that same list: never write a hint twice.

Оформление КП, база знаний, промпты и ToV are open to a plain MANAGER (module 022) — they work
with those texts daily and spot a bad wording first. Switches, keys, mailboxes and logs stay
admin-only: those break the service, not a sentence. Every such edit goes through
`ContentLog::record()` and shows on the admin's «Обзор» — a text two people edit silently ends
up somewhere nobody meant. Tone of Voice lives in `storage/`, never in the repository: a deploy
overwrites repository files and used to erase every edit.

Nothing is ever wider than the phone. A grid column is `minmax(0, 1fr)` and its items get
`min-width: 0`: bare `1fr` is `minmax(auto, 1fr)`, and that `auto` is the column's MIN-CONTENT —
one `white-space: nowrap` line inside made the page 907 px wide on a 412 px screen, and
`overflow-x: hidden` then simply cut the text off. The text of a letter wraps
(`overflow-wrap: anywhere`); only a table, a diagram or a code block may scroll, inside its own
box. `document.body.scrollWidth` must equal the viewport width.

The screen wears the shop's own skin (atlant-armour.ru): a light chrome bar carrying the red
wordmark — never a red bar — white blocks standing on a warm charcoal stage, one vivid brand red,
and corners that are all but square. Because the page is dark and the blocks are white, ink is a
token, not a constant: `--text`, `--text-muted`, `--border` and `--accent-ink` are declared for the
stage in `:root` and restated on every light block, which must also restate `color: var(--text)` —
redeclaring the property alone leaves `color` inherited from `body`. A new block that has its own
light background belongs in that selector list. Red TEXT always goes through `--accent-ink`;
`--primary` is for fills, borders and rules only, and putting it on text breaks contrast on both
the white and the dark side.

### One letter, one screen
A request and a letter are ONE thing and open as one screen (module 023). `#mail/requests` does
not exist; `#mail/request/N` and the letter a notification links to both land on the thread
card, because a notification a manager cannot answer from is a notification that wasted the
trip. The layout is the same wherever the card is opened from: correspondence on the left,
matched positions on the right at desktop width and underneath on a phone — never the other way
round «depending on the entry point», which is what read as two different products. The facts
panel is on EVERY letter; on one with no request it says why it is empty rather than vanishing.

A card that costs a catalog match to open is cached for a minute in the tab (`App.apiCached`),
shown instantly and re-checked in the background. Any write clears the whole cache: what
exactly it changed is not visible from there, and a stale price is worse than a wait.

Hints are the SAME texts as `App.HINTS`, and on a first visit to a screen they run as a
guided tour — one bubble at a time, with the thing being explained highlighted. A wall of
text is a page nobody reads; a tour is a page everybody finishes.

## Board
Everything a letter needs is IN the letter (module 012): the «Подходящие позиции» table and
the reply box are drawn under the conversation, so a КП is priced and an answer is written
without leaving the company card. The positions table is scoped to its host block
(`[data-match-host]`) because several can be open at once — never go back to page-wide
element ids for it. One reply box per conversation: do not add a second button that opens
another way to answer. On a company card that box is ALREADY OPEN — the newest conversation
THAT AWAITS AN ANSWER unfolds with the card, and «Подходящие позиции» and «Сгенерировать
ответ» are on the screen without a click (module 019). Answered and sent conversations stay
folded, and the unfolding does not mark anything read (module 020). When nothing awaits an
answer the composer still stands open under the list — a company with no pending letter must
not send you looking for a «Написать» button. Do not put a «Написать» button back at the top of the card, and
do not hide the draft button when there is nothing to answer: disable it and say why. A
conversation with no request says so in the positions block — a silently missing table reads
as a feature that disappeared.

A letter being WRITTEN is work too, and it is on the board (module 033). The draft saves
itself from the first keystroke — for every letter, the first letter to a company included:
a draft has no message id and no thread key of its own, so it is keyed by the company it is
addressed to (`MailDrafts`). Saving it puts a card into «В работе» — the column is named
(`board_columns.kind='work'`), like the intake one, so renaming or reordering columns cannot
redirect the drafts. The card is filled in FROM THE LETTER: the company from the signature,
the ИНН and the phone out of the body, the subject and the first line — never a second form
asking the manager for what he has just typed. A company card already on the board is never
duplicated: it moves out of «Входящие» (somebody is writing to it), and a column the manager
dragged it into is left alone. Sending the letter keeps the card and clears the draft; an
erased draft takes its own card with it but never one that carries correspondence.

«Письма» is ONE board and a card on it is a COMPANY (module 011) — its letters, its requests
and its КП are things you open the card to see, never a second list beside it. New mail puts
itself there: `Boards::sync()` runs on every open of the board, so nothing waits for a manager
to press «в доску». Do not add a screen that lists letters, requests or companies as a sibling
of the board, and do not make a card carry one letter again. A card with an unanswered letter
is bold and rises inside its column; an answered one dims and keeps the order it was dragged
into — the column itself is the manager's decision and code never changes it.

Отмеченные галочками карточки переезжают ГРУППОЙ и своим порядком (модуль 036,
`Boards::moveCards()` — порядок считается один раз на всю группу, иначе она приезжает
перевёрнутой). Экран доски не имеет права врать: перетаскивание рисует переезд само, поэтому
после броска доска перерисовывается ОТВЕТОМ СЕРВЕРА, а перетаскивание, которое ничем не
кончилось, возвращает её как было. Показанное и сохранённое — одно и то же, иначе обновление
страницы отменяет работу, которую человек уже видел сделанной. Фильтры, поиск и отметки при
такой перерисовке остаются.
Отмечают карточки и ПО СТАТУСУ — ждут ответа, непрочитанные, с КП: фильтр для этого не годится,
он ПРЯЧЕТ остальные, а отметить надо, продолжая видеть доску целиком. Состояние карточки
разбирается в одном месте (`App.cardInState()`), общем с фильтром.

## Analogues, the shape of a request, and the КП document
A position with no FREE remainder (`stock - reserved`) is never left blank: `Alternatives`
answers it with something we can ship — by name through `Synonyms` (built-in groups plus
`MATCH_SYNONYMS`, never replaced), then by description against the requirements read out of
the client's own sentence — and the КП NAMES the requirements it meets, quoting OUR text as
proof. A claim our own description does not support never reaches the document, whoever
made it. Every path here must give the same answer with no model key, only flatter: the
model pass sharpens the choice, it is not what finds it. A line with no analogue in stock
stays «под заказ» — it never becomes a question to the manager or to the client.

Аналог называется СЛОВАМИ КЛИЕНТА (модуль 036). Галочка «аналог» на строке подбора открывает
поле, в котором уже стоит строка из письма, — менеджер её правит, и в КП она печатается над
названием нашего товара курсивом серым: закупщик находит в предложении свою позицию, не сверяя
два документа глазами. Живёт она в `alt_of` — том же поле, в котором аналог хранится с модуля
013, — и едет в `proposal_items.alt_of`; `raw_name` правка не переписывает: письмо — это то,
что написал клиент. Галочки нет — в документе только наше название: вторая строка под нашим же
именем это шум.

Whether a request arrived as a table is decided by `RequestShape` from the letter alone,
stored once on `requests.shape`, and never asked or inferred by a model — a table-shaped
request opens its КП with the correspondence table, a text one does not. The manager's
switch on the КП beats the setting, which beats the shape.

НДС, реквизиты, адреса, банк and the договор come from `Requisites` (the организация and
the договор in МойСклад) and are FROZEN onto the proposal in `proposals.requisites_json`
when it is generated. Never regenerate that block on reprint, and never let a model near
it. A link to the shop goes through `Bitrix::productUrl()`, which verifies before it caches:
a 404 in a signed document is worse than no link.

## The shop and the QR
The site answers through `bitrix-module/atlant.kpsync` (module 017) — an installable 1С-Битрикс
module that lives in this repository and is READ-ONLY against the shop. Its export
(`Bitrix::syncFromSite()`) is an optimisation over the per-product path and never a replacement:
every code path must still answer with no module, a module switched off and an unreachable site,
only slower. An exported row is matched on артикул, then on код — never on the name, because a
name that merely looks alike puts the wrong page into a signed document.
The QR beside the link encodes `proposal_items.site_url` itself — never a shortener, never a
tracking wrapper: what the client scans has to be the address he can also read. `Qr` writes a
base64 PNG because that is the only picture the preview, mPDF and `Html2Docx::image()` all read;
what it cannot encode returns null and the card prints the link alone.

## The Bitrix module
`bitrix-module/atlant.kpsync/include.php` registers an autoload map. Every class named there
MUST exist as a file: a missing one is not a silent no-op, it is `Failed opening required …`
on the module's own settings page AND a dead endpoint, because `kp.php` asks
`Config::enabled()` on its first line. `tests/module_023.php` walks that map and fails when a
promised file is absent.

## МойСклад stock
`/report/stock/all` reads several `store=` values in a filter as AND, not OR: asking for two
warehouses at once answers with an empty table, and an empty table written into the cache is
every position «под заказ» while the shelf is full. Warehouses are therefore asked ONE AT A
TIME and the numbers summed (module 023). An empty answer is never treated as «nothing in
stock»: the unfiltered report is tried, then `/entity/assortment`, which carries stock and is
readable by a token with no rights to reports. The result carries `error` and `fallback`, and
zero updated positions is reported RED in the panel — a refresh that matched nothing is a
breakage, not an empty warehouse, and silence about it costs a month of wrong КП.

## Контрагент, которого нет в МойСклад
A letter from a company МойСклад does not know is a dead end — no invoice, no order — and the
only way out used to be retyping the ИНН from the signature by hand. The ИНН is found instead:
`Crm::moyskladHint()` takes it off the company card, and when there is none, out of the letter
itself, attachments included (`Crm::letterText()`), and says so (`inn_from_letter`). It never
calls МойСклад — it is what the letter card shows; the network is touched only when somebody
presses «Создать контрагента в МойСклад». That button SEARCHES BY ИНН FIRST and links what it
finds: two companies with one ИНН in the reference book is a worse outcome than a missing one.
A card created here takes the conversation with it (`Crm::attachThread`) — letters, requests
and contacts — so the company is not an empty rectangle.

## Prompts and the model's discipline
Every system prompt gets the discipline block appended by `Prompts::render()` — do the whole
job, no abbreviation, no invented facts, and a clarifying question ONLY when the answer is
needed to price something or issue a document. Put a new behavioural rule there, not in a
thirteenth copy inside one prompt. A generation that must be reproducible (a КП, a match)
calls the model at temperature 0 and treats its answer as a CHOICE among things the code
verified, never as a source of names, prices or stock.

## Learning from corrections
Everything a human fixed after the machine lives in ONE table and ONE shape — what was asked,
what the machine answered, what it should have been (`Learning`, module 022). It has two
consumers and they must not drift apart: recent corrections go into the prompts as examples,
and the accumulated ones go out as an archive to the company wiki (`dansury/Atlant`,
`GRAPH/RAW/NEW`). The export order is load-bearing: the archive reaches GitHub FIRST, and only
a confirmed write stamps `exported_at` — stamping earlier loses the corrections when GitHub
answers with an error, and the next archive is built from the new ones only. The same question
with the same correct answer is never stored twice: identical examples crowd the different ones
out of a prompt.

## Setup, support and the trial request
A service nobody can INSTALL is a service with one operator (module 038). `SetupWizard` is
not a second settings screen: it asks the same `Settings::SPEC` keys, renders its fields from
that spec and writes through `Settings` — it stores no value of its own, only where the
operator stopped (`settings['setup_wizard']`, no `cfg.` prefix, because state is not a
setting). A step is closed by a FACT — a mailbox is on, the catalog has rows, a provider
answers, a logo is uploaded — never by «the field is filled»: that is what lets the wizard be
re-run on a working service and still say what is missing, and what keeps «Пройти заново»
from touching a single setting. Every key that has to be fetched from somewhere carries the
REAL link to the page that issues it and the rights to tick — «go to your account settings»
is not an instruction. The wizard never writes a second connection test: it calls the same
`test_moysklad` / `test_llm` the settings tabs call.

A manager who hits a broken screen must be able to say so FROM that screen, with a
screenshot. `Support` takes the complaint, the files and the screen's own hash, and stops it
at the ADMINISTRATOR: a public tracker is not the place for «у меня всё пропало», and the
title strangers will read is written by somebody who knows how to name it. Approval, and only
a confirmed issue number, marks the ticket sent — same order as the learning export, for the
same reason. A decline comes back to its author with a reason; silence is how you teach people
to stop writing. GitHub has no API for issue attachments at all, so a file is committed into
`SUPPORT_ASSETS_PATH` and printed in the issue body — image as an image, the rest as a link —
and it goes up BEFORE the issue. The original stays in `storage/support/` and is served by the
panel: a link into a private repository does not open for a browser that is not signed in.

A request that did not arrive by mail is still a request. The «Новый запрос» screen takes
TEXT AND FILES, its link stands ABOVE «Входящие» (in the intake column itself, where the mail
lands), and what it creates goes straight into «В работе» — it is being worked on already,
since somebody typed it in — and the browser lands ON THAT CARD: the endpoint returns the
address, not just an id. `requests.is_trial` marks the wizard's own trial request so that
«Ромашка» из примера never looks like a client waiting for an answer.

Quality is asked about, not assumed: 👍/👎 on the trial КП and the trial letter is a `quality`
support ticket carrying the model that produced it. A 👎 offers models that COST MORE
(`LLM::pricier()`) — by the real price where the refreshed OpenRouter catalog has one, by the
`tier` of the built-in catalog where it does not, and never a model whose provider has no key
or whose slug the cloud does not serve. The chosen one becomes the provider's default and
moves that provider to the head of the chain; a choice that lasts one request is not a choice.

## Configuration
Never read `config.php` directly. `config.php` holds DEFAULTS only and is optional; the effective value is `Settings::get('KEY')` (DB override → config.php → built-in default), and `$cfg` from bootstrap is already that merged array. A new setting must be declared in `Settings::SPEC` so the admin panel can show and override it. Secrets go through `Crypt` and never reach the browser: the panel shows `Settings::mask()` — the first four characters and the last four — which is enough to tell two tokens apart and useless to steal. An identifier that is not a secret (the МойСклад ID организации, a Folder ID) is shown in full; do not mask it.

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


