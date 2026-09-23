# Module 047 — payments from T-Bank, the «Сборка» column, shipment letters, card fixes

Source: the request of 2026-09-23 (two screenshots: the company feed with
duplicated order/invoice rows, the product suggest with modifications listed
twice).

## 1. Company feed: one row per order / invoice

`App.loadChat()` merges the milestone events `order_created` / `invoice_created`
into the document row they describe (`correspondence.meta.order_id` /
`meta.invoice_id` = `docs[].id`). The event is dropped; the document row takes
the event's time (the moment the CRM learned about the document) and says
«заказ создан» / «счёт выставлен» in its head. An event without a matching
document (deleted in MoySklad) stays as it was.

## 2. Product suggest: a modification is listed once, under its product

`products.php?action=search` selects `parent_id` too. Without it
`Variants::expandSuggest()` saw every found modification as a product of its
own (no family, no group header) — and then listed it again inside its
product's family. Now a found modification lands in its family: header, the
product itself («весь товар, без размера»), then its modifications.

## 3. Photos: the modification's own and the product's

`KpContent::productImageList(id)` for a modification returns its own photos
first, then the parent product's photos:

- a modification without own photos — parent photos under the plain keys
  (`local:N`, `url:N`) as before, so saved selections stay valid;
- a modification with own photos — own photos under plain keys, parent photos
  under `parent:local:N` / `parent:url:N`;
- the same URL / file is listed once.

`imageBytes()` and `itemGallery()` resolve keys through this list, so any photo
picked in the match table prints in the КП.

## 4. Description arrives with the product

Picking a catalog row (`pickSuggest`, `chooseMatch` for a new row) fills the
«Описание товара» field at once when the field is empty or still holds the
catalog text (`data-from-catalog="1"`); a text the manager wrote is kept. The
field is then marked `data-from-catalog="1"`.

## 5. Photos survive «Сформировать КП»

«Сформировать КП» saves the match table, redraws it and starts the КП build —
up to a minute. The redrawn photo strips ask `requests.php?action=item_images`,
and that request waited for the PHP session lock the build was holding: the
strips said «Загружаем фотографии...» until the КП was ready. `requireAuth()`
now releases the session (`session_write_close()`) once the manager is known —
API calls only read the session after that (login/logout write it through
`Auth`, which starts it again).

`MoySklad::fetchProductImages()` asks `/entity/variant/{id}/images` for a
modification (`products_cache.product_type = 'variant'`) — `/entity/product`
knows products only, and the КП of a modification got no MoySklad photos.

## 6. Composer: «подпись» under the text

The signature checkbox (`.composer__sign`) stands under the editor, above the
attachment chips — where the signature ends up in the letter.

## 7. «Подходящие позиции» folded by default

The match block opens folded, except for the first letter of a КП/price
request: `requests.php?action=get` returns `match_open = true` when the
request's category is `kp_request` (or empty — an old request) and none of the
request's threads has an outgoing letter yet. A fold set by hand for the letter
(`foldGet('items')`) still wins.

## 8. Answered letters are not bold

A reply that arrives through the mailbox's «Отправленные» (sent from another
mail client, or synced back) marks the incoming letters of its thread that are
older than the reply as read — the same rule `storeOutgoing()` applies to a
reply sent from the service (`MailArchive::markThreadAnswered(threadKey, at)`,
called from `storeIncoming(direction='out')`). Unread letters were what kept
the thread row and the board card bold after the answer.

Migration v43 applies the same rule once to the archive: an incoming letter
followed by one of ours in the same thread is marked read.

## 9. «Сборка» column

- Column kind `assembly` (exclusive per board, like `inbox`/`work`).
  `Boards::assemblyColumn(boardId)`; missing → created on demand as «Сборка»
  (`#2f8f8a`) right after the «Ждём оплату» column (title starting with
  «Ждём оплат»/«Ждем оплат»), else before the first `closed` column, else last.
- Migration v43 adds it to every existing board; the default columns of a new
  board include it.
- The board shows the kind label «оплачено».

## 10. T-Bank: incoming payments → MoySklad «Входящий платёж» → «Сборка»

### Settings (group `bank`, «Банк (Т-Банк)»)

| Key | Type | Default | Meaning |
|---|---|---|---|
| `TBANK_TOKEN` | secret | '' | T-API token (T-Bank Business → Интеграции → T-API) with the «Выписка» scope |
| `TBANK_ACCOUNTS` | text | '' | Account numbers (20 digits), comma-separated. Empty — the check does nothing |
| `TBANK_API_URL` | text | `https://business.tbank.ru/openapi` | API base |
| `TBANK_LOOKBACK_DAYS` | int | 3 | How far back each run reads the statement |
| `TBANK_CREATE_PAYMENTIN` | bool | 1 | Create «Входящий платёж» in MoySklad for a matched payment |

### `lib/tbank.php` — `TBank`

- `statement(string $account, string $from, string $to): array` —
  `GET {base}/api/v1/statement?accountNumber=&from=&to=&operationStatus=Transaction&limit=1000[&cursor=]`,
  `Authorization: Bearer`; follows `nextCursor`. Returns normalized incoming
  operations only (`typeOfOperation = Credit`):
  `{id, date, amount, purpose, payer_inn, payer_name, account, number}`.
  `normalize(array $op): ?array` is pure (tested without network): it takes
  `operationId`, `operationDate`/`chargeDate`, `accountAmount`/`operationAmount`,
  `payPurpose`, `counterParty{inn,name}` (fallback `payer{inn,name}`),
  `documentNumber`.

