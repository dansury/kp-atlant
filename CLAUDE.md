## SPEC-driven development

### 1 Lookup file -> spec mapping in spec.md. It is a thin navigation index — read once, then open ONE /spec/<module>.md for the relevant area. Never read the whole /spec/ folder.

### 2 For reference details (signatures, DB schemas, algorithms, flows, SEO contract, email flows, referrals, QR cards): open exactly one /spec/<module>.md that matches the module you are editing. If the detail is missing there, read the source file — do NOT pull another /spec/<module>.md unless needed.

###3 Spec-driven workflow — for any new feature or non-trivial change:
Read the relevant /spec/<module>.md.
Update that spec to describe the planned change (signatures, tables, flows, configs) — before writing any code.
Implement code to match the updated spec.
Specs describe actual functionality only — never changelogs or version history. For pure bug fixes that require no design decisions, step 2 may be skipped; update the spec after the fix if its content was inaccurate.

## Как работать с ТЗ
- **Читать ТЗ целиком и до конца, каждый пункт.** Пункт, упомянутый вскользь,
  — такое же требование, как выделенный жирным. Пропущенный пункт приходится
  исправлять вторым заходом, и это дороже, чем прочитать внимательно сразу.
- **Сверяться с ТЗ перед сдачей**: пройти по списку требований и для каждого
  назвать, где именно оно выполнено. Не выполнено — сказать об этом прямо, а
  не промолчать.
- **Требование про интерфейс проверять глазами на разметке**, а не только в
  коде: «поле скрыто» значит, что его не видно на экране, а `hidden` в HTML
  ещё может быть побеждён любым `display` в CSS.

