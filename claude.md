# Project Rules — Atlant Armour КП Automation

## Spec-driven workflow
Always start with spec.md — thin navigation index (file → spec mapping). Read it first to find the relevant module spec. Then open ONE /specs/<module>/spec.md. Never read the whole /specs/ folder. Never preload multiple specs "just in case". For reference details open exactly one /specs/<module>/spec.md that matches the module you are editing. If the detail is missing there, read the source file — do NOT pull another /specs/<module>/spec.md unless the task genuinely spans modules.

## Stack
PHP 8.1+ / SQLite / vanilla JS. No heavy frameworks. Deploy on shared hosting.

## LLM integration
Use OpenRouter + Yandex Foundation Models wrapper from NeuroPro (lib/llm.php pattern). Dual-provider fallback chain.

## Language
Code comments in English. UI in Russian. Specs in English.

## Graphify
Use graph to understand the project and update it after new implementations.


