<?php
/**
 * Cron: 24h email fallback — hourly check for stale requests.
 * Usage: php cron/check_notifications.php
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/notifier.php';

Notifier::checkFallback($cfg);
