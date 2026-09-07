<?php
/**
 * SPA entry point.
 */
// Cache-bust assets so managers never run a stale build after a deploy
$assetVer = max(
    @filemtime(__DIR__ . '/assets/js/app.js') ?: 0,
    @filemtime(__DIR__ . '/assets/css/app.css') ?: 0
);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atlant Armour — КП</title>
    <!-- Installable on a phone: manifest + icons + standalone chrome (module 007) -->
    <meta name="theme-color" content="#f1f0ee">
    <meta name="description" content="Разбор входящих запросов, коммерческие предложения и счета Atlant Armour.">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Атлант КП">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/assets/icons/icon-192.png" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $assetVer ?>">
</head>
<body>
    <header class="header">
        <div class="header__logo">
            <svg class="header__mark" viewBox="0 0 40 26" aria-hidden="true" focusable="false">
                <path d="M0 0C6 0 11 1 20 6C29 1 34 0 40 0C34 5 27 13 20 26C13 13 6 5 0 0Z"/>
            </svg>
            Atlant Armour <span class="header__sub">КП</span>
        </div>
        <nav class="header__nav" id="nav"></nav>
        <div class="header__user" id="userBlock"></div>
    </header>

    <main class="main" id="app">
        <div class="loading">Загрузка...</div>
    </main>

    <div class="toast-container" id="toasts"></div>

    <script src="/assets/js/app.js?v=<?= $assetVer ?>"></script>
</body>
</html>
