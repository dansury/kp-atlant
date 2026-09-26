# 065 — Issues #131–#132: the autopick in one button, the client's sizes, no false analogue, a closed card reopens, every notice closes

The manager's ask: the positions of a letter must be picked «почти в одну
кнопку» — open the card, look, press «Сформировать КП». Issue #132 shows where
it broke: «Баллистический шлем Атом Арамид (размер Л, М) - количество по 2
штуки каждого» became ONE row «… - количество по», standing on size S, asking
«Равнозначные варианты — выберите один» between S, XL and another helmet, and
then offering size L of the same helmet as an «Аналог».

## 1. The client's sizes (#132)

### 1.1 The line of the letter (`ItemLines`)

- A quantity may be written as «количество по N», «кол-во по N», «по N»; the
  name stops before these words — «- количество по» is never part of a name.
- «каждого / каждой / каждый / каждому / на каждый размер» after the unit, or
  «по N» before it, marks the quantity as PER SIZE: the item carries
  `each = true`.

### 1.2 Sizes named by one hint (`Variants`)

- `Variants::SIZE_ALIASES`: letter sizes typed in Cyrillic are the same sizes —
  «С» = S, «М» = M, «Л» = L, «ХС» = XS, «ХЛ» = XL, «ХХЛ» = XXL, «ХХХЛ» = XXXL,
  «2ХЛ»/«3ХЛ»/«4ХЛ» = 2XL/3XL/4XL (a Latin/Cyrillic mix — «XЛ» — too). An alias
  is accepted ONLY after a size hint («размер», «р.», «р-р», «рост»): a bare
  «с» is a preposition and a bare «м» is a metre.
- `Variants::sizeList(string $text): string[]` — every size named after ONE
  hint: «размер Л, М», «размеры S/M/L», «р. 52-54, 56-58», «размер L и XL».
  Canonical labels (Latin, upper case), in the letter's order, no repeats.
  Empty when there are none.
- `sizeLabel()` answers through the same aliases (one size or null).
- `stripSize()` and `baseName()` remove the whole list with its hint: the
  catalog is searched by «Баллистический шлем Атом Арамид».
- `Variants::expand()`: a line with no per-size quantities («р.S-5шт…» stays
  the first rule) but two or more sizes in `sizeList()` becomes one line per
  size. Quantity:
  - `each` (the item flag, or «по N» / «каждого» in its text) → N on every size;
  - otherwise the letter gave a TOTAL: it is divided evenly, the remainder to
    the first sizes (5 over L, M → 3 + 2), and every line carries
    `qty_note` «в письме N шт. на размеры L, M — разделено поровну, проверьте».
    The total of the lines is the total of the letter: an error in quantity is
    an error in money. A total smaller than the number of sizes («1 шт, размер
    L, M») is «L or M», not a list — the line is not split.
  `qty_note` goes into the row's `match_hint` (§1.5), never into `notes`.
- `RequestItems::splitSizeRows(int $requestId): int[]` — a row written before
  this module as ONE line with a size list («… (размер Л, М) - количество по»)
  is split the same way: the first size stays on the row, the others are
  inserted right after it; returns the rows now standing on a size. Confirmed,
  out-of-scope and already labelled rows are not touched. It runs on every open
  of the card (`ensure()`, followed by `RequestItems::resolveLabels()`, which
  moves each such row onto its size within the product it already found — no
  model, no catalog search — and drops a machine analogue that was only another
  size of the same product) and at the start of «↻ Подобрать заново».
- A re-match clears the machine's «аналог: …» from `notes` together with the
  analogue flag: `notes` prints in the КП.

### 1.3 The size picks the modification — and the colour the client wrote

`Variants::pickFor(array $variants, string $label, string $context = '', float $qty = 0): ?array`
(`pick()` stays as its one-argument form):

1. modifications whose characteristic or bracketed name holds the label
   («L» ↔ «Размер шлема: L(60-62)»; exact value first, then a whole word);
2. among them, those whose OTHER values (colour) are named in the client's
   text (`$context`) — «мультикам», «койот»;
3. then free stock ≥ quantity, then any free stock, then the most free stock,
   then the name.

