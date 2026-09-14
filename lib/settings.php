<?php
/**
 * Layered configuration.
 *
 * Precedence: DB override (settings table, `cfg.` prefix) > config.php > built-in default.
 * That means config.php seeds the defaults, the admin panel overrides them, and deleting
 * config.php from the server leaves the values edited in the UI in charge.
 */
final class Settings {
    /** Keys the admin panel knows about: key => [group, label, type, secret, default, hint] */
    public const SPEC = [
        // --- General ---
        'APP_URL'          => ['general', 'Публичный адрес сервиса', 'text', false, '', 'https://… — используется в вебхуках МойСклад'],
        'TIMEZONE'         => ['general', 'Часовой пояс', 'text', false, 'Europe/Moscow', ''],
        'SESSION_LIFETIME' => ['general', 'Время жизни сессии, сек', 'int', false, 86400, ''],

        // --- LLM ---
        'LLM_PROVIDER_PRIORITY' => ['llm', 'Порядок провайдеров', 'text', false, 'yandex', 'Через запятую: yandex, openrouter'],
        'LLM_TIMEOUT_SEC'       => ['llm', 'Таймаут запроса, сек', 'int', false, 30, ''],
        'LLM_TEMPERATURE'       => ['llm', 'Температура по умолчанию', 'text', false, '0.3', ''],
        'LLM_MODEL_PICKER'      => ['llm', 'Выбор модели в окне ответа', 'bool', false, 1, 'Менеджер выбирает нейросеть прямо при создании ответа; выключено — работает цепочка провайдеров'],
        'LLM_PROXY'             => ['llm', 'Адрес прокси для запросов к нейросетям', 'text', false, '', 'http://host:port или socks5h://host:port. Общий адрес для обоих провайдеров — какой из них через него реально ходит, решают переключатели ниже'],
        'LLM_PROXY_AUTH'        => ['llm', 'Логин:пароль прокси', 'secret', true, '', 'user:password, если прокси с авторизацией'],
        'LLM_PROXY_OPENROUTER'  => ['llm', 'Прокси для OpenRouter', 'bool', false, 1, 'OpenRouter обычно недоступен напрямую с российского хостинга — прокси включён по умолчанию'],
        'LLM_PROXY_YANDEX'      => ['llm', 'Прокси для Yandex Foundation Models', 'bool', false, 0, 'Yandex Cloud обычно доступен напрямую с российского хостинга — прокси выключен по умолчанию'],
        'OPENROUTER_API_KEY'    => ['llm', 'Ключ OpenRouter', 'secret', true, '', ''],
        'OPENROUTER_MODEL'      => ['llm', 'Модель OpenRouter', 'model:openrouter', false, 'google/gemini-2.5-flash', ''],
        'OPENROUTER_BASE_URL'   => ['llm', 'Адрес API OpenRouter', 'text', false, 'https://openrouter.ai/api/v1', 'Свой зеркальный адрес, если основной недоступен'],
        'YANDEX_API_KEY'        => ['llm', 'Ключ Yandex', 'secret', true, '', ''],
        'YANDEX_FOLDER_ID'      => ['llm', 'Folder ID Yandex', 'text', false, '', ''],
        'YANDEX_MODEL'          => ['llm', 'Модель Yandex', 'model:yandex', false, 'yandexgpt', 'Слаг без версии: /latest подставляется сам'],
        'LLM_DISCIPLINE'        => ['llm', 'Дисциплина ответа', 'bool', false, 1, 'Ко всем промптам добавляется блок правил: делать работу целиком, не сокращать, не задавать лишних уточняющих вопросов, не выдумывать факты'],
        'LLM_DISCIPLINE_TEXT'   => ['llm', 'Текст блока дисциплины', 'textarea', false, '', 'Пусто — встроенный текст (виден в «Промптах»). Здесь его можно переписать под себя'],

        // --- Knowledge base (module 005): the company wiki from GitHub ---
        'KNOWLEDGE_ENABLED'      => ['knowledge', 'Использовать базу знаний', 'bool', false, 1, 'Вики компании подмешивается в промпты, когда относится к делу'],
        'KNOWLEDGE_REPO'         => ['knowledge', 'Репозиторий GitHub', 'text', false, 'dansury/Atlant', 'В формате owner/repo'],
        'KNOWLEDGE_BRANCH'       => ['knowledge', 'Ветка', 'text', false, 'Main', ''],
        'KNOWLEDGE_PATH'         => ['knowledge', 'Папка с вики', 'text', false, 'GRAPH/wiki', 'Путь внутри репозитория; читаются все .md'],
        'GITHUB_TOKEN'           => ['knowledge', 'Токен GitHub', 'secret', true, '', 'Fine-grained токен с правом Contents: Read. Для приватного репозитория обязателен'],
        'KNOWLEDGE_SYNC_TTL_SEC' => ['knowledge', 'Проверять обновления не чаще, сек', 'int', false, 600, '0 — проверять версию репозитория перед каждой генерацией'],
        'KNOWLEDGE_TIMEOUT_SEC'  => ['knowledge', 'Таймаут запроса к GitHub, сек', 'int', false, 20, ''],
        'KNOWLEDGE_TASKS'        => ['knowledge', 'Где применять', 'text', false, 'mail_reply,reply_kp,reply_product,reply_availability,reply_order_status,reply_delivery,reply_edo,reply_closing_docs,reply_contract,reply_tender,reply_gov_order,reply_return,reply_docs,reply_wholesale,reply_complaint,cover_letter,followup,normalize_names', 'Ключи задач через запятую: mail_reply, cover_letter, followup, normalize_names'],
        'KNOWLEDGE_MAX_CHARS'    => ['knowledge', 'Максимум символов вики в промпте', 'int', false, 6000, 'Бюджет для ответа на письмо; у остальных задач — доля от него'],
        'KNOWLEDGE_MIN_HITS'     => ['knowledge', 'Минимум совпавших терминов', 'int', false, 2, 'Ниже порога раздел вики не подмешивается — «незачем»'],
        'KNOWLEDGE_VECTORS'      => ['knowledge', 'Векторный поиск по базе знаний', 'bool', false, 1, 'Разделы вики тоже векторизуются эмбеддингами Yandex: к словесному подбору добавляются разделы, подходящие по смыслу. Без ключа Yandex подбор молча остаётся словесным'],
        'KNOWLEDGE_VECTOR_TOP'   => ['knowledge', 'Разделов по смыслу, максимум', 'int', false, 3, 'Сколько разделов добавлять сверх найденных по словам'],
        'KNOWLEDGE_VECTOR_MIN'   => ['knowledge', 'Порог близости по смыслу', 'text', false, '0.55', 'Косинусная близость 0…1. Ниже порога раздел не добавляется'],

        // --- Triage (module 006): what a letter is and how it gets answered ---
        'TRIAGE_ENABLED'          => ['triage', 'Классифицировать входящие письма', 'bool', false, 1, 'Выключено — как раньше: каждое письмо становится запросом КП'],
        'TRIAGE_SERVICE_SENDERS'  => ['triage', 'Служебные отправители', 'text', false, 'yandex.ru, yandex.com, yandex-team.ru, moysklad.ru, sweb.ru, nic.ru, rutubeinfo.ru, ofd.astral.ru, chek.pofd.ru, ofd.ru, platformaofd.ru, atol.ru, aqsi.ru, account.2gis.com, trello.com, todoist.com, accounts.google.com, google.com, sender.ozon.ru, ozon.ru, sfr.gov.ru, nalog.ru, avito.ru, no-reply@*, noreply@*, no_reply@*, notification@*, notifications@*, mailer-daemon@*, devnull@*', 'Через запятую. Домен покрывает поддомены; можно маски вида no-reply@*'],
        'TRIAGE_MIN_CONFIDENCE'   => ['triage', 'Порог уверенности классификатора', 'text', false, '0.5', 'Ниже порога письмо помечается «не определено» — разбирает менеджер'],
        'TRIAGE_CATALOG_LIMIT'    => ['triage', 'Позиций каталога в промпте', 'int', false, 6, 'Сколько товаров из МойСклад подмешивать в ответ'],
        'TRIAGE_AUTO_DRAFT'       => ['triage', 'Готовить черновик сразу', 'bool', false, 0, 'Иначе черновик создаётся по кнопке «Создать ответ» — это экономит вызовы модели'],
        'TRIAGE_SPAM_SENDERS'     => ['triage', 'Отправители-спамеры', 'text', false, '', 'Через запятую — адреса, отмеченные кнопкой «Спам» в письме. Письма с них дальше в запрос не попадают и уходят в папку спама на сервере'],
        'TRIAGE_LEARN'            => ['triage', 'Учить классификатор на правках', 'bool', false, 1, 'Категория, которую менеджер поправил в окне ответа, попадает в промпт примером — и в похожем письме модель повторит его решение'],
        'TRIAGE_LEARN_SAMPLES'    => ['triage', 'Примеров из правок в промпте', 'int', false, 8, 'Берутся последние. 0 — не подмешивать'],

        // --- Inbound channels (module 015): the site form, and the address
        // every answer must leave from ---
        'SITE_FORM_UNWRAP'     => ['triage', 'Разворачивать письма с форм сайта', 'bool', false, 1, 'Письмо «Новый вопрос с сайта» приходит от нас самих: отправителем становится посетитель, темой — его вопрос'],
        'SITE_FORM_SPAM_FILTER'=> ['triage', 'Отсеивать спам из форм сайта', 'bool', false, 1, 'Заявки, заполненные ботом (имя «1», сообщение «555», SQL-payload), не доходят до модели и не заводят запрос'],
        'MAIL_OUTGOING_FROM'   => ['mail', 'Адрес для всех исходящих', 'text', false, '', 'Пусто — письмо уходит из ящика, в который пришло. Заполнено — ВСЕ ответы уходят с этого адреса, каким бы ящиком их ни открыли'],

        // --- Web push (module 007) ---
        'PUSH_ENABLED'      => ['push', 'Push-уведомления', 'bool', false, 1, 'Уведомления на телефон администратора о новых письмах и запросах'],
        'PUSH_VAPID_PUBLIC' => ['push', 'VAPID public key', 'text', false, '', 'Генерируется автоматически при первом включении'],
        'PUSH_VAPID_PRIVATE'=> ['push', 'VAPID private key', 'secret', true, '', 'Генерируется автоматически; менять вручную не нужно'],
        'PUSH_VAPID_SUBJECT'=> ['push', 'Контакт для push-сервиса', 'text', false, '', 'mailto:… — по нему push-сервис свяжется при проблемах'],

        // --- MoySklad ---
        'MOYSKLAD_TOKEN'  => ['moysklad', 'Токен МойСклад', 'secret', true, '', 'Профиль сотрудника в МойСклад → «Токен доступа». При смене пароля сотрудника токен отзывается'],
        'MOYSKLAD_ORG_ID' => ['moysklad', 'ID организации', 'text', false, '', ''],
        'CATALOG_PRICE_COLUMN' => ['moysklad', 'Колонка цены в импорте Excel', 'text', false, '', 'То же, что «Тип цены по умолчанию»: колонка «Цена: …» выгрузки МойСклад. Импорт записывает сюда выбранную колонку, пусто — берётся тип цены по умолчанию'],
        'CATALOG_IMPORT_ARCHIVED' => ['moysklad', 'Импортировать архивные позиции', 'bool', false, 0, 'Строки с «Архивный: да» обычно в КП не нужны'],
        'CATALOG_DEFAULT_PRICE_TYPE' => ['moysklad', 'Тип цены по умолчанию', 'text', false, 'Цена продажи', 'Имя типа цены МойСклад (Опт безнал, Розница, Цена продажи). Это же имя — колонка «Цена: …» в импорте Excel: импорт берёт цену из неё и записывает её сюда. Используется, когда для контрагента или товара не выбран другой тип'],
        // --- Правки, на которых сервис учится (модуль 022) ---
        'LEARNING_EXPORT_REPO'   => ['knowledge', 'Репозиторий для выгрузки правок', 'text', false, 'dansury/Atlant', 'Куда уходит архив правок. Пусто — тот же, что у вики'],
        'LEARNING_EXPORT_BRANCH' => ['knowledge', 'Ветка для выгрузки правок', 'text', false, 'Main', ''],
        'LEARNING_EXPORT_PATH'   => ['knowledge', 'Папка для выгрузки правок', 'text', false, 'GRAPH/RAW/NEW', 'Путь внутри репозитория. Архив кладётся файлом с датой в имени'],
        'REQUISITES_AUTOSYNC' => ['moysklad', 'Тянуть реквизиты из МойСклад', 'bool', false, 1, 'НДС, ИНН/КПП, адреса, банк и договор берутся из организации и договора в МойСклад и фиксируются в КП'],
        // --- Склады, модификации и «не наша номенклатура» (модуль 022) ---
        'MOYSKLAD_STORES'     => ['moysklad', 'Склады для остатков', 'stores', false, '', 'С каких складов МойСклад брать остатки. Пусто — со всех сразу. Отмечайте только те, с которых реально отгружаете: остаток витрины или брака в КП превращается в обещание, которого не выполнить'],
        'MOYSKLAD_STOCK_SYNC' => ['moysklad', 'Подтягивать остатки из МойСклад', 'bool', false, 1, 'Отчёт «Остатки» читается вместе с каталогом. Выключено — остатки берутся только из импорта Excel, а их там нет'],
        'MOYSKLAD_VARIANTS'   => ['moysklad', 'Загружать модификации', 'bool', false, 1, 'Размеры и цвета одного товара приходят из МойСклад как отдельные карточки — без них КП собирает три размера в одну строку'],
        'CATALOG_SPLIT_VARIANTS' => ['match', 'Разбивать позицию на модификации', 'bool', false, 1, '«Шлем (р.S-5шт, р.M-13шт)» становится двумя строками с их количествами, а не одной на 18 штук'],
        'CATALOG_SCOPE_FILTER' => ['match', 'Отсеивать не нашу номенклатуру', 'bool', false, 1, 'Позиции из списка ниже не попадают ни в КП, ни в ответ клиенту — мы ими не занимаемся'],
        'CATALOG_OUT_OF_SCOPE' => ['match', 'Чем мы не занимаемся', 'textarea', false, '', 'По строке на правило. Пусто — встроенный список (пожарно-техническое снаряжение). Кнопка «не наш профиль» на строке запроса пополняет этот список сама'],
        'SCOPE_REPLY_MODE'     => ['match', 'Что писать про такие позиции', 'select:silent,decline', false, 'silent', '«silent» — молчать о них вовсе; «decline» — одной фразой сказать, что мы их не поставляем'],

        // --- Matching and catalog vectors (module 009) ---
        'MATCH_MIN_SCORE'      => ['match', 'Порог совпадения', 'text', false, '0.6', 'Ниже него позиция каталога не предлагается вовсе'],
        'MATCH_AUTO_CONFIRM'   => ['match', 'Порог автоподтверждения', 'text', false, '0.88', 'Выше него позиция подставляется сама и помечается «ок»'],
        'MATCH_EQUAL_DELTA'    => ['match', 'Разница «равнозначных», доли', 'text', false, '0.05', 'Кандидаты в пределах этой разницы считаются равнозначными — менеджер выбирает сам'],
        'MATCH_VECTOR_WEIGHT'  => ['match', 'Вес векторного поиска', 'text', false, '0.5', '0 — только слова, 1 — только смысл. Работает при включённой векторизации'],
        'MATCH_CANDIDATES'     => ['match', 'Сколько вариантов показывать', 'int', false, 5, ''],
        'VECTOR_ENABLED'       => ['match', 'Векторный поиск по каталогу', 'bool', false, 1, 'Эмбеддинги Yandex Cloud. Без ключа Yandex подбор молча остаётся словесным'],
        'VECTOR_MODEL_DOC'     => ['match', 'Модель эмбеддингов каталога', 'text', false, 'text-search-doc', 'emb://<folder>/<модель>/latest'],
        'VECTOR_MODEL_QUERY'   => ['match', 'Модель эмбеддингов запроса', 'text', false, 'text-search-query', ''],
        'VECTOR_BATCH'         => ['match', 'Позиций в одной пачке', 'int', false, 20, 'Размер порции, которая обрабатывается за один заход. Больше — быстрее, но легче упереться в лимит'],
        'VECTOR_CONCURRENCY'   => ['match', 'Параллельных запросов', 'int', false, 5, 'Сколько запросов пачки висят на линии одновременно. Уменьшите при HTTP 429 от Yandex'],
        'VECTOR_PAUSE_MS'      => ['match', 'Пауза между пачками, мс', 'int', false, 100, 'Страховка от rate limit'],
        'VECTOR_BUDGET_SEC'    => ['match', 'Лимит времени на шаг, сек', 'int', false, 20, 'Шаг останавливается по времени, следующий продолжает с того же места'],
        'VECTOR_RETRIES'       => ['match', 'Повторов при ошибке', 'int', false, 3, 'На 429 и 5xx позиция уходит в повтор с нарастающей паузой'],
        'VECTOR_TIMEOUT_SEC'   => ['match', 'Таймаут запроса, сек', 'int', false, 20, ''],
        'VECTOR_ENDPOINT'      => ['match', 'Адрес API эмбеддингов', 'text', false, '', 'Пусто — стандартный адрес Yandex Cloud. Свой нужен, когда API доступен только через зеркало'],
        'MATCH_SYNONYMS'       => ['match', 'Свои синонимы', 'textarea', false, '', 'По строке на группу, слова через запятую. Первое слово — основное: «бронежилет, броник, бж». Встроенный список этим дополняется, а не заменяется'],
        'ALT_ENABLED'          => ['match', 'Предлагать аналоги, когда позиции нет в наличии', 'bool', false, 1, 'В КП вместо пустой строки встаёт похожая позиция со склада, и документ прямо называет, каким требованиям она соответствует'],
        'ALT_USE_LLM'          => ['match', 'Сверять описания нейросетью', 'bool', false, 1, 'Выключено — аналог всё равно подбирается: по названию, синонимам и векторам, а требования сверяются по тексту описания'],

        // --- КП: what the document prints and where the numbers come from (module 013) ---
        'KP_MATCH_TABLE'       => ['kp', 'Таблица соответствия в начале КП', 'select:auto,always,never', false, 'auto', 'auto — таблица появляется, когда запрос пришёл таблицей или спецификацией; текстовый запрос её не получает'],
        'KP_CARD_PHOTOS'       => ['kp', 'Фото в карточке товара', 'int', false, 1, '1 — первая фотография товара из МойСклад. Явный выбор менеджера в редакторе КП этот лимит не ограничивает'],
        'KP_SHOW_SITE_LINK'    => ['kp', 'Ссылка на товар на сайте', 'bool', false, 1, 'Под описанием позиции печатается ссылка на её страницу на сайте (см. «Сайт (Битрикс)»)'],
        'KP_VAT_EXEMPT_NOTE'   => ['kp', 'Формулировка без НДС', 'text', false, 'НДС не облагается', 'Печатается, когда организация в МойСклад не плательщик НДС'],
        'KP_FILE_BRAND'        => ['kp', 'Бренд в имени файла КП', 'text', false, 'Атлант Армор', 'Файл называется «КП_{бренд}_для_{кому}_от_{дата}». Клиент ищет его в своей папке через неделю — «KP-2026-002.pdf» там не находится никак'],
        'KP_ATTACH_FORMAT'     => ['kp', 'Формат КП для клиента', 'select:docx,pdf,both', false, 'docx', 'Чем КП уходит в письме. Закупщику нужен редактируемый файл: он переносит позиции в свою форму — поэтому по умолчанию Word'],
        'KP_REQUISITES_BLOCK'  => ['kp', 'Блок реквизитов в КП', 'bool', false, 1, 'Реквизиты, банк, адреса и договор подтягиваются из МойСклад и фиксируются в КП на момент создания'],
        'KP_QR_CODE'           => ['kp', 'QR-код рядом со ссылкой', 'bool', false, 1, 'Ту же ссылку клиент наводит камерой — в распечатанном КП и в Word ссылка не кликается (см. «Сайт (Битрикс)»)'],
        'KP_QR_SIZE'           => ['kp', 'Размер QR-кода, px', 'int', false, 90, 'Как он печатается в документе. Меньше 70 плохо читается камерой с бумаги'],
        'KP_UNMATCHED_NOTE'    => ['kp', 'Текст над позициями без совпадения', 'textarea', false, 'По этим позициям запроса мы уточняем наличие, сроки и цену и вернёмся с ответом отдельно.', 'Позиции, которым каталог ничего не ответил, КП называет словами клиента отдельным блоком — а не оставляет клиенту искать дыру самому'],

        // --- The shop on 1С-Битрикс (module 013) ---
        'BITRIX_ENABLED'         => ['bitrix', 'Связь с сайтом', 'bool', false, 0, 'Без неё ссылки на товар в КП просто не печатаются'],
        'BITRIX_SITE_URL'        => ['bitrix', 'Адрес сайта', 'text', false, 'https://atlant-armour.ru', 'Без слеша в конце'],
        'BITRIX_WEBHOOK_URL'     => ['bitrix', 'Входящий вебхук Битрикс', 'secret', true, '', 'URL, который по ?article=&code=&name= отвечает JSON со ссылкой на товар: {"url":"…"} или {"result":[{"DETAIL_PAGE_URL":"…"}]}'],
        'BITRIX_URL_TEMPLATE'    => ['bitrix', 'Шаблон адреса товара', 'text', false, '', 'Например /catalog/{article}/ — подставляются {article}, {code}, {slug}, {id}. Используется, когда вебхука нет или он не ответил'],
        'BITRIX_SEARCH_TEMPLATE' => ['bitrix', 'Шаблон поиска по сайту', 'text', false, '/search/?q={query}', 'Последний вариант: ссылка на поиск по артикулу. Пусто — ссылку не печатать вовсе'],
        'BITRIX_VERIFY_URL'      => ['bitrix', 'Проверять ссылку перед КП', 'bool', false, 1, 'Ссылка, отвечающая не 2xx, в документ не попадает'],
        'BITRIX_CACHE_DAYS'      => ['bitrix', 'Хранить найденную ссылку, дней', 'int', false, 30, ''],
        'BITRIX_TIMEOUT_SEC'     => ['bitrix', 'Таймаут запроса к сайту, сек', 'int', false, 10, ''],
        'BITRIX_EXPORT_PAGE'     => ['bitrix', 'Товаров за один шаг выгрузки', 'int', false, 500, 'Модуль «Атлант: выгрузка каталога для КП» отдаёт весь каталог страницами — одним запросом вместо одного на позицию. Меньше — если хостинг сайта не успевает'],
        'BITRIX_EXPORT_STEPS'    => ['bitrix', 'Шагов выгрузки за один проход', 'int', false, 20, 'Проход обрывается на этом числе страниц, следующий продолжает с того же места'],

        // --- Mail defaults (a new mailbox is pre-filled from these) ---
        'IMAP_HOST'       => ['mail', 'IMAP сервер', 'text', false, '', ''],
        'IMAP_PORT'       => ['mail', 'IMAP порт', 'int', false, 993, ''],
        'IMAP_USER'       => ['mail', 'IMAP логин', 'text', false, '', ''],
        'IMAP_PASSWORD'   => ['mail', 'IMAP пароль', 'secret', true, '', ''],
        'IMAP_ENCRYPTION' => ['mail', 'IMAP шифрование', 'select:ssl,tls,notls', false, 'ssl', ''],
        'SMTP_HOST'       => ['mail', 'SMTP сервер', 'text', false, '', ''],
        'SMTP_PORT'       => ['mail', 'SMTP порт', 'int', false, 465, ''],
        'SMTP_USER'       => ['mail', 'SMTP логин', 'text', false, '', ''],
        'SMTP_PASSWORD'   => ['mail', 'SMTP пароль', 'secret', true, '', ''],
        'SMTP_ENCRYPTION' => ['mail', 'SMTP шифрование', 'select:ssl,tls,', false, 'ssl', ''],
        'SMTP_FROM_NAME'  => ['mail', 'Имя отправителя', 'text', false, 'Atlant Armour', ''],
        'SMTP_FROM_EMAIL' => ['mail', 'Адрес отправителя', 'text', false, '', ''],

        // --- Mail behaviour ---
        'MAIL_ARCHIVE_ALL'   => ['mail', 'Архивировать всю почту', 'bool', false, 1, 'Входящие и исходящие складываются в раздел «Почта»'],
        'MAIL_SYNC_SENT'     => ['mail', 'Забирать папку «Отправленные»', 'bool', false, 1, 'Письма, отправленные из другого клиента, тоже попадут в архив'],
        'MAIL_APPEND_SENT'   => ['mail', 'Класть свои письма в «Отправленные»', 'bool', false, 1, 'IMAP APPEND после отправки'],
        'MAIL_FETCH_LIMIT'   => ['mail', 'Писем за один проход', 'int', false, 50, ''],
        'MAIL_BODY_MAX_KB'   => ['mail', 'Максимум тела письма, КБ', 'int', false, 512, ''],
        'MAIL_BACKFILL_BATCH'   => ['mail', 'Писем за один шаг скачивания архива', 'int', false, 100, 'Скачивание всей почты идёт шагами — на дешёвом хостинге ставьте меньше'],
        'MAIL_BACKFILL_SECONDS' => ['mail', 'Лимит времени на шаг, сек', 'int', false, 20, 'Шаг прерывается по времени, следующий продолжает с того же места'],
        'MAIL_THREADS'       => ['mail', 'Показывать письма цепочками', 'bool', false, 1, 'Письма с одной темой (с «Re:» и без) собираются в одну переписку по всем ящикам'],

        // --- Дедупликация и импорт переписки (модуль 021) ---
        'MAIL_DEDUP'         => ['mail', 'Дедупликация писем', 'bool', false, 1, 'Одно и то же письмо не кладётся в архив дважды — ни из второго ящика, ни из папки «Отправленные», ни из импортированного mbox. Проверка идёт по всем ящикам сразу'],
        'MAIL_DEDUP_CONTENT' => ['mail', 'Сравнивать письма по содержимому', 'bool', false, 1, 'Кроме Message-ID сравнивается отпечаток письма: отправитель, получатели, тема, текст и байты вложений. Ловит копии, у которых шлюз переписал Message-ID или не проставил его вовсе'],
        'MBOX_STEP_SECONDS'  => ['mail', 'Лимит времени на шаг импорта mbox, сек', 'int', false, 20, 'Шаг обрывается по времени, следующий продолжает с того же байта файла'],
        'MBOX_STEP_LETTERS'  => ['mail', 'Писем за один шаг импорта mbox', 'int', false, 200, 'На дешёвом хостинге ставьте меньше'],
        'MBOX_ATTACH_MAX_MB' => ['mail', 'Максимум вложения из mbox, МБ', 'int', false, 25, 'Файл крупнее пропускается — письмо всё равно импортируется'],
        'MBOX_MESSAGE_MAX_MB'=> ['mail', 'Максимум письма из mbox, МБ', 'int', false, 40, 'Письмо целиком крупнее этого пропускается: разбор держит его в памяти, а на дешёвом хостинге её немного'],
        'MBOX_CHUNK_MB'      => ['mail', 'Кусок загрузки mbox, МБ', 'int', false, 4, 'Браузер режет файл на куски такого размера. Если прокси отвечает 413, кусок уменьшается сам — вдвое, до 256 КБ'],
        'MBOX_UPLOAD_KEEP_DAYS' => ['mail', 'Хранить брошенную загрузку mbox, дней', 'int', false, 3, 'Недогруженный файл из storage/mbox/.parts удаляется, если за это время к нему не вернулись'],
        // --- Board (module 011) ---
        'BOARD_INBOX_DAYS'   => ['mail', 'Глубина автозагрузки доски, дней', 'int', false, 180, 'Переписки свежее этого срока сами попадают в «Входящие»; старые остаются в архиве'],
        'BOARD_AUTOLOAD'     => ['mail', 'Складывать новые письма на доску', 'bool', false, 1, 'Каждая компания, написавшая нам, получает карточку во «Входящих» без ручного «в доску»'],

        // --- Notifications ---
        'FALLBACK_EMAIL'        => ['notify', 'Почта для эскалации', 'text', false, '', 'Куда уходит письмо о необработанном запросе'],
        'FALLBACK_HOURS'        => ['notify', 'Порог эскалации, часов', 'int', false, 24, ''],
        'NOTIFICATION_POLL_SEC' => ['notify', 'Опрос уведомлений, сек', 'int', false, 30, ''],

        // --- Auto-deploy (module 014): the active-development checkbox ---
        'AUTOPULL_ENABLED'  => ['deploy', 'Проверять обновления при каждом запуске', 'bool', false, 0, 'На время активной разработки: каждое открытие страницы тихо спрашивает у GitHub head отслеживаемой ссылки, и новый коммит выкладывается через pull.php — страница открывается заново уже на новом коде. Репозиторий, токен и пароль pull.php берутся из pull-config.php в корне сайта'],
        'AUTOPULL_INTERVAL' => ['deploy', 'Проверять не чаще, сек', 'int', false, 0, '0 — при каждом открытии страницы. Каждая проверка — один запрос к API GitHub (лимит 5000 в час с токеном)'],
        'AUTOPULL_URL'      => ['deploy', 'Адрес pull.php', 'text', false, '', 'Пусто — вычисляется сам из каталога скрипта. Заполняется, когда хостинг не открывает собственный домен изнутри PHP'],

        // --- Logging ---
        'LOG_LEVEL'          => ['log', 'Уровень логирования', 'select:debug,info,warning,error', false, 'info', ''],
        'LOG_RETENTION_DAYS' => ['log', 'Хранить логи, дней', 'int', false, 30, ''],
        'LOG_PHP_ERRORS'     => ['log', 'Ловить ошибки и warning PHP', 'bool', false, 1, ''],
        'LOG_PHP_DEPRECATED' => ['log', 'Писать deprecated-предупреждения PHP', 'bool', false, 0, 'Служебные сообщения новой версии PHP — нужны разработчику, не администратору'],
    ];

