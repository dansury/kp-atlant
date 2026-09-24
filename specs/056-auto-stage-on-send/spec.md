# Module 056 — the card moves by itself when a КП or an invoice is sent

Source: the request of 2026-09-24: «если мы выслали счёт клиенту, карточка
переходит автоматически в «Ждём оплату». Если отправили КП — в «КП
отправлено». Приоритет — у «Ждём оплату»».

## 1. Stage columns

Two more column kinds (`board_columns.kind`), one per board each:

| kind | Default title | Found by title (migration and fallback) |
|---|---|---|
| `kp_sent` | КП отправлено | `/^кп\s+(отправлен|выслан)/iu` |
| `payment` | Ждём оплату | `/^жд[её]м\s+оплат/iu` |

Schema v48 sets the kind on existing columns by title. `saveColumn()` knows
both kinds and keeps them exclusive, like `inbox`, `work`, `assembly`.

`Boards::stageColumn(int $boardId, string $kind): array` — the column of that
kind; else the first column matching the title (it gets the kind); else a new
column: `kp_sent` after the `work` column, `payment` after `kp_sent`
(columns to the right shift by one).

## 2. Moving forward only

`Boards::advance(?int $cpId, ?string $threadKey, string $kind): ?string` —
puts the company card (the thread card when there is no company) into the
stage column and returns its title, or `null` when nothing moved.

Rank of a column: `kp_sent` = 1, `payment` = 2, `assembly` = 3, anything else
(inbox, work, closed, custom) = 0. The card moves only when the target rank
is higher than the rank of the column it stands in:

- КП sent while the card waits for payment or is in «Сборка» → stays;
- invoice sent → «Ждём оплату» from anywhere except «Сборка»;
- a card in «Закрыто» (rank 0) → a new deal, it moves;
- no card yet → it is created in the stage column; a dismissed card comes
  back (`addCard`).

When one letter carries both a КП and an invoice, only `payment` applies —
«Ждём оплату» has priority.

## 3. Where it fires

Only after the letter really went out (`Mailer::send` did not throw), so a
scheduled letter moves the card when it leaves, not when it is queued:

| Path | Stage |
|---|---|
| `MailCompose::send` (the letter form, scheduled letters) with an attached invoice | `payment` |
| same, with an attached КП (PDF or Word) and no invoice | `kp_sent` |
| `proposals.php?action=send` | `kp_sent` |
| `invoices.php?action=send` | `payment` |

In `MailCompose::send` the stage is applied after `MailDrafts::sent()`
(which may put a new card into «В работе»).

### Knowing what was attached

`outbox_docs` (schema v48): `name` (stored outbox file name, PK),
`manager_id`, `kind` (`kp` | `invoice`), `doc_id`, `created_at`.
`Outbox::adopt($path, $filename, $managerId, $kind = null, $docId = null)`
records the row; `mail.php?action=attach_doc` passes `invoice` / `kp` (both
`kp` and `kp_docx`). `Outbox::docsOf(array $names, int $managerId)` →
`[{kind, doc_id}]` for the files of a letter. A file the manager uploaded
himself carries no kind and moves nothing.

After a letter with documents is sent: an invoice gets `sent_at`, `sent_to`
(if not set yet); a КП gets `status='sent'`, `sent_at` (as
`proposals.php?action=send` does). The rows of sent files are deleted;
`Outbox::sweep` deletes rows whose file is gone.

The move is logged (`Logger::info('boards', …)`); the send response carries
`stage` (column title or `null`) and the letter form says «карточка → «…»» in its
send toast.
