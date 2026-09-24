# 060 — Issues #92–#95: photos by pick, one way to send a КП, a card leaves with its last letter, voice in support

## 1. Issue #92 — every ticked photo goes into the КП

The picker and the document disagreed: with no pick the picker showed EVERY
photo ticked while the document printed one (`KP_CARD_PHOTOS`), and an
explicit pick was cut to «Фото на позицию, максимум» by `array_slice`.

- The per-position count is ONE value — `KpContent::photoLimit(?int $proposalId): int`:
  `proposals.photos_per_item` when set, otherwise `settings.kp_max_images_per_item`
  (default 5). `PdfGenerator::html()` and `enrichItems()` read it from there.
- `KpContent::itemGallery($item, $max)`:
  - no pick (`selected_images` NULL) → the first `$max` photos;
  - an explicit pick → EVERY ticked photo, in catalog order, never capped;
  - an explicitly empty pick → no photos.
- `KP_CARD_PHOTOS` is removed from `Settings::SPEC`: a second knob that
  silently overrode the first was the bug.
- `proposals.php?action=item_images` and `requests.php?action=item_images`
  return `default_count` (= `photoLimit()` of the КП; the setting on a request
  row). The pickers (`App.loadItemPhotos`, `App.loadMatchPhotos`) tick the first
  `default_count` photos when nothing was picked — the screen shows exactly what
  prints.
- The settings label reads «Фото на позицию по умолчанию» with the hint that
  a hand pick is not limited by it; the КП editor field likewise.

## 2. Issue #94 — a КП is sent only from the letter editor

«Подтвердить и отправить» (the КП window under the letter and the КП page
header) and the «Отправка» block of the КП page are gone, with
`App.confirmAndSend`, `App.sendProposal`, `App.kpAttachFiles` and the API
actions `proposals.php?action=confirm|send`. A КП reaches the client ONLY as a
file attached in the letter editor (`mail.php?action=attach_doc`, kind `kp` /
`kp_docx`) and sent with that letter — which already marks it `sent` and moves
the card (module 056).

What «confirm» did is done at attach time by `KpConfirm` (`lib/kp_confirm.php`):

- `KpConfirm::priceGate(int $id, array $input, int $managerId): ?array` — the
  no-price question (module 018). Returns the `no_price` payload while a
  position without price is not acknowledged and `no_price_ack` is absent;
  stores the acknowledgement otherwise. `attach_doc` answers 409 with it and
  `App.attachDoc` repeats the call through `App.postWithNoPriceAck()` (which
  now returns the response, or null when the manager said no).
- `KpConfirm::prepare(int $id, int $managerId): void` — the corrections the
  manager made (cover letter, text around the table, substitutions; each lesson
  stored once), today's stock (`KpContent::refreshStock`), and status
  `draft → confirmed`. It runs before the file is built, so the file carries
  fresh stock and the lesson matches what is attached.

## 3. Issue #93 — deleting a company's last letter removes its card

`MailSync::deleteMessage()` already removed a thread card; a COMPANY card
stayed on the board empty. After the delete it now runs
`Boards::pruneEmptyCards($counterpartyId)` (via `pruneBoard()`, never fatal) —
the same rule as archiving: a card with no live letter of its family, no draft
and no hand-made request leaves the board. The answer carries
`card_removed` (count); `App.deleteMail` then says «карточка убрана с доски» and
goes back to the board instead of re-drawing an empty company card.

## 4. Issue #95 — the support form takes voice

The support modal (`App.supportModal`) gets «🎤 голосом» under «Коротко» and
«Что случилось» — the same `App.dictate(btn, target)` as the letter editor
(module 059), same endpoint `mail.php?action=transcribe`. `App.closeModal()`
stops a recording in progress; a recording whose field is gone is not sent for
recognition.
