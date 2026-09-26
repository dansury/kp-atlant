# 063 — Issue #121: typed text is never lost, the next step is lit, the rest folds away

## 1. Every typed text survives (field drafts)

A text typed and not sent — a support ticket, a note, a request pasted from a
messenger, a prompt being rewritten — used to live only in the DOM: closing the
modal, a reload or a dead network erased it. The letter composer had a server
draft (`MailDrafts`, module 033), but a failed save lost it as well.

### 1.1 Which fields
- every `<textarea id="…">` and every `input[data-keep]` of the app;
- `data-keep="<key>"` names the key explicitly (the support form, which opens
  from any screen: `support.title`, `support.body`); otherwise the key is
  `<route>#<id>` (`mail/company/12#noteText`);
- `data-keep="off"`, `readonly`, `disabled` and `type=hidden|password|file`
  are never kept; the match table rows (`data-field`) have their own autosave.

### 1.2 Where it is kept
- **Device first**: `localStorage['keep:<key>'] = {v, base, at}` on every input,
  immediately — this copy exists with no network at all.
- **Cookie only as a fallback**: when `localStorage` throws (private mode, full
  quota) the draft goes into a cookie `kpk_<hash>` (≤ 3 KB, 30 days, one
  cookie per field, at most 6). Cookies travel with EVERY request: all drafts
  in cookies would outgrow the 8 KB header limit and take the whole API down
  with 400, so a cookie is used only where the browser store is not.
- **Server**: `FieldDrafts` (`lib/field_drafts.php`), table `field_drafts`
  (`manager_id`, `key`, `body`, `base`, `updated_at`, primary key
  `manager_id + key`), 2 s after the last keystroke. `api/drafts.php`:
  `list` (the manager's drafts, loaded once at login), `save` {key, body, base}
  (an empty body deletes), `clear` {key}. Drafts older than 30 days are pruned
  on save; a body is capped at 200 000 characters, a key at 190.

### 1.3 Restoring
- `base` is a hash of the value the field had when the screen drew it (the
  server's text). A draft is put back only while that value is unchanged:
  a prompt a colleague re-saved meanwhile is not overwritten with a stale copy
  — such a draft is dropped. A draft equal to the field's value is dropped too.
- Of the device copy and the server copy the newer (`at`) wins.
- A restored field gets a line under it: «↺ Восстановлен несохранённый текст ·
  Стереть» — the manager sees that the text is theirs from last time, and can
  return to the original value.
- Fields drawn later (modals, tabs) are caught by a `MutationObserver` on the
  document, not by a call in every page function.

### 1.4 Clearing
A successful send clears its drafts (`App.keepClear(key)` /
`App.keepClearIn(root)`): support, a note, a new request, a forward, a
knowledge-base correction. A failed send clears nothing.

### 1.5 The letter composer and a dead network
`composerChanged()` also mirrors the composer body into the device store
(`keep:cmp:<thread key or company>`); `restoreComposerDraft()` takes it when
the server has no draft, the server is unreachable, or the device copy is
newer. A successful send clears it.

`App.api()` turns a network failure (`fetch` rejects) into
«Нет связи с сервером — введённое сохранено на этом устройстве»
(`err.offline = true`), so the manager knows the text is not lost.

## 2. The next step is lit (`App.markNext()`)

At every moment one button is THE next action, and it wears `.btn--next`: a
pulsing accent ring (a steady ring under `prefers-reduced-motion`). Only one
per block:
- composer: the editor is empty and there is a letter to answer →
  «✨ Сгенерировать ответ»; there is text → «Отправить»;
- «Подходящие позиции»: a row has no catalog product → «Подобрать по
  каталогу»; every row is matched → «Сформировать КП».
It is recomputed on input in the composer and after the match table redraws.

## 3. Landing on the action (`App.landOnAction()`)
After a route draws, a screen without its own focus logic lands on its next
action: `#mail/new` focuses the request text, `#mail/compose` the first empty
field of «Кому» / body. The company card keeps `focusOnOpen()` (module 062).

## 4. Less at once on a phone
- The formatting toolbar of the composer is folded on a phone behind «Aa»;
  🎤 stays visible (dictation is what a phone is for).
- «Цены и условия — на все позиции» is a `<details class="fold">`: open on a
  desktop, folded on a phone, the summary shows what is chosen
  («Розница · −5% · под заказ»).
- «📲 Установить приложение» glows with an outline, not a red fill: one filled
  button per toolbar — «Написать».

## 5. Folded and unfolded look different (`.fold`)
A foldable block (`details.fold`) wears a dashed border and a neutral
background while folded, and an accent bar on the left with a tinted summary
while open — the state is read from the colour, not by clicking twice.