### `lib/payments.php` — `Payments`

Table `bank_payments` (migration v43):
`id, operation_id UNIQUE, account, operation_date, amount, payer_inn,
payer_name, purpose, doc_number, invoice_id, order_id, counterparty_id,
moysklad_payment_id, status (matched|unmatched|error), error, created_at`.

- `check(): array` — for every account: statement for the last
  `TBANK_LOOKBACK_DAYS`, each operation not yet in `bank_payments` goes
  through `record()`. Returns `{read, new, matched, unmatched, errors}`.
- `invoiceNumbers(string $purpose): string[]` — numbers after «счёт/счета/сч.»
  (with or without «№», «на оплату»), leading zeros dropped. Pure.
- `match(array $op): ?array` — the invoice the payment is for:
  1. an invoice whose number (leading zeros dropped) is named in the purpose
     and whose sum is ≥ the payment − 1 ₽ (if the payer's INN is known and the
     invoice's company has an INN, they must be equal);
  2. else the payer's INN → companies with that INN → their unpaid invoices of
     exactly this sum (±0.01): exactly one → it.
- `record(array $op)` — stores the row; a match: creates the MoySklad
  payment (`MoySklad::createPaymentIn()`), re-reads the invoice
  (`MsSync::upsertInvoice`), and when the invoice (or the order's invoices) is
  paid in full, calls `Fulfillment::orderPaid()`. No match → one notification
  «Поступила оплата — счёт не найден» (payer, sum, purpose) to everyone.
  A failure of MoySklad leaves `status=error` with the text, notifies, and the
  card still moves (the money is in the bank).

`MoySklad::createPaymentIn(string $invoiceId, float $sum, array $o): array` —
reads the invoice (`organization`, `agent`, `customerOrder`), posts
`/entity/paymentin` with those metas, `sum` (kopecks), `paymentPurpose`,
`incomingNumber`, `incomingDate`, `moment`, and `operations = [{invoiceout
meta, linkedSum}]` — the payment is linked to the invoice and through it to the
order, as MoySklad does for a payment created «на основании» a счёт.

### `lib/fulfillment.php` — `Fulfillment`

- `orderPaid(int $orderId, string $source): bool` — once per order
  (`orders.paid_at`): the company card goes to the assembly column (created if
  missing, revived if dismissed), a feed event `payment_received`, and the
  notification «Сообщить складу о необходимости отправки» (company, order, sum)
  to the order's manager (everyone when unknown), leading to the company card.
- `MsSync::upsertInvoice()` calls `orderPaid()` when an invoice already known
  locally goes from unpaid to paid in full — a payment entered in MoySklad by
  hand moves the card the same way. A first sync of an old paid invoice does
  not (no flood on deploy).

### Cron

`cron/check_payments.php` — every 10 minutes: `Payments::check()` then
`Fulfillment::checkShipments()`. `Настройки → МойСклад` has a «Т-Банк» card
with «Проверить оплаты сейчас» (`admin.php?action=bank_check`, admin only)
and the last result.

## 11. Shipment: track number → a letter draft

Settings (group `moysklad`): `MS_SHIP_SERVICE_ATTR` (default «СЛУЖБА
ДОСТАВКИ»), `MS_TRACK_ATTR` (default «ТРЕК-НОМЕР») — names of the order's
custom fields, compared case-insensitively.

`MoySklad::getOrder()` also returns `attributes` — `name → string value`
(a custom-entity / dictionary value gives its `name`).

`Fulfillment::checkShipments(int $limit = 30): array` — for company cards in
the assembly column: their orders (last 120 days) without
`orders.shipped_notified_at`. For each, the order is read from MoySklad; a
filled track number:

- `orders.ship_service`, `orders.ship_track`, `orders.shipped_notified_at`;
- a draft (`mail_drafts`, `kind = 'shipment'`) to the company's email
  (`Crm::primaryEmail`), manager = the order's manager, else the card's,
  else the first admin; in the thread of the order's request (reply to its
  last incoming letter) when there is one and that manager has no draft there
  yet, otherwise a new letter to the company:
  «Здравствуйте! Ваш заказ № N отправлен[ службой S]. Трек-номер: T.» and,
  when the service is СДЭК/CDEK,
  `https://www.cdek.ru/ru/tracking/?order_id=<track>` as a link;
  subject «Заказ № N отправлен»;
- the board card carries the draft (`Boards::draftCard`), keeps its column;
- notification «Заказ отправлен — письмо клиенту готово» to that manager.

`Fulfillment::shipmentText(order, service, track): array{subject, html}` and
`isCdek(service)` are pure.

On the board a card whose draft is a shipment draft is `hot`, bold
(`bcard--attention`) and shows the chip «отправлен: письмо готово».

## Tests

`php tests/module_047.php` — feed merge data, suggest grouping, photo list of a
modification, fold flag, answered thread read, assembly column placement,
statement normalization, invoice numbers, payment matching, paid → card moves
and notifies once, shipment draft text (CDEK link), shipment check with a
stubbed order.
