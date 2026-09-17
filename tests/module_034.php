<?php
/**
 * Мастер настройки, обратная связь и проверочное КП — на выбрасываемой базе и
 * без сети:
 *
 *   — жалоба менеджера сохраняется с файлами и будит АДМИНИСТРАТОРА, а не всех;
 *   — менеджер видит свои обращения, администратор — все;
 *   — тело issue несёт слова менеджера, экран, автора и картинку файлом;
 *   — без репозитория и токена обращение не уезжает и говорит, чего не хватает;
 *   — отклонение возвращается автору причиной, а не тишиной;
 *   — шаги мастера считаются по ФАКТУ: ящик есть, логотипа нет, каталог пуст;
 *   — мастер пишет в те же `Settings`, пустое поле секрета не стирает ключ,
 *     а чужой ключ шаг не принимает;
 *   — «пройти заново» обнуляет только отметку, но не настройки;
 *   — «палец вниз» заводит обращение и предлагает модели ДОРОЖЕ нынешней;
 *   — проверочный запрос помнится мастером и не считается письмом клиента.
 *
 * Запуск:  php tests/module_034.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-034-' . getmypid() . '.db';
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
require_once ROOT . '/lib/outbox.php';
require_once ROOT . '/lib/support.php';
require_once ROOT . '/lib/setup_wizard.php';
require_once ROOT . '/lib/llm.php';
require_once ROOT . '/lib/boards.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');

$adminId = Db::insert('managers', ['login' => 'admin', 'name' => 'Админ', 'is_admin' => 1,
                                   'email' => 'a@atlant-armour.ru', 'password_hash' => 'x']);
$managerId = Db::insert('managers', ['login' => 'manager', 'name' => 'Менеджер',
                                     'email' => 'm@atlant-armour.ru', 'password_hash' => 'x']);
$admin   = ['id' => $adminId, 'is_admin' => 1];
$manager = ['id' => $managerId, 'is_admin' => 0];

/** Файл «как из браузера»: Outbox переносит его в папку менеджера. */
$stage = function (int $who, string $name, string $bytes): array {
    $tmp = sys_get_temp_dir() . '/kp-up-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $bytes);
    return Outbox::accept(['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($bytes),
                           'error' => UPLOAD_ERR_OK], $who);
};
// 1×1 PNG — настоящая картинка, чтобы mime определялся, а не угадывался
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

// =====================================================================  1

echo "\n== 1. Схема модуля на месте ==\n";

