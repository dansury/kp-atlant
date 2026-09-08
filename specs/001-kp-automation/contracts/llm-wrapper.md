# LLM Wrapper Contract

**File**: `lib/llm.php`  
**Adapted from**: NeuroPro `lib/llm.php` (C-007)  
**Providers**: OpenRouter (primary), Yandex Foundation Models (fallback)

---

## Class: `LLM`

### `LLM::init(array $config): void`
```php
// $config keys (from config.php):
// LLM_PROVIDER_PRIORITY => 'openrouter,yandex' or 'yandex,openrouter'
// OPENROUTER_API_KEY => '...'
// OPENROUTER_MODEL => 'google/gemini-2.5-flash' (default)
// YANDEX_API_KEY => '...'
// YANDEX_FOLDER_ID => '...'
// YANDEX_MODEL => 'yandexgpt' (default; slug without a version —
//                  the request is built as gpt://<folder>/<slug>/latest)
// LLM_TIMEOUT_SEC => 30
```

### `LLM::chatText(string $system, string $user, float $temp = 0.3): string`
Returns plain text response. Strips `<think>...</think>` tags and markdown fences.

### `LLM::chatJson(string $system, string $user, float $temp = 0.1): array`
Returns parsed JSON. Uses `response_format: {type: json_object}` where supported.
Retries up to 2 times on malformed JSON. Strips markdown fences before parsing.

---

## Usage in KP System

### Parse request text (US1, US2)
```php
$system = "Extract product items from a commercial request. Return JSON...";
$user = $rawText;
$parsed = LLM::chatJson($system, $user);
// Returns: {items: [{name, qty, raw_text}], org_name, contact, delivery_terms}
```

### Generate cover letter (US1, FR-007)
```php
$system = "You write cover letters for commercial proposals. ToV rules: {$tov}...";
$user = "Products: {$itemList}. Counterparty: {$org}. Corrections: {$fewShot}";
$letter = LLM::chatText($system, $user, 0.4);
```

### Generate follow-up (US6)
```php
$system = "Generate a follow-up email per EMAILRULES: {$rules}. ToV: {$tov}";
$user = "Original KP: {$kpSummary}. Days since: {$days}. Counterparty: {$org}";
$followup = LLM::chatText($system, $user, 0.4);
```

### Normalize product names for matching (D-006)
```php
$system = "Normalize product names: expand abbreviations, fix transliteration...";
$user = json_encode($rawNames);
$normalized = LLM::chatJson($system, $user);
// Returns: [{original, normalized, category}]
```

---

## Fallback Chain

1. Try primary provider (first in `LLM_PROVIDER_PRIORITY`)
2. On timeout/5xx/rate-limit → try secondary provider
3. On both fail → throw `LLMException`
4. On JSON parse fail → retry same provider up to 2 times with lower temp (0.05)

---

## Differences from NeuroPro

| NeuroPro | KP System |
|---|---|
| Prompt versioning system | Removed — not needed |
| `dispatchPair()` parallel calls | Removed — sequential is fine |
| Session tracking per user | Removed — stateless calls |
| Multiple model families | Simplified — one model per provider |
| `$store` dependency | Removed — no prompt DB |
