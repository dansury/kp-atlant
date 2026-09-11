# Spec Index — Atlant Armour КП Automation

| Module | Spec file | Status |
|---|---|---|
| КП Automation (core) | `specs/001-kp-automation/spec.md` | Implemented |
| Orders, Invoice Sync & Company Chat | `specs/002-orders-crm-chat/spec.md` | Draft |
| Rich КП: cards, photos, upsell | `specs/003-kp-rich-content/spec.md` | Implemented |
| Админ-панель: логи, настройки, почта, промпты | `specs/004-admin-console/spec.md` | Implemented |
| База знаний: вики компании из GitHub в промптах | `specs/005-knowledge-base/spec.md` | Implemented |
| Классификация писем и ответ по типу запроса | `specs/006-email-triage/spec.md` | Implemented |
| PWA и web push для администраторов | `specs/007-pwa-push/spec.md` | Implemented |
| Каталог, подходящие позиции, выбор модели | `specs/008-catalog-and-matching/spec.md` | Implemented |
| Векторный подбор позиций и выбор равнозначных | `specs/009-catalog-vectors/spec.md` | Implemented |
| Почтовая программа: цепочки, «Отправленные», доски | `specs/010-mail-client-boards/spec.md` | Implemented |
| Одна доска: компания = карточка, письма и запросы внутри | `specs/011-unified-board/spec.md` | Implemented |
| Письмо как рабочее место: позиции и ответ внутри, цепочки по отправителю | `specs/012-letter-card-workbench/spec.md` | Implemented |
| Аналоги из наличия, таблица соответствия, реквизиты и НДС из МойСклад | `specs/013-alternatives-and-requisites/spec.md` | Implemented |

## Research
| Вопрос | Файл |
|---|---|
| Чем искать факты для ответа на shared-хостинге (RAG / БД / эмбеддинги) | `specs/006-email-triage/research.md` |

## Tests
| Что проверяет | Файл |
|---|---|
| Модуль 013 целиком: аналоги, таблица соответствия, карточка, реквизиты, НДС | `php tests/module_013.php` |
| Редеплой с GitHub не стирает базу, подпись и вложения | `php tests/deploy_preserves_data.php` |

## Constitution
`.specify/memory/constitution.md`
