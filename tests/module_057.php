<?php
/**
 * Модуль 057: задержка отправки на отмену, стрелки сворачивания, итог «Обновить из МойСклад».
 *
 *   — письмо ждёт в очереди N секунд своей настройки, отменяется, досылается «сейчас»;
 *   — одно письмо не уходит дважды: крон и вкладка забирают строку условием;
 *   — интерфейс: отсчёт с «Отменить», «свернуть все», стрелки с подсказками.
 *
 * Запуск:  php tests/module_057.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-057-' . getmypid() . '.db';
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
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/mail_threads.php';
require_once ROOT . '/lib/boards.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
require_once ROOT . '/lib/mail_schedule.php';

echo "\n== 1. Задержка менеджера ==\n";
$m1 = Db::insert('managers', ['login' => 'yana', 'name' => 'Яна', 'password_hash' => 'x']);
$m2 = Db::insert('managers', ['login' => 'petr', 'name' => 'Пётр', 'password_hash' => 'x']);
ok('по умолчанию 20 с', MailSchedule::delayFor($m1) === 20);
MailSchedule::setDelay($m1, 0);
ok('0 — без задержки', MailSchedule::delayFor($m1) === 0);
MailSchedule::setDelay($m1, 999);
ok('больше 120 не бывает', MailSchedule::delayFor($m1) === 120);
MailSchedule::setDelay($m1, null);
ok('null — снова по умолчанию', MailSchedule::delayFor($m1) === 20);

echo "\n== 2. Письмо ждёт в очереди и отменяется ==\n";
$payload = ['to' => 'client@example.ru', 'subject' => 'КП', 'text' => 'Добрый день'];
$d = MailSchedule::delay($payload, $m1, 20);
$row = Db::one("SELECT * FROM mail_scheduled WHERE id=?", [$d['id']]);
ok('лежит pending', $row['status'] === 'pending');
ok('время = сейчас + 20 с', abs(strtotime($row['send_at']) - time() - 20) <= 2, $row['send_at']);
ok('в due() его ещё нет', !array_filter(MailSchedule::due(), fn($r) => (int)$r['id'] === $d['id']));
MailSchedule::cancel($d['id'], $m1);
ok('отменено', Db::val("SELECT status FROM mail_scheduled WHERE id=?", [$d['id']]) === 'cancelled');
$threw = false;
try { MailSchedule::sendNow($d['id'], $m1); } catch (RuntimeException) { $threw = true; }
ok('отменённое «сейчас» не уходит', $threw);

echo "\n== 3. Никакой двойной отправки ==\n";
$d2 = MailSchedule::delay($payload, $m1, 20);
Db::update('mail_scheduled', ['status' => 'sending'], 'id=?', [$d2['id']]);   // крон забрал
$threw = false;
try { MailSchedule::cancel($d2['id'], $m1); } catch (RuntimeException) { $threw = true; }
ok('забранное кроном не отменяется', $threw);
ok('«сейчас» не шлёт второй раз', MailSchedule::sendNow($d2['id'], $m1) === ['already' => 'sending']);
Db::update('mail_scheduled', ['status' => 'sent'], 'id=?', [$d2['id']]);
ok('ушедшее — already sent', MailSchedule::sendNow($d2['id'], $m1) === ['already' => 'sent']);

$d3 = MailSchedule::delay($payload, $m1, 20);
$threw = false;
try { MailSchedule::sendNow($d3['id'], $m2); } catch (RuntimeException) { $threw = true; }
ok('чужое письмо не отправить', $threw);

// Почтового ящика нет — отправка падает; ошибку видит менеджер, повтора кроном нет
$threw = false;
try { MailSchedule::sendNow($d3['id'], $m1); } catch (Throwable) { $threw = true; }
$r3 = Db::one("SELECT status, attempts FROM mail_scheduled WHERE id=?", [$d3['id']]);
ok('упавшее «сейчас» — ошибка менеджеру', $threw);
ok('и письмо не повторится кроном (failed)', $r3['status'] === 'failed' && (int)$r3['attempts'] === 1, json_encode($r3));

// Крон: упавшее письмо возвращается в очередь до трёх попыток
$d4 = MailSchedule::delay($payload, $m1, 20);
Db::update('mail_scheduled', ['send_at' => date('Y-m-d H:i:s', time() - 5)], 'id=?', [$d4['id']]);
$run = MailSchedule::run();
$r4 = Db::one("SELECT status, attempts FROM mail_scheduled WHERE id=?", [$d4['id']]);
ok('крон: упало → снова pending', $run['failed'] === 1 && $r4['status'] === 'pending' && (int)$r4['attempts'] === 1, json_encode($r4));

echo "\n== 3б. Повторное нажатие — то же письмо ==\n";
$mid = Db::insert('managers', ['login' => 'twice', 'name' => 'Двойной', 'password_hash' => 'x']);
$p1 = ['to' => 'again@example.ru', 'subject' => 'Ответ', 'text' => 'Добрый  день', 'files' => ['b', 'a']];
$a = MailSchedule::delay($p1, $mid, 20);
$b = MailSchedule::delay(['files' => ['a', 'b'], 'text' => 'Добрый день'] + $p1, $mid, 20);
ok('второе нажатие отдаёт ту же строку', $b['id'] === $a['id']);
ok('в очереди одно письмо', (int)Db::val("SELECT COUNT(*) FROM mail_scheduled WHERE manager_id=?", [$mid]) === 1);
$c2 = MailSchedule::delay(['text' => 'Другой текст'] + $p1, $mid, 20);
ok('другой текст — новое письмо', $c2['id'] !== $a['id']);
Db::update('mail_scheduled', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')], 'id=?', [$a['id']]);
ok('только что ушедшее — already sent', (MailSchedule::delay($p1, $mid, 20)['already'] ?? '') === 'sent');
Db::update('mail_scheduled', ['sent_at' => date('Y-m-d H:i:s', time() - 600)], 'id=?', [$a['id']]);
$d2 = MailSchedule::delay($p1, $mid, 20);
ok('через 10 минут — осознанная повторная отправка', isset($d2['id']) && $d2['id'] !== $a['id']);

echo "\n== 4. Интерфейс ==\n";
$js = file_get_contents(ROOT . '/public/assets/js/app.js');
$api = file_get_contents(ROOT . '/public/api/mail.php');
ok('send кладёт письмо на задержку', str_contains($api, "MailSchedule::delay(\$input"));
ok('send_now есть', str_contains($api, "case 'send_now'"));
ok('обе отправки через sendMail', substr_count($js, 'this.sendMail(body)') === 2);
ok('отсчёт с «Отменить»', str_contains($js, 'data-undo>Отменить</button>'));
ok('настройка задержки', str_contains($js, "settings.php?action=my_send_delay"));
ok('свернуть все позиции', str_contains($js, 'App.foldAllRows(this)'));
ok('свернуть все письма', str_contains($js, 'App.foldAllLetters(this)'));
ok('слов «▾ Свернуть» на кнопках больше нет', !str_contains($js, "'▾ Свернуть'") && !str_contains($js, '▸ Развернуть ('));
ok('стрелка у письма и у переписки', str_contains($js, 'class="lmsg__caret"') && str_contains($js, 'class="conv__caret"'));
ok('заголовок блока сворачивает', str_contains($js, 'head.dataset.foldClick'));
$inv = file_get_contents(ROOT . '/public/api/invoices.php');
ok('синхронизация отвечает linked и реквизитами', str_contains($inv, "\$res['linked']") && str_contains($inv, 'Requisites::syncCounterparty'));
ok('итог синхронизации словами', str_contains($js, 'syncReport(r)'));
ok('поле заперто на время отсчёта', str_contains($js, 'this.lockComposer(c, true)') && str_contains($js, "if (!c || c.dataset.sending) return;"));
ok('ушедшее письмо стирается из поля', str_contains($js, 'this.clearComposer(c);'));
ok('страница письма перерисовывается', str_contains($js, 'afterSend(key)') && str_contains($js, 'if (!box) this.route();'));
ok('повтор отвечает already', str_contains($api, "jsonOk(['already' => \$d['already']])"));

echo $fail ? "\n$fail FAIL\n" : "\nВсё ок\n";
exit($fail ? 1 : 0);
