# Module 009 — Positions that pull themselves in, and a choice when they are equal

**Status:** Implemented
**Files:** `lib/embeddings.php`, `lib/matcher.php`, `lib/request_items.php`,
`cron/index_vectors.php`, `public/api/products.php`, `public/api/requests.php`,
`public/assets/js/app.js`

Two halves of one complaint: «подходящие позиции надо чтобы тянулись сами и был выбор,
если позиции равнозначные».

## 1. They already pull themselves in — what was missing is the question

`RequestItems::ensure()` has filled the table on the first open of a card since module 008,
without a model call. What it also did was **pick the first row of a tie**. «Бронежилет
скрытого ношения Страж, размер L» and «… размер M» score identically against «бронежилет
скрытого ношения страж»; the card silently quoted the L, and the mistake was found by the
client.

`ProductMatcher::matchItems()` now separates three outcomes:

| Outcome | Condition | What the card does |
|---|---|---|
| подтверждено | best ≥ `MATCH_AUTO_CONFIRM` (0.88) and no rival within `MATCH_EQUAL_DELTA` | row filled, «ок» ticked, autopick never touches it again |
| выбор | ≥ 2 candidates within `MATCH_EQUAL_DELTA` (0.05) of the leader | `needs_choice = 1`, the row is highlighted and lists the equal options |
| подсказка | best ≥ `MATCH_MIN_SCORE` but below auto-confirm | row filled as a guess, «ещё похожие» underneath |

A choice is one click (`requests.php?action=items_choose`), after which the line is
confirmed and never re-picked. Nothing about this depends on the vectors below — with the
lexical matcher alone the tie is still a tie, and still asked about.

## 2. Meaning, not only words: Yandex Cloud embeddings

Levenshtein and Jaccard cannot match «броник скрытого ношения» to «Бронежилет скрытого
ношения Страж» — the words a client uses are not the words in the catalog. `Embeddings`
adds a second opinion from Yandex Foundation Models (`text-search-doc` for the catalog,
`text-search-query` for the phrase from the letter).

**The score is a blend, and the vector can only help:**

```
score = (1 - MATCH_VECTOR_WEIGHT) · lexical + MATCH_VECTOR_WEIGHT · meaning
```

- `meaning` is the cosine stretched over the range where it means something:
  0.35 ≈ unrelated, 0.85 ≈ the same thing, linear in between.
- A product with **no vector yet** is scored on `lexical` alone rather than on a zero, so a
  half-built index ranks sensibly instead of burying everything unindexed.
- A row qualifies at `score ≥ MATCH_MIN_SCORE` **or** at a purely semantic hit ≥ 0.8 — that
  is the case lexical matching cannot reach at all.
- Every candidate says where it came from: `по словам` / `по смыслу` / `по словам и смыслу`.

`ProductMatcher` also stopped trusting `products_cache.name_normalized`: that column was
written by whichever importer filled the row (API or Excel), and a catalog half from each
was being matched by two different rules. The comparison text is now built in the matcher,
and its normalizer strips punctuation as well — the catalog writes «Страж, размер L», the
client writes «страж», and the comma alone cost the word.

## 3. Indexing 1 200 positions on a shared host without falling over

One request per product, in sequence, is twenty minutes and a dead `max_execution_time`.
All 1 200 at once is HTTP 429 and half an index. `Embeddings::indexCatalog()` is therefore:

- **батчами** — `VECTOR_BATCH` (20) texts leave together through `curl_multi`, with
  `curl_multi_select()` rather than a busy loop that would eat a shared CPU;
- **по времени** — the step stops at `VECTOR_BUDGET_SEC` (20 с) and reports where it stopped;
- **с продолжением** — a row is re-embedded only when the text it was built from changed
  (`text_hash`), so the query for «что осталось» *is* the resume cursor: nothing extra is
  stored and an interrupted run never repeats work;
- **терпеливо** — 429 and 5xx go back in the queue with a doubling pause (0.25 → 4 с),
  `VECTOR_RETRIES` (3) times; a position that still fails is counted and skipped, never fatal;
- **вежливо** — `VECTOR_PAUSE_MS` (100 мс) between batches.

The panel («Настройки → Каталог товаров → Векторный поиск») runs those steps in a loop from
the browser and shows the progress bar; `cron/index_vectors.php` does the same from cron.
A step that indexes nothing stops the loop instead of hammering a broken API.

Matching a whole letter costs **one** batch as well: `matchItems()` embeds every line at
once before scoring, so a 14-position letter opens the card with one parallel request
instead of fourteen sequential ones.

**Storage.** `product_vectors` keeps the vector normalized and packed as float32
(`pack('g*')`), which makes a cosine a plain dot product and 1 200 positions ≈ 1 MB instead
of ~12 MB of JSON. The index is unpacked once per request.

**Degradation is silent and total.** No Yandex key, vectors switched off, an empty index or
a dead API — `Embeddings::enabled()` is false or the search returns nothing, and the match is
exactly the lexical one it was before. A КП never fails because an embedding did not arrive.

## Settings added

| Key | Meaning |
|---|---|
| `MATCH_MIN_SCORE` | below it a catalog row is not offered at all (0.6) |
| `MATCH_AUTO_CONFIRM` | above it a row is filled in and ticked «ок» (0.88) |
| `MATCH_EQUAL_DELTA` | candidates within this of the leader are «равнозначные» (0.05) |
| `MATCH_VECTOR_WEIGHT` | 0 — only words, 1 — only meaning (0.5) |
| `MATCH_CANDIDATES` | how many variants a line shows (5) |
| `VECTOR_ENABLED` | use the vector index at all |
| `VECTOR_MODEL_DOC`, `VECTOR_MODEL_QUERY` | Yandex embedding models |
| `VECTOR_BATCH`, `VECTOR_PAUSE_MS`, `VECTOR_BUDGET_SEC`, `VECTOR_RETRIES`, `VECTOR_TIMEOUT_SEC` | the resilience knobs above |
| `VECTOR_ENDPOINT` | mirror of the embeddings API for a filtered network |

## Schema v11 (this module)

- `product_vectors` — `product_id`, `model`, `dim`, `text_hash`, `vec` (BLOB), `updated_at`
- `request_items.needs_choice`, `request_items.match_source`

## API

| Action | Meaning |
|---|---|
| `requests.php?action=items_choose` | the manager picked one of the equal candidates |
| `products.php?action=vector_index` | one bounded, resumable indexing step |
| `products.php?action=vector_stats` | how much of the catalog is vectorized |
| `products.php?action=vector_reset` | drop the index (model or folder changed) |
| `products.php?action=match_preview` | what the request card would see for a phrase |

## Cron

```
php cron/index_vectors.php --budget=60        # steps until done
php cron/index_vectors.php --once             # a single step
```
