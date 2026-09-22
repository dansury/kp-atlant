# Module 042 — scope fix, price/delivery clarity, board load, document cleanup

Source: GitHub issue #60 — a single large wishlist covering the whole product.
It listed over thirty distinct requests. This module implements the subset
below (bug fixes and self-contained, low-risk features); the rest is tracked
in `TODO.md` at the repo root, per CLAUDE.md §3.

## 1. Bug: marking a line "не наша номенклатура" wiped other, unsaved lines

`App.setItemScope()` called `items_scope`, then re-rendered the whole match
table from the server's response — which reflects only what was already
saved. Any edits a manager had typed into *other* rows since the last click
of "Сохранить" were discarded, appearing to the manager as if all their
matched products had vanished.

Fix: `setItemScope()`, `applyConditions()`, and `chooseMatch()`'s
already-saved-row branch (`public/assets/js/app.js`) now call
`requests.php?action=items_save` with the currently-collected row state
immediately before the action that triggers a full re-render — the same
guard `rematchItems()` already used.

## 2. Manual product pick didn't pull in the description

Picking a product from the "равнозначные варианты" list on an unsaved row
(`App.chooseMatch()`, no `itemId` yet) filled in name/article/id but left
`comment_text` empty until the row was saved and re-fetched. `matchChoice()`
now carries each option's `description` in a `data-description` attribute,
and `chooseMatch()` fills the comment field from it immediately (only when
the field still holds the catalog's own text, never overwriting something
the manager typed).

## 3. Manual price: dropped the "цена вручную" checkbox

The checkbox toggled `price_is_manual` but exposed no visible effect
(the real effect — protecting the price from being overwritten by
re-matching/bulk conditions — is server-side and invisible). Replaced with
implicit tracking: `price_is_manual` is now a hidden field, set to `1`
whenever the manager types directly into the price `<input>`
(`App.markPriceManual`), and reset to `0` whenever a price is picked from
the price-type `<select>` (`App.applyPriceOption`) — so picking a type
always overwrites, but a value typed by hand survives everything else,
exactly as `price_is_manual` already behaved server-side
(`RequestItems::all()`).

## 4. "Под заказ": auto-tick and hide the wait fields until it's on

`wait_months`/`wait_discount`/`wait_prepay` are now hidden
(`.match-extra__wait`, `hidden` attribute) both on the per-row panel and the
bulk "Цены и условия — на все позиции" panel, and shown only while "под
заказ" is checked (`App.toggleWaitFields`). The checkbox itself now defaults
to checked when the row is a backorder (`is_backorder` or `stock <= 0`),
even before the manager has touched it, matching "проставляется
автоматически, если товара нет в наличии".

## 5. Red "!" when stock is short of the requested quantity

New: `App.qtyWarning()` / `App.toggleQtyWarning()` show a red `!` next to
the quantity field when `stock > 0 && quantity > stock` — a distinct signal
from the existing all-or-nothing backorder note, for "there is some, but
not enough".

## 6. Per-counterparty conditions, replacing "цена по умолчанию для контрагента"

`counterparties.default_price_type` (surfaced as a select in the company
card) is removed from the UI — it duplicated the bulk-conditions panel and
did nothing itself. In its place: `Terms::conditions()` /
`Terms::remember()` (`lib/terms.php`) now take an optional
`$counterpartyId` and persist/read a `counterparties.kp_terms_json` column,
which takes priority over the per-manager `managers.kp_terms_json` when
both exist. `requests.php` (`get`, `items`, `items_conditions`) passes the
request's `counterparty_id` through.

## 7. Board: "Закрыто" column skips loading letter bodies; per-column card limit

`Boards::companyStats()` fetches one `SELECT ... ORDER BY date_at DESC LIMIT
1` per counterparty just for the subject/preview shown on a card — the
board's only true per-card query. `Boards::get()` now passes the ids of
columns whose `kind = 'closed'` (the factory "Закрыто" column carries this
kind; `board_columns.kind` migrated in bootstrap v38) to `decorateAll()`,
which skips that fetch for cards sitting in those columns — they still get
the batched letter/unread counts, just not the last message's text.

A new `board_columns.card_limit` (nullable int) caps how many cards a
column's `Boards::get()` response returns (after sorting), so a column can
be told to show only its most recent N cards. Settable via
`boards.php?action=column_save` (`card_limit` field, `0` clears it) and a
small gear affordance on the column's card-count badge in the board UI.

## 8. КП document

