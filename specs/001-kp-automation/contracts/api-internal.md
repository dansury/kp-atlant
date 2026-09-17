# Internal API Contracts

**Base URL**: `/api/`  
**Auth**: PHP session cookie (all endpoints require auth except `POST /api/auth.php?action=login`)  
**Content-Type**: `application/json` (request and response)  
**Error format**: `{"error": "message", "code": 400}`

---

## Auth (`auth.php`)

### POST `?action=login`
```json
// Request
{"login": "admin", "password": "secret"}
// Response 200
{"ok": true, "manager": {"id": 1, "name": "Кирилл", "is_admin": true}}
// Response 401
{"error": "Invalid credentials", "code": 401}
```

### POST `?action=logout`
```json
// Response 200
{"ok": true}
```

### GET `?action=me`
`setup_pending` is sent to admins only — the setup wizard has never been finished (module 038).
```json
// Response 200
{"id": 1, "login": "admin", "name": "Кирилл", "email": "...", "is_admin": true,
 "setup_pending": false}
```

---

## Requests (`requests.php`)

### GET `?action=list&status=new&page=1&per_page=20`
```json
// Response 200
{
  "items": [
    {
      "id": 1,
      "source": "email",
      "counterparty_name": "ООО Газпром",
      "status": "new",
      "items_count": 3,
      "manager_name": null,
      "created_at": "2026-08-25T10:00:00"
    }
  ],
  "total": 42,
  "page": 1
}
```

### GET `?action=get&id=1`
```json
// Response 200
{
  "id": 1,
  "source": "email",
  "raw_text": "Прошу выставить КП на...",
  "parsed": {
    "org_name": "ООО Газпром",
    "contact": "Иванов И.И.",
    "items": [
      {"name": "аптечка Лазарь Multicam", "qty": 10, "raw": "10 шт. аптечка Лазарь Multicam"}
    ],
    "delivery_terms": "доставка ТК до Москвы"
  },
  "counterparty": {"id": 1, "name": "ООО Газпром", "inn": "7736050003"},
  "manager": null,
  "status": "new",
  "email_from": "ivanov@gazprom.ru",
  "created_at": "2026-08-25T10:00:00"
}
```

### POST `?action=create` (manual paste, US2; files and the board card — module 038)
`files` — names returned by `mail.php?action=upload`; `trial` marks the setup wizard's own
check. The request lands as a card in «В работе» and the answer says WHERE to go.
```json
// Request
{"text": "Нужно 20 жгутов-турникетов CAT...", "counterparty_name": "ООО Ромашка",
 "files": ["a1b2__спецификация.xlsx"], "trial": false}
// Response 201
{"id": 2, "status": "processing", "type": "kp_request", "counterparty_id": 7,
 "card_id": 31, "files": 1, "hash": "mail/company/7"}
```

### POST `?action=assign&id=1`
```json
// Response 200 — assigns to current session manager
{"ok": true}
```

---

## Proposals (`proposals.php`)

### POST `?action=generate&request_id=1`
Triggers KP generation: LLM parse → MoySklad search → PDF draft.
```json
// Response 201
{
  "id": 1,
  "status": "draft",
  "items": [
    {
      "id": 1,
      "position": 1,
      "product_name": "Аптечка тактическая «Лазарь» Multicam",
      "moysklad_product_id": "abc-123",
      "unit": "шт.",
      "quantity": 10,
      "price": 4500.00,
      "vat_rate": 5,
      "stock_available": 25,
      "match_confidence": 0.95,
      "is_confirmed": true
    }
  ],
  "cover_letter": "Добрый день! По Вашему запросу...",
  "pdf_preview_url": "/api/proposals.php?action=preview&id=1"
}
```

### PUT `?action=update&id=1`
```json
// Request — manager edits
{
  "items": [{"id": 1, "quantity": 15, "price": 4300, "is_confirmed": true}],
  "pre_table_text": "Условия: самовывоз г. Москва",
  "post_table_text": "В комплект к каждому включены...",
  "cover_letter_final": "Добрый день, Иван Иванович!...",
  "execution_days": 10,
  "vat_rate": 5,
  "show_vat_total": true
}
// Response 200
{"ok": true, "pdf_preview_url": "/api/proposals.php?action=preview&id=1"}
```

