<?php
/**
 * App configuration. Copy to config.php and fill in real values.
 *
 * Since module 004 this file is OPTIONAL and holds only the DEFAULTS. Everything
 * an admin edits in «Админ → Настройки» is stored in the DB and takes precedence;
 * delete config.php from the server and the service keeps running on those values.
 * The panel shows the source of every setting and can reset it back to this file.
 */
return [
    // --- General ---
    'APP_URL'           => 'https://kp.atlant-armour.ru',
    'TIMEZONE'          => 'Europe/Moscow',
    'DB_PATH'           => __DIR__ . '/data/kp.db',
    'SESSION_LIFETIME'  => 86400,

    // --- Managers (seeded on first run) ---
    // Each entry: login => [password, display name, email, is_admin]
    // A manager edited in the admin panel is no longer overwritten from here.
    'MANAGERS' => [
         'admin' => ['CHANGE_ME', 'Имя менеджера', 'mail@example.com', true],
		 // 'petr' => ['CHANGE_ME', 'Пётр', 'mail@example.com', false],
        // 'ivanov' => ['pass123', 'Иванов И.И.', 'ivanov@example.com', false],
    ],

    // --- LLM ---
    'LLM_PROVIDER_PRIORITY' => 'yandex',
    'LLM_TIMEOUT_SEC'       => 30,
    'OPENROUTER_API_KEY'    => '',
    'OPENROUTER_MODEL'      => 'google/gemini-2.5-flash',
    'YANDEX_API_KEY'        => '',
    'YANDEX_FOLDER_ID'      => '',
    'YANDEX_MODEL'          => 'yandexgpt/latest',

    // --- МойСклад ---
    'MOYSKLAD_TOKEN'  => '',
    'MOYSKLAD_ORG_ID' => '',

    // --- Mail ---
    // These are the defaults for a NEW mailbox. Real mailboxes (there can be
    // several, each with its own manager) live in «Админ → Почта».

     // IMAP (incoming email)
    'IMAP_HOST'     => 'smtp.spaceweb.ru',
    'IMAP_PORT'     => 993,
    'IMAP_USER'     => 'info@atlant-armour.ru',
    'IMAP_PASSWORD' => '',
    'IMAP_ENCRYPTION' => 'ssl',

    // SMTP (outgoing email)
    'SMTP_HOST'       => 'smtp.spaceweb.ru',
    'SMTP_PORT'       => 465,
    'SMTP_USER'       => 'info@atlant-armour.ru',
    'SMTP_PASSWORD'   => '',
    'SMTP_ENCRYPTION' => 'ssl',
    'SMTP_FROM_NAME'  => 'Atlant Armour',
    'SMTP_FROM_EMAIL' => 'info@atlant-armour.ru',

    // Mail archive: every incoming and outgoing letter is kept in the service
    'MAIL_ARCHIVE_ALL' => 1,   // archive everything, not only requests
    'MAIL_SYNC_SENT'   => 1,   // also pull the IMAP «Отправленные» folder
    'MAIL_APPEND_SENT' => 1,   // put our own letters there too
    'MAIL_FETCH_LIMIT' => 50,  // letters per sync pass
    'MAIL_BODY_MAX_KB' => 512, // body size kept per letter

    // Notifications
    'FALLBACK_EMAIL'       => 'atlant.armour@yandex.ru',
    'FALLBACK_HOURS'       => 24,
    'NOTIFICATION_POLL_SEC' => 30,

    // Error log shown in «Админ → Логи»
    'LOG_LEVEL'          => 'info',   // debug | info | warning | error
    'LOG_RETENTION_DAYS' => 30,
    'LOG_PHP_ERRORS'     => 1,
];