    /** Group titles for the admin UI */
    public const GROUPS = [
        'general'  => 'Общие',
        'llm'      => 'Нейросети',
        'moysklad' => 'МойСклад',
        'match'    => 'Подбор позиций',
        'kp'       => 'Коммерческое предложение',
        'bitrix'   => 'Сайт (Битрикс)',
        'knowledge' => 'База знаний (вики)',
        'triage'   => 'Разбор входящей почты',
        'push'     => 'Push-уведомления',
        'mail'     => 'Почта (значения по умолчанию)',
        'notify'   => 'Уведомления',
        'deploy'   => 'Автообновление кода',
        'log'      => 'Логи',
    ];

    private static array $file = [];
    private static ?array $db = null;

    /** Remember what config.php gave us (may be an empty array — the file is optional). */
    public static function boot(array $fileConfig): void {
        self::$file = $fileConfig;
        self::$db = null;
    }

    /** Raw config.php value, or null when the file does not define the key. */
    public static function fileValue(string $key): mixed {
        return array_key_exists($key, self::$file) ? self::$file[$key] : null;
    }

    public static function fileConfig(): array {
        return self::$file;
    }

    /** DB overrides, loaded once per request. */
    private static function overrides(): array {
        if (self::$db === null) {
            self::$db = [];
            try {
                foreach (Db::all("SELECT key, value FROM settings WHERE key LIKE 'cfg.%'") as $row) {
                    $key = substr($row['key'], 4);
                    self::$db[$key] = self::isSecret($key) ? Crypt::decrypt($row['value']) : $row['value'];
                }
            } catch (Throwable) {
                // Schema not ready yet (first boot) — config.php alone is enough to get there
            }
        }
        return self::$db;
    }

