# Module 043 — three errors from the journal: Yandex route, JSON retry, MoySklad retries

Source: the error journal of 18–22.09.2026, three recurring entries.

```
llm.php:492  LLMException: Failed to parse JSON after 3 attempts: Syntax error
             /api/mail.php?action=sync
moysklad.php:1248  MoySkladException: MoySklad request failed after 3 retries:
             GET /entity/customerorder/…?expand=positions.assortment,agent,state
             /api/moysklad_hook.php
llm  All LLM providers failed: yandex returned HTTP 400:
     {"message":"Model is not available via gRPC API. Please use HTTP OpenAI API instead."}
     /api/admin.php?action=learning_rethink
```

Each of the three is a case the code knew nothing about: a model served by the
other Yandex endpoint, an answer the model repeats verbatim on every retry, and
a MoySklad rate limit whose own `Retry-After` was never read.

## 1. Yandex: the models that answer only on the OpenAI-compatible route

Yandex Cloud serves models through two endpoints:

| Route | URL | Models |
|---|---|---|
| `fm` | `…/foundationModels/v1/completion` | YandexGPT family |
| `openai` | `…/v1/chat/completions` | most open models (Llama, Qwen, Gemma, DeepSeek, GPT-OSS) |

`lib/llm.php` only knew the first one. Picking an open model from the catalog
ended in HTTP 400 *"Model is not available via gRPC API. Please use HTTP OpenAI
API instead"* — and a hand-picked model does not start the provider chain (that
is deliberate, module 029), so the request simply failed.

The provider is asked, not guessed, exactly as module 029 does with `unknown
model`:

```
yandexRoute(slug)          what we already learned about this slug: fm | openai
callYandex(...)            route openai → straight to the OpenAI-compatible URL;
                           otherwise the FM URL, and HTTP 400 «via gRPC / use
                           HTTP OpenAI API» remembers the route and repeats the
                           request on it at once — the answer still arrives
callYandexOpenAI(...)      {model: gpt://folder/slug/latest, messages:[…],
                           temperature, max_tokens, response_format} with
                           `Authorization: Api-Key`; the reply is OpenAI-shaped
                           (`choices[0].message.content`)
verifyYandexModels()       the same 400 now scores the slug `ok` and stores its
                           route, instead of landing in «не удалось выяснить»
```

The route lives next to the probe result in `settings.yandex_models`
(`{checked: {slug: ok|missing}, routes: {slug: openai}, synced_at}`), so it is
learned once per cloud and «Забыть проверку» forgets it with everything else.
The model picker marks such a slug «— по OpenAI-совместимому API».

## 2. JSON: a retry that knows what was wrong with the previous answer

`LLM::chatJson()` asked three times with the same prompt, changing only the
temperature. A model that answers with prose, with a truncated object or with a
raw newline inside a string does it again all three times — hence three
identical failures and one `Syntax error` in the journal with nothing to act on.

* **The retry carries the reason.** Attempts 2 and 3 append a block to the
  system prompt: what could not be parsed, the demand for a bare JSON object,
  and — when the answer was cut off — the demand to answer shorter.
* **Truncation is now visible.** `httpPost()` remembers the provider's own
  verdict (`finish_reason: length` on the OpenAI shape,
  `ALTERNATIVE_STATUS_TRUNCATED_FINAL` on the FM shape); `LLM::lastTruncated()`
  reports it, the retry hint and the journal entry both name it, and the
  operator is pointed at `LLM_MAX_TOKENS`.
* **The journal entry can be acted on**: provider, model, truncation flag, and
  both the head and the tail of the answer (the head alone showed nothing when
  the answer was prose, the tail alone showed nothing when it was cut off).
* **`decodeJson()` handles two more shapes** on top of module 034's repair:
  bytes that are not valid UTF-8 (they made every `/u` regex bail out and
  turned the whole answer into «not JSON»), and raw control characters inside
  string literals — a literal line break inside a value is the single most
  common way a model breaks its own JSON. Both are repaired, never invented.
* **`LLM_MAX_TOKENS`** (new setting, default 4096) replaces the number hard-wired
  into the Yandex request. It bounds the Yandex answer only; OpenRouter keeps
  the provider's own default, so nothing that fits today starts being cut off.

## 3. MoySklad: the rate limit is read, the retries are honoured, the event is not lost

`MoySklad::request()` retried three times on 429/5xx with a 1-2-4 s backoff and
ignored the headers MoySklad sends with the refusal. A webhook burst — MoySklad
delivers one event per order, and the API allows 45 requests per 3 s — spent the
three attempts inside the same rate-limit window and threw.

* `X-RateLimit-Retry-After` (milliseconds) and `Retry-After` (seconds) are read
  and waited out; without them the backoff is 1-2-4-8 s with jitter. Five
  attempts, and no sleep after the last one. The whole loop is capped at 45
  seconds of wall clock: a rate limit answers instantly, so five attempts fit
  easily, while a host that hangs spends the budget on timeouts and stops after
  two or three — a page must not wait minutes either way.
* A request that never connected (cURL error, code 0) is retried too. It used to
  be indistinguishable from «no such document» — `getOrder()` returned `null` and
  the order quietly counted as unlinked.
* The exception says what happened: `… (HTTP 429: Rate limit exceeded)` instead
  of only the path, and counts the attempts actually made.
* `checkPermissions()` probes through `tryGet()`: there «did not answer» is an
  answer, and the check keeps explaining HTTP 0 as «запрос до api.moysklad.ru не
  дошёл» instead of re-throwing the request exception.
* **A failed webhook event is kept and retried.** `webhook_log` gains
  `status`/`attempts`/`event_json`; the per-event work moved out of
  `public/api/moysklad_hook.php` into `MsSync::handleWebhookEvent()`, and
  `MsSync::retryFailedWebhooks()` — called from `cron/sync_moysklad.php` every
  five minutes — re-runs the events that failed, up to 5 attempts within 3 days.
  Before this, an order whose webhook hit the rate limit was synced only if its
  company already had documents in the last 30 days (that is all the catch-up
  cron looks at); the first order of a new company was lost until someone
  opened the card by hand.

## Files

| What | Where |
|---|---|
| Yandex routes, JSON retry, truncation | `lib/llm.php` |
| `LLM_MAX_TOKENS` setting | `lib/settings.php`, `public/assets/js/app.js` (`adminLlm`), `config.example.php` |
| Route in the probe result | `public/api/admin.php` (`yandex_models_verify`), `App.verifyYandexModels` |
| MoySklad retries | `lib/moysklad.php` (`request`) |
| Webhook retry | `lib/sync.php` (`handleWebhookEvent`, `retryFailedWebhooks`), `public/api/moysklad_hook.php`, `cron/sync_moysklad.php` |
| Schema v39 | `lib/bootstrap.php` |

## Test

`php tests/module_043.php`