### GET `?action=preview&id=1`
Returns regenerated PDF as `application/pdf` stream.

### POST `?action=confirm&id=1`
Sets `status=confirmed`, regenerates final PDF.
```json
{"ok": true, "pdf_path": "data/kp/KP-2026-001.pdf"}
```

### POST `?action=send&id=1`
Sends email with PDF attachment.
```json
// Request
{"to": "ivanov@gazprom.ru", "subject": "КП от Atlant Armour"}
// Response 200
{"ok": true, "sent_at": "2026-08-25T12:00:00"}
```

---

## Products (`products.php`)

### GET `?action=search&q=аптечка+лазарь`
Возвращает то, что можно ВЫБРАТЬ: у товара с модификациями — сами модификации, у товара без
модификаций — его самого. `stock` — свободный остаток, резерв уже вычтен.
```json
// Response 200
{
  "items": [
    {
      "moysklad_id": "abc-124",
      "name": "Аптечка тактическая «Лазарь» (Цвет: Multicam)",
      "variant_label": "Multicam",
      "group_name": "Аптечка тактическая «Лазарь»",
      "group_article": "APT-LAZ",
      "article": "APT-LAZ-MC",
      "price": 4500.00,
      "stock": 22,
      "unit": "шт.",
      "prices": {"Розница": 4500.00}
    }
  ]
}
```
`variant_label` и `group_name` пустые — это товар без модификаций, он выбирается сам.

### POST `?action=refresh_cache`
Forces full product cache refresh from MoySklad.
```json
{"ok": true, "count": 350, "elapsed_sec": 2.4}
```

---

## Counterparties (`counterparties.php`)

### GET `?action=search&q=газпром`
### GET `?action=get&id=1`
### POST `?action=create` — `{"name": "...", "inn": "...", "contact_person": "..."}`
### GET `?action=lookup_moysklad&inn=7736050003` — search in MoySklad by INN

---

## Notifications (`notifications.php`)

### GET `?action=poll`
```json
// Response 200 — polled every 30s by JS
{
  "items": [
    {"id": 1, "type": "new_request", "title": "Новый запрос от ООО Газпром", "ref_type": "request", "ref_id": 1, "created_at": "..."}
  ],
  "unread_count": 3
}
```

### POST `?action=read&id=1`
```json
{"ok": true}
```

---

## Corrections (`corrections.php`)

### GET `?action=list&page=1`
### GET `?action=export` — returns all corrections as JSON array (NFR-008)

---

## Settings (`settings.php`)

### GET `?action=legal_entity`
### PUT `?action=legal_entity` — update legal entity fields
### POST `?action=upload_signature` — multipart upload of signature PNG
### POST `?action=upload_logo` — multipart upload of logo
### GET `?action=email_rules` — current EMAILRULES.md content
### PUT `?action=email_rules` — `{"content": "# Rules\n..."}`

---

## Orders (`orders.php`) — US5

### POST `?action=create&proposal_id=1`
```json
// Response 201
{"ok": true, "moysklad_order_id": "def-456", "order_number": "00123"}
// Response 403 — no write access
{"error": "MoySklad API: no write access to customerorder", "code": 403}
```

---

## Follow-ups (`followups.php`) — US6

### GET `?action=list` — pending follow-up suggestions
### GET `?action=get&id=1` — draft text
### PUT `?action=update&id=1` — `{"final_text": "..."}`
### POST `?action=send&id=1` — send follow-up email
### POST `?action=dismiss&id=1`

---

## Deals (`deals.php`) — US7

### GET `?action=status&counterparty_id=1`
```json
{
  "counterparty": "ООО Газпром",
  "status": "repeat_client",
  "orders_count": 2,
  "total_amount": 450000,
  "last_order_date": "2026-07-15",
  "shipments": 2,
  "payments": 2
}
```

---

## Support (`support.php`) — module 038

