# Module 049 — delivery mode per КП, no cover letter in КП settings, side rail, КП above the letter

Source: the request of 2026-09-23 (six points).

## 1. No cover letter among the КП settings

The letter text is written and edited in one place only — the letter field
(composer) of the correspondence. The full КП editor (`#mail/proposal/N`) and
«✎ Текст по полям» (`proposals.php?action=doc_text`) show no «Сопроводительное
письмо» field. `proposals.php?action=save` / `doc_text_save` still accept
`cover_letter_final` from older clients, but nothing in the UI sends it.

## 2. Delivery mode per КП

`requests.delivery_mode` and `proposals.delivery_mode` (TEXT, NULL = the
`KP_DELIVERY_MODE` setting). Values (`DeliveryShare::MODES`):

| Mode | КП table / total | Invoice | Terms | Letter |
|---|---|---|---|---|
| `included` | spread over unit prices (module 045) | spread | `{delivery_in_price}` = `доставку, ` | — |
| `line` | own row, added to the total | service `MS_DELIVERY_SERVICE_ID` | СДЭК clause | — |
| `separate` | not printed, not in the total | not billed | `Доставка в стоимость не включена и оплачивается отдельно.` | `DeliveryShare::letterLine()` |

`DeliveryShare::mode(array $row): string` — the row's own mode when valid,
otherwise the setting (itself validated, default `included`).
`KP_DELIVERY_MODE` accepts `included,line,separate`.

`DeliveryShare::letterLine(array $row): string` — for `separate` with delivery
on and a price above zero: `Доставка оплачивается отдельно, её стоимость —
1 500,00 руб.`; otherwise empty. It is appended (once — skipped when the text
already contains it):

- to the reply draft (`mail.php?action=draft_reply`) of a letter with a
  request, before the signature;
- to the body of `proposals.php?action=send` and to the КП-as-text letter.

The mode is chosen in the delivery row of the match panel: a select
«в стоимость товаров / отдельной строкой / оплачивается отдельно» replaces the
old «усл. 1» columns (delivery is one sum for the whole order, no unit and no
quantity). `items_save` takes `delivery.mode`; `RequestItems::delivery()`
returns `mode`; `KpSet` copies it into the КП with the rest of the delivery.
The full editor's «Доставка» card has the same select.

## 3. Company card side rail (desktop)

On screens wider than 640 px «Информация» and «Заметки, заказы и счета» are
not a column: they collapse into vertical tabs on the right edge of the
window and open as a drawer over the page. They are always collapsed when the
card opens or a correspondence is switched (the fold state is not remembered
on desktop); opening one closes the other. The phone keeps the module 046
behaviour.

A red dot on the «Заметки» tab marks that the company has at least one note
(a manager's note, not an event or a document). The dot is inserted/removed
in the DOM, never hidden with `hidden` (a CSS `display` would override it).

The explanation «Заметки для коллег, вехи сделки …» and the empty-feed text
live behind the title's hint icon (`HINTS.events`); the empty feed says only
«Пока пусто».

## 4. КП preview above the letter

`kpSlot()` inserts the КП box right before the composer, not after it. When
the match panel is moved into an open correspondence it goes before the КП
box, so the order is: match panel → КП → letter.
