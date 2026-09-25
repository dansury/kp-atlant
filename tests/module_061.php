<?php
/**
 * Модуль 061: issues #97–#114.
 *
 *   — #92 отмеченные фото идут в КП все, без выбора — первые по настройке;
 *   — #94 КП уходит только из письма: подтверждение при вложении;
 *   — #93 последнее письмо компании удалено — карточка уходит с доски;
 *   — #95 🎤 в форме поддержки.
 *
 * Запуск:  php tests/module_061.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-061-' . getmypid() . '.db';
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
require_once ROOT . '/lib/variants.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/kp_set.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/fulfillment.php';
require_once ROOT . '/lib/outbox.php';
require_once ROOT . '/lib/support.php';
require_once ROOT . '/lib/sync.php';
require_once ROOT . '/lib/speech.php';
require_once ROOT . '/lib/branding.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js   = file_get_contents(ROOT . '/public/assets/js/app.js');
$css  = file_get_contents(ROOT . '/public/assets/css/app.css');
$sw   = file_get_contents(ROOT . '/public/sw.js');
$idx  = file_get_contents(ROOT . '/public/index.php');

// ===================================================================== 1
echo "1. #114/#102 предупреждения и блокировка поиска\n";
ok('pkey_derive без длины ключа', !preg_match('/openssl_pkey_derive\([^)]*,\s*32\)/', file_get_contents(ROOT . '/lib/webpush.php')));
ok('curl_close в speech не вызывается', !str_contains(file_get_contents(ROOT . '/lib/speech.php'), 'curl_close('));
ok('ключ цены — строка', str_contains(file_get_contents(ROOT . '/lib/request_items.php'), "\$prices[(string)(\$row['moysklad_product_id'] ?? '')]"));
ok('Db::path() — файл базы', Db::path() === $tmpDb);
ok('Db::isLocked узнаёт блокировку', Db::isLocked(new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked'))
   && !Db::isLocked(new RuntimeException('no such table')));
$lock = fopen(dirname($tmpDb) . '/.search.lock', 'c');
flock($lock, LOCK_EX);
Db::q("INSERT OR IGNORE INTO search_dirty VALUES (0, 999999)");
$pending = SearchIndex::refresh(1.0);
ok('индекс занят другим — поиск не ждёт и не падает', $pending >= 1, (string)$pending);
flock($lock, LOCK_UN); fclose($lock);
ok('свободен — индексирует', SearchIndex::refresh(5.0) === 0);

// ===================================================================== 2
echo "2. #113 запись лога текстом, журнал файлом, журнал в обращении\n";
Logger::warning('php', 'openssl_pkey_derive(): deprecated', ['file' => '/x/lib/webpush.php', 'line' => 59, 'errno' => 8192]);
$row = Db::one("SELECT * FROM app_log WHERE message LIKE 'openssl_pkey_derive%'");
$text = Logger::entryText($row);
ok('текст записи — уровень, источник, сообщение, контекст', str_starts_with($text, 'warning · php · ')
   && str_contains($text, 'openssl_pkey_derive(): deprecated') && str_contains($text, '"line": 59'));
$exp = Logger::export(['level' => 'warning']);
ok('выгрузка — заголовок и записи', str_starts_with($exp, 'Журнал Atlant КП') && str_contains($exp, 'openssl_pkey_derive'));
$mgr = Db::insert('managers', ['login' => 'adm61', 'password_hash' => 'x', 'name' => 'Админ', 'is_admin' => 1]);
$t = Support::submit($mgr, ['title' => 'Лог', 'body' => 'см. файл', 'attach_log' => 1, 'quiet' => 1]);
$f = Db::one("SELECT * FROM support_files WHERE ticket_id=?", [$t['id']]);
ok('журнал лёг файлом в обращение', $f && str_starts_with((string)$f['filename'], 'atlant-log-') && is_file(ROOT . '/' . $f['path']));
@unlink(ROOT . '/' . $f['path']); @rmdir(ROOT . '/storage/support/' . $t['id']);
ok('API: log_get и logs_export', str_contains(file_get_contents(ROOT . '/public/api/admin.php'), "case 'log_get':")
   && str_contains(file_get_contents(ROOT . '/public/api/admin.php'), "case 'logs_export':"));
ok('журнал прикладывает только администратор', str_contains(file_get_contents(ROOT . '/public/api/support.php'), "if (!\$isAdmin) unset(\$input['attach_log']);"));
ok('уведомление ведёт на саму запись', str_contains(file_get_contents(ROOT . '/lib/logger.php'), "'/#settings/logs/error/' . \$logId"));
ok('UI: ⧉ в строке, скачать, в поддержку', str_contains($js, 'App.copyLog(') && str_contains($js, 'logs_export')
   && str_contains($js, 'App.logToSupport(') && str_contains($js, 'execCommand(\'copy\')'));

// ===================================================================== 3
echo "3. #105 картинки в issue, мобильная вёрстка, отправка\n";
ok('постоянная ссылка blob?raw=true', Support::stableUrl(['html_url' => 'https://github.com/o/r/blob/main/a.png',
   'download_url' => 'https://raw.githubusercontent.com/o/r/main/a.png?token=X']) === 'https://github.com/o/r/blob/main/a.png?raw=true');
ok('старая raw-ссылка переписывается', Support::displayUrl('https://raw.githubusercontent.com/o/r/main/s/a.png?token=AB')
   === 'https://github.com/o/r/blob/main/s/a.png?raw=true');
ok('тело issue из свежей записи', str_contains(file_get_contents(ROOT . '/lib/support.php'), '$fresh = self::get($id) ?: $row;'));
ok('картинка печатается картинкой', str_contains(Support::issueBody(['body' => 'x', 'files' => [['filename' => 'a.png', 'mime' => 'image/png',
   'remote_url' => 'https://github.com/o/r/blob/main/a.png?raw=true']]]), '![a.png](https://github.com/o/r/blob/main/a.png?raw=true)'));
ok('светлая тема без авто-затемнения', str_contains($idx, 'content="only light"') && str_contains($css, 'color-scheme: only light'));
ok('окно не шире экрана', str_contains($css, 'max-width: min(460px, 100%); min-width: 0;'));
ok('лента статусов не распирает страницу', str_contains($css, '.mailbox__side { position: relative; flex-direction: row;'));
ok('строка отправки прилипает на телефоне', str_contains($css, '.composer__actions { position: sticky;'));
ok('генерация над полем, отправка под ним', strpos($js, 'class="composer__gen"') < strpos($js, 'data-cmp-rte contenteditable')
   && strpos($js, 'data-cmp-rte contenteditable') < strpos($js, '<div class="composer__actions">'));

// ===================================================================== 4
echo "4. #104 ссылка на issue из уведомления\n";
$m = new ReflectionMethod('Push', 'appUrl'); $m->setAccessible(true);
ok('абсолютный адрес не склеивается', $m->invoke(null, 'https://github.com/dansury/kp-atlant/issues/103') === 'https://github.com/dansury/kp-atlant/issues/103');
ok('относительный — с адресом сервиса', str_ends_with($m->invoke(null, '/#mail'), '/#mail') && str_starts_with($m->invoke(null, '/#mail'), 'http'));
ok('SW открывает чужой адрес окном', str_contains($sw, 'origin !== self.location.origin') && str_contains($sw, "atlant-kp-shell-v3"));

// ===================================================================== 5
echo "5. #103 уведомления помечают карточки\n";
$cp = Db::insert('counterparties', ['name' => 'ООО «Метка»']);
$board = Boards::singleton();
$work = Boards::workColumn((int)$board['id']);
$cardId = Boards::addCard((int)$work['id'], ['counterparty_id' => $cp]);
$nid = Notifier::notify('order_paid', 'Сообщить складу о необходимости отправки', 'оплачен', 'counterparty', $cp, $mgr, '/#mail/company/' . $cp);
Notifier::notify('new_request', 'Новое письмо', null, 'counterparty', $cp, $mgr);
$_SESSION['manager_id'] = $mgr;
$find = function () use ($board, $cardId) {
    foreach (Boards::get((int)$board['id'])['columns'] as $c) foreach ($c['cards'] as $cc) if ($cc['id'] === $cardId) return $cc;
    return null;
};
$card = $find();
ok('карточка помечена и горит', $card && count($card['notices']) === 1 && $card['hot'], json_encode($card['notices'] ?? null, JSON_UNESCAPED_UNICODE));
ok('новое письмо карточку не метит', ($card['notices'][0]['title'] ?? '') === 'Сообщить складу о необходимости отправки');
ok('уведомления компании — для полоски', count(Notifier::cardNotices($mgr, $cp)['cp'][$cp] ?? []) === 1);
Notifier::markRead($nid, $mgr);
$card = $find();
ok('галочка — метки нет', $card && $card['notices'] === []);
ok('UI: полоска с «✓ Сделано», подсветка', str_contains($js, 'App.doneCardNotice(') && str_contains($css, '.bcard--notice'));

// ===================================================================== 6
echo "6. #112 отгрузки: метка карточки и трек в черновике\n";
$order = Db::insert('orders', ['moysklad_id' => 'ms-061', 'name' => '00061', 'sum' => 100, 'counterparty_id' => $cp, 'manager_id' => $mgr]);
$draft = Db::insert('mail_drafts', ['counterparty_id' => $cp, 'manager_id' => $mgr, 'subject' => 'Re: заказ',
                                   'body' => '<p>Добрый день!</p>', 'updated_at' => date('Y-m-d H:i:s')]);
Fulfillment::$fetchOrder = fn($id) => ['attributes' => []];
Fulfillment::$fetchDemands = fn($id) => [['id' => 'dm-1', 'name' => '00007', 'moment' => '', 'attributes' => []]];
$r = Fulfillment::checkShipments(30, $cp);
ok('новая отгрузка без трека — запомнена', $r['demands'] === 1 && $r['shipped'] === 0, json_encode($r));
ok('и карточка помечена', (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='order_shipped' AND ref_id=? AND is_read=0", [$cp]) === 1);
$r = Fulfillment::checkShipments(30, $cp);
ok('второй раз — не новость', $r['demands'] === 0);
Fulfillment::$fetchDemands = fn($id) => [['id' => 'dm-1', 'name' => '00007', 'moment' => '', 'attributes' => ['ТРЕК-НОМЕР' => '1234567890', 'СЛУЖБА ДОСТАВКИ' => 'СДЭК']]];
$r = Fulfillment::checkShipments(30, $cp);
$body = (string)Db::val("SELECT body FROM mail_drafts WHERE id=?", [$draft]);
ok('трек вписан — в ТОТ ЖЕ черновик', $r['shipped'] === 1 && str_contains($body, '1234567890') && str_contains($body, 'Добрый день'), $body);
ok('второго черновика нет', (int)Db::val("SELECT COUNT(*) FROM mail_drafts WHERE counterparty_id=?", [$cp]) === 1);
$r = Fulfillment::checkShipments(30, $cp);
ok('тот же трек второй раз не пишется', $r['shipped'] === 0 && substr_count((string)Db::val("SELECT body FROM mail_drafts WHERE id=?", [$draft]), '<b>1234567890</b>') === 1);
Fulfillment::$fetchDemands = fn($id) => [
    ['id' => 'dm-1', 'name' => '00007', 'attributes' => ['ТРЕК-НОМЕР' => '1234567890']],
    ['id' => 'dm-2', 'name' => '00008', 'attributes' => ['ТРЕК-НОМЕР' => '555']]];
$r = Fulfillment::checkShipments(30, $cp);
ok('вторая отгрузка со своим треком — в черновик', $r['shipped'] === 1 && str_contains((string)Db::val("SELECT body FROM mail_drafts WHERE id=?", [$draft]), '555'));
ok('«Обновить из МойСклад» смотрит отгрузки', str_contains(file_get_contents(ROOT . '/public/api/invoices.php'), 'Fulfillment::checkShipments(30, $cpId)'));

// ===================================================================== 7
echo "7. #111 заказ и счёт к карточке по номеру\n";
ok('поиск по номеру и привязка', method_exists('MoySklad', 'findByName') && method_exists('MsSync', 'linkDocuments'));
ok('API и пункт меню', str_contains(file_get_contents(ROOT . '/public/api/invoices.php'), "case 'link':")
   && str_contains($js, 'App.linkMsDoc(') && str_contains($js, "invoices.php?action=link"));

// ===================================================================== 8
echo "8. #110 лимит колонки: «ещё» и поиск\n";
$col = Db::one("SELECT * FROM board_columns WHERE board_id=? AND kind='closed'", [(int)$board['id']]);
Db::update('board_columns', ['card_limit' => 2], 'id=?', [(int)$col['id']]);
$ids = [];
for ($i = 0; $i < 5; $i++) {
    $c = Db::insert('counterparties', ['name' => "Закрытая $i" . ($i === 4 ? ' уникальноеслово' : '')]);
    $ids[] = Boards::addCard((int)$col['id'], ['counterparty_id' => $c]);
}
$got = null;
foreach (Boards::get((int)$board['id'])['columns'] as $c) if ($c['id'] === (int)$col['id']) $got = $c;
ok('на доске — лимит, всего — все', count($got['cards']) === 2 && $got['total'] === 5, count($got['cards']) . '/' . $got['total']);
$next = Boards::columnCards((int)$col['id'], 2);
ok('следующая пачка — по лимиту', count($next['cards']) === 2 && $next['total'] === 5);
$tail = Boards::columnCards((int)$col['id'], 4);
ok('последняя — остаток', count($tail['cards']) === 1);
$shown = array_merge(array_column($got['cards'], 'id'), array_column($next['cards'], 'id'), array_column($tail['cards'], 'id'));
ok('пачки без повторов', count(array_unique($shown)) === 5);
SearchIndex::refresh(10.0);
$found = Boards::searchCards('уникальноеслово');
ok('поиск достаёт карточку за лимитом', count($found) === 1 && $found[0]['column_id'] === (int)$col['id']);
ok('UI: стрелка и подтягивание найденного', str_contains($js, 'App.boardMore(') && str_contains($js, 'search_cards')
   && str_contains($js, "card.dataset.found === low"));

// ===================================================================== 9–16
echo "9–16. последнее письмо, «Отправить позже», логотип, «Закрыть», установка, ручной запрос, SpeechKit, счётчик\n";
ok('#109 последнее письмо раскрыто и в фокусе', str_contains($js, "const open = isLast || (m.direction === 'in'")
   && str_contains($js, 'this.focusLastLetter(latest.thread_key)'));
ok('#108 «Отправить позже», день и время отдельно', str_contains($js, '⏱ Отправить позже') && !str_contains($js, '⏱ Отложить')
   && str_contains($js, 'data-cmp-date') && str_contains($js, 'data-cmp-time') && !str_contains($js, 'datetime-local'));
ok('#107 логотип — загруженный', method_exists('Branding', 'headerKind') && str_contains($idx, 'Branding::headerKind()')
   && !str_contains($idx, 'header__mark'));
ok('#106 «Открыть» ↔ «Закрыть»', str_contains($js, "this.kpIsOpen(id) ? 'Закрыть' : 'Открыть'") && str_contains($js, 'kpToggleLabels()'));
ok('#101 кнопка установки светится', str_contains($js, 'installGlowHtml()') && str_contains($css, '.btn--glow'));
ok('#99 ручной запрос — в списке и переименован', str_contains($js, 'class="mside__new"') && !str_contains($js, 'Запрос не из почты')
   && substr_count($js, 'Составить КП по ручному запросу') >= 3);
Settings::set('SPEECH_MODEL', 'general:rc');
$q = Speech::query('oggopus');
ok('#98 модель SpeechKit из настроек', isset(Settings::SPEC['SPEECH_MODEL']) && $q['topic'] === 'general:rc' && $q['lang'] === 'ru-RU', json_encode($q));

// #97
$box = Db::insert('mailboxes', ['name' => 'Чужой', 'email' => 'x@atlant-armour.ru', 'is_active' => 1, 'manager_id' => $mgr]);
$plain = Db::insert('managers', ['login' => 'man61', 'password_hash' => 'x', 'name' => 'Менеджер', 'is_admin' => 0]);
Db::q("UPDATE mail_messages SET is_read=1");
Db::insert('mail_messages', ['direction' => 'in', 'mailbox_id' => $box, 'from_email' => 'c@c.ru', 'subject' => 'Чужое',
                             'thread_key' => 'k-other', 'is_read' => 0, 'date_at' => date('Y-m-d H:i:s'), 'folder' => 'INBOX', 'uid' => 77]);
ok('#97 письмо чужого ящика не считается менеджеру', MailThreads::unreadCount(Db::one("SELECT * FROM managers WHERE id=?", [$plain])) === 0
   && MailThreads::unreadCount(Db::one("SELECT * FROM managers WHERE id=?", [$mgr])) === 1);
$cp9 = Db::insert('counterparties', ['name' => 'Снятая']);
Db::insert('mail_messages', ['direction' => 'in', 'from_email' => 'd@d.ru', 'subject' => 'Старое', 'thread_key' => 'k-dis',
                             'counterparty_id' => $cp9, 'is_read' => 0, 'date_at' => date('Y-m-d H:i:s', time() - 3600)]);
$before = MailThreads::unreadCount();
$dc = Boards::addCard((int)$work['id'], ['counterparty_id' => $cp9]);
Db::update('board_cards', ['dismissed_at' => date('Y-m-d H:i:s')], 'id=?', [$dc]);
ok('#97 письмо снятой после него карточки не считается', MailThreads::unreadCount() === $before - 1);
$reader = new class { public function seenUids(array $u): array { return array_values(array_intersect($u, [77])); } };
$n = MailSync::syncSeen(Db::one("SELECT * FROM mailboxes WHERE id=?", [$box]), 'INBOX', $reader);
ok('#97 прочитанное в почте — прочитано здесь', $n === 1 && (int)Db::val("SELECT is_read FROM mail_messages WHERE uid=77") === 1);

echo $fail ? "\nFAILED: $fail\n" : "\nall ok\n";
exit($fail ? 1 : 0);
