# Module 067 — Issues #134–#139: the letter opens by its text, paper stays white, stock everywhere, the whole name, words find the variant, CDEK delivery

**Status:** Implemented
**Files:** `public/assets/js/app.js`, `public/assets/css/app.css`, `public/api/settings.php`,
`public/api/products.php`, `public/api/delivery.php`, `lib/cdek.php`, `lib/matcher.php`,
`lib/variants.php`, `lib/moysklad.php`, `lib/catalog_import.php`, `lib/settings.php`,
`lib/bootstrap.php`
**Tests:** `php tests/module_067.php`

## 1. #134 — a folded letter opens by its text

A folded letter of a conversation shows the beginning of its text (`.lmsg__peek`), and
that is what a finger reaches for — but only the header and the arrow opened it.
`App.tmsgClick()` on the `[data-tmsg]` article: a click on a FOLDED letter anywhere but
its header, a button, a link or a field opens it. An open letter does not fold on a
click: its text is selected and its links are pressed.

A letter with no text part had no beginning to show at all — nothing to press. The
beginning is taken from the HTML then (`App.htmlText()`, an inert `DOMParser`: nothing
is loaded or run).

## 2. #135 — what «фото» means

The field of «Цены и условия» is labelled «фото в КП, шт.», its placeholder is the
default number itself (`ui.kp_photos` = `KpContent::photoLimit()`), and a «?»
(`App.HINTS['kp-photos']`) says what it does: how many product photos the КП prints
for EACH position; «Применить ко всем» ticks the first N photos of every row, which can
then be changed in the row; 0 — a КП without photos; empty — as in «Оформление КП».
A `title` is no explanation on a phone, and «как в настройках» was cut to «как в н».

## 3. #136 — paper stays white under «Тёмная тема для сайтов»

Chrome gives an embedded document the preferred scheme of its FRAME
(`StyleEngine::ResolveColorSchemeForEmbedding`: a non-`normal` `color-scheme` of the
`<iframe>` element → its used scheme). Our frames inherited `only light` from the page,
so the letter inside got a LIGHT preference, and with forced darkening on,
`SetUsedColorScheme` darkens exactly that case (`force_dark && !prefers_dark`) —
whatever the document itself declares. The page stayed light, the letter went black.

`.html-frame, .kp-page { color-scheme: dark }`: the embedded document now prefers dark,
its own `only light` opts it out of darkening, and the paper is white. The frame
element is not forced (it has `dark`), and without forced darkening nothing changes.
Reproduced in Chromium with `--blink-settings=forceDarkModeEnabled=true,
preferredColorScheme=0` (no DevTools emulation — an emulated scheme applies to every
frame and hides the bug).

## 4. #137 — how many are available, wherever a product is offered

The free stock (`stock − reserve`) is printed next to every product the screen offers:

- the matched position itself — «в наличии N шт.» / «нет в наличии — под заказ» under
  the name and in the folded row (`App.stockBadge()`); a pick from the list updates it;
- «ещё похожие» — each candidate with its number;
- «Равнозначные варианты» and the catalog list — as before (`stockLabel`, «остаток N»);
- the modification hint of module 065 — «выбрано: L · Multicam (5 шт.); есть также:
  L · Mox — 3 шт., …» (`Variants::resolveRow()`).

## 5. #138 — the whole name, and the words find the variant

**The whole name.** The product name of a position is a one-row `<textarea>` that grows
with its text (`field-sizing: content`, and `App.growField()` where the browser has no
such property). A modification's name — «… (Размер шлема: M(56-59); Цвет: Multicam)» —
is read without scrolling the field. Enter does not break the line.

**Words narrow the list.** The catalog list under the field keeps only what holds EVERY
word typed:

- `ProductMatcher::search($q, $limit, $maxWords)` — the list asks with up to 16 words
  (the search keeps 6 by default for the letter lines of module 065): the size at the
  end of a long name is the word that tells the variants apart, and it used to be cut;
