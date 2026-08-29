<?php
// Atlant Armour KP Automation — configuration
// Copy to config.php and fill in real values

return [
    // Database
    'DB_PATH' => __DIR__ . '/data/kp.db',

    // LLM providers
    'LLM_PROVIDER_PRIORITY' => 'openrouter,yandex', // comma-separated
    'OPENROUTER_API_KEY'    => '',
    'OPENROUTER_MODEL'      => 'google/gemini-2.5-flash',
    'YANDEX_API_KEY'        => '',
    'YANDEX_FOLDER_ID'      => '',
    'YANDEX_MODEL'          => 'yandexgpt/latest',
    'LLM_TIMEOUT_SEC'       => 30,

    // MoySklad
    'MOYSKLAD_TOKEN' => '',

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

    // App
    'APP_URL'        => 'https://kp.atlant-armour.ru',
    'APP_NAME'       => 'Atlant Armour КП',
    'SESSION_LIFETIME' => 86400, // 24h
    'TIMEZONE'       => 'Europe/Moscow',
];
