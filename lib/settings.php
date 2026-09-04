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
        'OPENROUTER_API_KEY'    => ['llm', 'Ключ OpenRouter', 'secret', true, '', ''],
        'OPENROUTER_MODEL'      => ['llm', 'Модель OpenRouter', 'model:openrouter', false, 'google/gemini-2.5-flash', ''],
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
        'KNOWLEDGE_TASKS'        => ['knowledge', 'Где применять', 'text', false, 'mail_reply,cover_letter,followup,normalize_names', 'Ключи задач через запятую: mail_reply, cover_letter, followup, normalize_names'],
        'KNOWLEDGE_MAX_CHARS'    => ['knowledge', 'Максимум символов вики в промпте', 'int', false, 6000, 'Бюджет для ответа на письмо; у остальных задач — доля от него'],
        'KNOWLEDGE_MIN_HITS'     => ['knowledge', 'Минимум совпавших терминов', 'int', false, 2, 'Ниже порога раздел вики не подмешивается — «незачем»'],

        // --- MoySklad ---
        'MOYSKLAD_TOKEN'  => ['moysklad', 'Токен МойСклад', 'secret', true, '', ''],
        'MOYSKLAD_ORG_ID' => ['moysklad', 'ID организации', 'text', false, '', ''],

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

        // --- Notifications ---
        'FALLBACK_EMAIL'        => ['notify', 'Почта для эскалации', 'text', false, '', 'Куда уходит письмо о необработанном запросе'],
        'FALLBACK_HOURS'        => ['notify', 'Порог эскалации, часов', 'int', false, 24, ''],
        'NOTIFICATION_POLL_SEC' => ['notify', 'Опрос уведомлений, сек', 'int', false, 30, ''],

        // --- Logging ---
        'LOG_LEVEL'          => ['log', 'Уровень логирования', 'select:debug,info,warning,error', false, 'info', ''],
        'LOG_RETENTION_DAYS' => ['log', 'Хранить логи, дней', 'int', false, 30, ''],
        'LOG_PHP_ERRORS'     => ['log', 'Ловить ошибки и warning PHP', 'bool', false, 1, ''],
    ];

    /** Group titles for the admin UI */
    public const GROUPS = [
        'general'  => 'Общие',
        'llm'      => 'Нейросети',
        'moysklad' => 'МойСклад',
        'knowledge' => 'База знаний (вики)',
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
