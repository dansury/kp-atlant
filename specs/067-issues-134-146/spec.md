# 067 — Issues #134–#146: letters open where they are tapped, the name of a position is read whole, the search narrows, the matcher remembers, СДЭК counts the delivery, the update is announced

**Status:** Implemented
**Files:** `public/assets/js/app.js`, `public/assets/css/app.css`, `public/index.php`,
`public/api/notifications.php`, `public/api/products.php`, `public/api/requests.php`,
`public/api/cdek.php`, `public/api/settings.php`, `lib/matcher.php`, `lib/match_memory.php`,
`lib/variants.php`, `lib/request_items.php`, `lib/mail_compose.php`, `lib/cdek.php`,
`lib/moysklad.php`, `lib/settings.php`, `lib/bootstrap.php`
**Tests:** `php tests/module_067.php`

## 1. Letters (#134, #141, #143, #145)

### 1.1 A tap on the text opens the letter (#134, #141)

A folded letter shows the beginning of its text (`.lmsg__peek`). A tap on that
text — or anywhere in `.lmsg__text` of a FOLDED letter — unfolds it, exactly as
the header does (`App.openTmsgFromText(el, event)`). An open letter does not
fold from its body: its text is selected and copied there. The peek is not
selectable (`user-select: none`): a long press on a phone used to select it and
scroll the clipped block to the middle of the text instead of opening it.
The same holds for the preview of a conversation row on the company card
(`.conv__preview`): it lies inside `.conv__head` and opens the conversation.

### 1.2 «✓ Прочитано» by hand (#143)

The company card unfolds the newest conversation by itself and marks nothing
read (module 020). Reading is the manager's, so the manager can also SAY it:

- an unread incoming letter (`lmsg--unread`, bold sender) carries «✓» in its
  header — `App.markMailRead(id, true)` → `mail.php?action=read`;
- the «⋯» menu of every incoming letter has «✉ Непрочитанным» / «✓ Прочитано»;
- a conversation row with unread letters carries «✓» among its actions —
  `mail.php?action=thread_read`, the whole conversation.

After any of them the row's unread pill, the letter's bold and the «Письма»
counter update at once (`App.refreshMailBadge()`); nothing is reloaded.

### 1.3 The card lands on the LAST letter (#145)

Opening a company card or a letter page puts the screen on the newest letter
of the newest conversation — ours or the client's — unfolded and highlighted
(`App.focusLastLetter(key, root)`). The «second visit goes to the reply field»
rule of issue #116 is gone: the manager asked for the last letter every time.
The letter page (`#mail/t/…`) lands the same way.

## 2. Letters stay paper under Chrome's forced dark (#136)

Chrome's «dark theme for sites» respects `color-scheme: only light` in the
page, but an iframe is told what the user prefers BY ITS OWNER ELEMENT
(Chromium `StyleEngine::ResolveColorSchemeForEmbedding()`): the `<iframe>`
inherited `only light` from `:root`, so the frame believed the user prefers
LIGHT, and Chromium force-darkens a frame with forced dark on and a light
preference REGARDLESS of its own `only light` (`force_dark && !prefers_dark`
in `ComputedStyleBuilder::SetUsedColorScheme()`). The page stayed white and
every letter went black.

Rule: every `iframe` carries `color-scheme: light dark` — it tells the frame the
user's real preference, and the frame's own `only light` then holds. The frames
still declare `only light` themselves (module 064). Reproduced in headless
Chromium with `--blink-settings=forceDarkModeEnabled=true,preferredColorScheme=0`
and no colour-scheme emulation: before — white page, black frame; after — white.

## 3. The match table (#135, #137, #138)

### 3.1 «фото» explains itself (#135)

The field in «Цены и условия — на все позиции» is a select «фото в КП у
позиции»: «как в настройках (N)», «без фото», «1 фото» … «12 фото». N is
`KP_MAX_IMAGES_PER_ITEM` from `settings.php?action=ui` (`kp_photos`). The
summary of the folded panel says «фото в КП: N». The «?» text of
`kp-conditions` says what the number is.

### 3.2 Free stock next to every product (#137)

Wherever a product is offered, its free stock is printed next to it:

