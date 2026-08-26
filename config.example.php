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
         'admin' => ['lika1231', 'Яна', 'atlant.armour@yandex.ru', true],
		 'petr' => ['petratlant', 'Петр', 'atlant.armour@yandex.ru', false],
        // 'ivanov' => ['pass123', 'Иванов И.И.', 'ivanov@example.com', false],
    ],

    // --- LLM ---
    'LLM_PROVIDER_PRIORITY' => 'yandex',
    'LLM_TIMEOUT_SEC'       => 30,
    'OPENROUTER_API_KEY'    => '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',
    'OPENROUTER_MODEL'      => 'google/gemini-2.5-flash',
    'YANDEX_API_KEY'        => 'AQVNwk7Wd67tHzOR-p2FKXfuzImMUeqeGt-yqGsq',
    'YANDEX_FOLDER_ID'      => 'b1gevvro8frl0d208mon',
    'YANDEX_MODEL'          => 'yandexgpt/latest',

    // --- МойСклад ---
    'MOYSKLAD_TOKEN'  => '2fd26cc39566866db3b0d33adfa66d189b9b8c56',
    'MOYSKLAD_ORG_ID' => '1b3d3013-eb2f-11f0-0a80-13a900844344',

     // IMAP (incoming email)
    'IMAP_HOST'     => 'smtp.spaceweb.ru',
    'IMAP_PORT'     => 993,
    'IMAP_USER'     => 'info@atlant-armour.ru',
    'IMAP_PASSWORD' => 'Volokolamskoe73',
    'IMAP_ENCRYPTION' => 'ssl',

    // SMTP (outgoing email)
    'SMTP_HOST'       => 'smtp.spaceweb.ru',
    'SMTP_PORT'       => 465,
    'SMTP_USER'       => 'info@atlant-armour.ru',
    'SMTP_PASSWORD'   => 'Volokolamskoe73',
    'SMTP_ENCRYPTION' => 'ssl',
    'SMTP_FROM_NAME'  => 'Atlant Armour',
    'SMTP_FROM_EMAIL' => 'info@atlant-armour.ru',

    // Notifications
    'FALLBACK_EMAIL'       => 'atlant.armour@yandex.ru',
    'FALLBACK_HOURS'       => 24,
    'NOTIFICATION_POLL_SEC' => 30,
];
