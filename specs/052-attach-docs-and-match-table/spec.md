# Module 052 — documents into the letter in one click, match table, «СОТРУДНИК» in the invoice

Source: the request of 2026-09-24 (ten points): download / attach the КП under
its preview, attach every generated document and check it by downloading,
remove «Все настройки КП», a second «+ Позиция», folding matched rows,
integer quantities, pinned «?» icons, the MoySklad creation window on
«Счёт в МойСклад», the required «СОТРУДНИК» field of the invoice, rail arrows.

## 1. Documents into the letter

### 1.1 Under the КП preview

The opened КП (`openKp`, `.kp-open`) ends with a bar `.kp-docbar` under the
frames and the height grip:

| Button | Action |
|---|---|
| 📎 PDF в письмо | `kpAttach(id, 'kp')` |
| 📎 Word в письмо | `kpAttach(id, 'kp_docx')` |
| ⬇ PDF / ⬇ Word | `kpDownload(id, kind)` |

Both save an unsaved edit of the A4 sheet first (`kpPageSave`), so the file
is exactly what is on screen. `kpAttach` then calls `attachDoc(kind, id)` →
`mail.php?action=attach_doc`. The top bar keeps «🧾 Счёт в МойСклад»,
«Подтвердить и отправить», «Свернуть»; ⬇ Word/PDF moved to the bar under the
sheet. **«Все настройки КП →» is removed** (it duplicated the main window);
the `#mail/proposal/N` page itself stays reachable by URL.

A just-created invoice note lands in `[data-kp-invoice]` under the bar.

### 1.2 Everywhere else a document is generated

- Buttons under the match table (`matchKpButton`): «📎 В письмо» — the КП
  PDF as it is.
- Invoices of the КП under the match table (`loadKpSummary`): «⬇ PDF» and
  «📎 В письмо» (`attachDoc('invoice', id)`).
- The invoice note of the КП window and the invoice dock under the letter
  already attach in one click.

### 1.3 The attached file is downloadable

Every attached file is drawn by one helper, `fileChip(f)`: the file name is
a link to `mail.php?action=outbox_file&name=<stored name>` (download of the
very copy that will be sent), `×` removes it. Used by the reply composer,
the КП send form and all «attach» buttons.

`Outbox::path(name, managerId)` → the file inside this manager's outbox
folder (`basename`, no `..`), or `null`. `outbox_file` answers 404 «Файла уже
нет — приложите документ заново» for a missing / foreign file; the download
name is `Outbox::displayName()` (no storage prefix), `Content-Disposition:
attachment`, `nosniff`, MIME by extension (pdf, docx, xlsx, else
octet-stream).

## 2. Match table

- **«+ Позиция» twice**: in the toolbar above and in `.match-add-bottom` under
  the rows. A new row scrolls into view and focuses its name field.
- **Folding matched rows**: each row has a `▾/▸` button (`.match-row__fold`)
  left of «из письма…». A folded row (`.match-row--folded`) shows only that
  line and a summary `[data-row-summary]`: product, `qty unit × price = sum`.
  All inputs stay in the DOM (autosave and totals see them). The toolbar
  button «▴ Свернуть подобранные» folds every row with a product and without
  `needs_choice`; when all of them are folded it unfolds all rows. Folded
  row ids are remembered on this device (`localStorage['kp.rowFold']`, last
  500 ids) and survive re-renders.
- **Integer quantity**: the field is `step=1 min=0 inputmode=numeric`; `.`,
  `,`, `-`, `+`, `e` are not typed; a pasted fraction is rounded on change.
  `App.intQty()` / `RequestItems::qty()`: `≤ 0 → 0`, otherwise
  `max(1, round(q))` — used when collecting rows, when saving them and for
  quantities from the parser.

## 3. Pinned «?»

A hint that stands as its own item of a wrapping flex row is wrapped together
with its subject in `.hint-pin` (`inline-flex`, `nowrap`): «аналог» + ?,
🚫 + ? («не наша номенклатура»), category select + ?, «нет в наличии» + ?.

## 4. «Счёт в МойСклад» for a company not in MoySklad

`invoices.php?action=create_from_proposal` answers 400 with
`ms_unlinked: {counterparty_id, org_id, name, inn, email}`. The client opens
the existing «Контрагент в МойСклад» window (`msCreateForm`) prefilled with
it; after «Создать в МойСклад» the invoice is issued again for the same
organisation (`kpInvoice(id, btn, orgId)`), without reloading the page
(`App._msAfter`, cleared by `closeModal`). An unlinked organisation in the
«На какую организацию счёт?» dialog is no longer disabled — choosing it opens
the same window.

## 5. «СОТРУДНИК» in the invoice

The order and the invoice both get the attribute named `MS_EMPLOYEE_ATTR`
(default «СОТРУДНИК») from the manager's card (`managers.name, email,
moysklad_uid`).

`MoySklad::entityAttributes($entity)` — `/entity/<entity>/metadata/attributes`:
name → `{id, type, required, dictionary}`. `attributesBody()` builds the value
by type:

| Type | Value |
|---|---|
| `string`, `text`, `link` | the manager's name |
| `employee` | `employeeMeta($manager)`: by `uid` (= «UID в МойСклад»), then `email`, then ФИО (`sameEmployee`: word order, «Петрова Я.») |
| `customentity` | the dictionary entry with the same name |
| other | not filled |

An attribute that is unknown or cannot be filled goes to `missing`. Before
POST `/entity/invoiceout` a required invoice attribute that stays empty
throws a clear error instead of MoySklad's 412: «не нашли сотрудника
МойСклад для менеджера «…». Укажите «UID в МойСклад» (логин сотрудника) в
карточке менеджера: Админ → Менеджеры».

## 6. Rail tabs on desktop

The side tabs («Информация», «Заметки…») on desktop show `▸` when open (the
drawer closes to the right) and `◂` when folded; blocks in the page flow and
the phone keep `▾/▸`.
