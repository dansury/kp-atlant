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
| КП как в образце, доставка строкой подбора, ИНН из переписки нейросетью | `specs/034-kp-sample-delivery-inn/spec.md` | Implemented |
| Документ набран как образец, ход работы виден, имя файла информативное | `specs/035-kp-typography-and-progress/spec.md` | Implemented |
| Письмо самому себе, вилка цен, общие условия КП, аналог и групповой перенос | `specs/036-self-mail-price-range-analogs/spec.md` | Implemented |
| Срок из подбора переписывает срок в КП, адрес отправителя в переписке, перенаправление письма | `specs/037-wait-term-forward-mail/spec.md` | Implemented |
| Мастер настройки с нуля, обратная связь в issues и проверочные КП с оценкой качества | `specs/038-setup-wizard-support-trial/spec.md` | Implemented |
| Подбор по любой переписке и подпись менеджера в письмах | `specs/039-always-match-and-mail-signature/spec.md` | Implemented |
| Подбор перестал врать, почта перестала терять письма | `specs/040-match-truth-and-mail-trash/spec.md` | Implemented |
| Форма письма, организация из списка, промпты учатся на правках | `specs/041-letter-shape-and-prompt-learning/spec.md` | Implemented |
| Область подбора не сбрасывает правки, доставка в цене товара, доска «Закрыто» без тела письма, документ без таблицы соответствия | `specs/042-scope-price-delivery-and-board-fixes/spec.md` | Implemented (partial — issue #60) |
| Yandex по OpenAI-совместимому маршруту, JSON с поправкой, МойСклад не теряет заказ | `specs/043-llm-routes-json-and-moysklad-retries/spec.md` | Implemented |
| Фото идут за товаром, настройки объясняют себя, письмо уходит по расписанию | `specs/044-photos-settings-hints-scheduled-send/spec.md` | Implemented (partial — issue #60) |
| Остаток issue #60: письмо без остатков цифрой, доставка в счёте, «не наша номенклатура» в КП, КП страницей A4, подбор над письмом, строки как в Gmail | `specs/045-issue60-remainder/spec.md` | Implemented |
| Issue #67: документ КП (деньги, скидка, вилка, НДС, шапка, QR), отсутствующая номенклатура по галочке, блоки письма на телефоне, масштаб листа, описания и Excel из Битрикса, требования клиента в тексте КП | `specs/046-issue67-document-and-mobile-blocks/spec.md` | Implemented |
| Оплаты из Т-Банка, колонка «Сборка», письмо с трек-номером; лента без дублей, подсказка без повторов, фото товара, подбор свёрнут | `specs/047-payments-assembly-shipping/spec.md` | Implemented |
| Условия и подпись в конце КП, вкладка «Подпись», Word = PDF, одно КП на запрос, папка модулей | `specs/048-terms-signature-single-kp/spec.md` | Implemented |
| Доставка по режиму у каждого КП, без письма в настройках КП, вкладки справа, КП над письмом | `specs/049-delivery-mode-side-rail/spec.md` | Implemented |
| Письма списком как в Gmail, цвета статусов, одна шапка экрана, UX-проход по экранам | `specs/050-gmail-list-and-ux-pass/spec.md` | Implemented |
| WYSIWYG-редактор КП со страницами, тексты для следующих КП, подпись по выбору, медленная модель ≠ фильтр, жирные неотвеченные в списке | `specs/051-wysiwyg-kp-and-signature/spec.md` | Implemented |
| Documents into the letter in one click and downloadable, match table (+ Позиция below, folding, integer qty), pinned «?», МойСклад window on invoice, «СОТРУДНИК» in the invoice, rail arrows | `specs/052-attach-docs-and-match-table/spec.md` | Implemented |
| КП buttons on a folded match table; invoice order in «Резерв» on a store with the employee | `specs/053-folded-kp-bar-and-order-reserve/spec.md` | Implemented |

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
| Каталог моделей Yandex: список от облака (Models API), снятые слаги, модели сверх списка | `php tests/yandex_catalog.php` |
| Модуль 030: НДС в КП всегда, цена с налогом или налог сверху, счёт тем же способом | `php tests/module_030.php` |
| Модуль 031: цитата в ответе, снятие карточки с доски, заметки, удаление письма, вёрстка переписки | `php tests/module_031.php` |
| Модуль 032: описание подставлено в поле, печатается одним блоком, в промпт ответа не идёт | `php tests/module_032.php` |
| Модуль 033: черновик первого письма, карточка в «В работе», ИНН из письма в МойСклад | `php tests/module_033.php` |
| Модуль 034: доставка в подборе, приложение №1 в КП, срок по подбору, разбор оборванного JSON | `php tests/module_034.php` |
| Модуль 036: письмо самому себе, вилка цен, общие условия КП, аналог, групповой перенос | `php tests/module_036.php` |
| Модуль 037: срок из подбора в собранном КП, адреса перенаправления, пересылка письма | `php tests/module_037.php` |
| Модуль 038: обращения с файлами и ревью, шаги мастера по факту, оценка качества и модель подороже | `php tests/module_038.php` |
| Модуль 039: подбор заводится по любой переписке, письмо уходит с подписью | `php tests/module_039.php` |
| Модуль 040: подбор перестал врать, почта перестала терять письма | `php tests/module_040.php` |
| Модуль 041: письмо нужной формы, промпты учатся на правках | `php tests/module_041.php` |
| Модуль 042: область подбора не сбрасывает правки, доставка в цене товара, доска «Закрыто», без таблицы соответствия | `php tests/module_042.php` |
| Модуль 046: деньги и скидка в КП, отсутствующая номенклатура по порядку, НДС, Word, описание с сайта, Excel, требования клиента, блоки и масштаб | `php tests/module_046.php` |
| Модуль 043: маршрут модели Yandex, разбор JSON и повторная попытка, повторы МойСклад и вебхуков | `php tests/module_043.php` |
| Модуль 044: фотографии за товаром, подсказки настроек, свой звук, вход и отложенная отправка | `php tests/module_044.php` |
| Модуль 045: доставка в цене и в счёте, «не наша номенклатура» в КП, письмо без остатков, форма счёта, страница A4 | `php tests/module_045.php` |
| Модуль 047: оплата Т-Банка → платёж МойСклад и «Сборка», трек-номер → письмо, подсказка, фото, подбор, жирный шрифт | `php tests/module_047.php` |
| Модуль 048: условия и подпись КП, вкладка «Подпись», Word = PDF, одно КП на запрос | `php tests/module_048.php` |
| Модуль 049: режим доставки у КП, «оплачивается отдельно» в письме, без письма в настройках КП, вкладки справа, КП над письмом | `php tests/module_049.php` |
| Модуль 050: «Прочитано» не мешает новому письму поднять карточку, список как в Gmail, цвета статусов, шапка экрана, группы настроек | `php tests/module_050.php` |
| Модуль 051: поля КП и подстановки из редактора, заготовки для следующих КП, подпись по выбору, разрыв страницы в Word, таймаут модели | `php tests/module_051.php` |
| Модуль 052: вложения скачиваются, «СОТРУДНИК» в счёте по типу поля, целое количество, интерфейс по исходнику | `php tests/module_052.php` |
| Модуль 053: кнопки КП при свёрнутом подборе, статус по имени, склад по умолчанию, резерв позиций | `php tests/module_053.php` |
| Векторы каталога и вики: хеш раздела, переживший пересборку индекс, маска секрета, НДС каталога | `php tests/module_009_vectors.php` |
| Редеплой с GitHub не стирает базу, подпись и вложения | `php tests/deploy_preserves_data.php` |

## Constitution
`.specify/memory/constitution.md`
