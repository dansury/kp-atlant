# Open issue audit — 2026-10-08

Repository: `dansury/kp-atlant`. Baseline: `27bcf14` (`main`).

All 27 issues open at the start of the audit were compared with the available
code and remote branches. No implementation newer than 2026-09-28 was found
outside the support-assets branch. The previous Codex session could not be
retrieved, so this audit makes no claim about unpushed local work.

Only fully implemented requirements are eligible for closure. Partial,
unverified and absent requirements remain open. Production mailbox/service
credentials and the live application were not available for end-to-end checks.
Existing saved match rows are preserved; to update the reported old row,
run the existing rematch action after deploying the fix.

| Issue | Status | Evidence / remaining work |
|---|---|---|
| [#156](https://github.com/dansury/kp-atlant/issues/156) | Partial | КП applies VAT including delivery, but invoice goods use row VAT while delivery uses proposal default VAT in `DeliveryShare::invoicePositions`; these rates can differ. Keep open. |
| [#157](https://github.com/dansury/kp-atlant/issues/157) | Partial | `App.attachmentLink` offers preview for PDF/images/Office files; no explicit download action for those incoming attachments. |
| [#158](https://github.com/dansury/kp-atlant/issues/158) | Unverified | `Mailboxes::cfg` already falls back to IMAP credentials; no new SMTP fix since the report. A live mailbox diagnostic is needed. |
| [#159](https://github.com/dansury/kp-atlant/issues/159) | Partial | Placement updates in `moveCompanyCard`/`moveThreadCard`; the open card stays open and no list navigation refresh runs there. |
| [#160](https://github.com/dansury/kp-atlant/issues/160) | Partial | Earlier mobile compaction exists, but `matchKpButton` still renders a cluster of document buttons after generation. |
| [#161](https://github.com/dansury/kp-atlant/issues/161) | Not fixed | `DeliveryShare::included` requires a positive amount; zero included delivery remains a separate row in `Pdf::prepare` and `KpText`. |
| [#162](https://github.com/dansury/kp-atlant/issues/162) | Not fixed | `App.pickSuggest` updates selection without `setRowFold`; folding is a separate action. |
| [#163](https://github.com/dansury/kp-atlant/issues/163) | Not fixed | Contact extraction has one `contact_person`; no separate given name/patronymic extraction and initials guard. |
| [#164](https://github.com/dansury/kp-atlant/issues/164) | Partial | Existing reorder, photo reset and price controls remain; no implementation of the full requested placement/default-price changes after this report. |
| [#165](https://github.com/dansury/kp-atlant/issues/165) | Not fixed | `KpContent` still derives availability as «под заказ» from zero free stock; unchecking wait conditions does not suppress it. |
| [#166](https://github.com/dansury/kp-atlant/issues/166) | Not fixed | `templates/kp.html` prints requested wording and replacement without the requested «Аналог!» between them. |
| [#167](https://github.com/dansury/kp-atlant/issues/167) | Not fixed | Support form still includes the title/«Коротко» field and separate body. |
| [#168](https://github.com/dansury/kp-atlant/issues/168) | Partial | Install glow and push prompt exist, but phone CSS hides the install label; manual install path does not request notification permission. |
| [#169](https://github.com/dansury/kp-atlant/issues/169) | Not fixed | No editable supply-contract generator or request-controlled contract attachment implemented. |
| [#170](https://github.com/dansury/kp-atlant/issues/170) | Not fixed | `moveCompanyCard`/`moveThreadCard` reload placement only, keeping the letter open. |
| [#171](https://github.com/dansury/kp-atlant/issues/171) | Partial | Invoice button exists, but it silently generates КП when absent, and existing-КП actions remain buttons; no invoice-intent highlight. |
| [#172](https://github.com/dansury/kp-atlant/issues/172) | Partial | CDEK weight parsing/calculator exist; no full request-city auto-open, persistent per-modification dimensions or full-value insurance flow. |
| [#173](https://github.com/dansury/kp-atlant/issues/173) | Not fixed | `DeliveryShare::letterLine` returns empty for zero; zero delivery handling depends on mode and is inconsistent. |
| [#174](https://github.com/dansury/kp-atlant/issues/174) | Not fixed | Editor inserts non-editable page-break elements; no page/break deletion control. |
| [#175](https://github.com/dansury/kp-atlant/issues/175) | Unverified | `supportSend` calls `keepClearIn`, including `support.files`, but reported persistence is not reproduced. Pending server-save/clear ordering also needs validation; do not close from the presence of a clear call alone. |
| [#176](https://github.com/dansury/kp-atlant/issues/176) | Partial | Reserve and manual invoice attachment exist; no automatic attachment at issuance with day selector and automatic document unposting after expiry. |
| [#177](https://github.com/dansury/kp-atlant/issues/177) | Implemented | `App.threadMessage` renders «Прочитано» immediately beside «Ответить» for unread letters; `App.markMailRead` persists it. Module 067 checks this flow. |
| [#178](https://github.com/dansury/kp-atlant/issues/178) | Partial | Attachment extraction and size splitting exist, but no verified table-aware expansion of the supplied matrix into invoice positions. |
| [#179](https://github.com/dansury/kp-atlant/issues/179) | Not fixed | No category-coverage assignment, dated vacation delegation or priority lazy loading of board columns. |
| [#180](https://github.com/dansury/kp-atlant/issues/180) | Partial | Earlier UX guidelines and compaction exist; no implementation/spec of the requested complete on-demand redesign. |
| [#181](https://github.com/dansury/kp-atlant/issues/181) | Not fixed | No generator/editor based on the supplied supply-contract template. |
| [#182](https://github.com/dansury/kp-atlant/issues/182) | Fixed in this change | `ProductMatcher::modelConflict` excludes wrong numbered models; memory keys keep model revisions and invalid historical memory is ignored. `tests/issue_182.php`: 16 regression checks. |

## Validation

All 58 PHP test suites pass (including the new issue #182 suite).
PHP syntax, JavaScript syntax and `git diff --check` pass.
The new test reproduces failures on baseline code, including the false
revision match and the match-memory collision.