- «ещё похожие» — every candidate `name · N шт.` (or «под заказ» at zero);
- «Равнозначные варианты» — as before (`stockLabel`);
- the autocomplete — as before, every modification with its own number;
- the colour hint of a modification (`Variants::resolveRow()`): «выбрано:
  L · Coyote (5 шт.); есть также: L · Multicam (3 шт.), …»;
- the chosen product itself: under the name field «в наличии N шт.» /
  «нет в наличии — под заказ» (`App.stockLine()`), updated when another product
  is picked;
- the add-on modules of a КП (`proposals.php?action=get` gives every addon its
  `stock` — `Variants::freeStock()`, by modifications, module 058; «подобрать
  модули» passes it along).

### 3.3 The name is read whole (#138)

The product name of a row is a `<textarea rows=1 data-field="product_name">`
that grows with its text (`field-sizing: content`, `App.growName()` where the
browser lacks it) — «Баллистический шлем Атом Арамид (Размер шлема: M(56-59);
Цвет: Multicam)» is read without scrolling. Enter does not break the line: it
picks the first suggestion, if one is open.

### 3.4 The search narrows as it is typed (#138)

`ProductMatcher::search()`:
- takes up to 12 words (was 6): a full modification name is 11 words, and the
  size stood beyond the sixth — erasing its last letters widened nothing and
  narrowed nothing;
- a word of ONE or TWO characters («xl», «m», «л») counts only as a whole word
  or as the start of a word of the name/characteristics — never inside another
  word, and never in the description: «xl» must not be found inside «XXL»'s
  neighbour text, and «m» must not match every «м» of a description.

`Variants::expandSuggest(array $rows, int $max, string $query = '')`: when the
query names a modification (some of a product's modifications were found by
the query and some were not), only the FOUND modifications are offered, and
the product row «весь товар, без размера» is not. «Бр3 xl» → the XL
modification of the plate; «плита бр3» → the whole family, as before.
The field searches on every edit, erasing included (the search is not
restarted only on a type-in): erasing «XL)» from a full name shows the whole
family again, erasing to «(Размер: X» shows XS/XL/XXL.

## 4. The matcher remembers what we sold (#142)

### 4.1 What went wrong

«Тактические наушники с активным шумоподавлением» found «Переходники для
наушников Peltor» — an ACCESSORY for headphones — and the analogue pass offered
it as «ближайшая позиция нашего производства». The same wording in an older
letter had been answered by the manager with «Earmor M31 MOD3 стрелковые
наушники», and the КП went out; nothing of that reached the next match.

### 4.2 The kind of product and «для …»

- `headWord()` skips adjectives: «тактические», «баллистический», «боковая»
  are not the kind of product; «наушники», «шлем», «плита» are. An adjective is
  a word of 5+ letters ending in -ый/-ий/-ой/-ая/-яя/-ое/-ее/-ые/-ого/-его/
  -ому/-ему/-ых/-их/-ую/-юю, or in -ие after к/г/х/ч/ш/щ. All adjectives — the
  first word stays the head.
- `headInName()` looks for the head among the first five words of the name
  BEFORE a preposition (для, к, под, на, с, со, от, без, из): «Переходники для
  наушников» is a «переходник», «Earmor M31 MOD3 стрелковые наушники» is
  «наушники».
- An ACCESSORY is dropped from the candidates: the name's own kind (the first
  non-adjective word before the preposition) is not in the query, and the
  query's kind stands after «для/к/под/на» in the name. «Переходники для
  наушников» never answers «наушники»; «переходник для наушников» still finds
  it. Analogues come from the same candidates and inherit the rule.

### 4.3 `MatchMemory` (`lib/match_memory.php`, table `match_memory`)

```
match_memory(id, phrase_key TEXT, phrase TEXT, moysklad_id TEXT, product_name TEXT,
             source TEXT, hits INTEGER, manager_id INTEGER, first_at TEXT, last_at TEXT,
             UNIQUE(phrase_key, moysklad_id))
```

- `MatchMemory::key(string $phrase): string` — the client's wording reduced to
  what names a product: `ProductMatcher::phraseKey()` — size removed
  (`Variants::stripSize`), noise words removed, key words (4+ letters and
  markers «бр3») sorted and joined. Word order and «(размер L)» do not matter.
  Fewer than one key word → empty key → nothing is remembered.
- `remember(string $phrase, string $productId, string $source, ?int $managerId)`
  stores the FAMILY ROOT of the product (a modification → its product): the
  size of the next letter is its own and is chosen by `Variants`. The same
  pair again adds a hit and moves `last_at`.
