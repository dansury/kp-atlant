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
| Автообновление кода: проверка GitHub на каждой странице, деплой через `pull.php` | `specs/014-auto-deploy/spec.md` | Implemented |
| Письма с форм сайта, недоставленные ответы, вторая половина сделки | `specs/015-inbound-channels/spec.md` | Implemented |
| КП уходит клиенту в Word (.docx) | `specs/016-kp-docx/spec.md` | Implemented |

## Research
| Вопрос | Файл |
|---|---|
| Чем искать факты для ответа на shared-хостинге (RAG / БД / эмбеддинги) | `specs/006-email-triage/research.md` |
| Из чего на самом деле состоит почта: разбор 5 419 писем за 2022–2026 | `specs/006-email-triage/corpus-2026.md` |

## Tests
| Что проверяет | Файл |
|---|---|
| Модуль 013 целиком: аналоги, таблица соответствия, карточка, реквизиты, НДС | `php tests/module_013.php` |
| Модули 015 и 016: формы сайта, спам, bounce, вложения, единый адрес, .docx | `php tests/module_015_016.php` |
| Редеплой с GitHub не стирает базу, подпись и вложения | `php tests/deploy_preserves_data.php` |

## Constitution
`.specify/memory/constitution.md`