    /** Effective value: DB override → config.php → built-in default. */
    public static function get(string $key, mixed $default = null): mixed {
        $over = self::overrides();
        if (array_key_exists($key, $over)) return self::cast($key, $over[$key]);
        if (array_key_exists($key, self::$file)) return self::$file[$key];
        if (isset(self::SPEC[$key])) return self::SPEC[$key][4];
        return $default;
    }

    /** Where the effective value comes from: db | config | default */
    public static function source(string $key): string {
        if (array_key_exists($key, self::overrides())) return 'db';
        if (array_key_exists($key, self::$file)) return 'config';
        return 'default';
    }

    /** Store an override. It wins over config.php from now on. */
    public static function set(string $key, mixed $value): void {
        $stored = self::isSecret($key) ? Crypt::encrypt((string)$value) : (string)$value;
        Db::q(
            "INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
            ['cfg.' . $key, $stored]
        );
        self::$db = null;
    }

    /** Drop the override and fall back to config.php / the built-in default. */
    public static function forget(string $key): void {
        Db::q("DELETE FROM settings WHERE key=?", ['cfg.' . $key]);
        self::$db = null;
    }

    public static function isSecret(string $key): bool {
        return !empty(self::SPEC[$key][3]);
    }

    /** Full effective config — what the app runs on ($cfg). MANAGERS stays file-only. */
    public static function effective(): array {
        $out = [];
        foreach (self::SPEC as $key => $_) {
            $out[$key] = self::get($key);
        }
        // Anything config.php defines that the panel does not manage (MANAGERS, DB_PATH, …)
        foreach (self::$file as $key => $value) {
            if (!array_key_exists($key, $out)) $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Admin panel view: every managed key with its source and, for secrets,
     * only a hint (a secret never travels to the browser — Constitution, V).
     */
    public static function describe(): array {
        $rows = [];
        foreach (self::SPEC as $key => [$group, $label, $type, $secret, $default, $hint]) {
            $value  = self::get($key);
            $source = self::source($key);
            $rows[] = [
                'key'          => $key,
                'group'        => $group,
                'label'        => $label,
                'type'         => $type,
                'secret'       => (bool)$secret,
                'hint'         => $hint,
                'source'       => $source,
                'has_config'   => array_key_exists($key, self::$file),
                'has_override' => $source === 'db',
                'default'      => $secret ? '' : (string)$default,
                'value'        => $secret ? '' : (string)$value,
                'filled'       => $secret ? ((string)$value !== '') : null,
                'tail'         => $secret ? self::mask((string)$value) : null,
            ];
        }
        return $rows;
    }

    /**
     * A secret as the panel shows it: first four characters and last four.
     * Both ends, because one end does not identify a token — an operator with
     * three МойСклад tokens and two Yandex keys has to be able to tell from the
     * screen WHICH one is stored, without the value ever being readable.
     */
    public static function mask(string $secret): string {
        $len = mb_strlen($secret);
        if ($len === 0) return '';
        if ($len <= 8) return str_repeat('•', $len);
        return mb_substr($secret, 0, 4) . '…' . mb_substr($secret, -4);
    }

    /** @deprecated Use mask() — kept so older callers keep working. */
    public static function tail(string $secret): string {
        return self::mask($secret);
    }

    /** DB values arrive as strings; give ints and bools back in their declared shape. */
    private static function cast(string $key, string $raw): mixed {
        $type = self::SPEC[$key][2] ?? 'text';
        return match (true) {
            $type === 'int'  => (int)$raw,
            $type === 'bool' => (int)((string)$raw !== '' && $raw !== '0'),
            default          => $raw,
        };
    }
}
