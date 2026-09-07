# Module 011 — One board: companies, requests and letters as a single card

**Status:** Implemented
**Files:** `lib/boards.php`, `lib/kp_content.php`, `lib/parser.php`, `lib/bootstrap.php`,
`lib/settings.php`, `public/api/boards.php`, `public/api/counterparties.php`,
`public/api/proposals.php`, `public/assets/js/app.js`, `public/assets/css/app.css`

Module 010 gave the office a board, but the board was one of four things to look at:
letters lived in «Входящие», their requests in «Запросы», the client in «Компании», and a
card had to be dragged onto the board by hand before any of it was visible there. The same
conversation was therefore four rows in four lists, and a manager had to know which of them
to check. This module removes the other three.

## 1. A card is a company

`board_cards.counterparty_id` is what a card carries now. Everything that company has ever
written hangs on that one card, whatever subject each letter had and whichever mailbox it
arrived in — one object instead of «компания + запрос + письмо».

A sender we cannot resolve to a company yet still lands on the board, as a conversation card
(`thread_key`, as in module 010). The moment triage resolves the company, `sync()` **upgrades
that same card in place** — it keeps the column the manager dragged it into, because the card
was already being worked on and moving it back to «Входящие» would lose that work.

The card shows what the manager decides by: the company name, the subject and first line of
the newest letter, how many letters and how many unread, open requests, the state of the last
КП, and when the last letter came.

## 2. «Входящие» loads itself

`Boards::sync()` runs on every open of the board (`boards.php?action=get`) and puts there
every company that has written inside `BOARD_INBOX_DAYS`, plus every unresolved conversation.
Nothing is put on the board by hand any more — «в доску» is what arriving mail means.

- Spam and service mail (`Triage` categories `spam`, `service`) never make a card.
- A company already anywhere on the board is never added again — the intake cannot duplicate
  a card that sits in «Ждём оплату».
- The intake column is the one marked `board_columns.kind = 'inbox'`, not «the leftmost one»,
  so renaming or reordering the columns cannot silently redirect new mail.
- `BOARD_AUTOLOAD = 0` turns the whole intake off for an office that wants to fill the board
  by hand.
- Deleting a column no longer deletes its cards: they go back to «Входящие». A column is a
  stage of work, not a wastebasket for a client's correspondence.

## 3. Loud when they wait, quiet when they don't

The board says at a glance who needs a person, whatever column they are in:

| State | On the card |
|---|---|
| They wrote last (`last_in_at > last_out_at`) | bold title, red edge, top of its column |
| Unread letters | warm background, the count as a red pill |
| Answered | normal weight, dimmed text, the manual order it was dragged into |

A new letter to a company sitting in «КП отправлено» lights that card up and floats it to the
top **of that column** — the stage the manager chose is never overridden, only the order
inside it. Answered cards keep the exact order they were dragged into, so an arranged board
still looks arranged. The conversation list uses the same two states, so bold/dim means the
same thing everywhere.

## 4. The company card is the correspondence

`#mail/company/<id>` is now the whole client: every conversation newest first, each one
opening **in place** with its letters, the request it became and the КП that answered it;
реквизиты, contacts, orders and счета МойСклад on the right; internal notes underneath. The
stage buttons at the top move the board card without going back to the board.

Answering starts here — «Составить ответ» (the module 006 draft), «Ответить вручную», and
«Позиции и КП →» to the request card where the catalog match and the prices live. There is no
separate mail section to switch to; `#mail/inbox` survives as a flat archive for a search
across everything, and `#mail/requests` / `#mail/companies` for a link somebody saved.

## 5. What the manager writes, the model learns

Everything typed by hand on a КП is captured as a `corrections` row on confirm and fed back:

| Field | Captured from | Used for |
|---|---|---|
| `cover_letter` | the rewritten cover letter | few-shot style examples (as before) |
| `pre_table` / `post_table` | the paragraphs around the table | **pre-filled** on the next КП |
| `item_substitution` | a position answered with an analogue | the letter explains the swap in the manager's own words |

An analogue is decided by `KpContent::substitutions()`: `proposal_items.requested_name` (what
the client asked for) against `product_name` (what we offer). A model code — «6Б47», «АШ-1» —
must survive the swap; without one, half the words in common is the same product. It costs no
model call, so it runs on every КП. The editor marks exactly those positions and asks the
manager for one line — «аналог по классу защиты» — which goes into the cover letter and into
the examples the next letter is written from.

## Settings added

| Key | Meaning |
|---|---|
| `BOARD_AUTOLOAD` | put new mail on the board by itself (on) |
| `BOARD_INBOX_DAYS` | how far back the intake reaches, days (180) |

## Schema v13 (this module)

- `board_cards.counterparty_id` + index — the card is a company
- `board_columns.kind` — `'inbox'` marks the intake column
- `proposal_items.requested_name` — what was asked for, next to what we offered
- `corrections.field` rebuilt with `item_substitution` and `reply`

## API

| Action | Meaning |
|---|---|
| `boards.php?action=get` | the board, after pulling new mail into «Входящие» |
| `boards.php?action=sync` | the intake alone |
| `boards.php?action=card_add` | accepts `counterparty_id`; moves the existing card if there is one |
| `boards.php?action=placement&counterparty_id=…` | which column a company sits in |
| `counterparties.php?action=threads&id=…` | every conversation of a company, with its request and КП |

## Routes

`#mail` — the board (the section's only view) · `#mail/company/<id>` — a company with all of
its correspondence · `#mail/t/<key>` — one conversation on its own · `#mail/request/<id>`,
`#mail/proposal/<id>` — positions and the КП · `#mail/inbox` — the flat archive.
