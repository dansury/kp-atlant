# 068 — Issues #149–#150: reserve for 3 business days, the reply writes itself, edits teach it, the wiki in one place

## Reserve under an invoice (#150)

«Выставить счёт» creates the МойСклад order in «Резерв» (`MS_ORDER_STATE`, module 026/054)
and binds the invoice to it — unchanged.

The reserve is held for `MS_RESERVE_DAYS` (default **3**) days, counted as BUSINESS days
while `MS_RESERVE_BUSINESS = 1` (default): Saturday and Sunday are skipped.

```php
Reserves::until(?int $from = null): ?string   // 'Y-m-d H:i:s' or null when days() = 0
Reserves::daysLabel(?int $n = null): string   // «3 рабочих дня» / «14 дней»
Reserves::letterNote(): string                // MS_RESERVE_LETTER with {days}; '' = no note
Reserves::appendToLetter(string $text, string $note): string  // idempotent
Reserves::noteForRequest(int $requestId): string  // note when an order of the request holds an unpaid reserve
Reserves::noteForInvoice(int $invoiceId): string
```

The CLIENT hears about the term in the letter that carries the invoice. The paragraph is
a setting (`MS_RESERVE_LETTER`, textarea), `{days}` → `daysLabel()`:

> Товар по счёту зарезервирован за вами на {days}. Если на оплату нужно больше времени —
> сообщите нам, пожалуйста; иначе по истечении этого срока резерв будет снят.

It lands in the letter by two roads, both idempotent:
- `mail.php?action=draft_reply` appends it (before the signature) when the request has an
  order with a held, unpaid reserve;
- `mail.php?action=attach_doc` for an invoice returns `reserve_note`, and the composer adds
  the paragraph above the signature unless it is already in the text.

The order is VISIBLE wherever its invoice is: under the КП buttons
(`proposals.php?action=summary` → `KpSet::invoices()`) and in the invoice dock under the letter
(`invoices.php?action=for_request`), each invoice carries `order = Reserves::orderBrief()` —
`{id, name, state, sum, url, reserve}` — and `App.orderLine()` prints «Заказ 00012 ↗ · Резерв ·
резерв до 30.09» (or «резерв снят», «оплачен», «срок резерва вышел»), the number linking to the
order in МойСклад (`MoySklad::orderUrl()`).

`invoices.php?action=create_from_proposal` stores `orders.reserve_until = Reserves::until()`
and returns `reserve_note`. `cron/check_reserves.php` reminds the manager once the term is
over and the invoice is unpaid (unchanged logic; the text names the business days).

## The reply writes itself (#149)

`MAIL_AUTO_REPLY` (bool, default 1, group `triage`): the reply is drafted when the card
opens — no «Сгенерировать ответ» press.

Qualification is the server's (`Triage::autoPlan(array $msg): array{mode,reason}`):

| mode | when | what happens |
|---|---|---|
| `off` | setting off | nothing |
| `none` | category has no reply prompt (spam, service, callback…), or the letter is already answered | nothing |
| `wait` | category in `Triage::SELECTION_FIRST` (`kp_request`, `order`, `tender`), the request has positions, and it has no КП yet | the composer says «ответ соберётся после «Сформировать КП» или «Выставить счёт»» |
| `now` | everything else | the draft is generated |

`draft_reply` takes `auto: 1` (the open) or `after: 'kp' | 'invoice'` (the button):
- `auto` + `wait` → `{deferred: true, reason}`, no model call;
- `auto` + `none`/`off` → `{skipped: reason}`, no model call;
- `auto` and the letter already has `model_draft_text` → that text back (`cached: true`),
  never a second model call for one open;
- `after` → generated regardless of the gate (the КП/invoice now exists), and the reserve
  note is added when the invoice holds one.

The browser (`App.autoDraft(key, after)`) asks only when the composer is EMPTY (no restored
draft, nothing typed) and at most once per letter per tab; after «Сформировать КП» and
«Выставить счёт» it asks with `after`. A letter the manager already typed into is never
overwritten.

## Reply edits are recorded and used (#149)

Every sent reply is already stored with the model's draft (`learning_samples.kind = 'sent'`,
module 041). Now they are also USED: `Learning::replyLessons(string $category, int $limit)`
takes the last `MAIL_REPLY_LEARN_SAMPLES` (default 3, 0 = off) sent replies whose text differs
from the model's draft — same category first — and `Triage::draft()` appends them to the
system prompt through the prompt `reply_lessons` (`{{examples}}`, editable in «Промпты»).
Each side is clipped to 700 characters; the signature is not part of the example.

## The wiki in one place (#149)

«Настройки → База знаний» carries everything about the company wiki, for the admin:
- **Источник** — `KNOWLEDGE_ENABLED`, `KNOWLEDGE_REPO`, `KNOWLEDGE_BRANCH`, `KNOWLEDGE_PATH`
  (folder picker), `GITHUB_TOKEN`, `KNOWLEDGE_SYNC_TTL_SEC`, with «Сохранить»;
- **Обновление базы** — the copy's state and «Проверить и обновить» / «Перечитать всё заново»;
- **Пополнение базы** — `LEARNING_EXPORT_REPO`, `LEARNING_EXPORT_BRANCH`,
  `LEARNING_EXPORT_PATH` (folder picker), the number of new corrections and «Выгрузить архивом».

The folder picker lists the directories of the repository branch:
`admin.php?action=knowledge_folders&repo=&branch=` → `Knowledge::folders(repo, branch)` (the
git tree, recursive, directories only, at most 1000). The field stays typeable: a folder that
does not exist yet is created by the first export. Fields are rendered by the same
`App.settingField()` as «Все параметры», saved through `admin.php?action=settings`.

## Support: the screenshot survives the autosave (#149 comment)

A file attached to a support ticket is uploaded at once (`storage/outbox/<manager>/`, 48 h).
The form keeps its list in a hidden `data-keep="support.files"` field (JSON of
`{name, filename, size, image}`), so the same field-draft mechanism (module 063) that keeps
the title and the text keeps the files: reopening the form brings the chips back, images
previewed through `mail.php?action=outbox_file`. A successful send clears it with the rest.
`outbox_file` answers image types with their own MIME so the preview renders.
