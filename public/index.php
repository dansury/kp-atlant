<?php
/**
 * SPA entry point.
 */
// Cache-bust assets so managers never run a stale build after a deploy
$assetVer = max(
    @filemtime(__DIR__ . '/assets/js/app.js') ?: 0,
    @filemtime(__DIR__ . '/assets/css/app.css') ?: 0
);

// Логотипы, загруженные через «Настройки → Логотипы» (модуль 021). Берём
// только `Branding` — без bootstrap: оболочке страницы не нужны ни база, ни
// проверка обновлений, а знак нужен до первого запроса к API.
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/lib/branding.php';
$brandVer  = Branding::stamp();
$appLogo   = Branding::uploaded('app');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atlant Armour — КП</title>
    <!-- Installable on a phone: manifest + icons + standalone chrome (module 007) -->
    <meta name="theme-color" content="#ffffff">
    <meta name="description" content="Разбор входящих запросов, коммерческие предложения и счета Atlant Armour.">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Атлант КП">
    <link rel="manifest" href="/manifest.webmanifest">
    <!-- Значок вкладки и иконка приложения — из загруженного файла, иначе встроенные -->
    <link rel="icon" href="/api/branding.php?kind=favicon&amp;v=<?= $brandVer ?>">
    <link rel="apple-touch-icon" href="/api/branding.php?kind=icon&amp;size=192&amp;v=<?= $brandVer ?>">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $assetVer ?>">

    <!-- Виджет поддержки Replain -->
    <script>
    window.replainSettings = { id: '02391a2c-0104-46cd-9b04-87a680dfb320' };
    (function(u){var s=document.createElement('script');s.async=true;s.src=u;
    var x=document.getElementsByTagName('script')[0];x.parentNode.insertBefore(s,x);
    })('https://widget.replain.cc/dist/client.js');
    </script>

    <!-- Yandex.Metrika counter -->
    <script type="text/javascript">
        (function(m,e,t,r,i,k,a){
            m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
            m[i].l=1*new Date();
            for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
            k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
        })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id=112558579', 'ym');

        ym(112558579, 'init', {ssr:true, webvisor:true, clickmap:true, ecommerce:"dataLayer", referrer: document.referrer, url: location.href, accurateTrackBounce:true, trackLinks:true});
    </script>
    <!-- /Yandex.Metrika counter -->
</head>
<body>
    <noscript><div><img src="https://mc.yandex.ru/watch/112558579" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
    <header class="header">
        <div class="header__logo">
            <?php if ($appLogo): ?>
            <img class="header__brand" src="/api/branding.php?kind=app&amp;v=<?= $brandVer ?>" alt="Atlant Armour">
            <?php else: ?>
            <svg class="header__mark" viewBox="0 0 40 26" aria-hidden="true" focusable="false">
                <path d="M0 0C6 0 11 1 20 6C29 1 34 0 40 0C34 5 27 13 20 26C13 13 6 5 0 0Z"/>
            </svg>
            <?php endif; ?>
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
