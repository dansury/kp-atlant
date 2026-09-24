# Module 050 — Gmail-style list, coloured statuses, one page header, UX pass

Source: the request of 2026-09-23 (two points): a Gmail-like view of «Письма»
where a conversation (= a board card) rises to the top on a new incoming
letter; board columns are statuses and carry their own colours; then a UX
pass over every screen.

No API contract, table or permission changes. Everything below is client side
(`public/assets/js/app.js`, `public/assets/css/app.css`) except §7, a bug fix
inside `Boards::bulk()`.

The requested library `joscha/gmailui` is not used: it is a 2014 bookmarklet
helper (CoffeeScript, jQuery 1.11, lodash 2.4) that ships no CSS and only emits
markup with Gmail's own obfuscated class names (`J-J5-Ji`, `T-I`…) — it
renders only inside gmail.com. The Gmail patterns are implemented natively.

## 1. Two views of «Письма»

`#mail/list[/<columnId>]` — the list (Gmail), `#mail/board` — the kanban.
Both are drawn from the same `boards.php?action=get` payload; a card is one row
of the list and one card of the board. A switch «☰ Список | ▦ Доска» (a `nav`
of two links, the current one `aria-current="page"`) sits next to the title. Bare `#mail` opens the view last used
on this device (`localStorage.mailView`, default `board`). Old bookmarks keep
working: `#mail` and `#mail/board` still open the board when it was last used.

## 2. The list

- **Order**: by `last_at` descending — a new letter moves its row to the top
  («поднятие ветки»). Rows without `last_at` (notes, empty drafts) go last,
  newest card id first. Rows that moved up since the previous render on this
  tab are highlighted briefly (`.mrow--risen`, off under
  `prefers-reduced-motion`).
- **Sidebar — statuses as labels**: «Все» + one item per board column, each with
  its colour swatch, name and the number of unread cards in it (the number of
  cards when none is unread). The chosen status is in the URL
  (`#mail/list/<columnId>`). Below: «Архив», «Корзина». On a phone the sidebar
  is a horizontally scrolling chip row above the list.
- **Row**: checkbox · company (bold when it has unread mail or waits for an
  answer — module 051) + letter count ·
  status chip (column colour **and** column name) · subject — preview ·
  📎 · date (today → `HH:MM`, this year → `12 сен`, older → `12.09.24`).
  «Ждёт ответа» is a dot with a text alternative, never colour alone; a draft
  reads «Черновик», a ready shipment letter «письмо готово». Read rows sit on
  a grey background, unread on white (Gmail).
- **Hover actions** (replace the date on hover, always reachable by keyboard):
  «Прочитано», «В архив», «Убрать с доски» — the same `boards.php?action=bulk`
  ops as the board, with the same confirmations.
- **Toolbar**: select-all checkbox, «⟳», bulk bar when something is ticked
  (the board's bulk bar, same ops), «N писем» on the right. The filter panel
  starts folded in the list (open when a filter is set) and has no «Выбрать
  все / Снять» — the select-all checkbox does that.
- A row of the «Закрыто» status shows no «(без темы)»: that column never loads
  the letter text (issue #60).
- **Keyboard** (when focus is not in a field): `j`/`k` next/previous row,
  `o`/`Enter` open, `x` tick, `e` archive, `Shift+I` mark read, `/` search.
  Listed behind the `mail-keys` hint.
- **Filters, search, bulk ticks** are the board's own: they address cards by
  `[data-card]`, so they work on either view.
- **Empty state**: says what is absent and offers «Забрать почту», or
  «Сбросить фильтры» when filters/search hide everything.

## 3. Coloured statuses on the board

- A column head is tinted with the column colour (`--col`, 14 % over the
  surface), with a colour swatch; the title keeps the normal ink (contrast).
- A card's left edge is its column colour. «Ждёт ответа» is the bold title plus
  a red dot with a text alternative (it used to be a red left edge, which now
  belongs to the status).
- Column actions move into one «⋯» menu: «Переименовать», «Цвет», «Лимит
  карточек», and, separated and in red, «Удалить колонку» (confirmation as
  before). «Цвет» offers eight swatches and a free colour picker and saves
  through the existing `boards.php?action=column_save` (`color`).

## 4. «Письма» header

Title + view tabs on the left. On the right: search, «⟳» (icon button,
`aria-label="Забрать почту"`), **«✉ Написать»** (the one primary action),
«Ещё ▾»: «+ Запрос не из почты», «+ Колонка» (board only), «Архив»,
«Корзина». «Архив» leaves the filter row.

## 5. One page header for every inner screen

`App.pageHead({back, title, after, meta, actions, menu})`:
a small back link above the title («← Письма», always leading to `#mail`),
title with badges, meta line, then on the right the actions (primary last and
filled) and a «⋯» overflow menu (`.menu`, `<details>`; closes on outside click
and Escape). Rare and destructive actions live in the menu, destructive ones
last, red, after a separator.

| Screen | Visible actions | In «⋯» |
|---|---|---|
| Переписка `#mail/t/…` | В архив / Вернуть в работу | Удалить переписку |
| Письмо `#mail/msg/…` | Ответить / вся переписка | В архив, Вернуть, Спам, Удалить |
| Компания `#mail/company/…` | МойСклад ↗ | Разделить по отправителям, Обновить из МойСклад |
| Запрос `#mail/request/…` | Взять в работу, Создать ответ, **Сформировать КП / Создать заказ** | — |
| КП `#mail/proposal/…` | Обновить PDF, **Подтвердить и отправить** | — |
| Every letter of a correspondence | Ответить на это письмо, В архив, Перенаправить | Спам, Удалить письмо |
| Корзина, Компании, Новый запрос | as before, back link unified | — |

«Новый запрос»: labels are bound to their fields, optional fields say
«необязательно», and an empty submit shows its error under the text field
(`App.fieldError(id, text)`, `aria-invalid`) instead of a toast.

## 6. Settings and notifications

- **Settings**: twenty flat tabs become a grouped side navigation
  (`nav[aria-label]`, `aria-current="page"`): «Работа с КП», «Интеграции»,
  «Команда и сервис», «Система». URLs `#settings/<tab>` are unchanged. On a
  phone the navigation is a single `<select>` of the same groups.
- **Notifications**: the list comes first, the push-subscription card after
  it; «✓» becomes a labelled «Прочитано»; «Прочитать все» marks every shown
  notification read (one existing `read` call per item); the empty state says
  what will appear here.

## 7. A new letter raises a card that was marked read (bug fix)

`Boards::bulk(…, 'read')` stored the wall-clock time in `board_cards.seen_at`
and a card counted as answered while `seen_at >= last_at`. `last_at` is the
letter's `Date:` header — the sender's clock — so a letter written before the
button press but delivered after it never raised the card again. Now `seen_at`
stores the card's own `last_at` at the moment of the press (wall clock only
when the card has no letters): any letter newer than the ones the manager saw
raises the card. Covered by `tests/module_026.php` §2.

## 8. Accessibility baseline

- `:focus-visible` ring on links, tabs, rows, `summary` and checkboxes.
- `a[onclick]` without `href` (the app's many text actions) get
  `tabindex=0`, `role=button` and Enter/Space activation, applied by one
  `MutationObserver`.
- `@media (prefers-reduced-motion: reduce)` turns animations and transitions
  off.
- Modals are `role=dialog` with a labelled «Закрыть», close on Escape and focus
  their first field.
- A route change ends a running hint tour and closes an open hint: a bubble of
  the previous screen never hangs over the next one.
