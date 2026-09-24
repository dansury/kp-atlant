# 059 — Voice input for letters; issues #86–#90

## 1. Voice input («печатать письма голосом»)

Taken from `dansury/kraskiweb` (`Ai::transcribeVoice`, `AudioRemux`, the
MediaRecorder recorder in `assets/app.js`).

- `lib/audio_remux.php` — `AudioRemux::webmOpusToOggOpus(bytes): ?string`,
  pure-PHP WebM(Opus) → Ogg(Opus) remux (EBML walk → OpusHead + frames →
  Ogg pages, granule @48 kHz, Ogg CRC-32). No ffmpeg on the host. Null on any
  malformed input.
- `lib/speech.php` — `Speech`:
  - `ready(): bool` — `YANDEX_API_KEY` and `YANDEX_FOLDER_ID` are set (the
    same key as YandexGPT; the service account needs `ai.speechkit-stt.user`).
  - `transcribe(path, mime): string` — Yandex SpeechKit STT v1 short
    recognition (`STT_URL`, `lang=ru-RU`). WebM is remuxed to OggOpus first;
    if that answer is empty/400 the original bytes go with `format(mime)`.
    Throws `RuntimeException` with words for the manager: no key, empty
    record, > 1 MB (v1 limit ≈ 30 s), HTTP error with the API's message,
    nothing recognised. Every HTTP failure is `Logger::error('speech')` with
    the code and the answer. Proxy: `LLM::proxyFor('yandex')`.
  - `format(mime)` — `mp3` / `lpcm` / default `oggopus`.
  - `$http` — test hook `(bytes, format) → {code, body}`.
- `mail.php?action=transcribe` — multipart `audio` → `{text}` or `{error}`.
- UI: «🎤 голосом» in every letter editor — the reply/compose box
  (`threadComposer`, contenteditable) and the reply modal (`#cmpText`).
  `App.dictate(btn, target)`: click → record (`MediaRecorder`, Opus preferred),
  the button shows «⏹ 0:SS — стоп», click again → upload → the text is inserted
  at the caret saved before recording (`App.insertDictation`), as typing
  (fires `input` → the draft autosaves). Auto-stop at 29 s; a tap < 0.6 s is
  discarded; one recording at a time. No mic / no MediaRecorder → a toast.
- The Yandex key help (`Settings::SPEC`, setup wizard) names the STT role.

## 2. Issue #86 — «под заказ» comes back

- `request_items.wait_manual`, `proposal_items.wait_manual` INTEGER DEFAULT 0
  (schema v50): the manager set or cleared «под заказ» by hand.
- UI (`matchRowExtra`): the box is auto-checked for an out-of-stock row only
  while `wait_manual = 0`; any click sets the hidden `wait_manual = 1`.
- `RequestItems::save()` stores it; `toProposalItems()` / `KpSet::itemRow()`
  carry it; `applyConditions()` never changes `wait_on` of a manual row;
  `Terms::prepare()` never re-raises it with `KP_WAIT_AUTO`.
- `proposals.php` item update with `wait_on` sets `wait_manual = 1` and copies
  the decision onto the source `request_items` row, so «Пересобрать» keeps it.
- Stock of a product with modifications is the free stock of its modifications:
  `RequestItems::all()` shows it, `save()` recomputes it (`refreshStock()`),
  `choose()` uses `Variants::freeStock()`. A generic product with stock on its
  modifications is not «под заказ».

## 3. Issue #87 — WYSIWYG КП does not remove strikethrough

The template strikes the old price with the class `.was` (and `<del>`);
`execCommand` removes tags only. `App.kpUnstrike(doc)` runs after
«Очистить оформление» and after «Зачёркнутый» when the selection was struck:
it drops `.was`, `line-through` styles and unwraps `del/s/strike` on every
element the selection touches (and their ancestors).

## 4. Issue #88 — СДЭК track and screenshots in support

- `Fulfillment::checkShipments()` checks orders of companies on board cards in
  EVERY column except `kind = 'closed'` (was: «Сборка» only). Track filled →
  shipment draft (СДЭК → `cdekUrl()` link in it), as before.
- `Boards` card: `cdek = attention && draft body has the cdek.ru tracking link`;
  list row `grow--cdek` and card `bcard--cdek` are purple until the letter is
  sent.
- Support modal: a screenshot is pasted with Ctrl+V anywhere in the window
  (not only in the textarea — focus opens on «О чём»), dropped onto the drop
  zone or chosen; images show a thumbnail.

## 5. Issue #89 — «Написать» opens the full reply editor

`#mail/compose` → `App.pageMailCompose()`: `threadComposer('', {to, subject})`
on its own page — formatting, attachments, signature, draft, scheduled send,
voice. `App.mailCompose()` with nothing to reply to routes there; after
sending the page returns to `#mail`.

## 6. Issue #90 — admin support goes straight to GitHub

`support.php?action=submit` from an administrator: the ticket is stored
(quietly — no notification to the admins) and `Support::approve()` runs at
once when `SUPPORT_REPO` and the token are set. The answer carries
`issue_number`/`issue_url`, or `issue_error` — then the ticket stays «На ревью».
A manager's ticket still waits for the admin.
