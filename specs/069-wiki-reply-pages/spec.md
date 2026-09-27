# 069 — The wiki answers letters: reply pages per category, dated facts

## Problem
The wiki lint in `dansury/Atlant` (PR #10) added pages written FOR replies:
«Вопросы и ответы из почты» (how the team actually answered ~840 threads),
«Опт и работа с юрлицами» (prepayment, договор, печать, НДС, ЭДО, опт, тендеры),
«Гарантия, обмен и возврат». It also dated every price, VAT regime and term.

Two gaps in module 005 made that work invisible to a reply:
- Retrieval is by words only. A letter «можно по гарантийному письму?» does not
  necessarily share two rare terms with the B2B page, so the page that holds the
  rule may lose to a product page that happens to repeat a word.
- The knowledge block called the wiki «источник истины». With dated prices and a
  VAT regime that changed twice in 2026, the model could quote a 03.2026 price or
  «без НДС» as current — the exact thing a draft must never invent.

## Reply pages (FR-069.1)
`KNOWLEDGE_REPLY_PAGES` (group `knowledge`, textarea) — one rule per line:

```
<task>[, <task>…]: <page>[; <page>…]
```

- `<task>` — a prompt key from `Knowledge::TASKS`, or `*` = every reply task
  (`mail_reply` and `reply_*`; not the КП or the matcher).
- `<page>` — the page's `# ` title or its file name without `.md`, case-insensitive.
- Lines starting with `#` and blank lines are ignored.

Default:
```
*: Вопросы и ответы из почты
reply_kp, reply_wholesale, reply_contract, reply_edo, reply_closing_docs, reply_tender, reply_gov_order, reply_docs: Опт и работа с юрлицами
reply_return, reply_complaint: Гарантия, обмен и возврат
reply_delivery, reply_order_status: Доставка и оплата
```

`Knowledge::replyPages(string $task): string[]` parses it.
`Knowledge::search($query, $budget, $task = '')` then:
1. Takes the sections of the task's pages that share at least ONE term with the
   letter (instead of `KNOWLEDGE_MIN_HITS`) — the page is on topic by the
   category, the words only pick its section. Up to HALF the budget,
   `source = 'pinned'`. Pages named for the task come before `*` pages, and the
   pick is round robin: the best section of every page (clipped to its share of
   the half) before the second section of any — one long Q&A section must not
   crowd the category's own page out.
2. Fills the rest with the ordinary word pick (and vectors), skipping sections
   already taken.

A page with no section sharing a term with the letter adds nothing: pinning
raises a page, it never pastes it whole. No setting, an empty value or a page
missing from the copy → exactly the module 005 answer.

## Dated facts (FR-069.2)
The block header moves out of code into prompt `knowledge_block`
(«Настройки → Промпты», `{{sections}}`). Default text adds the rule: prices,
stock, VAT and terms in the wiki are dated history; the current ones come from
the catalog, the order data and the requisites of this prompt; a wiki price is
never named to the client as current. Rules of service (100% prepayment, no
seal, exchange terms) are used as they are.

## Interface (FR-069.3)
- «Настройки → База знаний → Подключение к GitHub» gets a block «Ответы на
  письма» with `KNOWLEDGE_REPLY_PAGES` and a hint with the exact values to enter.
- «Где используется» lists each task's reply pages; a page that is not in the
  downloaded copy is red («нет в вики — переименована?»): renaming a page in the
  wiki silently unpins it otherwise.
- «Проверка подбора» marks a pinned section «закреплена за задачей», and a
  draft there is written in the category whose prompt is the chosen task.
- Updating stays where it was: «Проверить и обновить» (managers too), the TTL
  check before every generation, `cron/sync_knowledge.php`.

## Status
`Knowledge::status()['tasks'][]['pages']` = `[{page, found}]`.

## Files
`lib/knowledge.php`, `lib/settings.php`, `lib/prompts.php`, `public/api/admin.php`
(`knowledge_preview`), `public/assets/js/app.js`, `tests/module_069.php`.
