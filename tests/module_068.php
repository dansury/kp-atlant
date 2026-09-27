<?php
/**
 * Модуль 068: issues #149–#150.
 *
 *   — резерв под счёт: 3 рабочих дня, абзац о сроке в письме со счётом;
 *   — ответ пишется при открытии: квалификация (сразу / после КП / не отвечаем);
 *   — правки ответов идут в промпт примером «было → стало»;
 *   — вики в одном месте: папки репозитория, поле-выбор; скриншот обращения в черновике.
 *
 * Запуск:  php tests/module_068.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-068-' . getmypid() . '.db';
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
require_once ROOT . '/lib/reserves.php';
require_once ROOT . '/lib/triage.php';
require_once ROOT . '/lib/learning.php';
require_once ROOT . '/lib/knowledge.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js   = file_get_contents(ROOT . '/public/assets/js/app.js');
$mail = file_get_contents(ROOT . '/public/api/mail.php');
$inv  = file_get_contents(ROOT . '/public/api/invoices.php');
$adm  = file_get_contents(ROOT . '/public/api/admin.php');

// ===================================================================== 1
echo "1. Резерв — три рабочих дня (#150)\n";
ok('по умолчанию три дня', Reserves::days() === 3);
ok('и они рабочие', Reserves::businessDays());
$fri = strtotime('2026-09-25 18:00:00');   // пятница
ok('счёт в пятницу держит товар до среды', Reserves::until($fri) === '2026-09-30 18:00:00', (string)Reserves::until($fri));
$mon = strtotime('2026-09-28 10:00:00');
ok('счёт в понедельник — до четверга', Reserves::until($mon) === '2026-10-01 10:00:00', (string)Reserves::until($mon));
ok('подпись срока — «3 рабочих дня»', Reserves::daysLabel() === '3 рабочих дня');
ok('склонение: 1 / 5 / 21', Reserves::daysLabel(1) === '1 рабочий день' && Reserves::daysLabel(5) === '5 рабочих дней'
   && Reserves::daysLabel(21) === '21 рабочий день');
Settings::set('MS_RESERVE_BUSINESS', '0');
ok('календарные — ровно N суток', Reserves::until($fri) === '2026-09-28 18:00:00');
ok('и подпись без «рабочих»', Reserves::daysLabel(14) === '14 дней');
Settings::forget('MS_RESERVE_BUSINESS');
Settings::set('MS_RESERVE_DAYS', '0');
ok('0 дней — резерва нет и абзаца нет', Reserves::until() === null && Reserves::letterNote() === '');
Settings::forget('MS_RESERVE_DAYS');

$note = Reserves::letterNote();
ok('абзац называет срок и просьбу сообщить', str_contains($note, 'на 3 рабочих дня') && str_contains($note, 'сообщите')
   && str_contains($note, 'резерв будет снят'), $note);
$t = Reserves::appendToLetter("Добрый день!\n\nСчёт во вложении.", $note);
ok('абзац встаёт в письмо', str_ends_with($t, $note));
ok('и второй раз не повторяется', Reserves::appendToLetter($t, $note) === $t);
ok('даже если письмо переформатировали', Reserves::contains(str_replace(' ', "\n", $t), $note));

// Заказ под счёт, резерв держится — абзац есть у запроса и у счёта
$cp  = Db::insert('counterparties', ['name' => 'ООО Ромашка']);
$req = Db::insert('requests', ['source' => 'email', 'raw_text' => 'Прошу КП', 'counterparty_id' => $cp, 'category' => 'kp_request']);
$prop = Db::insert('proposals', ['request_id' => $req, 'counterparty_id' => $cp]);
$ord = Db::insert('orders', ['counterparty_id' => $cp, 'proposal_id' => $prop, 'moysklad_id' => 'ms-o', 'name' => '001',
                             'sum' => 1000, 'applicable' => 1, 'reserve_until' => Reserves::until()]);
$invId = Db::insert('invoices', ['order_id' => $ord, 'proposal_id' => $prop, 'counterparty_id' => $cp, 'moysklad_id' => 'ms-i', 'name' => '001',
                                 'sum' => 1000, 'payed_sum' => 0]);
ok('у запроса с неоплаченным резервом — абзац', Reserves::noteForRequest($req) === $note);
ok('у счёта — тоже', Reserves::noteForInvoice($invId) === $note);
Db::update('invoices', ['payed_sum' => 1000], 'id=?', [$invId]);
ok('оплачен — про резерв молчим', Reserves::noteForInvoice($invId) === '' && Reserves::noteForRequest($req) === '');
Db::update('invoices', ['payed_sum' => 0], 'id=?', [$invId]);
Db::update('orders', ['reserve_released_at' => date('Y-m-d H:i:s')], 'id=?', [$ord]);
ok('резерв снят — тоже молчим', Reserves::noteForInvoice($invId) === '');

$brief = Reserves::orderBrief($ord);
ok('заказ строкой: номер, статус-ссылка в МойСклад, резерв', $brief['name'] === '001'
   && $brief['url'] === MoySklad::orderUrl('ms-o') && array_key_exists('reserve', $brief), json_encode($brief, JSON_UNESCAPED_UNICODE));
ok('нет заказа — нет строки', Reserves::orderBrief(null) === null && Reserves::orderBrief(999999) === null);
require_once ROOT . '/lib/kp_set.php';
ok('заказ приходит вместе со счетом КП', (KpSet::invoices($prop)[0]['order']['id'] ?? null) === $ord);
ok('и в строке счёта под письмом', str_contains($inv, "'order'       => Reserves::orderBrief("));
ok('интерфейс рисует заказ под счётом в обоих местах', substr_count($js, '${this.orderLine(i.order)}') === 2
   && str_contains($js, 'title="Открыть заказ в МойСклад"'));
ok('счёт пишет срок резерва через Reserves::until()', str_contains($inv, "'reserve_until' => \$until")
   && str_contains($inv, "'reserve_note' =>") && !str_contains($inv, "MS_RESERVE_DAYS', 14"));
ok('ответ и вложение счёта несут абзац', str_contains($mail, 'Reserves::noteForRequest(') && str_contains($mail, "\$out['reserve_note'] = Reserves::noteForInvoice(\$id)"));
ok('напоминание называет рабочие дни', str_contains(file_get_contents(ROOT . '/cron/check_reserves.php'), 'Reserves::daysLabel()'));
ok('в письме абзац встаёт над подписью', str_contains($js, 'addReserveNote(composer, note)')
   && str_contains($js, 'if (sign) box.insertBefore(p, sign); else box.appendChild(p);'));

// ===================================================================== 2
echo "\n2. Ответ пишется сам, после квалификации (#149)\n";
$msg = function (array $o) {
    $row = $o + ['folder' => 'INBOX', 'uid' => random_int(1, 1 << 30), 'direction' => 'in', 'subject' => 'Запрос', 'body_text' => 'Прошу КП', 'from_email' => 'a@romashka.ru',
                 'date_at' => '2026-09-20 10:00:00', 'thread_key' => 't-' . uniqid(), 'message_id' => '<' . uniqid() . '@x>'];
    $row['id'] = Db::insert('mail_messages', $row);
    return $row;
};
$req2 = Db::insert('requests', ['source' => 'email', 'raw_text' => 'Прошу КП', 'counterparty_id' => $cp, 'category' => 'kp_request']);
Db::insert('request_items', ['request_id' => $req2, 'position' => 1, 'raw_name' => 'Плита Бр3', 'quantity' => 2]);
$m = $msg(['category' => 'kp_request', 'request_id' => $req2]);
ok('запрос КП с позициями ждёт КП', Triage::autoPlan($m)['mode'] === 'wait', json_encode(Triage::autoPlan($m), JSON_UNESCAPED_UNICODE));
$p2 = Db::insert('proposals', ['request_id' => $req2, 'counterparty_id' => $cp]);
ok('КП собрано — ответ пишется', Triage::autoPlan($m)['mode'] === 'now');
Db::q("DELETE FROM proposals WHERE id=?", [$p2]);
ok('вопрос о товаре пишется сразу', Triage::autoPlan($msg(['category' => 'product_question', 'request_id' => $req2]))['mode'] === 'now');
ok('заказ без позиций пишется сразу', Triage::autoPlan($msg(['category' => 'order']))['mode'] === 'now');
ok('на спам не отвечаем', Triage::autoPlan($msg(['category' => 'spam']))['why'] === 'no_prompt');
ok('на звонок не отвечаем', Triage::autoPlan($msg(['category' => 'callback']))['why'] === 'no_prompt');
$q = $msg(['category' => 'product_question']);
Db::insert('mail_messages', ['folder' => 'Sent', 'uid' => 7, 'direction' => 'out', 'subject' => 'Re: Запрос', 'body_text' => 'Ответ', 'from_email' => 'we@atlant-armour.ru',
                             'date_at' => '2026-09-20 12:00:00', 'thread_key' => $q['thread_key'], 'message_id' => '<o@x>']);
ok('уже ответили — второй раз не пишем', Triage::autoPlan($q)['why'] === 'answered');
Settings::set('MAIL_AUTO_REPLY', '0');
ok('опция выключена — ничего', Triage::autoPlan($m)['mode'] === 'off');
Settings::forget('MAIL_AUTO_REPLY');

ok('сервер: auto — план, after — после КП/счёта, черновик один раз', str_contains($mail, "\$plan = Triage::autoPlan(\$msg);")
   && str_contains($mail, "in_array(\$input['after'] ?? '', ['kp', 'invoice'], true)")
   && str_contains($mail, "'cached'          => true"));
ok('браузер: при открытии, после КП и после счёта', str_contains($js, 'if (!restored) this.autoDraft(key);')
   && str_contains($js, "if (opts.reply !== false) this.autoDraftActive('kp');")
   && str_contains($js, "this.autoDraftActive('invoice');")
   && str_contains($js, "{open: false, reply: false}"));
ok('набранное не перезаписывается', str_contains($js, 'if (!id || !area || this.composerHasText(area)) return;')
   && str_contains($js, "if (r.deferred || r.skipped || this.composerHasText(area))"));

// ===================================================================== 3
echo "\n3. Правки ответов учитываются (#149)\n";
Learning::recordSent(['subject' => 'Цена на плиты', 'question' => 'Сколько стоит?', 'correct_answer' => "Добрый день!\nЦена та же.\n--\nИван",
                      'auto_answer' => "Добрый день!\nЦена та же.\n--\nИван", 'context' => ['category' => 'kp_request']]);
ok('без правки учить нечему', Learning::replyLessons('kp_request') === '');
Learning::recordSent(['subject' => 'Сроки', 'question' => 'Когда отгрузка?', 'auto_answer' => "Добрый день!\nУточним сроки и вернёмся.\n--\nИван",
                      'correct_answer' => "Добрый день!\nОтгрузим завтра со склада в Москве.\n--\nИван", 'context' => ['category' => 'availability']]);
Learning::recordSent(['subject' => 'КП', 'question' => 'Пришлите КП', 'auto_answer' => "Готовим КП.\n--\nИван",
                      'correct_answer' => "КП во вложении, цены действуют неделю.\n--\nИван", 'context' => ['category' => 'kp_request']]);
$l = Learning::replyLessons('kp_request');
ok('правка — в промпт примером', str_contains($l, 'КАК МЕНЕДЖЕР ПРАВИЛ ЧЕРНОВИКИ') && str_contains($l, 'цены действуют неделю'));
ok('своя категория — первой', strpos($l, 'цены действуют неделю') < strpos($l, 'Отгрузим завтра'));
ok('подпись примером не служит', !str_contains($l, 'Иван'));
ok('ноль примеров — выключено', Learning::replyLessons('kp_request', 0) === '');
ok('текст блока — промпт reply_lessons', isset(Prompts::registry()['reply_lessons']));
ok('Triage::draft приклеивает правки', str_contains(file_get_contents(ROOT . '/lib/triage.php'), '$system .= Learning::replyLessons($category);'));

// ===================================================================== 4
echo "\n4. База знаний в одном месте, скриншот в черновике (#149)\n";
ok('папки пути — выбором', Settings::SPEC['KNOWLEDGE_PATH'][2] === 'folder' && Settings::SPEC['LEARNING_EXPORT_PATH'][2] === 'folder');
ok('папки репозитория читает сервер', method_exists('Knowledge', 'folders') && str_contains($adm, "case 'knowledge_folders':"));
try { Knowledge::folders('не репо', 'main'); $bad = false; } catch (RuntimeException $e) { $bad = true; }
ok('кривой репозиторий — отказ без запроса', $bad);
ok('вкладка «База знаний»: источник, обновление, пополнение', str_contains($js, "KB_SOURCE_KEYS: ['KNOWLEDGE_ENABLED', 'KNOWLEDGE_REPO', 'KNOWLEDGE_BRANCH', 'KNOWLEDGE_PATH',")
   && str_contains($js, "'GITHUB_TOKEN'") && str_contains($js, 'Обновление базы')
   && str_contains($js, "KB_EXPORT_KEYS: ['LEARNING_EXPORT_REPO', 'LEARNING_EXPORT_BRANCH', 'LEARNING_EXPORT_PATH']")
   && str_contains($js, "App.learningExport(this, () => App.adminKnowledge())"));
ok('поле настройки одно на все вкладки', str_contains($js, 'settingField(it, catalogs = {})')
   && str_contains($js, "\${items.map(it => this.settingRow(it, catalogs)).join('')}")
   && str_contains($js, "App.loadRepoFolders('\${id}'"));
ok('файлы обращения — в черновике формы', str_contains($js, 'data-keep="support.files"')
   && str_contains($js, 'this.supportFilesKeep();') && str_contains($js, "action=outbox_file&name=\${encodeURIComponent(f.name)}"));
ok('скрытое поле хранится, только если попросило', str_contains($js, "if (!dk || /^(password|file|checkbox|radio|number)\$/i.test(el.type)) return null;"));
ok('картинка из вложений отдаётся картинкой', str_contains($mail, "'png'  => 'image/png'"));

echo $fail ? "\nFAILED: $fail\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
