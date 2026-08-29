<?php
/**
 * App configuration. Copy to config.php and fill in real values.
 */
return [
    // --- General ---
    'APP_URL'           => 'https://kp.atlant-armour.ru',
    'TIMEZONE'          => 'Europe/Moscow',
    'DB_PATH'           => __DIR__ . '/data/kp.db',
    'SESSION_LIFETIME'  => 86400,

    // --- Managers (seeded on first run) ---
    // Each entry: login => [password, display name, email, is_admin]
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

    // Notifications
    'FALLBACK_EMAIL'       => 'atlant.armour@yandex.ru',
    'FALLBACK_HOURS'       => 24,
    'NOTIFICATION_POLL_SEC' => 30,
];
