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
        'YANDEX_MODEL'          => ['llm', 'Модель Yandex', 'model:yandex', false, 'yandexgpt/latest', ''],

        // --- Knowledge base (module 005): the company wiki from GitHub ---
        'KNOWLEDGE_ENABLED'      => ['knowledge', 'Использовать базу знаний', 'bool', false, 1, 'Вики компании подмешивается в промпты, когда относится к делу'],
        'KNOWLEDGE_REPO'         => ['knowledge', 'Репозиторий GitHub', 'text', false, 'dansury/Atlant', 'В формате owner/repo'],
        'KNOWLEDGE_BRANCH'       => ['knowledge', 'Ветка', 'text', false, 'Main', ''],
        'KNOWLEDGE_PATH'         => ['knowledge', 'Папка с вики', 'text', false, 'GRAPH/wiki', 'Путь внутри репозитория; читаются все .md'],
        'GITHUB_TOKEN'           => ['knowledge', 'Токен GitHub', 'secret', true, '', 'Fine-grained токен с правом Contents: Read. Для приватного репозитория обязателен'],
        'KNOWLEDGE_SYNC_TTL_SEC' => ['knowledge', 'Проверять обновления не чаще, сек', 'int', false, 600, '0 — проверять версию репозитория перед каждой генерацией'],
        'KNOWLEDGE_TIMEOUT_SEC'  => ['knowledge', 'Таймаут запроса к GitHub, сек', 'int', false, 20, ''],
        'KNOWLEDGE_TASKS'        => ['knowledge', 'Где применять', 'text', false, 'mail_reply,reply_kp,reply_product,reply_availability,reply_order_status,reply_return,reply_docs,reply_wholesale,reply_complaint,cover_letter,followup,normalize_names', 'Ключи задач через запятую: mail_reply, cover_letter, followup, normalize_names'],
        'KNOWLEDGE_MAX_CHARS'    => ['knowledge', 'Максимум символов вики в промпте', 'int', false, 6000, 'Бюджет для ответа на письмо; у остальных задач — доля от него'],
        'KNOWLEDGE_MIN_HITS'     => ['knowledge', 'Минимум совпавших терминов', 'int', false, 2, 'Ниже порога раздел вики не подмешивается — «незачем»'],

        // --- Triage (module 006): what a letter is and how it gets answered ---
        'TRIAGE_ENABLED'          => ['triage', 'Классифицировать входящие письма', 'bool', false, 1, 'Выключено — как раньше: каждое письмо становится запросом КП'],
        'TRIAGE_SERVICE_SENDERS'  => ['triage', 'Служебные отправители', 'text', false, 'yandex.ru, yandex.com, yandex-team.ru, moysklad.ru, sweb.ru, nic.ru, rutubeinfo.ru, no-reply@*, noreply@*, devnull@*', 'Через запятую. Домен покрывает поддомены; можно маски вида no-reply@*'],
        'TRIAGE_MIN_CONFIDENCE'   => ['triage', 'Порог уверенности классификатора', 'text', false, '0.5', 'Ниже порога письмо помечается «не определено» — разбирает менеджер'],
        'TRIAGE_CATALOG_LIMIT'    => ['triage', 'Позиций каталога в промпте', 'int', false, 6, 'Сколько товаров из МойСклад подмешивать в ответ'],
        'TRIAGE_AUTO_DRAFT'       => ['triage', 'Готовить черновик сразу', 'bool', false, 0, 'Иначе черновик создаётся по кнопке «Создать ответ» — это экономит вызовы модели'],
        'TRIAGE_SPAM_SENDERS'     => ['triage', 'Отправители-спамеры', 'text', false, '', 'Через запятую — адреса, отмеченные кнопкой «Спам» в письме. Письма с них дальше в запрос не попадают и уходят в папку спама на сервере'],

        // --- Web push (module 007) ---
        'PUSH_ENABLED'      => ['push', 'Push-уведомления', 'bool', false, 1, 'Уведомления на телефон администратора о новых письмах и запросах'],
        'PUSH_VAPID_PUBLIC' => ['push', 'VAPID public key', 'text', false, '', 'Генерируется автоматически при первом включении'],
        'PUSH_VAPID_PRIVATE'=> ['push', 'VAPID private key', 'secret', true, '', 'Генерируется автоматически; менять вручную не нужно'],
        'PUSH_VAPID_SUBJECT'=> ['push', 'Контакт для push-сервиса', 'text', false, '', 'mailto:… — по нему push-сервис свяжется при проблемах'],

        // --- MoySklad ---
        'MOYSKLAD_TOKEN'  => ['moysklad', 'Токен МойСклад', 'secret', true, '', 'Профиль сотрудника в МойСклад → «Токен доступа». При смене пароля сотрудника токен отзывается'],
        'MOYSKLAD_ORG_ID' => ['moysklad', 'ID организации', 'text', false, '', ''],
        'CATALOG_PRICE_COLUMN' => ['moysklad', 'Колонка цены в импорте Excel', 'text', false, 'Цена: Опт безнал', 'Название колонки выгрузки МойСклад, из которой брать цену КП'],
        'CATALOG_IMPORT_ARCHIVED' => ['moysklad', 'Импортировать архивные позиции', 'bool', false, 0, 'Строки с «Архивный: да» обычно в КП не нужны'],
        'CATALOG_DEFAULT_PRICE_TYPE' => ['moysklad', 'Тип цены по умолчанию', 'text', false, 'Цена продажи', 'Точное имя типа цены из МойСклад (Цена продажи, Розничная цена, Опт и т.п.). Используется, когда для контрагента или товара не выбран другой тип'],

        // --- Matching and catalog vectors (module 009) ---
        'MATCH_MIN_SCORE'      => ['match', 'Порог совпадения', 'text', false, '0.6', 'Ниже него позиция каталога не предлагается вовсе'],
        'MATCH_AUTO_CONFIRM'   => ['match', 'Порог автоподтверждения', 'text', false, '0.88', 'Выше него позиция подставляется сама и помечается «ок»'],
        'MATCH_EQUAL_DELTA'    => ['match', 'Разница «равнозначных», доли', 'text', false, '0.05', 'Кандидаты в пределах этой разницы считаются равнозначными — менеджер выбирает сам'],
        'MATCH_VECTOR_WEIGHT'  => ['match', 'Вес векторного поиска', 'text', false, '0.5', '0 — только слова, 1 — только смысл. Работает при включённой векторизации'],
        'MATCH_CANDIDATES'     => ['match', 'Сколько вариантов показывать', 'int', false, 5, ''],
        'VECTOR_ENABLED'       => ['match', 'Векторный поиск по каталогу', 'bool', false, 1, 'Эмбеддинги Yandex Cloud. Без ключа Yandex подбор молча остаётся словесным'],
        'VECTOR_MODEL_DOC'     => ['match', 'Модель эмбеддингов каталога', 'text', false, 'text-search-doc', 'emb://<folder>/<модель>/latest'],
        'VECTOR_MODEL_QUERY'   => ['match', 'Модель эмбеддингов запроса', 'text', false, 'text-search-query', ''],
        'VECTOR_BATCH'         => ['match', 'Позиций в одной пачке', 'int', false, 20, 'Пачка уходит параллельно через curl_multi. Больше — быстрее, но легче упереться в лимит'],
        'VECTOR_PAUSE_MS'      => ['match', 'Пауза между пачками, мс', 'int', false, 100, 'Страховка от rate limit'],
        'VECTOR_BUDGET_SEC'    => ['match', 'Лимит времени на шаг, сек', 'int', false, 20, 'Шаг останавливается по времени, следующий продолжает с того же места'],
        'VECTOR_RETRIES'       => ['match', 'Повторов при ошибке', 'int', false, 3, 'На 429 и 5xx позиция уходит в повтор с нарастающей паузой'],
        'VECTOR_TIMEOUT_SEC'   => ['match', 'Таймаут запроса, сек', 'int', false, 20, ''],
        'VECTOR_ENDPOINT'      => ['match', 'Адрес API эмбеддингов', 'text', false, '', 'Пусто — стандартный адрес Yandex Cloud. Свой нужен, когда API доступен только через зеркало'],

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

        // --- Notifications ---
        'FALLBACK_EMAIL'        => ['notify', 'Почта для эскалации', 'text', false, '', 'Куда уходит письмо о необработанном запросе'],
        'FALLBACK_HOURS'        => ['notify', 'Порог эскалации, часов', 'int', false, 24, ''],
        'NOTIFICATION_POLL_SEC' => ['notify', 'Опрос уведомлений, сек', 'int', false, 30, ''],

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
        'knowledge' => 'База знаний (вики)',
        'triage'   => 'Разбор входящей почты',
        'push'     => 'Push-уведомления',
        'mail'     => 'Почта (значения по умолчанию)',
        'notify'   => 'Уведомления',
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
                'tail'         => $secret ? self::tail((string)$value) : null,
            ];
        }
        return $rows;
    }

    /** Last characters of a secret — enough to tell two keys apart, useless to steal. */
    public static function tail(string $secret): string {
        $len = mb_strlen($secret);
        return $len === 0 ? '' : ($len <= 4 ? str_repeat('•', $len) : '…' . mb_substr($secret, -4));
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
