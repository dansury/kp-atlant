<?php
/**
 * SPA entry point.
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));

// Ключ кэша сборки: время И размер файлов (модуль 066) — страница, собранная,
// пока деплой дописывал app.js, не делит адрес с целым файлом
require_once ROOT . '/lib/app_build.php';
$assetVer = AppBuild::stamp(__DIR__);

// Логотипы, загруженные через «Настройки → Логотипы» (модуль 021). Берём
// только `Branding` — без bootstrap: оболочке страницы не нужны ни база, ни
// проверка обновлений, а знак нужен до первого запроса к API.
require_once ROOT . '/lib/branding.php';
$brandVer  = Branding::stamp();
$logoKind  = Branding::headerKind();

/**
 * Виджет чата — из настроек, а не зашитый в страницу (issue #60).
 *
 * Его можно выключить галочкой и заменить кодом любого другого сервиса.
 * Оболочка по-прежнему обходится без базы, если та недоступна: не открылась —
 * печатается встроенный код, ровно тот, что стоял здесь раньше.
 */
require_once ROOT . '/lib/settings.php';
$supportWidget = Settings::SUPPORT_WIDGET_DEFAULT;
try {
    require_once ROOT . '/lib/db.php';
    require_once ROOT . '/lib/crypt.php';
    $widgetCfg = file_exists(ROOT . '/config.php') ? (array)(require ROOT . '/config.php') : [];
    Db::init($widgetCfg['DB_PATH'] ?? ROOT . '/data/kp.db');
    Settings::boot($widgetCfg);
    $supportWidget = (int)Settings::get('SUPPORT_WIDGET', 1) === 1
        ? (string)Settings::get('SUPPORT_WIDGET_CODE', Settings::SUPPORT_WIDGET_DEFAULT) : '';
} catch (Throwable) { /* база не открылась — страница всё равно должна открыться */ }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <!-- minimum-scale=1: телефон не остаётся уменьшенным после клавиатуры
         закрытого окна (issue #125); приблизить по-прежнему можно -->
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, viewport-fit=cover">
    <!-- Тема выбирается явно (issue #123): светлая — «only light», чтобы
         «авто-тёмный режим» Chrome не перекрашивал поля (issue #105); тёмная —
         своя палитра. Ставится ДО стилей — без белой вспышки на тёмном телефоне -->
    <meta name="color-scheme" content="only light" id="metaScheme">
    <script>
        (function () {
            var mode = 'light';
            try { mode = localStorage.getItem('theme') || 'light'; } catch (e) { /* приватное окно */ }
            var dark = mode === 'dark' || (mode === 'auto' && window.matchMedia
                && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            if (dark) document.getElementById('metaScheme').content = 'dark';
        })();
    </script>
    <title>Atlant Armour — КП</title>
    <!-- Installable on a phone: manifest + icons + standalone chrome (module 007) -->
    <meta name="theme-color" content="#ffffff" id="metaThemeColor">
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

    <!-- Виджет чата: код из «Настройки → Обратная связь» (issue #60) -->
<?php if (trim($supportWidget) !== ''): ?>
    <?= $supportWidget ?>
<?php endif; ?>

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
            <?php if ($logoKind === 'kp'): ?>
            <img class="header__brand header__brand--wide" src="/api/branding.php?kind=kp&amp;v=<?= $brandVer ?>" alt="Atlant Armour">
            <span class="header__sub">КП</span>
            <?php else: ?>
            <img class="header__brand" alt="" aria-hidden="true"
                 src="<?= $logoKind !== '' ? '/api/branding.php?kind=' . $logoKind . '&amp;v=' . $brandVer : '/assets/icons/icon-192.png' ?>">
            Atlant Armour <span class="header__sub">КП</span>
            <?php endif; ?>
        </div>
        <nav class="header__nav" id="nav"></nav>
        <div class="header__user" id="userBlock"></div>
    </header>

    <main class="main" id="app">
        <div class="loading">Загрузка...</div>
    </main>
    <!-- Сборка интерфейса (issue #146): новая с прошлого раза — так и сказать,
         пока она грузится; App.init() покажет «Интерфейс обновлён» -->
    <script>
        window.kpBuild = '<?= $assetVer ?>';
        try {
            var kpWas = localStorage.getItem('kp.build');
            if (kpWas && kpWas !== window.kpBuild) {
                var kpLoading = document.querySelector('#app > .loading');
                if (kpLoading) kpLoading.textContent = 'Загружаем обновление интерфейса…';
            }
        } catch (e) { /* приватное окно — просто «Загрузка...» */ }
    </script>

    <div class="toast-container" id="toasts"></div>

    <!-- Сторож загрузки (модуль 066): «Загрузка...» не бывает вечной. app.js не
         загрузился, пришёл обрезанным или упал до старта — один раз на вкладку
         чистим кэши этого устройства и перезагружаемся; не помогло — причина и
         кнопка. ES5: он работает там, где сам app.js не разобрался. -->
    <script>
        (function () {
            var KEY = 'kp.bootRetry';
            var ASSETS = ['/assets/js/app.js?v=<?= $assetVer ?>', '/assets/css/app.css?v=<?= $assetVer ?>'];
            var done = false;

            // Кэши service worker и оба файла мимо кэша браузера. Воркер не
            // снимаем: вместе с ним ушла бы подписка на push этого устройства
            function purge() {
                var jobs = [];
                try {
                    if (window.caches && caches.keys) {
                        jobs.push(caches.keys().then(function (ks) {
                            return Promise.all(ks.map(function (k) { return caches.delete(k); }));
                        }));
                    }
                    if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {
                        jobs.push(navigator.serviceWorker.getRegistrations().then(function (rs) {
                            return Promise.all(rs.map(function (r) { return r.update(); }));
                        }));
                    }
                    if (window.fetch) ASSETS.forEach(function (u) { jobs.push(fetch(u, {cache: 'reload'})); });
                } catch (e) { /* чистим, что можем */ }
                if (!window.Promise) return {then: function (f) { f(); }};
                return Promise.all(jobs.map(function (p) { return p.then(null, function () {}); }));
            }

            function show(reason) {
                var app = document.getElementById('app');
                if (!app) return;
                app.innerHTML = '<div class="card" style="max-width:520px;margin:40px auto">'
                    + '<div class="card__title">Интерфейс не загрузился</div>'
                    + '<p class="muted" data-reason></p>'
                    + '<p>Нажмите «Перезагрузить» — кэш этого устройства будет очищен.</p>'
                    + '<button type="button" class="btn btn--primary">Перезагрузить</button></div>';
                app.querySelector('[data-reason]').textContent = reason;
                app.querySelector('button').onclick = function () {
                    try { sessionStorage.removeItem(KEY); } catch (e) { /* */ }
                    this.disabled = true;
                    purge().then(function () { location.reload(); });
                };
            }

            window.kpBootFail = function (reason) {
                if (done || window.kpBooted) return;
                done = true;
                reason = String(reason || 'неизвестная ошибка');
                var retried = true;   // без хранилища — без автоповтора, чтобы не зациклиться
                try { retried = !!sessionStorage.getItem(KEY); } catch (e) { /* */ }
                if (retried) { show(reason); return; }
                try { sessionStorage.setItem(KEY, String(Date.now())); } catch (e) { /* */ }
                var app = document.getElementById('app');
                if (app) app.innerHTML = '<div class="loading">Обновляем интерфейс...</div>';
                purge().then(function () { location.reload(); });
            };

            // Ошибка в самом app.js до старта: обрезанный файл, сбой на верхнем уровне
            window.addEventListener('error', function (e) {
                if (!window.kpBooted && /\/assets\/js\/app\.js/.test(e.filename || '')) window.kpBootFail(e.message);
            });
        })();
    </script>
    <script src="/assets/js/app.js?v=<?= $assetVer ?>"
            onerror="kpBootFail('файл интерфейса не загрузился')"
            onload="if (typeof App === 'undefined') kpBootFail('файл интерфейса пришёл повреждённым');
                    else setTimeout(function () { if (!window.kpBooted) kpBootFail('интерфейс не запустился'); }, 4000)"></script>
</body>
</html>