- `recall(string $phrase): ?array` — the most recent pair for the key whose
  product is still in the catalog and not archived:
  `{moysklad_id, product_name, hits, source, last_at}`.
- Sources, all human decisions, never the matcher's own guess:
  - `kp_sent` — a КП left in a letter (`MailCompose::afterDocsSent`): every
    printed line with the client's wording (`requested_name`), not an analogue,
    not excluded, not out of scope;
  - `manual` — the manager put another product on a row
    (`RequestItems::save()` with a changed `moysklad_product_id` and «ок»,
    `RequestItems::choose()`); not for an analogue.
- The v57 migration fills the memory from every КП already sent.
- `ProductMatcher::matchItems()` asks the memory first (after a shop link):
  a remembered product is the match with score 0.97, source `memory`,
  `is_confirmed = 1`, `match_hint` «как в прошлых КП: «…» (N раз)»; the
  catalog's candidates stay as «ещё похожие». The card labels the source
  «как в прошлых КП». A wrong memory is fixed by picking another product — the
  newer pair wins.

## 5. СДЭК: the delivery counts itself (#139)

### 5.1 The screen

Next to the delivery price field stands «🧮» (title «Рассчитать доставку
СДЭК»). It opens a fold under the delivery row; nothing in it is required —
the price field keeps taking a number typed by hand.

The fold:
- **Куда** — city (autocomplete from СДЭК with a key; plain text without);
  **откуда** — `CDEK_FROM_CITY`, printed muted.
