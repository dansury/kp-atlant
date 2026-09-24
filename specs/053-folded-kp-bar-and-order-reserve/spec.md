# Module 053 — КП buttons on a folded match table, the invoice's order in «Резерв» on a store with an employee

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