Any signed-in manager may submit; review and the GitHub issue are admin-only.

### GET `?action=list&status=new`
```json
// Response 200 — a manager sees only their own tickets
{
  "items": [
    {"id": 3, "kind": "bug", "title": "Не отправляется КП", "status": "new",
     "page": "#mail/company/12", "manager_name": "Менеджер", "issue_url": null,
     "files": [{"id": 5, "filename": "скриншот.png", "mime": "image/png", "size": 8213,
                "remote_url": null}], "created_at": "2026-09-17T10:00:00"}
  ],
  "kinds": {"bug": "Не работает", "idea": "Предложение", "question": "Вопрос",
            "quality": "Качество КП и письма"},
  "statuses": {"new": "На ревью", "approved": "В GitHub", "declined": "Отклонена"},
  "enabled": true, "is_admin": false, "pending": 0,
  "repo": "dansury/kp-atlant", "token_set": true, "max_mb": 25
}
```

### POST `?action=upload` — multipart `file`, staged like a letter's attachment
```json
{"ok": true, "file": {"name": "a1b2__скриншот.png", "filename": "скриншот.png", "size": 8213}}
```

### POST `?action=submit`
```json
// Request
{"kind": "bug", "title": "...", "body": "...", "page": "#mail/company/12",
 "files": ["a1b2__скриншот.png"]}
// Response 200
{"ok": true, "id": 3, "files": 1, "items": [/* the list again */]}
```

### GET `?action=file&id=5` — the stored file itself (not a GitHub link)

### POST `?action=approve` — admin; uploads the files, then creates the issue
```json
// Request
{"id": 3, "title": "Не отправляется КП из карточки"}
// Response 200
{"ok": true, "number": 61, "url": "https://github.com/…/issues/61", "uploaded": 1, "failed": []}
// Response 400 — nothing was sent and the ticket is still «new»
{"error": "Нужен токен GitHub с правом Issues: Write — «Настройки → Обратная связь»", "code": 400}
```

### POST `?action=decline` — admin; `{"id": 3, "note": "Уже есть в «Повторить»"}`

---

## Setup wizard (`setup.php`) — module 038, admin only

### GET `?action=state`
```json
{
  "steps": [
    {"key": "moysklad", "title": "МойСклад", "why": "…", "optional": false, "skipped": false,
     "state": {"status": "ok", "note": "Каталог: позиций 1240"},
     "links": [{"label": "Создать токен в МойСклад", "url": "https://…", "note": "…"}],
     "fields": [{"key": "MOYSKLAD_TOKEN", "label": "Токен МойСклад", "type": "secret",
                 "secret": true, "value": "", "filled": true, "tail": "1a2b…9z8y"}],
     "test": "test_moysklad"}
  ],
  "state": {"started_at": "…", "done_at": null, "skipped": [], "trial_request_id": 0,
            "trial_counterparty_id": 0, "trial_votes": {}},
  "done": 6, "total": 8, "next": "llm", "ready": false, "trial_text": "Здравствуйте!…"
}
```

### POST `?action=save` — `{"step": "moysklad", "values": {"MOYSKLAD_TOKEN": "…"}}`
Keys that do not belong to the step are ignored; an empty secret keeps the stored value.

### POST `?action=skip` / `?action=start` / `?action=restart` / `?action=finish`
`restart` clears the wizard's own state only — no setting is touched.

### POST `?action=vote` — quality of the trial КП and letter
```json
// Request
{"what": "letter", "vote": "down", "comment": "Сухое, без сроков", "model": "yandex:yandexgpt-lite"}
// Response 200 — a «quality» support ticket plus models that cost MORE
{"ok": true, "ticket": 9, "current": {"provider": "yandex", "model": "yandexgpt-lite"},
 "pricier": [{"provider": "yandex", "model": "yandexgpt", "label": "YandexGPT Pro",
              "spec": "yandex:yandexgpt", "price": null, "tier": 2}],
 "done": 8, "total": 8}
```

### POST `?action=model` — `{"spec": "yandex:yandexgpt"}`
Stores it as the provider's default model and moves that provider to the head of the chain.