- **Вес** — one line per position of the table: name, quantity, weight of ONE
  piece (kg, editable) and the line's weight. The weight of one piece is found
  in this order: `products_cache.weight` (МойСклад «Вес»), then the product's
  (a modification borrows its product's), then the description and
  characteristics — «вес 2,3 кг», «масса 850 г», «Вес без батареек: 275 гр.»
  (`Cdek::weightFromText()`). Not found → empty, the manager types it. Total =
  Σ quantity × weight.
- **Упаковка** — «свои габариты» (Д × Ш × В, см) or a СДЭК box
  (`Cdek::boxes()`: code, name, dimensions, max weight; `CDEK_BOXES` overrides
  the built-in list, one per line `CODE; Название; Д×Ш×В; кг`). Places = ⌈total
  weight / box max⌉ (editable). Chargeable weight per place =
  max(real, Д×Ш×В / 5000).
- **Рассчитать**:
  - with `CDEK_CLIENT_ID` + `CDEK_CLIENT_SECRET` — `/v2/calculator/tarifflist`
    (`type` 1 for a contract «интернет-магазин», 2 for «доставка» —
    `CDEK_CONTRACT`), then for a chosen СДЭК box `/v2/calculator/tariff` with
    the box as a service (`CARTON_BOX_*`) so its price is in the sum. The list
    shows tariff, mode, days and price; a tap puts price (+ `CDEK_MARKUP` %)
    into the delivery field and «Доставка СДЭК: <тариф>, <N–M> дн.» into its
    name;
  - without a key — «по ставке»: the fold asks what the API would have known:
    the contract («нет договора / интернет-магазин / доставка») and the rate —
    ₽ per shipment and ₽ per kg (`CDEK_RATE_BASE`, `CDEK_RATE_KG`, editable
    here) — and computes base + rate × chargeable weight × places. A link opens
    the public СДЭК calculator to check the number. Without a rate nothing is
    invented: the fold says which field is missing.

### 5.2 Server

`lib/cdek.php`, `final class Cdek`:
- `enabled(): bool` — both key fields set;
- `token(): string` — `POST /v2/oauth/token` (client credentials), cached in
  `settings['cdek.token']` with its expiry;
- `cities(string $q): array` — `/v2/location/suggest/cities`, fallback
  `/v2/location/cities?city=`: `[{code, name}]`;
- `tariffs(int $from, int $to, array $packages, int $type): array` and
  `tariff(int $code, int $from, int $to, array $packages, int $type, array $services): array`;
- `rateQuote(array $packages, float $base, float $perKg): array` — the no-key
  formula, pure PHP;
- `chargeable(array $package): float`, `places(float $weight, array $box): int`;
- `weightFromText(string $text): ?float`, `weightFor(string $productId): ?float`;
- `requestWeights(int $requestId): array` — rows of the table with weights;
- `$transport` — a stub for tests. Every HTTP failure names the code and what
  СДЭК said.

`public/api/cdek.php`: `state` (enabled, from city, contract, rates, boxes,
markup), `weights&request_id=`, `cities&q=`, `calc` (POST: to, packages,
box, contract) → `{mode: 'api'|'rate', tariffs|quote}`. Auth required; the
key never reaches the browser.

Settings (`cdek` group «Доставка (СДЭК)»): `CDEK_CLIENT_ID`, `CDEK_CLIENT_SECRET`
(secret), `CDEK_TEST` (edu environment), `CDEK_FROM_CITY` (Москва),
`CDEK_CONTRACT` (`select:none,im,delivery`), `CDEK_RATE_BASE`, `CDEK_RATE_KG`,
`CDEK_MARKUP`, `CDEK_BOXES`. `products_cache.weight` (REAL) is written by the
МойСклад sync from the product's «Вес».

### 5.3 The table saves itself again

«Подставить» writes the price the way a hand would, and the table's autosave
carries it to `requests.delivery_price`. That autosave never ran: its guard
skipped events from inside `[data-conditions]` (the «Цены и условия» panel),
and the match host itself carries `data-conditions` (the conditions are stored
on it, module 036) — `closest()` matched the host for EVERY field. The guard
now skips only a `[data-conditions]` element that is not the host.

## 6. Fewer buttons, fewer lines (#144)

On a phone (≤ 640 px) the letter and the match block lose the lines that held a
single button:
- letter actions: «↩ Ответить» · «✓» · «🗄» · «↪» · «⋯» in one row — the
  long words live in `title`/`aria-label`;
- the match block title: count, request and «?» and the fold arrow on ONE
  line; the paragraph «Подбираются сами…» is gone from the phone (it is the
  «?» text);
- the toolbar: «+ Позиция» · «⇈» · «✓⇈» · «↻ Подобрать» in one row;
- a position: the fold arrow, «из письма…» and «🚫» in one row; «↑ ↓ ⠿» stand
  in the row of «ок / аналог», not on a line of their own;
- the КП bar: «🔄» · «Открыть» · «⬇ Word» · «⬇ PDF» · «📎» · «🧾 Счёт» — the
  invoice button says «🧾 Счёт» (title: «Завести контрагента и выставить счёт»
  when the buyer is not in МойСклад);
- the composer: «🎤» and «⏱» without words.
`document.body.scrollWidth` stays the viewport width.

## 7. The update is announced (#146)

- `index.php` knows the build (`AppBuild::stamp()`); the inline boot script
  compares it with `localStorage['kp.build']` and, when they differ, the
  placeholder reads «Загружаем обновление интерфейса…».
- `App.init()` compares the same: a build that changed since this device last
  ran shows a toast «Интерфейс обновлён» once, then stores the new stamp. The
  first visit stores it silently.
- `notifications.php?action=poll` returns `build`. A tab whose build is older
  shows a sticky bar «Вышло обновление интерфейса · Обновить» (`App.updateBar()`)
  — reload keeps typed text (module 063). The bar appears once per build.

## 8. Tests

`tests/module_067.php`:
- search: «бр3 xl» → only the XL modification, no family row; «плита бр3» →
  family; 1–2 character words as word starts only; 12 words;
- matcher: «Тактические наушники с активным шумоподавлением» never answers
  «Переходники для наушников Peltor»; head word skips adjectives;
- memory: key ignores order and size; `remember()` stores the root; `recall()`
  after an archived product → null; a sent КП feeds it; `matchItems()` answers
  from memory with source `memory`; a newer manual pick wins;
- СДЭК: `weightFromText()` on «вес 2,3 кг», «масса 850 г», «275 гр.»;
  chargeable weight; places; `rateQuote()`; `tariffs()` and `cities()` against
  a stub transport; no key → mode `rate`;
- variant hint carries stock counts;
- UI by source: peek opens the letter, «✓ Прочитано», `focusLastLetter` on open
  of card and letter page, `iframe { color-scheme: light dark }`, photo select
  with `kp_photos`, name textarea, stock in «ещё похожие», 🧮 fold, compact
  phone buttons, update bar and boot placeholder.