`Variants::ofLabel(array $variants, string $label): array` is step 1 on its own.
The result carries `other_choices`: labels of the other in-stock modifications
of the same size. `resolveRow(array $row, ?int $counterpartyId, string $context = '')`
passes the row's text and quantity, and when the client named no colour and
several are in stock it returns `match_hint` «выбрано: L(60-62) · Coyote; есть
также: L(60-62) · Multicam» — the row is settled, the manager sees the choice
was ours. A modification that does not exist is `match_hint` «модификации «XXL»
нет в каталоге — проверьте размер» (it used to go into `notes` and print in the
КП). A row standing on a size gets «ещё похожие» of the same size — the other
colours, then other products — never the other sizes of the same product
(`RequestItems::labelCandidates()`).

### 1.5 A hint for the manager, not for the client

`request_items.match_hint TEXT` (schema v55) holds what the matching wants the
manager to know: the quantity it divided, the colour it chose, why the model
picked the row. `notes` prints in the КП and the letter text; `match_hint`
never does. The card shows it under the row as «ℹ …» (`App.matchHintNote()`).
A product the manager puts on the row (`save()` with a changed product,
`choose()`) clears it; a re-match rewrites it.

### 1.6 Equal candidates of one product are the product

When the candidates within `MATCH_EQUAL_DELTA` of the leader are all
modifications of ONE product (or the product and its modifications),
`ProductMatcher::matchItems()` puts the product itself first and does not ask:
the size from the letter chooses the modification afterwards. Candidates carry
`parent_id` for that. A line with no size gets the hint «подходят несколько
модификаций одного товара — стоит товар целиком, размер и цвет можно выбрать в
«ещё похожие»».

### 1.7 What a sized row searches by

`Variants::searchName(string $rawName): string` — the row's own name without the
size, the colour and a list of quantities, but WITH a bracketed class: «Плита
(Бр4) (размер L)» searches «Плита (Бр4)», not any plate. Used by a re-match and
by the model's pool.

### 1.4 The query is the product, not the sentence (`ProductMatcher`)

`rankedCandidates()` compares the catalog with the query stripped of the size
list and of the words that are never part of a name: «количество», «кол-во»,
«каждого», «каждый», «штук(и)», «размер(ы)» — `ProductMatcher::QUERY_NOISE`.
«Шлем Атом Арамид (размер Л, М) количество по» is the product «Шлем Атом
Арамид», which then wins by exact name (module 062) over its own modifications
and over «Атом-2».

## 2. An analogue is another product (#132)

- `Alternatives::candidates()` never offers the excluded product's own family —
  its parent, its modifications, the product itself. A sibling modification is
  the requested product in another size or colour; calling it «аналог» is what
  #132 shows.
