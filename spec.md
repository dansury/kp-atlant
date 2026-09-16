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
| Модуль сайта: ссылки и QR-коды на товары | `specs/017-bitrix-site-module/spec.md` | Implemented |
| КП не уходит клиенту с молчаливой дырой | `specs/018-kp-completeness/spec.md` | Implemented |
| Архив писем, выключаемые ящики, карточка с телефона | `specs/019-archive-mailboxes-mobile/spec.md` | Implemented |
| Прочитанное, свёрнутое и размеченное | `specs/020-correspondence-markup-folding/spec.md` | Implemented |
| История из mbox, дедупликация, логотипы и ликбез | `specs/021-mbox-import-dedup/spec.md` | Implemented |
| Модификации, склады, «не наша номенклатура» и обучение на правках | `specs/022-variants-scope-learning/spec.md` | Implemented |
| Единая карточка письма, «под заказ» и сквозной поиск | `specs/023-letter-card-and-order-terms/spec.md` | Implemented |
| Разбор почты партиями, одна раскладка карточки, документ в браузере | `specs/024-bulk-triage-and-one-card/spec.md` | Implemented |
| Письма разделяются по отправителю, а не по домену | `specs/025-sender-separation/spec.md` | Implemented |
| Карточка читается сверху вниз, счёт живёт под письмом, каталог Yandex проверяется | `specs/029-card-reading-order/spec.md` | Implemented |
| НДС в КП печатается всегда, цена — с налогом или плюс налог | `specs/030-kp-vat-modes/spec.md` | Implemented |
| Письма не налезают друг на друга, ответ несёт цитату, заметка удаляется | `specs/031-letter-layout-quoting-notes/spec.md` | Implemented |
| Описание товара стоит в поле, печатается в КП и не уходит в письмо | `specs/032-item-description-in-kp/spec.md` | Implemented |
| Черновик письма — карточка в «В работе»; контрагент заводится в МойСклад по ИНН из письма | `specs/033-draft-cards-and-moysklad/spec.md` | Implemented |

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
| Модуль 017: QR-код и разбор выгрузки каталога с сайта | `php tests/module_017.php` |
| Модуль 019: архив писем, выключение и удаление ящика, колонка цены = тип цены | `php tests/module_019.php` |
| Модуль 020: прочитанное ставит человек, письма в переписке, разметка описаний | `php tests/module_020.php` |
| Модуль 021: импорт mbox, дедупликация писем со всех ящиков, логотипы | `php tests/module_021.php` |
| Модуль 022: модификации, «не наша номенклатура», документ, обучение на правках | `php tests/module_022.php` |
| Модуль 023: «под заказ», ручная цена, поиск по всей почте, спам с доски | `php tests/module_023.php` |
| Модуль 024: групповые операции, фильтры доски, превью и отправитель письма | `php tests/module_024.php` |
| Модуль 025: общий домен не склеивает компании, разделение карточки по отправителям | `php tests/module_025.php` |
| Отправленные письма из ящиков: имя папки, первый заход, ответ мимо сервиса | `php tests/module_028.php` |
| Модуль 029: порядок переписок, организации карточки, имя файла счёта, ошибки в уведомления, каталог Yandex | `php tests/module_029.php` |
| Модуль 030: НДС в КП всегда, цена с налогом или налог сверху, счёт тем же способом | `php tests/module_030.php` |
| Модуль 031: цитата в ответе, снятие карточки с доски, заметки, удаление письма, вёрстка переписки | `php tests/module_031.php` |
| Модуль 032: описание подставлено в поле, печатается одним блоком, в промпт ответа не идёт | `php tests/module_032.php` |
| Модуль 033: черновик первого письма, карточка в «В работе», ИНН из письма в МойСклад | `php tests/module_033.php` |
| Векторы каталога и вики: хеш раздела, переживший пересборку индекс, маска секрета, НДС каталога | `php tests/module_009_vectors.php` |
| Редеплой с GitHub не стирает базу, подпись и вложения | `php tests/deploy_preserves_data.php` |

## Constitution
`.specify/memory/constitution.md`
