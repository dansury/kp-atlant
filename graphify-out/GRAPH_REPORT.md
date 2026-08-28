# Graph Report — Atlant Armour КП Automation

**Extracted**: 2026-08-25  
**Mode**: deep (semantic, directed)  
**Source files**: 5 (spec.md, 001-kp-automation/spec.md, claude.md, constitution.md, tov.md)  
**Graph**: 48 nodes, 82 edges, 6 communities  

---

## Summary

The knowledge graph captures the full architecture of the Atlant Armour КП Automation system — from governing documents and principles through user stories, system components, data entities, and external integrations. The system's purpose: automate commercial proposal (КП) generation for a tactical equipment supplier, from incoming email to branded PDF to MoySklad order.

---

## Communities Detected

| # | Name | Nodes | Key members |
|---|---|---|---|
| 0 | Governance & Docs | 9 | spec.md, claude.md, constitution.md, principles V/VII/VIII |
| 1 | Core Pipeline (Email→PDF) | 12 | US1, US2, Email Receiver, Request Parser, PDF Generator, Email Sender, Notifier, Request, Proposal, ProposalItem, LegalEntity |
| 2 | CRM & Accounts | 7 | US3, Auth, CRM, Web Interface, Counterparty, Manager, Correspondence |
| 3 | LLM & Knowledge | 6 | US4, LLM Wrapper (NeuroPro), Knowledge Base, OpenRouter, Yandex FM, Correction |
| 4 | MoySklad Integration | 5 | US5, US7, MoySklad Integration, МойСклад API, principle II |
| 5 | Follow-up & ToV | 4 | US6, Follow-up Engine, EmailRules, tov.md, principle III |

**Confidence**: High for communities 1 and 4 (tight coupling, clear boundaries). Medium for community 0 (governance nodes connect broadly). Communities 3 and 5 are smaller but coherent.

---

## God Nodes (highest connectivity)

| Node | Type | In-edges | Out-edges | Total | Risk |
|---|---|---|---|---|---|
| **MoySklad Integration** | component | 5 | 1 | 6 | Central dependency — US1, US2, US3, US5, US6, US7 all depend on it. Outage blocks everything. |
| **LLM Wrapper (NeuroPro)** | component | 5 | 2 | 7 | Request parsing, follow-ups, and knowledge base all route through it. Dual-provider fallback mitigates. |
| **Web Interface** | component | 3 | 0 | 3 | US1, US2, US3 all need it. No outgoing deps — leaf node, but large surface area. |
| **001-kp-automation/spec.md** | document | 2 | 9 | 11 | Highest total degree. Defines all 7 user stories. Single point of spec truth. |
| **Proposal (КП)** | entity | 3 | 3 | 6 | Central data entity connecting Request, ProposalItem, Counterparty, LegalEntity, Manager. |

---

## Orphan Nodes

None. Every node has at least one edge. The graph is fully connected.

---

## Architectural Observations

**The core pipeline (community 1) is the largest cluster.** US1 alone touches 8 components — it's the happy path the entire system exists for. US2 shares 5 of those 8, confirming that manual paste reuses the same backend.

**MoySklad is a single point of failure across 4 communities.** Communities 1, 2, 4, and 5 all depend on it. The spec mentions local caching (constitution principle II allows it for speed), but cache invalidation on each КП generation means an API outage still blocks the pipeline.

**The LLM layer has good fault tolerance.** Dual-provider fallback (OpenRouter → Yandex FM) is architecturally sound. The knowledge base enriches prompts with correction history (principle IV), creating a feedback loop: US4 → Correction → Knowledge Base → LLM Wrapper → better drafts.

**Constitution principles map cleanly to components.** Each of the 8 principles constrains one or two specific components — no principle is orphaned, and no component escapes governance. This is a well-structured spec.

**Follow-up (community 5) is the most isolated cluster.** Only 4 nodes, loosely connected to the core via MoySklad and Email Sender. Good candidate for phased delivery — can ship after core pipeline is stable.

---

## Edge Type Distribution

