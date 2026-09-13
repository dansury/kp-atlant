# Module 018 — A КП that is complete, or says where it is not

**Status:** Implemented
**Files:** `lib/kp_content.php`, `lib/request_items.php`, `lib/parser.php`, `lib/triage.php`,
`lib/crm.php`, `lib/requisites.php`, `lib/prompts.php`, `lib/pdf.php`, `lib/settings.php`,
`lib/bootstrap.php`, `lib/mailsync.php`, `templates/kp.html`, `public/api/proposals.php`,
`public/api/requests.php`, `public/api/mail.php`, `public/assets/js/app.js`
**Tests:** `tests/module_018.php`

Five things found by sending one real request through the whole pipeline. Two of
them are requirements nobody had written down (§1, §2); three are places where
the code had drifted from a spec it already had (§3, §4, §5).

The thread running through all five: **the document must not be quietly
incomplete.** A hole a manager can see is work; a hole only the client sees is a
lost deal.

---

## 1. Подтверждение стало утверждением о документе, а не кликом

**Before.** SC-005 of spec 001 says «ни одно КП не уходит клиенту без
подтверждения менеджером», and the code honoured it literally: `confirm` and
`send` checked that a manager was logged in, and nothing else. A КП whose
«Итого» was **0,00 руб.** was confirmed and sent on exactly the same click as a
priced one. Nobody was lying — the requirement simply never said what
confirmation was confirmation *of*.

**Now.** `KpContent::priceGaps()` weighs the click against the document:
positions whose price is zero or absent, the КП total, and whether the КП has any
positions at all. `requireNoPriceAck()` in `public/api/proposals.php` runs it
before `status='confirmed'` and again before the letter goes out.

**Nothing is blocked dead.** A quote «по запросу цены» is a real document, and
the manager who means it says so once:

- The refusal is `HTTP 409` carrying `no_price` — the offending positions by
  number, name and quantity, plus the total. The browser asks with those
  positions named, not with a generic message.
- `no_price_ack: true` on the retry goes through, and the answer is **stored**
  (`proposals.no_price_ack_json`: the item ids, the manager, the moment). So
  «Подтвердить» and «Отправить» ask once between them, not twice.
- A price edited afterwards drops the answer (`update` compares the money before
  and after) and the question comes back. An edit that leaves prices and
  quantities alone keeps it.
- Every such confirmation is a `warning` in the journal, with the positions
  named: «КП подтверждено без цены по N поз.»

An empty КП is the same hole from the other side and is asked about the same way.

## 2. Позиции, на которые каталог не ответил, названы вслух

**Before.** A request line that matched nothing went into the КП with the
client's own words and a price of 0. Module 013 §1 answers «нашли, но нет на
складе» with an analogue — it says nothing about «в каталоге такого нет вообще».
That case had no requirement anywhere, and no output: the manager saw a grey
«без цены: N» under the positions table, and the client saw **nothing at all**.
The draft reply simply left the line out.

**Now.** A row with no `moysklad_product_id` that nobody confirmed by hand is not
a position — it is an unanswered sentence, and it is printed as one.

- `KpContent::unmatchedRows()` (КП) and `RequestItems::unmatched()` (request)
  name those rows, **verbatim in the client's own wording** — `requested_name`
  where there is one, `raw_name` otherwise, never our guess at it.
- `templates/kp.html` prints them under **«Позиции запроса, по которым нужно
  уточнение»**, after the positions and before the post-table text. Because the
  Word file is the same template (module 016), the block reaches both documents.
- The draft reply — both `RequestParser::generateReply()` and `Triage::draft()` —
  carries `RequestItems::unmatchedBlock()`: one wording, so the letter and the
  document say the same thing.
- The cover letter gets the same list and is told to name it.

**A row the manager confirmed by hand is not a hole.** `is_confirmed` there is
his decision — that line is answered, whatever the catalog thinks.

The text above the block is `KP_UNMATCHED_NOTE` in «Настройки → Оформление КП».

## 3. Письмо и таблица называют товар одним именем

**Before.** `generateCoverLetter()` handed the model a free-form list and let the
wiki retrieval fill in the rest, so one КП went out saying **«EARMOR M32X mark3»**
in the letter and **«ELECTRONIC EARMUFFS»** in the table. This is exactly what
module 016 exists to prevent between the PDF and the Word file — only here the
two halves that disagreed were inside one document.

**Now.** The letter is bound to the table:

- the list handed to the model is built from the КП's own `proposal_items`, from
  `product_name` — the string that will be printed in the table — and is labelled
  as such in the user message;
- the `cover_letter` prompt forbids any other name: not the model from the
  client's request, not a name from the wiki, not a translation, and no position
  that is not in that list;
