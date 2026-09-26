<?php
/**
 * Модуль 066: интерфейс загружается всегда.
 *
 *   — деплой заменяет файл целиком (рядом и `rename`), неизменённый не трогает;
 *   — ключ кэша сборки — время И размер файлов;
 *   — service worker берёт код из сети и не хранит ошибок;
 *   — сторож загрузки в index.php и старт без вечной «Загрузка...»;
 *   — проверка обновлений — в фоновом опросе, а не в каждом запросе;
 *   — файлы обращений — в своей ветке-сироте, на сайт не попадают.
 *
 * Запуск:  php tests/module_066.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-066-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);

$savedConfig = $hadConfig ? file_get_contents($configPath) : null;
$existing = $hadConfig ? (array)(require $configPath) : [];
$effective = ['DB_PATH' => $tmpDb] + $existing;
if ($effective['DB_PATH'] !== $tmpDb) {   // belt and braces: never run on anything else
    fwrite(STDERR, "tests: refusing to run against {$effective['DB_PATH']}\n");
    exit(2);
}
file_put_contents($configPath, "<?php return " . var_export($effective, true) . ";");
register_shutdown_function(function () use ($configPath, $savedConfig, $tmpDb) {
    if ($savedConfig === null) @unlink($configPath); else file_put_contents($configPath, $savedConfig);
    foreach ([$tmpDb, $tmpDb . '-wal', $tmpDb . '-shm'] as $f) @unlink($f);
});

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once ROOT . '/lib/app_build.php';
require_once ROOT . '/lib/outbox.php';
require_once ROOT . '/lib/support.php';
require __DIR__ . '/pull_functions.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
function put(string $path, string $body): void {
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $body);
}
$base = sys_get_temp_dir() . '/kp-066-' . getmypid();
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($base)));

// ===================================================================== 1
echo "1. Деплой: файл заменяется целиком, неизменённый не трогается\n";
$src = "$base/repo";
$dst = "$base/site";
put("$src/public/assets/js/app.js", str_repeat('new code; ', 5000));
put("$dst/public/assets/js/app.js", 'old code');
put("$src/lib/same.php", '<?php // same');
put("$dst/lib/same.php", '<?php // same');
touch("$dst/lib/same.php", strtotime('2020-01-01 00:00:00'));
put("$dst/data/kp.db", 'база');
put("$src/data/.gitkeep", '');
put("$dst/real.txt", 'old');
symlink("$dst/real.txt", "$dst/linked.txt");
put("$src/linked.txt", 'via link');
$inodeBefore = fileinode("$dst/public/assets/js/app.js");

$copied = 0;
copyTree($src, $dst, ALWAYS_KEEP, $copied);
clearstatcache();
ok('изменённый файл обновлён', file_get_contents("$dst/public/assets/js/app.js") === str_repeat('new code; ', 5000));
ok('и заменён переименованием, а не переписан на месте', fileinode("$dst/public/assets/js/app.js") !== $inodeBefore);
ok('временных файлов рядом не осталось', !glob("$dst/public/assets/js/.*pull-*") && !glob("$dst/.*pull-*"));
ok('неизменённый файл не тронут — время прежнее', filemtime("$dst/lib/same.php") === strtotime('2020-01-01 00:00:00'));
ok('и в счёт скопированных не попал', $copied === 2, "copied=$copied");
ok('ALWAYS_KEEP соблюдён', file_get_contents("$dst/data/kp.db") === 'база');
ok('ссылка оператора осталась ссылкой, файл записан через неё',
   is_link("$dst/linked.txt") && file_get_contents("$dst/real.txt") === 'via link');

// ===================================================================== 2
echo "\n2. Ключ кэша сборки — время и размер\n";
$pub = "$base/public";
put("$pub/assets/js/app.js", 'const App = {};');
put("$pub/assets/css/app.css", 'body{}');
$t = strtotime('2026-09-26 17:42:18');
touch("$pub/assets/js/app.js", $t);
touch("$pub/assets/css/app.css", $t);
$s1 = AppBuild::stamp($pub);
put("$pub/assets/js/app.js", 'const Ap');           // полфайла, та же секунда
touch("$pub/assets/js/app.js", $t);
$s2 = AppBuild::stamp($pub);
put("$pub/assets/js/app.js", 'const App = {};');
touch("$pub/assets/js/app.js", $t);
ok('обрезанный файл в ту же секунду — другой ключ', $s1 !== $s2, "$s1 / $s2");
ok('тот же файл — тот же ключ', AppBuild::stamp($pub) === $s1);
ok('ключ короткий и без пробелов', (bool)preg_match('/^[0-9a-f]{12}$/', $s1), $s1);
$index = file_get_contents(ROOT . '/public/index.php');
ok('index.php берёт ключ из AppBuild', str_contains($index, 'AppBuild::stamp(__DIR__)')
   && !str_contains($index, "filemtime(__DIR__ . '/assets/js/app.js')"));

// ===================================================================== 3
echo "\n3. Service worker: код — из сети, ошибки не хранятся\n";
$sw = file_get_contents(ROOT . '/public/sw.js');
ok('версия кэша поднята — старый кэш с битым app.js удаляется', str_contains($sw, "const VERSION = 'atlant-kp-shell-v4';"));
ok('скрипты и стили — сначала сеть', str_contains($sw, '/\.(?:js|css)$/i.test(url.pathname)')
   && str_contains($sw, 'e.respondWith(fetch(req).then((res) => keep(req, res))'));
ok('кэш кода — только когда сети нет', str_contains($sw, '.catch(() => caches.match(req).then((hit) => hit || Response.error()))'));
ok('хранится только целый 200 своего origin', str_contains($sw, "res.status === 200 && res.type === 'basic'"));
ok('старой записи «любой ответ в кэш» больше нет', !preg_match('/const copy = res\.clone\(\);\s*caches\.open\(VERSION\)\.then\(\(c\) => c\.put\(req, copy\)\)\.catch\(\(\) => \{\}\);\s*return res;\s*\}\)\.catch\(\(\) => hit\)/', $sw));

// ===================================================================== 4
echo "\n4. Сторож загрузки и старт без вечной «Загрузка...»\n";
ok('сторож стоит ДО app.js', strpos($index, 'window.kpBootFail') !== false
   && strpos($index, 'window.kpBootFail') < strpos($index, '<script src="/assets/js/app.js'));
ok('обрезанный файл замечен: onload без App', str_contains($index, "onload=\"if (typeof App === 'undefined') kpBootFail("));
ok('незагрузившийся файл замечен: onerror', str_contains($index, "onerror=\"kpBootFail('файл интерфейса не загрузился')\""));
ok('ошибка в самом app.js до старта замечена', str_contains($index, "/\\/assets\\/js\\/app\\.js/.test(e.filename || '')"));
ok('повтор — один раз на вкладку', str_contains($index, "var KEY = 'kp.bootRetry';")
   && str_contains($index, 'sessionStorage.setItem(KEY'));
ok('чистит кэши и берёт файлы мимо кэша браузера', str_contains($index, 'caches.delete(k)')
   && str_contains($index, "fetch(u, {cache: 'reload'})"));
ok('воркер не снимается — подписка на push живёт', !str_contains($index, 'unregister('));
ok('второй сбой — причина и кнопка', str_contains($index, 'Интерфейс не загрузился') && str_contains($index, 'Перезагрузить'));

$js = file_get_contents(ROOT . '/public/assets/js/app.js');
$init = substr($js, strpos($js, '    async init() {'), 4000);
ok('init отзывает сторожа первым делом', str_contains($init, "window.kpBooted = true;\n        try { sessionStorage.removeItem('kp.bootRetry'); }"));
ok('упавшая обвязка не останавливает старт', str_contains($init, "try { this[step](); } catch (err) { console.error(step, err); }"));
ok('форма входа — только на 401', str_contains($init, 'if (err.status !== 401) return this.renderBootError(err);'));
ok('«кто я» ждёт не дольше 45 с и говорит о медленном сервере',
   str_contains($js, "this.api('auth.php?action=me', {timeout: 45000})") && str_contains($js, 'Сервер отвечает медленно'));
ok('api() умеет таймаут', str_contains($js, 'signal: ctl ? ctl.signal : undefined') && str_contains($js, 'err.timeout = true;'));
ok('ошибка старта — причина и «Повторить»', (bool)preg_match('/renderBootError\(err\) \{.*?Интерфейс не загрузился.*?Повторить/s', $js));
ok('стиль блока ошибки есть', str_contains(file_get_contents(ROOT . '/public/assets/css/app.css'), '.boot-error {'));

// ===================================================================== 5
echo "\n5. Проверка обновлений — в фоновом опросе, после requireAuth()\n";
$boot = file_get_contents(ROOT . '/lib/bootstrap.php');
ok('bootstrap больше не зовёт AutoPull::run() в каждом запросе', substr_count($boot, 'AutoPull::run(') === 1
   && (bool)preg_match('/function autoPullCheck\(\): void \{[^}]*AutoPull::run\(/s', $boot));
ok('autoPullCheck() объявлена', function_exists('autoPullCheck'));
$notif = file_get_contents(ROOT . '/public/api/notifications.php');
$poll = substr($notif, strpos($notif, "case 'poll':"), 600);
ok('опрос зовёт проверку после requireAuth()', strpos($poll, 'requireAuth()') !== false
   && strpos($poll, 'autoPullCheck()') > strpos($poll, 'requireAuth()'));
ok('описание настройки больше не обещает «каждое открытие страницы»',
   !str_contains(Settings::SPEC['AUTOPULL_ENABLED'][5], 'каждое открытие страницы'));

// ===================================================================== 6
echo "\n6. Файлы обращений — в своей ветке-сироте\n";
ok('ветка по умолчанию — support-assets', Settings::get('SUPPORT_ASSETS_BRANCH') === 'support-assets');
ok('на сайте support/ закрыт', str_contains(file_get_contents(ROOT . '/.htaccess'), 'RewriteRule ^support/ - [F,L]'));

// Миграция v56: пустое значение, сохранённое «Сохранить всё», снимается
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('cfg.SUPPORT_ASSETS_BRANCH', '')");
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '55')");
runMigrations();
Settings::boot([]);
ok('миграция снимает пустое значение — действует новое умолчание',
   Db::val("SELECT COUNT(*) FROM settings WHERE key='cfg.SUPPORT_ASSETS_BRANCH'") == 0
   && Settings::get('SUPPORT_ASSETS_BRANCH') === 'support-assets');
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('cfg.SUPPORT_ASSETS_BRANCH', 'my-files')");
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '55')");
runMigrations();
ok('заданная оператором ветка остаётся', Db::val("SELECT value FROM settings WHERE key='cfg.SUPPORT_ASSETS_BRANCH'") === 'my-files');
Db::q("DELETE FROM settings WHERE key='cfg.SUPPORT_ASSETS_BRANCH'");
Settings::boot([]);

// GitHub — заглушка: записывает вызовы, отвечает по сценарию
$calls = [];
$script = [];
Support::$transport = function (string $method, string $url, ?array $body) use (&$calls, &$script) {
    $path = preg_replace('#^https://api\.github\.com/repos/#', '', $url);
    $calls[] = [$method, $path, $body];
    foreach ($script as $pattern => $answer) {
        if (preg_match($pattern, "$method $path")) return [$answer[0], json_encode($answer[1], JSON_UNESCAPED_UNICODE)];
    }
    return [500, '{"message":"unexpected"}'];
};

$script = ['#^GET owner/repo/branches/have-it$#' => [200, ['name' => 'have-it']]];
$calls = [];
ok('ветка есть — берётся как есть', Support::ensureBranch('owner/repo', 'have-it') === 'have-it');
ok('и ничего не создаётся', count($calls) === 1 && $calls[0][0] === 'GET');

$script = [
    '#^GET owner/repo/branches/race$#' => [404, ['message' => 'Branch not found']],
    '#^POST owner/repo/git/trees$#'    => [201, ['sha' => 't1']],
    '#^POST owner/repo/git/commits$#'  => [201, ['sha' => 'c1']],
    '#^POST owner/repo/git/refs$#'     => [422, ['message' => 'Reference already exists']],
];
ok('ветку успели создать соседним запросом — это не ошибка', Support::ensureBranch('owner/repo', 'race') === 'race');

$script = [
    '#^GET owner/repo/branches/denied$#' => [404, ['message' => 'Branch not found']],
    '#^POST owner/repo/git/trees$#'      => [403, ['message' => 'Resource not accessible']],
    '#^GET owner/repo$#'                 => [200, ['default_branch' => 'main']],
];
ok('создать нельзя — файл едет в ветку по умолчанию', Support::ensureBranch('owner/repo', 'denied') === 'main');

// Одобрение обращения с картинкой: ветка создаётся сиротой, файл — в неё
Settings::set('SUPPORT_REPO', 'dansury/kp-atlant');
Settings::set('SUPPORT_TOKEN', 'ghp_test');
$mid = Db::insert('managers', ['login' => 'm066', 'name' => 'Менеджер', 'is_admin' => 1, 'password_hash' => 'x']);
$tmp = sys_get_temp_dir() . '/kp-066-up-' . getmypid();
file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$up = Outbox::accept(['name' => 'экран.png', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], $mid);
$sub = Support::submit($mid, ['kind' => 'bug', 'title' => 'Висит загрузка', 'body' => 'Не грузится'], [$up['name']]);
$script = [
    '#^GET dansury/kp-atlant/branches/support-assets$#' => [404, ['message' => 'Branch not found']],
    '#^POST dansury/kp-atlant/git/trees$#'   => [201, ['sha' => 'tree66']],
    '#^POST dansury/kp-atlant/git/commits$#' => [201, ['sha' => 'commit66']],
    '#^POST dansury/kp-atlant/git/refs$#'    => [201, ['ref' => 'refs/heads/support-assets']],
    '#^PUT dansury/kp-atlant/contents/#'     => [201, ['content' => [
        'html_url' => 'https://github.com/dansury/kp-atlant/blob/support-assets/support/uploads/x.png']]],
    '#^POST dansury/kp-atlant/issues$#'      => [201, ['number' => 140, 'html_url' => 'https://github.com/dansury/kp-atlant/issues/140']],
];
$calls = [];
Support::approve((int)$sub['id'], $mid);
$byKey = [];
foreach ($calls as [$m, $p, $b]) $byKey[$m . ' ' . preg_replace('#/contents/.*$#', '/contents/…', $p)] = $b;
$order = array_map(fn($c) => $c[0] . ' ' . preg_replace('#/contents/.*$#', '/contents/…', $c[1]), $calls);
ok('порядок: ветка → дерево → коммит → ссылка → файл → issue', $order === [
    'GET dansury/kp-atlant/branches/support-assets', 'POST dansury/kp-atlant/git/trees',
    'POST dansury/kp-atlant/git/commits', 'POST dansury/kp-atlant/git/refs',
    'PUT dansury/kp-atlant/contents/…', 'POST dansury/kp-atlant/issues'], implode(' | ', $order));
ok('коммит ветки — без родителей (сирота)', ($byKey['POST dansury/kp-atlant/git/commits']['parents'] ?? null) === []
   && ($byKey['POST dansury/kp-atlant/git/commits']['tree'] ?? '') === 'tree66');
ok('ссылка ветки — на этот коммит', ($byKey['POST dansury/kp-atlant/git/refs'] ?? []) === ['ref' => 'refs/heads/support-assets', 'sha' => 'commit66']);
ok('файл уходит в support-assets', ($byKey['PUT dansury/kp-atlant/contents/…']['branch'] ?? '') === 'support-assets');
ok('issue заведён, ссылка на файл — в ветке файлов', (int)Support::get((int)$sub['id'])['issue_number'] === 140
   && str_contains((string)$byKey['POST dansury/kp-atlant/issues']['body'], 'blob/support-assets/'));
Support::$transport = null;

echo "\n" . ($fail ? "$fail FAILED\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
