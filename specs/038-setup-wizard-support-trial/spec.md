# Module 038 — a service that can be installed from scratch, checked, and complained about

One request (issue #49) with three asks. All three are about the service having no front
door: not for a new installation, not for a request that did not arrive by mail, and not
for a manager whose screen broke.

> Нужно поле обратной связи, в которое можно вставлять картинки, файлы, видео — все,
> что можно сохранить в GitHub issues, и чтобы любой менеджер или админ мог пожаловаться,
> как в техподдержку, описать проблему, и она отправилась на ревью администратору,
> и с его подтверждения — в issues репозитория.

> Также нужно создать мастера настройки обязательных полей, и чтобы его можно было
> перезапустить из админки, чтобы он запрашивал необходимые ключи (говорил, откуда их
> брать, давал реальные ссылки) и данные (и даже предложил создать менеджеров) —
> то есть все необходимое для нового старта с нуля.

> Когда все будет готово, надо предложить создать тестовое КП и письмо: поле для ввода
> текста и файлов (ссылка на него должна быть над «Входящими», для тех случаев, когда
> запрос пришел через мессенджер), которое затем запускает создание карточки в папке
> «в работе» и перекидывает пользователя на эту карточку. И попросить пользователя дать
> обратную связь о качестве КП и письма. Если палец вниз — предложить выбрать другую
> модель, подороже.

## 1. A manager's complaint reaches the tracker, but not directly

A manager who hit a broken screen could only tell the administrator about it in words —
with a screenshot in a messenger that went nowhere.

The **«Поддержка»** button stands in the header and opens from ANY screen: the person
who saw the breakage complains, and from where they saw it. A ticket carries what the
manager described, the files (pictures, documents, videos) and the address of the
screen they would never have noticed themselves — `#mail/company/12` goes into the
issue by itself.

```
support_tickets  manager_id  kind  title  body  page  rating  model
                 status(new|approved|declined)  reviewed_by  reviewed_at  review_note
                 issue_number  issue_url  created_at
support_files    ticket_id  filename  path  mime  size  remote_url
```

Between the manager and the public tracker stands the **administrator**: a ticket waits
for review in «Настройки → Обратная связь», and only their button opens an issue. A
public tracker is not the place for «у меня всё пропало», and the title strangers read is
written by somebody who knows how to name it.

* the notice about a new ticket goes to ADMINISTRATORS, not to every manager;
* a decline returns to the author with a reason (`review_note`) and a notification:
  silence is how you teach people to stop writing;
* an approval goes to GitHub in the same order as the learning export (module 022):
  the issue first, and only a confirmed number marks the ticket sent. Marking earlier
  loses the complaint when GitHub answers with an error;
* a manager sees their own tickets, an administrator — all of them.

**Files.** An issue attachment can be uploaded only through GitHub's web interface — the
API has no such endpoint at all. So a file is committed into the repository
(`SUPPORT_ASSETS_PATH`, by default `support/uploads/YYYY/MM/`) and printed in the issue
body: a picture as a picture, anything else as a link. It is committed into its OWN
branch, `SUPPORT_ASSETS_BRANCH` (by default `support-assets`), which
`Support::ensureBranch()` creates on the first file as an ORPHAN — a tree with one
README and a commit with no parents (module 066): a file in the branch the deploy
tracks started a full deploy for one screenshot and left a screenshot with client data
in the web root. An empty setting means the repository's default branch; a branch that
cannot be created falls back to it too, with a warning in the log. The file goes up
FIRST: a link to a file the repository does not have is an issue with a broken picture.
The original stays in `storage/support/` — outside the repository, like everything that
survives a deploy — and is served by the panel through `support.php?action=file`: in a
private repository a GitHub link does not open for an anonymous browser, and a manager
must always see their own file.

**Token.** `SUPPORT_TOKEN` — its own, with `Issues: Write` and `Contents: Write`; empty —
the common `GITHUB_TOKEN` is used. Two keys are not a whim: the wiki is read by a token
with `Contents: Read`, and there is no reason to let that token open issues.

## 2. The setup wizard: what the service needs to start

Installing on a new host was an oral tradition: the administrator opened «Все параметры»
and filled it in from memory, and what was missing came out on the first client letter.

`SetupWizard` is not a second settings screen. It asks the same `Settings::SPEC` keys (the
fields are drawn from the spec itself, not from a second list of labels), writes to the
same `Settings` and stores NOT A SINGLE value of its own: its state is only where the
operator stopped and what they skipped (the `setup_wizard` row in `settings`, with no
`cfg.` prefix — it is state, not a setting).

| Step | What it asks | Where the link leads |
|---|---|---|
| Service and address | `APP_URL`, `TIMEZONE` | — |
| Managers | — | «Настройки → Менеджеры» |
| МойСклад | token, organisation ID, warehouses | the token creation page and the organisation card |
| Models | Yandex and OpenRouter keys, models | the Yandex Cloud console with the needed roles, `openrouter.ai/settings/keys` |
| Mail | the sending address | «Настройки → Почта», adding a mailbox |
| Logos | — | «Настройки → Логотипы» |
| GitHub | token, wiki repository, tickets repository | creating a fine-grained token with the listed rights |
| Site (Bitrix) | address and webhook | optional |
| Trial КП and letter | — | see section 3 |

The link is REAL and leads straight to the right page, with the rights to grant next to
it: «go to your account settings» is not an instruction.

**A step is closed by a fact, not by a «filled in» checkbox:** a mailbox is on, the
catalog is synced, the provider has a key and answers, a logo is uploaded. That is why
the wizard can be re-run on a working service — it shows exactly what is missing — and
«Пройти заново» resets the MARK, not the settings.

The wizard greets the administrator by itself until it has been completed once
(`auth.php?action=me` returns `setup_pending`), and leaves an open bookmark alone. On
«Обзор» it is one line: what is missing and how many tickets wait for review.

The wizard invents no connection checks of its own: it calls the same `test_moysklad`
and `test_llm` as the «МойСклад» and «Нейросети» tabs, and prints the answer in words —
the token's rights one by one, the model and its answer time — not «ok: true».

## 3. A request not from mail, a card in «В работе», and a quality rating

A request that came through a messenger, by phone or as a file to a colleague's mailbox
meant «retype it by hand and lose the attachment». The «Новый запрос» screen existed but
took ONE text, and the board had no link to it at all.

Now:

* the field takes **text and files** — a specification, a photo of a chat, a scan; a
  screenshot is pasted right into the text with Ctrl+V. The text of the attachments goes
  into position matching the same way as a letter's text (`Attachments::store`);
* its link stands **above «Входящие»** — in the intake column itself and in the board
  panel: a request from a messenger lands where mail lands, and the manager has no menu
  item to look for;
* the created request becomes a card in **«В работе»** (`Boards::workColumn()`, the named
  column of module 033) — it is being worked on already, since somebody typed it in — and
  the browser goes TO THAT CARD: `requests.php?action=create` returns the address
  (`hash`), not just an id;
* no second card of the same company is created — `Boards::addCard()` moves the existing
  one.

**The trial request** is the wizard's last step, and it is the same screen with the same
field, only pre-filled with an example. `requests.is_trial` tells it apart from a client
letter: no «new request» notifications go out for it — «Ромашка» from the example is not
a client somebody forgot to answer.

**The rating.** The wizard asks about the КП and the letter separately: 👍 / 👎. A rating
is a support ticket of kind `quality` carrying the model that produced it, so it lands
where complaints land and can travel into an issue. A «good» does not wake the
administrator: praise is no reason to ring.

**A thumbs-down offers a model that COSTS MORE** (`LLM::pricier()`). «Take a better
model» without a list is an invitation to read two providers' price lists. The order is
by the real price where there is one (the refreshed OpenRouter catalog now keeps
`price`) and by the `tier` step where there is none: Yandex has no price list in its API
at all, and the OpenRouter catalog may never have been refreshed. Models of a provider
with no key and slugs the cloud does not serve (`verifyYandexModels`) are left out:
offering what cannot be used is a dead end, not a choice. The chosen model becomes its
provider's default and moves that provider to the head of the chain — otherwise «picked
a pricier one» would be a choice for one request.

The panel remembers the card the wizard led to (`sessionStorage`) and draws a strip
with the same 👍/👎 above it: the person came here to check quality and has no reason to
go back for the rating.

## Schema (v36)

```
support_tickets, support_files   — tickets and their files
requests.is_trial                — the wizard's trial request
settings['setup_wizard']         — the wizard's state (not a setting)
```

## Settings

`SUPPORT_ENABLED`, `SUPPORT_REPO`, `SUPPORT_TOKEN`, `SUPPORT_ASSETS_PATH`,
`SUPPORT_ASSETS_BRANCH`, `SUPPORT_MAX_MB` — the «Обратная связь» group.

## Tests

`php tests/module_038.php` — tickets with files and review, the issue body, refusal
without keys, wizard steps by fact, writing into `Settings`, a restart, the quality
rating, the pricier model and the trial request's card in «В работе».
`php tests/module_066.php` — the files branch: created as an orphan, reused, a race, a
refusal falls back to the default branch.