$tables = array_column(Db::all("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
ok('таблица обращений заведена', in_array('support_tickets', $tables, true));
ok('таблица файлов обращения заведена', in_array('support_files', $tables, true));
$reqCols = array_column(Db::all("PRAGMA table_info(requests)"), 'name');
ok('у запроса есть отметка «проверочный»', in_array('is_trial', $reqCols, true));
ok('версия схемы поднята', (int)Db::val("SELECT value FROM settings WHERE key='schema_version'") >= 33);

// =====================================================================  2

echo "\n== 2. Жалоба менеджера: файлы, ревью, уведомление админу ==\n";

$shot = $stage($managerId, 'скриншот.png', $png);
$doc  = $stage($managerId, 'КП №5/2026.pdf', '%PDF-1.4 fake');
$sub = Support::submit($managerId, [
    'kind'  => 'bug',
    'title' => 'Не отправляется КП',
    'body'  => "Нажимаю «Отправить», крутится и ничего.\nПовторяется каждый раз.",
    'page'  => '#mail/company/12',
], [$shot['name'], $doc['name']]);

ok('обращение сохранено', $sub['id'] > 0);
ok('оба файла приняты', $sub['files'] === 2, 'файлов: ' . $sub['files']);
$ticket = Support::get((int)$sub['id']);
ok('обращение ждёт ревью', (string)$ticket['status'] === 'new');
ok('экран, с которого жаловались, сохранён', (string)$ticket['page'] === '#mail/company/12');
$stored = Db::one("SELECT path FROM support_files WHERE ticket_id=? ORDER BY id LIMIT 1", [(int)$sub['id']]);
ok('файлы лежат в storage, а не в репозитории',
   count($ticket['files']) === 2
   && str_starts_with((string)$stored['path'], 'storage/support/')
   && is_file(ROOT . '/' . $stored['path']));
ok('путь на диске в браузер не уезжает', !array_key_exists('path', $ticket['files'][0]));
ok('человеческое имя файла со слэшем не потерялось',
   (string)$ticket['files'][1]['filename'] === 'КП №5_2026.pdf', (string)$ticket['files'][1]['filename']);
ok('картинка распознана картинкой',
   Support::isImage((string)$ticket['files'][0]['mime'], (string)$ticket['files'][0]['filename']));

$notifAdmin = (int)Db::val("SELECT COUNT(*) FROM notifications WHERE manager_id=? AND type='support'", [$adminId]);
$notifMgr   = (int)Db::val("SELECT COUNT(*) FROM notifications WHERE manager_id=? AND type='support'", [$managerId]);
ok('о жалобе узнал администратор', $notifAdmin === 1);
ok('остальных не будили', $notifMgr === 0);
ok('уведомление ведёт на разбор обращений',
   (string)Db::val("SELECT url FROM notifications WHERE manager_id=? ORDER BY id DESC LIMIT 1", [$adminId])
   === '/#settings/support');

// Заголовок можно не писать — он берётся из первых слов
$short = Support::submit($managerId, ['kind' => 'idea', 'body' => 'Хочу кнопку «повторить письмо» на карточке']);
ok('обращение без заголовка берёт его из текста',
   str_starts_with((string)Support::get((int)$short['id'])['title'], 'Хочу кнопку'));
$empty = null;
try { Support::submit($managerId, ['kind' => 'bug']); } catch (Throwable $e) { $empty = $e->getMessage(); }
ok('пустое обращение не принимается', $empty !== null, (string)$empty);

// =====================================================================  3

echo "\n== 3. Кто что видит ==\n";

$other = Support::submit($adminId, ['kind' => 'question', 'title' => 'Вопрос админа']);
ok('администратор видит все обращения', count(Support::listFor($admin)) === 3);
ok('менеджер видит только свои', count(Support::listFor($manager)) === 2);
ok('чужое обращение менеджеру не показано',
   !in_array((int)$other['id'], array_map('intval', array_column(Support::listFor($manager), 'id')), true));
ok('фильтр по состоянию работает', count(Support::listFor($admin, 'approved')) === 0);
ok('счётчик ревью считает все новые', Support::pending() === 3);

// =====================================================================  4

echo "\n== 4. Тело issue: слова менеджера, а под ними — то, чего он не знает ==\n";

// Файл «уже уехал» — проверяем, как он печатается ссылкой
Db::update('support_files', ['remote_url' => 'https://raw.example/shot.png'],
           'id=?', [(int)$ticket['files'][0]['id']]);
$body = Support::issueBody(Support::get((int)$sub['id']) + ['manager_name' => 'Менеджер']);
ok('жалоба напечатана словами автора', str_contains($body, 'Нажимаю «Отправить»'));
ok('картинка вставлена картинкой', str_contains($body, '![скриншот.png](https://raw.example/shot.png)'));
ok('файл без ссылки не печатается сломанной ссылкой', !str_contains($body, '(КП №5_2026.pdf)'));
ok('автор назван', str_contains($body, 'Менеджер'));
ok('экран назван', str_contains($body, '#mail/company/12'));

$quality = Support::issueBody(['kind' => 'quality', 'rating' => 'down', 'model' => 'yandex:yandexgpt-lite',
                               'body' => 'Письмо получилось сухое', 'files' => []]);
ok('оценка качества печатается оценкой и моделью',
   str_contains($quality, '👎') && str_contains($quality, 'yandexgpt-lite'));

// =====================================================================  5

echo "\n== 5. В GitHub — только с ключами, и молча не падать ==\n";

Settings::set('SUPPORT_REPO', '');
Settings::set('SUPPORT_TOKEN', '');
Settings::set('GITHUB_TOKEN', '');
$why = '';
try { Support::approve((int)$sub['id'], $adminId); } catch (Throwable $e) { $why = $e->getMessage(); }
ok('без репозитория обращение не уезжает и говорит почему', str_contains($why, 'репозитори'), $why);

Settings::set('SUPPORT_REPO', 'dansury/kp-atlant');
$why = '';
try { Support::approve((int)$sub['id'], $adminId); } catch (Throwable $e) { $why = $e->getMessage(); }
ok('без токена — тоже, и называет нужное право', str_contains($why, 'Issues: Write'), $why);
ok('обращение осталось на ревью, а не «уехало»',
   (string)Support::get((int)$sub['id'])['status'] === 'new');

Settings::set('SUPPORT_TOKEN', 'ghp_support_token');
ok('свой токен поддержки берётся первым', Support::token() === 'ghp_support_token');
Settings::set('SUPPORT_TOKEN', '');
Settings::set('GITHUB_TOKEN', 'ghp_common_token');
ok('без своего берётся общий токен вики', Support::token() === 'ghp_common_token');

Support::decline((int)$short['id'], $adminId, 'Уже есть в «Повторить» на карточке');
$declined = Support::get((int)$short['id']);
ok('отклонение записано с причиной',
   (string)$declined['status'] === 'declined' && str_contains((string)$declined['review_note'], 'Повторить'));
ok('автор узнал об отказе',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE manager_id=? AND type='support'", [$managerId]) === 1);
ok('отклонённое больше не ждёт ревью', Support::pending() === 2);

// =====================================================================  6

echo "\n== 6. Шаги мастера считаются по факту ==\n";

$steps = fn(): array => array_column(SetupWizard::progress()['steps'], null, 'key');

$s = $steps();
ok('на пустой базе почта не настроена', $s['mail']['state']['status'] === 'todo');
ok('логотип не загружен', $s['branding']['state']['status'] === 'todo');
ok('МойСклад без токена — не пройден', $s['moysklad']['state']['status'] === 'todo');
ok('менеджер в базе есть — шаг закрыт', $s['managers']['state']['status'] === 'ok');
ok('сайт помечен необязательным', !empty($s['bitrix']['optional']));
ok('поля шага пришли из общего спека',
   ($s['moysklad']['fields'][0]['key'] ?? '') === 'MOYSKLAD_TOKEN'
   && ($s['moysklad']['fields'][0]['label'] ?? '') === Settings::SPEC['MOYSKLAD_TOKEN'][1]);
ok('секрет наружу не уезжает', ($s['moysklad']['fields'][0]['value'] ?? 'x') === ''
   && ($s['moysklad']['fields'][0]['secret'] ?? false) === true);

Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru',
                         'is_active' => 1, 'is_default' => 1]);
ok('включённый ящик закрывает шаг почты', $steps()['mail']['state']['status'] === 'ok');

Db::insert('products_cache', ['moysklad_id' => 'ms-p1', 'name' => 'Бронежилет', 'article' => 'BR-1']);
Settings::set('MOYSKLAD_TOKEN', 'ms-token');
$after = $steps();
ok('токен и наполненный каталог закрывают МойСклад', $after['moysklad']['state']['status'] === 'ok');
ok('состояние шага объясняет себя словами', str_contains((string)$after['moysklad']['state']['note'], 'позиций'));

$p = SetupWizard::progress();
ok('необязательный шаг в счёт не идёт', $p['total'] === count(SetupWizard::steps()) - 1);
ok('«следующий» — первый незакрытый обязательный', $p['next'] === 'general', (string)$p['next']);
ok('пока не всё закрыто, мастер не готов', $p['ready'] === false);

// =====================================================================  7

echo "\n== 7. Мастер пишет в те же настройки ==\n";

SetupWizard::saveStep('general', ['APP_URL' => 'https://kp.atlant-armour.ru', 'TIMEZONE' => 'Europe/Moscow'], $adminId);
ok('значение ушло в Settings', Settings::get('APP_URL') === 'https://kp.atlant-armour.ru');
ok('шаг закрылся сам, без отдельной галочки', $steps()['general']['state']['status'] === 'ok');

SetupWizard::saveStep('general', ['MOYSKLAD_TOKEN' => 'подложенный'], $adminId);
ok('чужой шагу ключ не принимается', Settings::get('MOYSKLAD_TOKEN') === 'ms-token');

SetupWizard::saveStep('moysklad', ['MOYSKLAD_TOKEN' => ''], $adminId);
ok('пустое поле секрета не стирает ключ', Settings::get('MOYSKLAD_TOKEN') === 'ms-token');
SetupWizard::saveStep('moysklad', ['MOYSKLAD_TOKEN' => 'ms-token-2'], $adminId);
ok('непустое — меняет', Settings::get('MOYSKLAD_TOKEN') === 'ms-token-2');

SetupWizard::skipStep('bitrix');
ok('пропущенный шаг помечен', in_array('bitrix', SetupWizard::state()['skipped'], true));
SetupWizard::saveStep('bitrix', ['BITRIX_ENABLED' => '0'], $adminId);
ok('заполненный шаг перестаёт быть пропущенным',
   !in_array('bitrix', SetupWizard::state()['skipped'], true));

ok('пока мастер не завершён, он сам встречает админа', SetupWizard::needed() === true);
SetupWizard::finish($adminId);
ok('после завершения не встречает', SetupWizard::needed() === false);
ok('дата завершения записана', (string)SetupWizard::state()['done_at'] !== '');

SetupWizard::restart($adminId);
ok('«пройти заново» обнуляет отметку', SetupWizard::needed() === true
   && (string)SetupWizard::state()['done_at'] === '');
ok('а настройки не трогает', Settings::get('APP_URL') === 'https://kp.atlant-armour.ru'
   && Settings::get('MOYSKLAD_TOKEN') === 'ms-token-2');
ok('перезапущенный мастер помнит, когда начали', (string)SetupWizard::state()['started_at'] !== '');

// =====================================================================  8

echo "\n== 8. Проверочный запрос и оценка качества ==\n";

$cpId = Db::insert('counterparties', ['name' => 'ООО «Ромашка»', 'inn' => '7810964292']);
$reqId = Db::insert('requests', ['source' => 'manual', 'raw_text' => SetupWizard::TRIAL_TEXT,
                                 'counterparty_id' => $cpId, 'status' => 'processing', 'is_trial' => 1]);
SetupWizard::rememberTrial($reqId, $cpId);
$state = SetupWizard::state();
ok('мастер помнит проверочный запрос', (int)$state['trial_request_id'] === $reqId);
ok('и карточку, на которую увёл', (int)$state['trial_counterparty_id'] === $cpId);
ok('шаг проверки просит оценку', $steps()['trial']['state']['status'] === 'todo');

$before = Support::pending();
SetupWizard::vote('kp', 'up', $adminId, ['model' => 'yandex:yandexgpt-lite']);
ok('«хорошо» записано', (SetupWizard::state()['trial_votes']['kp'] ?? '') === 'up');
ok('и оно тоже стало обращением', Support::pending() === $before + 1);
$upTicket = Support::listFor($admin)[0];
ok('обращение качества помечено как качество', (string)$upTicket['kind'] === 'quality'
   && (string)$upTicket['rating'] === 'up');
ok('похвалой администратора не будят',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE manager_id=? AND title LIKE '%Качество%'", [$adminId]) === 0);

SetupWizard::vote('letter', 'down', $adminId,
                  ['model' => 'yandex:yandexgpt-lite', 'comment' => 'Письмо сухое и без сроков']);
ok('«плохо» записано', (SetupWizard::state()['trial_votes']['letter'] ?? '') === 'down');
$downTicket = Support::listFor($admin)[0];
ok('жалоба несёт комментарий и модель',
   str_contains((string)$downTicket['body'], 'сухое') && (string)$downTicket['model'] === 'yandex:yandexgpt-lite');
ok('о «плохо» администратор узнаёт',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE manager_id=? AND title LIKE '%Качество письма%'", [$adminId]) === 1);
ok('обе оценки закрывают шаг проверки', $steps()['trial']['state']['status'] === 'ok');

// =====================================================================  9

echo "\n== 9. «Палец вниз» предлагает модель ПОДОРОЖЕ ==\n";

LLM::init(Settings::effective());
ok('без ключей предлагать нечего', LLM::pricier('yandex:yandexgpt-lite') === []);

Settings::set('YANDEX_API_KEY', 'yc-key');
Settings::set('YANDEX_FOLDER_ID', 'b1gfolder');
Settings::set('YANDEX_MODEL', 'yandexgpt-lite');
LLM::init(Settings::effective());

$dearer = LLM::pricier('yandex:yandexgpt-lite');
ok('модели дороже нашлись', count($dearer) > 0);
ok('все они дороже нынешней ступени',
   count(array_filter($dearer, fn($m) => (int)$m['tier'] <= 1)) === 0);
ok('нынешней модели в списке нет',
   !in_array('yandex:yandexgpt-lite', array_column($dearer, 'spec'), true));
ok('дешёвая ступень идёт первой', (int)$dearer[0]['tier'] <= (int)$dearer[count($dearer) - 1]['tier']);
ok('провайдер без ключа в список не попал',
   count(array_filter($dearer, fn($m) => $m['provider'] === 'openrouter')) === 0);
ok('у самой дорогой предлагать уже нечего', LLM::pricier('yandex:deepseek-r1') === []);

// Слаг, которого в облаке нет, не предлагается: проба его уже забраковала
Db::q("INSERT INTO settings (key, value) VALUES ('yandex_models', ?)
       ON CONFLICT(key) DO UPDATE SET value=excluded.value",
      [json_encode(['checked' => ['deepseek-r1' => 'missing', 'yandexgpt' => 'ok']])]);
ok('непроверенный слаг не предлагается',
   !in_array('yandex:deepseek-r1', array_column(LLM::pricier('yandex:yandexgpt-lite'), 'spec'), true));

// Живой каталог с ценами решает сам, а не ступени
Settings::set('OPENROUTER_API_KEY', 'or-key');
LLM::init(Settings::effective());
Db::q("INSERT INTO settings (key, value) VALUES ('openrouter_models', ?)
       ON CONFLICT(key) DO UPDATE SET value=excluded.value",
      [json_encode(['synced_at' => date('Y-m-d H:i:s'), 'models' => [
          ['id' => 'test/cheap', 'label' => 'Дешёвая', 'group' => 'Тест', 'price' => 0.000001],
          ['id' => 'test/dear',  'label' => 'Дорогая', 'group' => 'Тест', 'price' => 0.001],
      ]], JSON_UNESCAPED_UNICODE)]);
$byPrice = array_column(LLM::pricier('openrouter:test/cheap', 20), 'spec');
ok('модель с большей ценой предложена', in_array('openrouter:test/dear', $byPrice, true));
$fromDear = array_column(LLM::pricier('openrouter:test/dear', 20), 'spec');
ok('модель с меньшей ценой — нет', !in_array('openrouter:test/cheap', $fromDear, true));

// =====================================================================  10

echo "\n== 10. Карточка проверочного запроса — в «В работе» ==\n";

$board = Boards::singleton();
$work  = Boards::workColumn((int)$board['id']);
ok('колонка «В работе» есть', $work !== null);
$cardId = Boards::addCard((int)$work['id'], ['counterparty_id' => $cpId, 'request_id' => $reqId,
                                             'title' => 'ООО «Ромашка»', 'manager_id' => $adminId]);
ok('карточка легла в «В работе»',
   (string)Db::val("SELECT c.title FROM board_cards d JOIN board_columns c ON c.id=d.column_id WHERE d.id=?",
                   [$cardId]) === (string)$work['title']);
ok('карточка знает свой запрос', (int)Db::val("SELECT request_id FROM board_cards WHERE id=?", [$cardId]) === $reqId);
$again = Boards::addCard((int)$work['id'], ['counterparty_id' => $cpId, 'title' => 'ООО «Ромашка»']);
ok('второй карточки той же компании не заводится', $again === $cardId);

// Уборка доски сносит пустые карточки — но запрос из мессенджера не пустой,
// хотя писем у него нет вовсе
Boards::pruneEmptyCards($cpId);
ok('карточка запроса переживает уборку доски',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE id=?", [$cardId]) === 1);
$bare = Db::insert('counterparties', ['name' => 'ООО «Пустышка»']);
$bareCard = Boards::addCard((int)$work['id'], ['counterparty_id' => $bare, 'title' => 'ООО «Пустышка»']);
Boards::pruneEmptyCards($bare);
ok('карточка совсем без работы по-прежнему убирается',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE id=?", [$bareCard]) === 0);
ok('проверочный запрос помечен и не считается письмом клиента',
   (int)Db::val("SELECT is_trial FROM requests WHERE id=?", [$reqId]) === 1
   && (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='new_request'") === 0);

// =====================================================================

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