- `RequestItems::fillAlternatives()`: a row standing on a modification with no
  free stock first goes back to its OWN family (`RequestItems::familyFix()`):
  - the client named a size → `Variants::resolveRow()` (the in-stock colour of
    that size); still nothing in stock → «под заказ» on that size (issue #118),
    never another product;
  - no size named → the product itself, whose stock is its modifications'.
  Only a row whose family has nothing in stock looks for an analogue.
- `healAnalogues()` already clears an analogue from the same family (issue #124).

## 3. The model steps in by itself (#132)

«Если каталог не справляется, то нейросеть должна подключаться автоматически.»

- `lib/autopick.php` → `Autopick`:
  - `Autopick::available(): bool` — `MATCH_AUTO_LLM = 1` and a provider with a key
    (`LLM::ready()`).
  - `Autopick::pendingIds(int $requestId): int[]` — rows the catalog did not
    settle and the model was not asked about yet (`llm_checked_at IS NULL`):
    no product; `needs_choice = 1`; a product found by description or scored
    below `MATCH_AUTO_CONFIRM` (`Autopick::unsettled()`; a row placed by a shop
    link or by the model is settled). Confirmed rows, out-of-scope rows,
    analogues and rows with no client words (added by hand) are never pending.
  - `Autopick::status(int $requestId): array{pending:int,ids:int[],available:bool}`.
  - `Autopick::pool(array $row, ?int $counterpartyId = null): array` — the
    line's candidates (see below), each `{moysklad_id, name, article, unit,
    price, characteristics, sizes, free, description, score, source}`.
  - `Autopick::run(int $requestId, ?array $ids = null): array{asked:int,picked:int,items:array}` —
    ONE model call for every pending line (prompt `match_pick`, temperature 0,
    `Knowledge::augment()` with the budget in `Knowledge::TASKS`). A line's pool
    is what the code already verified: its current product and candidates, the
    catalog's candidates for the line (`ProductMatcher::findCandidates()`) and
    its trade synonyms (`Synonyms`), and the full-text hits of its words
    (`ProductMatcher::search()`); a modification is shown as its product (the
    size is chosen by `Variants`, not by the model), at most 12 per line. The
    model answers `{"lines":[{"line":n,"pick":"<id>"|null,"reason":"…"}]}`;
    an id outside the pool is ignored. A pick moves the row (then
    `resolveRow()` for the client's size), `match_source = 'нейросеть'`,
    `needs_choice = 0`, `match_hint` «выбрала нейросеть: <reason>», «ещё
    похожие» = the rest of the pool; `null` leaves the row. The row is written
    by `RequestItems::place(array $row, string $productId, array $opts)` — the
    one way a machine puts a product on a row: size through `resolveRow()`,
    price from the catalog unless typed by hand, photos reset on a new product.
    Every line asked gets `llm_checked_at`, so a card never asks twice about
    the same line. Rows still out of stock then get their analogue (locally).
    A dead model, no key or a broken answer leaves the rows exactly as the
    catalog put them, stamps nothing (they are asked again next time) and is
    logged (`Logger::warning('catalog', …)`). `$ask` (a callable
    `fn(string $system, string $user): array`) replaces the model in tests.
- `request_items.llm_checked_at TEXT` (schema v55). A re-match clears it on the
  rows it re-picks.
- Setting `MATCH_AUTO_LLM` (bool, default 1) in `Settings::SPEC`, group `match`.
- Automatic, without making the card slow:
  - `requests.php?action=get` and `action=items` return
    `autopick: {pending: N, available: bool}`;
  - the card draws the catalog's answer at once and, when `pending > 0` and
    `available`, posts `requests.php?action=items_autopick` in the background
    (`App.autopick()`, once per request per tab) with «🤖 Нейросеть уточняет
    позиций: N…» on the block. The rows being asked are `inert` and marked
    `.match-row--busy` (the model will rewrite them); the other rows stay
    editable, and the autosave waits for the answer (`host._autopicking`), then
    lays the edits made meanwhile over it and saves. The answer redraws the
    rows and says «Нейросеть подобрала позиций: 2 из 3»; a model that found
    nothing better says so on the block.
- `ProductMatcher::search()` reads the catalog once per request (it is called
  several times per line).

## 4. One button (R6)

- «Подобрать по каталогу» and «Подобрать нейросетью» become ONE button
  «↻ Подобрать заново»: the catalog for every open line, then the model for
  what the catalog did not settle (`rematchReport($id, false, true)` →
  `Autopick::run()` on the re-picked rows). `items_rematch&smart=1` still
  normalizes names first for an API caller.
- The choice banner says what is left after both: «Осталось выбрать: N —
  каталог и нейросеть не решили однозначно» (without a model: «каталог не
  решил»); while the model is still to be asked — «нейросеть сейчас попробует
  сама». Analogue rows are not counted: they carry their own «Аналог» note.
- The report of the button adds «нейросеть уточнила: N» (or «нейросеть не
  ответила»).
- The whole path on first open: lines split by size → rows on the right
  modifications → the model settles the rest → analogues only for another
  product in stock. What is left for the manager is «Сформировать КП».

## 5. #131 — a closed card comes back to «В работе»

- `Boards::reopenClosed(int $boardId): int`, run by `sync()`: a card in a
  `closed`-kind column (not dismissed) whose company — or, for a conversation
  card, whose thread — received an INBOUND letter dated after the card was
  moved there (`moved_at`), not archived and not spam/service/not-our-profile,
  moves to the top of «В работе» (`workColumn()`). The letter's own date
  decides: an mbox import of old mail reopens nothing. Logged as
  «Карточка → «В работе» (клиент написал после закрытия)».
- This is the one backward move a card makes by itself; every other stage
  still moves forward only (module 062).

## 6. Every notice has a close cross (#132)

- `App.toast()` draws «×» (`.toast__close`, `aria-label="Закрыть"`) on every
  toast — success, info and the sticky error; the send-delay toast and the
  «Обновляем из МойСклад…» toast carry it too. A phone has no hover: a sticky
  error used to stay on screen for good.
- The «×» is 32 px, white on the toast colour (AA on all three backgrounds),
  and closes at once; hovering still holds a toast, as before.
