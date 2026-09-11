# Module 012 — The letter is the workplace: positions, the reply, and one thread per sender

**Status:** Implemented
**Files:** `lib/mail_threads.php`, `lib/mail.php`, `lib/bootstrap.php`,
`public/assets/js/app.js`, `public/assets/css/app.css`, `public/index.php`,
`public/manifest.webmanifest`, `public/assets/icons/*`

Module 011 put the company on the board. Working inside that card still meant
leaving it: the «Подходящие позиции» table — the thing a КП is actually built
from — lived on a separate request page, and answering opened a dialog behind
one of two buttons that did almost the same thing. And conversations were keyed
by subject alone, so «Запрос КП на бронежилеты» from three companies was one
thread on one card.

## 1. One conversation per subject **and sender**

`MailThreads::party()` decides who the other side is: the corporate domain of
the address (so a colleague at the same company joins the same thread), or the
whole address when the domain is a free mailbox — two clients on gmail.com are
two companies. For a letter we sent, the party is the ADDRESSEE, so our own
answer still lands in the client's conversation, whichever of our mailboxes it
left from. The key is `s:md5(party|subject)`.

`In-Reply-To` remains the fallback for a letter with no subject.

The v14 migration re-keys the whole archive. A board card points at a thread by
its key, so every card is remembered before the rebuild and pointed back at the
conversation of its own first letter afterwards — the card keeps the column the
manager dragged it into, and no card is left dangling on a key that no longer
exists.

## 2. The request belongs to the conversation, not to its last letter

`summary()` and `replyContext()` used to read `request_id` and `counterparty_id`
off the newest row. A client's «ждём, спасибо» carries neither, so the moment
anyone replied, the thread lost its request — and with it the positions and the
КП. Both now take the conversation's own: `MAX(request_id)` over the thread.

## 3. The positions table, inside the letter

The «Подходящие позиции» table is drawn under the conversation it belongs to:
the same `request_items`, the same catalog autocomplete, the same «равнозначные»
question, and «Сформировать КП» right under the rows. A price corrected here is
the price the КП is built from.

The table was tied to fixed element ids (`#matchCard`, `#matchRows`), so only
one could exist on a page. It is scoped to its host block now
(`[data-match-host]`), and every action — add a row, re-match, save, choose a
variant, the running total — works off the block the button is in. The request
page keeps its own copy; nothing about it changed.

## 4. The reply, at the foot of the conversation

One box, already open, under the letters — no dialog, no second button. It
carries the recipient, the subject and the mailbox that carried the last letter,
and «✨ Черновик нейросетью» writes into the same textarea (module 006's
`draft_reply`) instead of into a window of its own. «Ответить на это письмо» on
a letter aims that same box at it rather than opening another one.

Removed: the second «Создать ответ» button standing next to «Ответить» (on the
thread page, on every letter, and on the single-letter page) and the
`mailCreateReply()` helper behind it. «Написать» still opens the compose dialog
— a new letter to nobody in particular has no conversation to sit under.

## 5. A white interface

The page background is white and blocks are told apart by a hairline border and
a very soft shadow; tints are spent on meaning instead — a card waiting for an
answer, a column of the board, a warning, an analogue that needs explaining. The
shop's red stays the single accent (the wordmark, the primary button, the active
tab) and is never the background of a screen. Red TEXT goes through
`--accent-ink` (6.1:1 on white); `--primary` is a fill and a border colour.
Contrast of every pair in use was checked by hand against WCAG AA.

Chosen with the `ui-ux-pro-max` design database (Email Client / CRM palettes,
data-dashboard typography, the web UX table): borders over background tints for
dense data, tabular numerals for money and counters, 8px radii, and a 1280px
working width so five board columns and a letter card with its positions fit
without a horizontal scroll. The font stays the system stack — an internal tool
that must open instantly offline should not wait on Google Fonts.

## 6. The real logo on the app icon

`icon-192`, `icon-512`, `icon-512-maskable` and `badge-96` are generated from
the brand lockup (the mark over the wordmark on the brand charcoal). The
maskable variant keeps the artwork inside the 80% safe zone so a круглый
launcher cannot clip the wordmark; the badge is the mark alone, white on
transparent, because Android tints a notification badge by its alpha.

## Schema v14 (this module)

No new columns: every `mail_messages.thread_key` is recomputed, and
`board_cards.thread_key` is remapped onto the new keys. `board_sync_sig` is
dropped so the board's intake re-decides once on the next open.

## Routes

Unchanged. `#mail/request/<id>` and `#mail/proposal/<id>` stay as deep views;
nothing on the letter card sends a manager to them any more except «Открыть КП».
