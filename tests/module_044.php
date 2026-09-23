<?php
/**
 * Модуль 044 (issue #60) — на выбрасываемой базе и без сети:
 *
 *   — фотографии не отстают от товара: выбор переживает сохранение таблицы,
 *     а смена товара снимает его и со строки, и с неотправленного КП;
 *   — «количество фото — на все позиции» проставляет первые N каждой строке;
 *   — у каждой настройки есть пояснение, а у каждого секрета — ссылка «где взять»;
 *   — виджет чата берётся из настроек и выключается;
 *   — звук уведомления — свой у каждого менеджера;
 *   — вход живёт долго, пишется в журнал входов и обнуляется администратором;
 *   — отложенное письмо дожидается своего времени и не теряет вложений;
 *   — документ: пустая строка перед заголовком, шапка справа, фото с отступом.
 *
 * Запуск:  php tests/module_044.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-044-' . getmypid() . '.db';
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
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/terms.php';
require_once ROOT . '/lib/outbox.php';
require_once ROOT . '/lib/mail_schedule.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

// ---- общая подготовка --------------------------------------------------------
$managerId = Db::insert('managers', ['login' => 'yana', 'password_hash' => Auth::hashPassword('secret'),
                                     'name' => 'Яна', 'is_admin' => 0]);
$adminId = Db::insert('managers', ['login' => 'boss', 'password_hash' => Auth::hashPassword('secret'),
                                   'name' => 'Админ', 'is_admin' => 1]);
$cpId = Db::insert('counterparties', ['name' => 'ООО «Покупатель»']);
$requestId = Db::insert('requests', ['source' => 'manual', 'raw_text' => 'нужны шлемы',
                                     'counterparty_id' => $cpId, 'manager_id' => $managerId]);

// Два товара с разными фотографиями: подменять один другим и есть весь сюжет
$shots = [];
foreach ([['p-1', 'Шлем «Альфа»', 3], ['p-2', 'Шлем «Бета»', 2]] as [$msId, $name, $count]) {
    $urls = [];
    for ($i = 0; $i < $count; $i++) $urls[] = "https://example.test/$msId-$i.jpg";
    Db::insert('products_cache', ['moysklad_id' => $msId, 'name' => $name, 'unit' => 'шт.',
                                  'price' => 1000, 'stock' => 5, 'image_urls' => json_encode($urls)]);
    $shots[$msId] = $count;
}

// =====================================================================  1
echo "\n1. Фотографии не отстают от товара\n";

$items = RequestItems::save($requestId, [
    ['raw_name' => 'шлем', 'product_name' => 'Шлем «Альфа»', 'moysklad_product_id' => 'p-1',
     'quantity' => 2, 'unit' => 'шт.', 'price' => 1000],
]);
$itemId = (int)$items[0]['id'];

// Менеджер отметил галочками две фотографии (так это делает `item_images_save`)
Db::update('request_items', ['selected_images' => json_encode(['url:0', 'url:1'])], 'id=?', [$itemId]);

// ...и сохранил таблицу. Поле `selected_images` форма не присылает вовсе —
// до этого модуля сохранение затирало выбор в NULL
$items = RequestItems::save($requestId, [
    ['id' => $itemId, 'raw_name' => 'шлем', 'product_name' => 'Шлем «Альфа»',
     'moysklad_product_id' => 'p-1', 'quantity' => 3, 'unit' => 'шт.', 'price' => 1000],
]);
ok('выбор фотографий переживает сохранение таблицы',
   json_decode((string)Db::val("SELECT selected_images FROM request_items WHERE id=?", [$itemId]), true)
     === ['url:0', 'url:1']);

// КП, собранное из этой строки, несёт тот же выбор
$proposalId = Db::insert('proposals', ['request_id' => $requestId, 'counterparty_id' => $cpId,
                                       'manager_id' => $managerId, 'status' => 'draft', 'number' => '2026-1']);
$propItemId = Db::insert('proposal_items', ['proposal_id' => $proposalId, 'request_item_id' => $itemId,
                                            'position' => 1, 'product_name' => 'Шлем «Альфа»',
                                            'moysklad_product_id' => 'p-1', 'quantity' => 3, 'price' => 1000,
                                            'selected_images' => json_encode(['url:0', 'url:1'])]);

// Менеджер поменял товар на строке — и фотографии прежнего больше не наши
RequestItems::save($requestId, [
    ['id' => $itemId, 'raw_name' => 'шлем', 'product_name' => 'Шлем «Бета»',
     'moysklad_product_id' => 'p-2', 'quantity' => 3, 'unit' => 'шт.', 'price' => 1000],
]);
ok('смена товара снимает выбор фотографий строки',
   Db::val("SELECT selected_images FROM request_items WHERE id=?", [$itemId]) === null);
ok('и снимает его с неотправленного КП',
   Db::val("SELECT selected_images FROM proposal_items WHERE id=?", [$propItemId]) === null);

// Отправленное КП не трогаем: оно уже у клиента, и документ не должен меняться
Db::update('proposal_items', ['selected_images' => json_encode(['url:0'])], 'id=?', [$propItemId]);
Db::update('proposals', ['status' => 'sent'], 'id=?', [$proposalId]);
RequestItems::forgetImages($itemId);
ok('отправленное КП остаётся таким, каким ушло',
   json_decode((string)Db::val("SELECT selected_images FROM proposal_items WHERE id=?", [$propItemId]), true) === ['url:0']);

// Выбор равнозначного варианта — тот же путь, и он тоже сбрасывает фотографии
Db::update('request_items', ['selected_images' => json_encode(['url:0'])], 'id=?', [$itemId]);
RequestItems::choose($requestId, $itemId, 'p-1');
ok('выбор равнозначного варианта тоже снимает фотографии прежнего',
   Db::val("SELECT selected_images FROM request_items WHERE id=?", [$itemId]) === null);

ok('полоса фотографий открыта сразу, а не за кнопкой',
   !str_contains(file_get_contents(ROOT . '/public/assets/js/app.js'), 'data-match-photos hidden'));
ok('миниатюры выстроены в очередь, а не грузятся разом',
   str_contains(file_get_contents(ROOT . '/public/assets/js/app.js'), 'chainPhotos(box)'));
ok('смена товара перечитывает полосу фотографий',
   str_contains(file_get_contents(ROOT . '/public/assets/js/app.js'), 'reloadMatchPhotos(row)'));

// =====================================================================  2
echo "\n2. «Количество фото — на все позиции»\n";

$touched = RequestItems::applyPhotoLimit($requestId, 1);
ok('предел проставлен каждой строке', $touched === 1);
ok('в выбор попала ПЕРВАЯ фотография',
   json_decode((string)Db::val("SELECT selected_images FROM request_items WHERE id=?", [$itemId]), true) === ['url:0']);

RequestItems::applyPhotoLimit($requestId, null);
ok('пустое значение возвращает «как в настройках»',
   Db::val("SELECT selected_images FROM request_items WHERE id=?", [$itemId]) === null);

RequestItems::applyPhotoLimit($requestId, 0);
ok('ноль — это решение «без фотографий», а не пустота',
   Db::val("SELECT selected_images FROM request_items WHERE id=?", [$itemId]) === '[]');

$c = Terms::remember($managerId, ['photos' => '2'], $cpId);
ok('предел запоминается вместе с остальными условиями', $c['photos'] === 2);
ok('и приезжает обратно следующим КП', Terms::conditions($managerId, $cpId)['photos'] === 2);
ok('пустая строка означает «как в настройках»', Terms::photoLimit('') === null);

// =====================================================================  3
echo "\n3. Каждая настройка объясняет себя\n";

$noHint = [];
$noLink = [];
foreach (Settings::SPEC as $key => $spec) {
    if (trim((string)($spec[5] ?? '')) === '') $noHint[] = $key;
    if (!empty($spec[3]) && !Settings::link($key)) $noLink[] = $key;
}
ok('у каждого ключа есть пояснение', !$noHint, implode(', ', $noHint));
ok('у каждого секрета есть ссылка «где взять»', !$noLink, implode(', ', $noLink));

$unknown = array_diff(array_keys(Settings::LINKS), array_keys(Settings::SPEC));
ok('ссылки не ведут к несуществующим ключам', !$unknown, implode(', ', $unknown));

$described = array_column(Settings::describe(), 'link', 'key');
ok('панель получает ссылку вместе с полем', ($described['GITHUB_TOKEN']['url'] ?? '') !== '');
ok('мастер настройки показывает ту же ссылку',
   str_contains(file_get_contents(ROOT . '/lib/setup_wizard.php'), "'link'   => Settings::link(\$key)"));
ok('оба экрана рисуют её одним и тем же кодом',
   substr_count(file_get_contents(ROOT . '/public/assets/js/app.js'), 'this.settingLink(') === 2);

// =====================================================================  4
echo "\n4. Виджет чата выключается и заменяется\n";

$index = file_get_contents(ROOT . '/public/index.php');
ok('идентификатор чужого аккаунта больше не зашит в страницу',
   !str_contains($index, '02391a2c-0104-46cd-9b04-87a680dfb320'));
ok('страница печатает код из настроек', str_contains($index, '$supportWidget'));
ok('встроенным кодом остаётся прежний виджет',
   str_contains(Settings::SUPPORT_WIDGET_DEFAULT, 'widget.replain.cc'));
Settings::set('SUPPORT_WIDGET', '0');
ok('галочка выключения есть и читается', (int)Settings::get('SUPPORT_WIDGET') === 0);
Settings::forget('SUPPORT_WIDGET');

// =====================================================================  5
echo "\n5. Звук уведомления — свой у каждого\n";

ok('у карточки менеджера есть свой звук', Db::hasColumn('managers', 'notify_sound'));
ok('и своя громкость', Db::hasColumn('managers', 'notify_volume'));
$settingsSrc = file_get_contents(ROOT . '/public/api/settings.php');
ok('интерфейс получает звук менеджера, а не общий',
   str_contains($settingsSrc, "\$mine['notify_sound']"));
ok('список звуков открыт не только администратору',
   str_contains(file_get_contents(ROOT . '/public/api/admin.php'), "    'sounds',"));

// =====================================================================  6
echo "\n6. Вход: долгая кука, журнал входов и обнуление\n";

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_USER_AGENT'] = 'Firefox';
$me = Auth::login('yana', 'secret');
ok('вход состоялся', ($me['name'] ?? '') === 'Яна');
ok('вход записан в журнал входов',
   (int)Db::val("SELECT COUNT(*) FROM manager_logins WHERE manager_id=?", [$managerId]) === 1);
ok('первый вход администратора не беспокоит',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='new_login'") === 0);

$_SERVER['REMOTE_ADDR'] = '198.51.100.3';
Auth::login('yana', 'secret');
ok('вход с нового адреса виден администратору',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='new_login' AND manager_id=?", [$adminId]) === 1);

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
Auth::login('yana', 'secret');
ok('знакомый адрес второй раз не беспокоит',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='new_login'") === 1);

$_SESSION['manager_id'] = $managerId;
$_SESSION['epoch'] = (int)Db::val("SELECT session_epoch FROM managers WHERE id=?", [$managerId]);
ok('сессия открывается', (currentManager()['id'] ?? 0) === $managerId);
Auth::resetSessions($managerId);
ok('после обнуления администратором она закрыта', currentManager() === null);

$GLOBALS['cfg']['SESSION_LIFETIME'] = 31536000;
ok('год жизни сессии принимается', sessionLifetime() === 31536000);
$GLOBALS['cfg']['SESSION_LIFETIME'] = 99999999;
ok('больше года не бывает', sessionLifetime() === 31536000);
$GLOBALS['cfg']['SESSION_LIFETIME'] = 5;
ok('опечатка не выкидывает всех', sessionLifetime() === 300);
$GLOBALS['cfg']['SESSION_LIFETIME'] = 86400;
ok('кука продлевается на каждом заходе',
   str_contains(file_get_contents(ROOT . '/lib/bootstrap.php'), 'renewSessionCookie('));
ok('сборщик мусора живёт столько же, сколько кука',
   str_contains(file_get_contents(ROOT . '/lib/bootstrap.php'), "ini_set('session.gc_maxlifetime'"));

// =====================================================================  7
echo "\n7. Отложенная отправка\n";

$letter = ['to' => 'client@example.test', 'subject' => 'КП', 'text' => 'Здравствуйте!',
           'files' => [['name' => 'aaaaaaaaaaaaaaaa__Счёт.pdf']]];
$when = date('Y-m-d H:i:s', time() + 3600);
$row = MailSchedule::add($letter, $managerId, $when);
ok('письмо встало в очередь', ($row['status'] ?? '') === 'pending' && $row['send_at'] === $when);
ok('и видно в списке отложенных', count(MailSchedule::pending($managerId)) === 1);
ok('чужих отложенных в списке нет', count(MailSchedule::pending($adminId)) === 0);
ok('его время ещё не пришло', MailSchedule::due() === []);

$thrown = '';
try { MailSchedule::add($letter, $managerId, date('Y-m-d H:i:s', time() - 7200)); }
catch (Throwable $e) { $thrown = $e->getMessage(); }
ok('прошедшее время не принимается', str_contains($thrown, 'прошло'), $thrown);

$thrown = '';
try { MailSchedule::add(['to' => '', 'text' => 'привет'], $managerId, $when); }
catch (Throwable $e) { $thrown = $e->getMessage(); }
ok('письмо без адреса не откладывается', $thrown !== '');

// Вложение отложенного письма переживает уборку папки исходящих
$dir = Outbox::dir($managerId);
$file = $dir . '/aaaaaaaaaaaaaaaa__Счёт.pdf';
file_put_contents($file, 'pdf');
$stale = $dir . '/bbbbbbbbbbbbbbbb__Старое.pdf';
file_put_contents($stale, 'pdf');
touch($file, time() - 5 * 86400);
touch($stale, time() - 5 * 86400);
Outbox::sweep($managerId);
ok('вложение отложенного письма не убирается', is_file($file));
ok('а забытое вложение — убирается', !is_file($stale));

$dueRow = Db::one("SELECT id FROM mail_scheduled WHERE manager_id=?", [$managerId]);
Db::update('mail_scheduled', ['send_at' => date('Y-m-d H:i:s', time() - 60)], 'id=?', [$dueRow['id']]);
ok('наступившее время поднимает письмо', count(MailSchedule::due()) === 1);

MailSchedule::cancel((int)$dueRow['id'], $managerId);
ok('отменённое письмо больше не уходит', MailSchedule::due() === []);

$presets = MailSchedule::presets(strtotime('2026-09-22 23:40:00'));   // вторник
$labels = array_column($presets, 'label');
ok('подсказка «завтра в 09:00» есть', in_array('Завтра в 09:00', $labels, true));
ok('и «в понедельник в 09:00» тоже', in_array('В понедельник в 09:00', $labels, true));
ok('«завтра» — это следующий день, а не сегодня',
   ($presets[1]['at'] ?? '') === '2026-09-23 09:00:00', $presets[1]['at'] ?? '');
ok('«понедельник» — ближайший понедельник',
   ($presets[2]['at'] ?? '') === '2026-09-28 09:00:00', $presets[2]['at'] ?? '');

ok('письмо уходит тем же кодом, что и по кнопке',
   str_contains(file_get_contents(ROOT . '/lib/mail_schedule.php'), 'MailCompose::send(')
   && str_contains(file_get_contents(ROOT . '/public/api/mail.php'), 'MailCompose::send('));
ok('крон отправляет отложенные',
   str_contains(file_get_contents(ROOT . '/cron/send_scheduled.php'), 'MailSchedule::run()'));
ok('и заход за почтой тоже — на случай одной записи в кроне',
   str_contains(file_get_contents(ROOT . '/cron/check_mail.php'), 'MailSchedule::run()'));

// =====================================================================  8
echo "\n8. Подбор сохраняется сам, а КП скачивается тем, что собрано\n";

$js = file_get_contents(ROOT . '/public/assets/js/app.js');
ok('кнопки «Сохранить» под подбором больше нет',
   !str_contains($js, 'App.saveMatchedItems(this)">Сохранить'));
ok('правки сохраняются сами', str_contains($js, 'autosaveMatch(host)'));
ok('автосохранение не перерисовывает таблицу под руками',
   str_contains($js, 'adoptItemIds(host, res.items || [])'));

// =====================================================================  9
echo "\n9. Документ: шапка, первая строка, отступы у фотографий\n";

$tpl = file_get_contents(ROOT . '/templates/kp.html');
ok('перед заголовком стоит пустая строка', str_contains($tpl, '<p class="title-gap">&nbsp;</p>'));
ok('фотографии не слипаются с текстом',
   str_contains($tpl, 'max-height: 240px; margin: 6px 0; padding: 4px;'));
ok('описание выровнено по левому краю', str_contains($tpl, '.card__desc { font-size: 10pt; text-align: left; }'));
ok('заголовок приложения — тоже', str_contains($tpl, '.appendix__title { font-size: 13pt; font-weight: bold; text-align: left; }'));

$docx = file_get_contents(ROOT . '/lib/docx.php');
ok('в Word шапка выровнена вправо',
   str_contains($docx, "'header'       => ['size' => 18, 'after' => 20, 'align' => 'right']"));
ok('а знак стоит слева', str_contains($docx, "// Знак слева, реквизиты поставщика — справа от него"));
ok('выравнивание из самого элемента доезжает до Word',
   str_contains($docx, "'text-align:' . \$side"));

echo "\n" . ($fail ? "ПРОВАЛЕНО: $fail\n" : "Все проверки прошли\n");
exit($fail ? 1 : 0);