- `Variants::expandSuggest($rows, $max, $query)` — a product found by the query no
  longer brings ALL its modifications: only those whose own text (name, article, code,
  characteristics, plus the product's name) holds every query word — by the beginning
  of a word, so «xl» is XL and not XXL, and «mult» is Multicam. Nothing holds them — the
  product row alone (the query named the product, not a size). Families that hold the
  words themselves stand above those found only through a description.

So deleting the end of «… M(56-59); Цвет: Multicam)» lists every colour of M(56-59),
and «Бр3 xl» lists the XL modifications.

## 6. #139 — delivery by CDEK tariffs, optional

«📦 Рассчитать ▾» stands right of the delivery price and opens a panel under the
delivery row. Nothing in the panel is required: the price can still be typed by hand.

**What the panel is filled with** (`delivery.php?action=prefill`):

- from — `CDEK_FROM_CITY`; to — the city of the company's legal address
  (`Cdek::cityFromAddress()`), otherwise empty; with API keys both fields suggest CDEK's
  own city list (`/location/suggest/cities`);
- weight — per position: `products_cache.weight` (МойСклад's «Вес», kg; a modification
  takes its product's), otherwise the weight written in the description or
  characteristics («Вес: 2,5 кг», «масса 800 г», a range takes its top) —
  `Cdek::weightFromText()`; × the quantity. Each position shows where its weight came
  from and can be corrected; a position with no weight says so;
- package — a CDEK box from `CDEK_BOXES` (the smallest that carries the weight; more
  boxes of the largest when none does) or own dimensions, and the number of boxes;
- contract — `CDEK_CONTRACT` (интернет-магазин → `type 1`, доставка → `type 2`, нет
  договора); when the setting is empty the panel ASKS: the API needs the contract type,
  and without a contract there is no API.

**What «Рассчитать» does** (`delivery.php?action=quote`):

- with `CDEK_CLIENT_ID` / `CDEK_CLIENT_SECRET` and a contract: an OAuth token
  (cached until it expires), the cities resolved to CDEK codes, and
  `POST /calculator/tarifflist` with every package — the answer is the list of tariffs
  with their price and days, cheapest first;
- without keys or without a contract: an ESTIMATE from the data entered —
  `CDEK_RATE_BASE + CDEK_RATE_PER_KG × billable weight`, where a package bills the
  larger of its weight and its volume weight (L×W×H / 5000). The panel says it is an
  estimate by the rate in the settings, not a CDEK tariff, and links CDEK's own
  calculator with the parameters to copy.

**«Взять»** puts the price into the delivery field and the tariff into its name
(«Доставка СДЭК: Посылка склад-дверь, 3–5 дн.») and saves the block like a hand edit.

**Settings** (group «Доставка СДЭК»): `CDEK_CLIENT_ID` (not a secret — an account
identifier), `CDEK_CLIENT_SECRET` (secret), `CDEK_API_URL`
(`https://api.cdek.ru/v2`; `https://api.edu.cdek.ru/v2` for a test account),
`CDEK_FROM_CITY`, `CDEK_CONTRACT`, `CDEK_BOXES`, `CDEK_RATE_BASE`, `CDEK_RATE_PER_KG`.

**Data:** `products_cache.weight REAL` (schema v57), filled by `MoySklad::mapProduct()`
and by the Excel import («Вес»).

## 7. Tests

`tests/module_067.php`: the letter click and the HTML beginning (by source); the photo
field and its hint; the frame scheme in CSS; stock next to the position, «ещё похожие»
and the modification hint; the name textarea; the word cap and the variant filter of
the list («бр3 xl» → XL only, a cut long name → every colour of its size, no word left →
the whole family, `xl` ≠ `xxl`); weight from МойСклад, from text, ranges and grams;
the box pick; the estimate with volume weight; the city of an address; the CDEK calls
against a stub transport (token cached, cities, `tarifflist` body, errors, sorting);
the migration and the МойСклад mapping.