- **Таблица соответствия запросу removed from output.** `PdfGenerator::html()`
  now always computes `$showMatchTable = false`; the underlying
  `KpContent::showMatchTable()`/`matchTableRows()` and the `KP_MATCH_TABLE`
  setting are untouched (dead but harmless) in case the correspondence-table
  feature is wanted back for a different purpose later.
- **"Аналог" line: italic → bold.** `.analog-of` (the client's own wording,
  printed above our product name when the "аналог" flag is set) is bold
  instead of italic, in both the HTML/PDF template and the docx style map.
- **Item table:** "Ед.изм." column removed; "Кол-во" renamed to "Кол-во, шт"
  (also applies to the delivery row, which loses its "усл." unit cell).
  A new "Со скидкой" column shows the discounted unit price (blank when
  there's no discount); the "Цена за ед." column now always shows the
  original, undiscounted price (previously it showed the discounted price
  with the original struck through inline).
- **QR code** moved to the left of "Подробнее на сайте", in both the
  PDF/HTML template (`.card__qr` float switched from right to left, markup
  reordered) and the docx image-anchor alignment.
- **Word photo-stacking bug fixed.** Multiple photos of one item are plain
  `<img>`s inside one `<div class="gallery">`, which `Html2Docx::block()`
  puts into a single paragraph; every such image anchored at the same
  `offset_v` (0), so in Word they rendered exactly on top of each other —
  only the last one was visible, though all were embedded in the package.
  `DocxGenerator::imageSpec()` now gives each unclassed (plain photo) `<img>`
  an increasing `offset_v` based on its index among its unclassed siblings
  (`photoIndex()`), stacking them instead of overlapping.

## 9. Delivery: included in item prices by default, not a separate line

New setting `KP_DELIVERY_MODE` (`included` | `line`, default `included`).
- `included` (new default): the delivery amount is distributed
  proportionally across the priced items' line sums (last item absorbs the
  rounding remainder so the split always adds up exactly to the delivery
  price); no separate delivery row is printed; the terms-text placeholder
  `{delivery_in_price}` resolves to `"доставку, "` (folded into the
  packaging-costs sentence) and `{delivery_separate_clause}` resolves to
  empty.
- `line`: unchanged old behaviour — delivery prints as its own table row,
  the terms text states delivery is billed separately
  ("Доставка в стоимость не включена и оплачивается при получении по
  тарифам СДЭК.").
- Distribution only changes the *displayed* line sums for this document; it
  is not persisted to `proposal_items`, so MoySklad invoice sync and any
  other consumer of the stored price are unaffected.
- `KpTerms::FACTORY_TEXT` carries the two placeholders; a one-time migration
  (bootstrap v38) upgrades any *unmodified* `default_terms_text` setting or
  proposal `terms_text` (never a `sent`/`order_created` one) from the old
  factory wording to the new one, the same technique the v33 migration used.
- The plain-text email version (`KpText::render()`) mirrors this: no
  separate delivery line when `KP_DELIVERY_MODE = included`.

## 10. Support ticket → GitHub issue: no footer

`Support::issueBody()` no longer appends the
`--- \n Отправил: … · Когда: … · Экран: … \n\n_Заведено из панели Атлант…_`
block. The metadata (`manager_name`, `created_at`, `page`, `kind`) still
lives on the `support_tickets` row and is shown in the app's own ticket
list — only the GitHub issue body drops it.

Admin notification on a new manager request was checked and **already
works** (`Support::submit()` → `Notifier::notify('support', …)` to every
active admin) — no code change was needed there. Surfacing a support
ticket as a card on the "Письма" board specifically is a separate,
unbuilt feature (`Boards`/`support_tickets` have no relationship today) —
deferred, see `TODO.md`.

## 11. Notifications: no "click to copy" hint; dismiss on mouse-leave

`App.toast()` no longer renders a "нажмите, чтобы скопировать" hint or a
click-to-copy handler (`App.copyText()` removed, now unused). Every toast
— including sticky error toasts, which previously had no dismiss path
except that click — now closes shortly after the mouse leaves it, once it
has been hovered at least once (`mouseenter` marks it "seen"; a toast never
hovered still auto-closes on its own timer, same as before, except sticky
errors which still wait to be seen).

## Deferred

See `TODO.md` at the repo root for the remaining ~20 items from issue #60
that are out of scope for this pass (major UI reorganisation, an
HTML-preview document editor, scheduled sending, per-user notification
sound/signature, a Gmail-styled correspondence card, auth cookie lifetime
and login-notification/reset, the Re:palin plugin toggle, settings-field
help links, the MoySklad "printed with signature" invoice option, and
several smaller cosmetic requests).
