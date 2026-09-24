# Module 058 — price range breakdown, the add-on block only for its host product, the add-on table without «Ед. изм.» and with real stock

Source: the request of 2026-09-24 (КП «от 25 000 до 39 000 руб.»).

## 1. The range is explained under the price

A generic product whose price sits on its modifications prints as a range
(module 036): «от 25 000 до 39 000 руб.». The client cannot tell which
modification costs what. Under such a price the КП now prints the breakdown —
by the characteristics the price actually DEPENDS on, and only by them:

```
от 25 000 до 39 000 руб.
Размер: S, M — 25 000 руб.
Размер: L, XL — 39 000 руб.
```

If the colour does not change the price and the size does, the lines name only
sizes.

`Catalog::rangeBreakdown(array $product, ?int $counterpartyId = null, ?string $priceType = null): array`
→ `[['label' => 'Размер: S, M', 'price' => 25000.0], …]`, cheapest first;
`[]` when there is no range (a modification, no modifications, one price).

1. Modifications: `products_cache` rows with `parent_id = product`, not
   archived, in `id` order (the catalog's own order — sizes stay S, M, L, not
   alphabetical). Each is priced exactly as `variantRange()` prices it
   (`priceFor()` of the modification with the parent lookup off); a
   modification with no price is skipped.
2. Characteristics: `characteristics` («Цвет: Multicam; Размер: L» from the API,
   «Цвет: Multicam, Размер: L» from Excel), or the last parentheses of the name
   when the column is empty — `Variants::characteristicPairs()`.
3. Relevant characteristics: start from all of them and drop one at a time
   while the price is still a function of the rest (every combination of the
   remaining values has one price). What is left is what the price depends on.
   If even all of them do not determine the price, every modification is listed
   by its full label.
4. Rows: modifications grouped by the values of the relevant characteristics;
   groups with the same price merge into one line. One relevant characteristic
   → «Размер: S, M»; several → «Цвет: Черный, Размер: L; Цвет: Олива, Размер: XL».

`KpContent::rangeRows(array $item, array $proposal): array` turns it into the
lines of one КП row. `PdfGenerator::html()` prints them (`$item['range_rows']`) under the unit price
the client pays — the «Со скидкой» column when it is printed, «Цена за ед.»
otherwise. Each line's price goes through `Terms::price()` (the row's discounts)
and gets the row's delivery share, exactly like the two ends of the range.
The breakdown is printed only while it agrees with the row: its cheapest price
equals `price` and its dearest equals `price_max`. A hand-typed price has no
range and no breakdown. The price type is not stored on the row, so the one
whose range matches is used: the counterparty's / the setting's first, then
every type the modifications carry. Word gets it through the same HTML; the
letter text (`KpText`) prints the same lines under the row.

## 2. «Дополнительные модули и доукомплектование» only for its host product

The add-on modules fit one product, not every vest. «Оформление КП» gets
«Модули подходят к товарам» (`settings.addon_hosts`, one product name per
line), seeded with «Бронежилет Атлант базовый Бр2 (без доп.модулей, без
бронеплит)».

`KpContent::addonHostIn(int $proposalId): bool` — the КП has a printed position
(`printedItems()`) whose product name, or its parent's name for a modification,
starts with one of the lines (case-insensitive, `ё` = `е`, spaces collapsed).
An empty setting means no restriction (every КП, as before).

- `seedAddons()` seeds nothing when there is no host in the КП.
- `PdfGenerator::html()` prints the block only when `addonHostIn()` — a КП
  seeded earlier, or rebuilt with the host removed, loses it too.
- The КП editor's «Доукомплектование» card says when the block will not print
  (`proposal.addon_host = false` from `proposals.php?action=get`).

## 3. The add-on table prints without «Ед. изм.»

Columns: №, Модуль, Цена за ед. The editor row loses its unit field;
`proposal_addons.unit` stays in old rows and decides nothing.

## 4. Stock of an add-on is today's, and a modification's counts

`seedAddons()` froze «под заказ» from `products_cache.stock` of the product
row. The add-on modules are generic products whose stock sits on their
modifications, so the product row is 0 and every module read «под заказ» while
the shelf was full.

- The free stock of an add-on is `Variants::freeStock()` — the sum over the
  modifications when there are any, `stock - reserved` otherwise.
- The note is recomputed at print time (`KpContent::addonNote()`): the automatic
  «под заказ» (`Terms::AUTO_NOTE`) or an empty note is replaced by today's
  answer; a note a manager wrote is kept.