- positions with no price are not offered to the model as products at all — they
  arrive in the «не нашли в каталоге» block of §2 instead.

The requested name still reaches the letter where it belongs: inside the
substitution block, as «просили X → предлагаем Y» (module 011).

## 4. Покупатель в документе — организация, а не e-mail

**Before.** `Crm::resolveCounterparty()` ends with `$name !== '' ? $name : $email`.
In the test letter the organization was named only in the signature of a *quoted*
message («С уважением, … АО "Уралэлемент"»), the prompt never looked there, so
`org_name` came back null — and the e-mail address became the company's name, the
`Requisites::snapshot()` `buyer.name`, and the **Покупатель** line of an official
document.

**Now**, three layers, in order:

1. **The prompt looks where a human signs.** `parse_request` and
   `classify_request` are told to read the signature, the letterhead and the
   quoted or forwarded parts, to take the legal form together with the name, and
   never to use an address or a domain as `org_name` — null instead.
2. **A regex safety net under the model.** `Crm::companyFromText()` reads
   «ООО/АО/ЗАО/ПАО/ИП/ФГУП/…» plus the name straight out of the letter, quoted
   forms first («АО "Уралэлемент"» → `АО «Уралэлемент»`), unquoted after. Our own
   организация is filtered out — every reply quotes our signature back at us.
   `resolveCounterparty()` reaches for it whenever the name is empty *or is
   itself an e-mail address*, before the e-mail fallback is allowed to win.
3. **The document refuses to print an address as a name.** If `buyer.name` still
   validates as an e-mail, `Requisites::snapshot()` prints **«Покупатель
   уточняется»** and keeps the address in `buyer.name_source` for the manager's
   screen. The КП editor shows it and says which card to fix.

Nothing here is generated: every value is МойСклад's, the letter's, or absent —
the rule module 013 §4 rests on.

## 5. «Подобрать нейросетью» не трогает подтверждённые строки и отчитывается

Not a new requirement — spec 008 §5 already says *«confirmed line is never
re-picked by the automatic match»*, and `RequestItems::rematch()` already skipped
those rows. What the manager saw was different: the table was blanked to a
«Спрашиваем нейросеть и подбираем…» spinner, and an error left the blank behind,
so a ✓ that had never been touched looked gone.

**Now.**

- The rows stay on screen; only the buttons go inactive under a note. An error
  leaves the table exactly as the save before it left the database.
- A confirmed row is not merely skipped on the way back — it is **never sent**.
  `rematchReport()` builds the query list from the open rows alone, so the model
  normalizes only what is still open and cannot rename a line it never saw. One
  less line in the prompt is also one less line paid for.
- The button now says what it did: `{repicked, found, empty, kept, alternatives}`
  comes back with the rows, and the toast reads «Пересмотрено строк: 2 —
  подобрано: 1, без совпадений: 1, подтверждённых не тронуто: 1». A run that
  found nothing no longer looks like a run that found everything.

---

## Requirements added to spec 001

- **FR-021** — confirmation is a statement about the document: a КП with a
  position priced at zero, or with no positions, is refused once with those
  positions named, and goes through only on an explicit second answer, which is
  recorded and invalidated by a later price edit.
- **FR-022** — request positions with no catalog match are named to the client
  verbatim, in the КП and in the draft reply, never dropped silently.
- **FR-023** — a position is called by one name throughout a КП: the cover letter
  uses the names of the table.
- **FR-024** — an e-mail address is never printed as the buyer of a КП.

## Settings added

| Key | Group | Meaning |
|---|---|---|
| `KP_UNMATCHED_NOTE` | kp | Text above the «нужно уточнение» block of the КП |

## Schema v20

- `proposals`: `no_price_ack_json` — which positions were knowingly signed off
  without a price, by whom and when. Empty means «КП с ценами».

## API changes

- `jsonError()` takes an optional `$extra` array, merged into the error body, so
  a refusal can carry the data the browser needs to ask a useful question.
- `proposals.php?action=confirm|send` accept `no_price_ack: true` and answer
  `409` with `no_price` without it.
- `proposals.php?action=get` returns `price_gaps`, `unmatched` and `buyer` —
  what the editor warns about before «Подтвердить» is pressed.
- `requests.php?action=items_rematch` returns the counts next to `items`.

## Not done here

- The no-price answer is per document, not per position: the manager confirms
  «этот КП уходит без цены по этим позициям», not each line separately. Per-line
  «цена по запросу» as a printed value is a separate job.
- `companyFromText()` reads Russian legal forms only. A foreign counterparty is
  still named by the model or by the manager.
- Nothing here makes the model's `org_name` authoritative: the manager's own edit
  of the company card always wins, and always did.