| Type | Count | Description |
|---|---|---|
| DEPENDS_ON | 28 | User story → component, component → component |
| DEFINES | 15 | Document → user story, document → principle |
| CONSTRAINS | 11 | Principle → component/entity |
| RELATES | 11 | Entity → entity relationships |
| INTEGRATES | 5 | Component → external system |
| PRODUCES | 7 | User story → entity/external (data flow) |
| REFERENCES | 5 | Document → document cross-references |

---

## Audit Trail

- Extraction method: Manual semantic analysis (graphify CLI requires LLM API key for doc-only corpus; extraction performed by Claude from 5 markdown files)
- Community detection: Heuristic grouping by functional domain and dependency clustering
- God node scoring: Total degree (in + out edges), weighted by cross-community connections
- All node descriptions and edge labels derived directly from spec text — no inference beyond explicit relationships
- Confidence caveat: Community boundaries are judgment calls, not algorithmic partitions. Nodes at community borders (e.g., Web Interface touches communities 1, 2; MoySklad touches 1, 2, 4, 5) could reasonably be assigned differently.

---

## Next Steps (spec-kit pipeline)

The graph confirms the spec is well-structured and ready for the next pipeline stages:

1. **`/clarify`** — Open questions to resolve before implementation:
   - EMAILRULES.md: does it exist yet, or is it created at first deploy?
   - MoySklad API token scope: read-only or read-write? (orders need write)
   - Fuzzy-match threshold for product search — how aggressive?
   - Push notification: Web Push API or a simpler polling approach?
   - PDF library choice for PHP (TCPDF, FPDF, Dompdf)?

2. **`/plan`** — Stack and architecture decisions the graph informs:
   - Core pipeline (community 1) is the MVP. Ship US1+US2 first.
   - CRM (community 2) and self-learning (community 3) are P2 — build after core proves stable.
   - Follow-up (community 5) is fully decoupled — ship last.
   - MoySklad wrapper needs a caching + retry layer given its god-node status.

3. **`/tasks`** → **`/implement`** — Implementation order follows community boundaries naturally.

---

## Update 2026-08-28 — Module 002 (Orders, Invoice Sync & Company Chat)

**Added**: 16 nodes, 20 edges. Graph now covers `specs/002-orders-crm-chat/spec.md` and US8–US11.

### New components

| Node | File | Role |
|---|---|---|
| Attachment Extractor | `lib/attachments.php` | Stores email attachments, extracts text from PDF/DOCX/XLSX/TXT, falls back to OCR for scans |
| Company Chat & Identity | `lib/crm.php` | Glues companies by ИНН → корпоративный домен → название, unified feed, contacts, notes, answer state, merge/split |
| MoySklad Sync | `lib/sync.php` | Local projection of orders and invoices, invoice printform cache, webhook registration |
| Webhook Receiver | `public/api/moysklad_hook.php` | `customerorder` / `invoiceout` events, protected by a URL secret |

### New externals

- **smalot/pdfparser** — vendored under `lib/pdfparser/` (PSR-0, LGPL-3.0), autoloaded by `lib/attachments.php` rather than composer, so the shared host needs no `composer install`.
- **Yandex Vision OCR** — reuses the existing `YANDEX_API_KEY` / `YANDEX_FOLDER_ID`; toggleable in settings.

### Shifts in the graph

- **MoySklad Integration remains the god node** and gets heavier: US8, US9 and the webhook receiver all hang off it. The mitigation is unchanged in shape but wider in scope — every new MoySklad-dependent feature degrades gracefully (FR-039): no webhook rights → pull on card open plus cron; no invoice rights → the invoice block simply stays empty.
- **Counterparty grew into the hub of community 2.** It now owns contacts, the chat feed, merge links and answer state. `Crm::rootId()` is the single place that resolves merged cards — any new query against `counterparties` must go through it or it will read a tombstone.
- **New coupling: Attachment Extractor → Request Parser.** Attachment text is now part of the LLM parse input, so a broken extractor silently degrades classification quality rather than failing loudly. Watch `extract_status` in the attachments table.
