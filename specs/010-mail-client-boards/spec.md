# Module 010 — A mail program: threads across mailboxes, «Отправленные», and boards

**Status:** Implemented
**Files:** `lib/mail_threads.php`, `lib/mail.php`, `lib/email.php`, `lib/boards.php`,
`public/api/mail.php`, `public/api/boards.php`, `public/api/admin.php`,
`public/assets/js/app.js`, `public/assets/css/app.css`

The mail section was a flat list of letters. The company's mail is forwarded to **two**
mailboxes at once — Яндекс and Gmail — and an answer can leave from either, so one
conversation was three unrelated rows and nobody could tell whether the client had been
answered.

## 1. One conversation, whichever mailbox it lives in

`MailThreads` groups letters the way the client sees them: **by the subject with its
prefixes stripped**. `Re:`, `RE[2]:`, `Fwd:`, `FW:`, `Ответ:`, `Пересылка:` and a leading
`[list]` tag come off (repeatedly — a subject carries several), whitespace collapses, case
is ignored, and the rest is the thread key. That rule is what joins a Gmail reply to a
Yandex original: the two mailboxes share no `Message-ID` history at all, only the subject.

`In-Reply-To` is the fallback for a letter with no subject: it inherits its parent's thread,
and a letter with neither stands alone under its own `Message-ID`.

- The key is computed **on the way in**, in `MailArchive::storeIncoming()` and
  `storeOutgoing()`, so a synced letter is already threaded when the page opens.
- The whole archive was keyed once by the v11 migration; «Настройки → Почта → Пересобрать
  цепочки» re-runs it after subjects are repaired.
- A filter narrows **which threads** are listed, never which letters are inside one: a
  thread found by «непрочитанные» still opens with its full history.

**The list reads like a mail program** — the subject in normal type, everything else (who
wrote, which mailbox, the first line of the body) small and grey, and the letter count as a
bubble next to the subject: «Запрос КП на бронежилеты · 4». Which mailboxes a conversation
touches is shown as chips, because «яндекс + gmail» is the whole reason the thread exists.

**Answering.** The reply window opens on the thread with the mailbox that carried its last
letter preselected — answering a Gmail-forwarded letter from Yandex is what split threads in
the first place — and any mailbox may be chosen instead. The answer is stored with the
thread key of the letter it answers, so it lands in the same conversation whatever it was
sent from.

## 2. Sent mail actually in «Отправленные»

The copy of an outgoing letter was appended to the folder named in the mailbox settings, and
a failure was a `Logger::warning` nobody read. When the name was wrong — Яндекс calls it
«Отправленные», Gmail «[Gmail]/Отправленные», cPanel «INBOX.Sent» — the letter left, the
copy went nowhere, and the folder on the server stayed empty.

- `EmailReader::findSentFolder()` matches the configured name against what IMAP `LIST`
  actually reports, then against the known names, then against any folder whose last segment
  reads as «sent»/«отправленные».
- The name that worked is written back to the mailbox, so the next send skips the search.
- The outcome is stored on the archived row (`mail_messages.sent_state`) and returned by
  `mail.php?action=send`: a failure is an **error** in the log, a red toast at send time, and
  a badge «нет в «Отправленных»» on the letter in the thread.
- «Настройки → Почта → Отправленные» prints the server's own folder list, the resolved name
  and the state of the last ten sent letters.
- The message keeps its `Message-ID`, so the copy that the next IMAP sync pulls back out of
  «Отправленные» is recognized as the same letter instead of appearing twice in the thread.

## 3. Kanban boards

`#boards` is a Trello-shaped board: named boards, columns you rename and recolour, and cards
you drag between them with the browser's own HTML5 drag-and-drop (no library on a page that
has none). A new board comes with the columns this office actually uses — Входящие, В работе,
КП отправлено, Ждём оплату, Закрыто.

**A card carries a thread, not a letter.** The whole conversation travels together, and a new
answer shows up on the card already sitting in «Ждём оплату» instead of starting a second one
somewhere else. The card shows the live state of that thread: how many letters, how many
unread, which mailboxes, when the last one came. The same thread is never added twice to one
board — «в доску» moves the existing card instead.

Dropping a card sends one `card_move`; if the server refuses, the board is re-read so the
screen never disagrees with the database.

## Settings added

| Key | Meaning |
|---|---|
| `MAIL_THREADS` | show mail as conversations (on) |

`MAIL_APPEND_SENT` (module 004) keeps its meaning, but now reports what happened.

## Schema v11 (this module)

- `mail_messages.thread_key`, `.thread_subject`, `.sent_state` + index on `(thread_key, date_at)`
- `boards`, `board_columns`, `board_cards`

## API

| Action | Meaning |
|---|---|
| `mail.php?action=threads` | conversation list (filters, paging, unread) |
| `mail.php?action=thread&key=…` | every letter of one conversation + the reply context |
| `mail.php?action=send` | accepts `thread_key`, returns `sent_state` / `sent_folder` / `warning` |
| `admin.php?action=mailbox_sent_check` | resolve and fix the IMAP «Отправленные» folder |
| `admin.php?action=mail_rethread` | rebuild the grouping over the whole archive |
| `boards.php?action=list\|get\|board_save\|board_delete` | boards |
| `boards.php?action=column_save\|column_delete\|columns_reorder` | columns |
| `boards.php?action=card_add\|card_move\|card_save\|card_delete` | cards |
| `boards.php?action=placement\|targets` | where a thread already is, and where it can go |

## Routes

`#mail` — conversations · `#mail/<thread_key>` — one conversation ·
`#mail/msg/<id>` — a single letter · `#boards` · `#board/<id>`
