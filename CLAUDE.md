## Как работать с ТЗ

Правила, о которых просил заказчик:

- **Читать ТЗ целиком и до конца, каждый пункт.** Пункт, упомянутый вскользь,
  — такое же требование, как выделенный жирным. Пропущенный пункт приходится
  исправлять вторым заходом, и это дороже, чем прочитать внимательно сразу.
- **Сверяться с ТЗ перед сдачей**: пройти по списку требований и для каждого
  назвать, где именно оно выполнено. Не выполнено — сказать об этом прямо, а
  не промолчать.
- **Требование про интерфейс проверять глазами на разметке**, а не только в
  коде: «поле скрыто» значит, что его не видно на экране, а `hidden` в HTML
  ещё может быть побеждён любым `display` в CSS.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
