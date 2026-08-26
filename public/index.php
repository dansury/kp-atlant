<?php
/**
 * SPA entry point.
 */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atlant Armour — КП</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header class="header">
        <div class="header__logo">Atlant Armour <span class="header__sub">КП</span></div>
        <nav class="header__nav" id="nav"></nav>
        <div class="header__user" id="userBlock"></div>
    </header>

    <main class="main" id="app">
        <div class="loading">Загрузка...</div>
    </main>

    <div class="toast-container" id="toasts"></div>

    <script src="/assets/js/app.js"></script>
</body>
</html>
