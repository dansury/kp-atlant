<?php
/**
 * Модуль 043 (issue #60) — на выбрасываемой базе и без сети:
 *
 *   — повторный вход, пока предыдущая сессия ещё не истекла, уведомляет
 *     ДРУГИХ администраторов и не трогает самого вошедшего;
 *   — «Сбросить вход» делает недействительной текущую сессию менеджера на
 *     currentManager(), а сессия, заведённая до этой миграции, не рвётся;
 *   — звук уведомления свой у каждого менеджера, пусто — общий из настроек;
 *   — app.js: фото в подборе открыты сразу, кнопка «Сбросить вход» есть.
 *
 * Запуск:  php tests/module_043.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-043-' . getmypid() . '.db';
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
require_once ROOT . '/lib/auth.php';
require_once ROOT . '/lib/notification_sound.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');

$admin1 = Db::insert('managers', ['login' => 'admin1', 'name' => 'Админ Первый',
                                  'password_hash' => Auth::hashPassword('secret'), 'is_admin' => 1]);
$admin2 = Db::insert('managers', ['login' => 'admin2', 'name' => 'Админ Второй',
                                  'password_hash' => Auth::hashPassword('secret'), 'is_admin' => 1]);
$mgr    = Db::insert('managers', ['login' => 'yana', 'name' => 'Яна',
                                  'password_hash' => Auth::hashPassword('secret'), 'is_admin' => 0]);

// =====================================================================  1

echo "\n== 1. Повторный вход уведомляет ДРУГИХ администраторов, не самого вошедшего ==\n";

$_SESSION = [];
$first = Auth::login('yana', 'secret');
ok('первый вход проходит', $first !== null && (int)$first['id'] === $mgr);
$afterFirst = Db::all("SELECT * FROM notifications WHERE type='system' AND title LIKE 'Новый вход%'");
ok('первый вход (предыдущей сессии не было) никого не уведомляет', count($afterFirst) === 0, (string)count($afterFirst));

// Второй вход тем же менеджером — предыдущая сессия (только что) ещё активна
$_SESSION = [];
$second = Auth::login('yana', 'secret');
ok('второй вход тоже проходит', $second !== null);
$notifs = Db::all("SELECT manager_id, title, url FROM notifications WHERE type='system' AND title LIKE 'Новый вход%'");
$notifiedAdmins = array_map(fn($n) => (int)$n['manager_id'], $notifs);
sort($notifiedAdmins);
ok('уведомлены оба админа', $notifiedAdmins === [$admin1, $admin2], json_encode($notifiedAdmins));
ok('сама Яна уведомление не получает', !in_array($mgr, $notifiedAdmins, true));
ok('ссылка ведёт на менеджеров', $notifs !== [] && $notifs[0]['url'] === '/#settings/managers');

// Вход администратора не пытается уведомить самого себя
Db::q("DELETE FROM notifications");
$_SESSION = [];
Auth::login('admin1', 'secret');
$_SESSION = [];
Auth::login('admin1', 'secret');
$selfNotifs = Db::all("SELECT manager_id FROM notifications WHERE type='system' AND title LIKE 'Новый вход%'");
ok('повторный вход admin1 уведомляет только admin2', array_map(fn($n) => (int)$n['manager_id'], $selfNotifs) === [$admin2]);

// =====================================================================  2

echo "\n== 2. «Сбросить вход»: текущая сессия менеджера перестаёт годиться ==\n";

$_SESSION = [];
$login = Auth::login('yana', 'secret');
ok('вход для проверки сброса прошёл', $login !== null);
$cur = currentManager();
ok('currentManager узнаёт вошедшего', $cur !== null && (int)$cur['id'] === $mgr);

Auth::kickSession($mgr);
$curAfterKick = currentManager();
ok('после «Сбросить вход» сессия недействительна', $curAfterKick === null);

$_SESSION = [];
$relogin = Auth::login('yana', 'secret');
$curAfterRelogin = currentManager();
ok('новый вход восстанавливает доступ', $curAfterRelogin !== null && (int)$curAfterRelogin['id'] === $mgr);

// Сессия без session_epoch (как до этой миграции) не рвётся сама
$_SESSION = ['manager_id' => $mgr];   // ни разу не проходила через Auth::login() этой версии
$legacy = currentManager();
ok('сессия без session_epoch (старый деплой) не разлогинивается сама', $legacy !== null);
ok('и после этого backfill\'ится', array_key_exists('session_epoch', $_SESSION));

// =====================================================================  3

echo "\n== 3. Звук уведомления — свой у каждого менеджера ==\n";

Settings::set('MAIL_SOUND', 'company-default.mp3');
Settings::set('MAIL_SOUND_VOLUME', '55');

$snd0 = NotificationSound::forManager($mgr);
ok('без своего звука — общий из настроек', $snd0['file'] === 'company-default.mp3' && $snd0['volume'] === 55,
   json_encode($snd0));

NotificationSound::save($mgr, 'my-sound.mp3', 80);
$snd1 = NotificationSound::forManager($mgr);
ok('свой звук и громкость сохранились', $snd1['own_file'] === 'my-sound.mp3' && $snd1['file'] === 'my-sound.mp3'
   && $snd1['own_volume'] === 80 && $snd1['volume'] === 80, json_encode($snd1));

$snd0b = NotificationSound::forManager($admin1);
ok('другой менеджер своего звука не унаследовал', $snd0b['own_file'] === '' && $snd0b['file'] === 'company-default.mp3');

NotificationSound::save($mgr, '', null);
$snd2 = NotificationSound::forManager($mgr);
ok('пустой файл возвращает к общему звуку', $snd2['own_file'] === '' && $snd2['file'] === 'company-default.mp3',
   json_encode($snd2));

// =====================================================================  4

echo "\n== 4. app.js: фото открыты сразу, «Сбросить вход» в панели менеджеров ==\n";

$js = (string)file_get_contents(ROOT . '/public/assets/js/app.js');
ok('коробка фото больше не hidden безусловно',
   (bool)preg_match('/data-match-photos \$\{i\.id \? \'\' : \'hidden\'\}/', $js));
ok('matchRowExtra запускает автозагрузку фото для сохранённых строк',
   (bool)preg_match('/if \(i\.id\) this\.autoLoadMatchPhotos\(i\.id\)/', $js));
ok('autoLoadMatchPhotos существует', str_contains($js, 'async autoLoadMatchPhotos(itemId)'));
ok('toggleMatchPhotos и автозагрузка делят одну разметку',
   (bool)preg_match('/renderMatchPhotoBox\(box, itemId, d\)/', $js) &&
   substr_count($js, 'renderMatchPhotoBox(box, itemId, d)') >= 2);
ok('кнопка «Сбросить вход» в форме менеджера', str_contains($js, 'App.kickManagerSession(${id})'));
ok('kickManagerSession дергает manager_kick_session', str_contains($js, 'admin.php?action=manager_kick_session'));
ok('«Моя подпись» отдаёт свой звук уведомлений', str_contains($js, "settings.php?action=notification_sound"));
ok('saveNotificationSound существует', str_contains($js, 'async saveNotificationSound(btn)'));

$adminApi = (string)file_get_contents(ROOT . '/public/api/admin.php');
ok('admin.php несёт manager_kick_session', str_contains($adminApi, "case 'manager_kick_session':"));

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
