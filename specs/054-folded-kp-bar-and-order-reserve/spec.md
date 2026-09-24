# Module 054 — КП buttons on a folded match table, the invoice's order in «Резерв» on a store with an employee; attach / remove documents in the letter, invoice print form, signature checkbox

Source: the request of 2026-09-24: the КП buttons (🔄 Пересобрать · Открыть ·
⬇ Word · ⬇ PDF · 📎 В письмо · 🧾 Счёт · Убрать) must stay visible when the
match table is folded; issuing an invoice must create the customer order in
the «Резерв» status, on a store (one by default, can be changed) and with the
employee filled in.

## 1. Folded match table keeps the КП bar

`.card--folded` hides every child of the match block except `.card__title`
and `.card__keep`. The КП bar (`[data-kp-buttons]` with `[data-match-saved]`)
and the invoices under it (`[data-kp-invoices]`) carry `.card__keep`, so a
folded table shows: the title with «▸ Развернуть», the КП buttons, the
invoices. `[data-kp-slot]` (the opened КП on the phone) keeps it too — opening
the КП from a folded table must show it. The rows, conditions and delivery
stay hidden and stay in the DOM (autosave keeps working).

## 2. The order under the invoice

`invoices.php?action=create_from_proposal&proposal_id&org_id&store_id`
creates the customer order first (module 026), then the invoice linked to it.

### 2.1 Status

`MS_ORDER_STATE` (default «Резерв») is looked up by name, trimmed and
case-insensitive, `ё` = `е` (`MoySklad::stateId(array $states, string $name)`).
Not found → the order is created without a status and «статус «…»» goes to
`order_missing`.

### 2.2 Store and reserve

Setting `MS_ORDER_STORE` (type `store`, group МойСклад): the default store of
orders and invoices, picked from the MoySklad store list in «Настройки».

`MoySklad::pickStore(array $stores, string $wanted, array $selected): ?string`
— the store id to use, first match of:

1. `$wanted` (the manager's choice or `MS_ORDER_STORE`) if it is a
   non-archived store;
2. the first of `$selected` (`MOYSKLAD_STORES`) that is a non-archived store;
3. the first non-archived store;
4. `null` (no stores) — the order goes without a store and «склад» goes to
   `order_missing`.

Order and invoice both get `store`. Every order position that is a product or
a variant (not a service) gets `reserve = quantity`: the goods are actually
reserved on that store, not only labelled «Резерв».

`invoices.php?action=stores` (any manager): `{items: [{id, name}], default}` —
non-archived stores and the resolved default.

### 2.3 Employee

The standard «Сотрудник» of the order and the invoice is `owner`:
`MoySklad::employeeMeta($manager)` (by «UID в МойСклад», email, ФИО). Not found
→ no `owner` (MoySklad sets the token's employee) and «сотрудник МойСклад для
менеджера «…»» goes to `order_missing`. If MoySklad rejects the document with
`owner` (the token may not assign other employees), it is posted again without
it and the same note goes to `order_missing`. The `MS_EMPLOYEE_ATTR`
attribute (module 052) is filled as before.

### 2.4 Choosing organisation and store

`kpInvoice(id, btn, picked)` asks once, before the request, via
`pickInvoiceTarget()` → `{org, store}` or `null` (cancelled):

- one organisation and at most one store → no dialog;
- otherwise the dialog «Выставить счёт» with an organisation select (when the
  card has 2+) and a store select (when there are 2+ stores, the default
  preselected), «Выставить счёт» and «Отмена».

The store list is read once per page (`App._msStores`). A repeat after
creating the counterparty in MoySklad (module 052) reuses the same choice.

## 3. Documents in the letter: attach and remove

- «📎 PDF в письмо» / «📎 Word в письмо» under the КП preview call
  `kpAttach(id, kind, btn)`. The file input of the old КП page is
  `kpAttachFiles(input)` — two methods with the same name overwrote each other,
  and the buttons did nothing.
- Documents go to the visible composer (`activeComposer()`: first
  `[data-composer]` with `offsetParent`, else the first one).
- `addFileChip(composer, f)`: the same document (same `filename`) attached again
  replaces its chip instead of adding a second one.
- Every attached file (КП, invoice, own file) has a ✕ button
  (`removeFileChip`) — the chip goes away, and the file is no longer in
  `files` of the send request.

## 4. Invoice print form

`MoySklad::exportInvoicePdf($id)`: POST `/entity/invoiceout/{id}/export`
answers with the PDF (200) or a `Location` (303/202). The `Location` is polled
by `downloadExport()` (for up to 40 s while it answers 202/404/429/5xx — a fresh print form takes MoySklad a while, a recent one comes at once),
following storage redirects by hand. A URL on the API host is requested with
the token; any other host (the file storage, a signed URL) — **without**
`Authorization` and JSON headers: the storage refuses a second auth method.
Export is re-requested at most 3 times, and not at all after 400/403/404/412.

The reason for a failure is kept (`MoySklad::lastExportError()`): it is logged
by `MsSync::ensureInvoicePdf` and appended to the 502 of
`invoices.php?action=pdf` and `mail.php?action=attach_doc`.

«👁 Просмотреть счёт» fetches the PDF first and shows it via a blob URL; an
error is shown as text instead of JSON inside the frame.

## 5. Signature checkbox edits the letter

The «подпись» checkbox under the letter inserts or removes the signature in
the editor itself — what is in the field is what goes out.

- `insertSignature(box, sign)`: appends `<div data-cmp-signature>` with the
  lines of `mailSignature()` joined by `<br>`; an empty field first gets an
  empty line to type above it. Nothing is added if the signature is already
  there.
- `removeSignature(box, sign)`: removes `[data-cmp-signature]` and any
  `p`/`div` whose normalized text is exactly the signature (the paragraph the
  AI draft ends with).
- `hasSignature(box, sign)`: a signature block, or at least half of the
  signature lines in the text (same rule as `MailSignature::has`).
- `syncSignature(c, fromContent)` runs after `restoreComposerDraft` (never in
  parallel with it, so the signature does not block the draft) and after an AI
  draft: for content that arrived ready (draft, AI) the checkbox shows whether
  the signature is in it; an empty field gets the signature when the checkbox
  is on.
- Toggling saves the draft only if there is text besides the signature (an
  untouched letter does not become a draft card).
- The send request still carries `signature`; the server does not add it a
  second time (`MailSignature::has`).
