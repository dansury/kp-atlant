<?php
/**
 * Модуль 031 целиком, на выбрасываемой базе и без сети:
 *
 *   — ответ несёт цитату письма, на которое отвечает: шапка, текст под «>»,
 *     `blockquote` в HTML, без второго круга истории и без повтора;
 *   — «убрать с доски» держится, а новое письмо возвращает карточку;
 *   — слияние компаний не заводит вторую карточку во «Входящих»;
 *   — заметка удаляется, веха сделки — нет;
 *   — из переписки удаляется одно письмо, остальные остаются;
 *   — в коде не осталось `imagedestroy()`, а в переписке — вложенных
 *     прокруток и обрезки.
 *
 * Запуск:  php tests/module_031.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-031-' . getmypid() . '.db';
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
require_once ROOT . '/lib/mail_text.php';
require_once ROOT . '/lib/mail_threads.php';
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/crm.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');

$mgr = Db::insert('managers', ['login' => 'yana', 'name' => 'Яна',
                               'email' => 'yana@atlant-armour.ru',
                               'password_hash' => 'x', 'is_admin' => 1]);
$boxId = Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru',
                                  'is_active' => 1, 'is_default' => 1]);

/** Письмо в базе — ровно столько полей, сколько нужно проверке. */
function letter(array $o): int {
    return Db::insert('mail_messages', $o + [
        'mailbox_id' => $GLOBALS['boxId'], 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
        'subject' => 'Тема', 'from_email' => 'client@zavod.ru', 'from_name' => 'Клиент',
        'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'текст', 'is_read' => 0,
        'date_at' => '2026-09-10 10:00:00',
    ]);
}

// =====================================================================  1

echo "\n== 1. Ответ несёт письмо, на которое отвечает ==\n";

$src = [
    'date_at'    => '2026-09-14 19:23:29',
    'from_name'  => 'Atlant Armour',
    'from_email' => 'info@atlant-armour.ru',
    'body_text'  => "Укажите, пожалуйста, контактное лицо: телефон и ФИО.\nВторая строка.",
];

$header = MailText::quoteHeader($src);
ok('в шапке цитаты дата письма', str_contains($header, '14 сентября 2026') && str_contains($header, '19:23'), $header);
ok('и его автор с адресом', str_contains($header, 'Atlant Armour <info@atlant-armour.ru>'), $header);

$plain = MailText::quoteText($src);
ok('каждая строка цитаты под знаком «>»',
   str_contains($plain, '> Укажите, пожалуйста, контактное лицо: телефон и ФИО.')
   && str_contains($plain, '> Вторая строка.'), $plain);

$res = MailText::withQuote('Добрый день! Счёт во вложении.', '<p>Добрый день! Счёт во вложении.</p>', $src);
ok('свой текст остался первым', str_starts_with($res['text'], 'Добрый день! Счёт во вложении.'), $res['text']);
ok('цитата приписана снизу', str_contains($res['text'], $header), $res['text']);
ok('в HTML цитата — blockquote', str_contains($res['html'], '<blockquote'), $res['html']);
ok('и текст в ней экранирован',
   !str_contains($res['html'], '<script'), $res['html']);

// Второй круг: цитировать надо ТО письмо, а не всю историю под ним
$round2 = $src;
$round2['body_text'] = "Новый вопрос по счёту.\n\n"
    . "13 сентября 2026, 10:00 +03:00 от Клиент <c@z.ru>:\n> старая история\n> ещё старее";
$deep = MailText::quoteText($round2);
ok('старая история в цитату не попадает', !str_contains($deep, 'старая история'), $deep);
ok('а новое письмо — попадает', str_contains($deep, '> Новый вопрос по счёту.'), $deep);
ok('шапка прошлого круга — тоже история, и её в цитате нет',
   !str_contains($deep, '13 сентября 2026'), $deep);

// Дважды одно и то же письмо не цитируется
$twice = MailText::withQuote($res['text'], $res['html'], $src);
ok('повторная цитата не приписывается',
   substr_count($twice['text'], $header) === 1, (string)substr_count($twice['text'], $header));

ok('без исходного письма ответ не меняется',
   MailText::withQuote('Просто письмо', '<p>Просто письмо</p>', null)['text'] === 'Просто письмо');

$empty = MailText::withQuote('Текст', '<p>Текст</p>', ['date_at' => '2026-09-14 10:00:00', 'body_text' => '']);
ok('пустое исходное письмо цитировать нечем', $empty['text'] === 'Текст', $empty['text']);

// =====================================================================  2

echo "\n== 2. «Убрать с доски» держится ==\n";

$cpA = Db::insert('counterparties', ['name' => 'ООО «Альфа»']);
$cpB = Db::insert('counterparties', ['name' => 'ООО «Бета»']);
letter(['thread_key' => 's:a1', 'counterparty_id' => $cpA, 'date_at' => '2026-09-10 10:00:00']);
letter(['thread_key' => 's:b1', 'counterparty_id' => $cpB, 'date_at' => '2026-09-10 11:00:00']);

$board = (int)Boards::singleton()['id'];
Boards::sync($board, true);
$cards = fn() => (int)Db::val("SELECT COUNT(*) FROM board_cards d JOIN board_columns c ON c.id=d.column_id
                               WHERE c.board_id=? AND d.dismissed_at IS NULL", [$board]);
ok('интейк завёл карточку каждой компании', $cards() === 2, (string)$cards());

// Разложим по колонкам — это и есть «разобранная доска»
$work = (int)Db::val("SELECT id FROM board_columns WHERE board_id=? AND kind IS NULL ORDER BY position LIMIT 1", [$board]);
$ids = array_map('intval', array_column(Db::all("SELECT id FROM board_cards"), 'id'));
foreach ($ids as $id) Boards::moveCard($id, $work, PHP_INT_MAX);

$columnOf = fn(int $id) => (int)Db::val("SELECT column_id FROM board_cards WHERE id=?", [$id]);

// «Прочитано» не трогает раскладку
Boards::bulk($ids, 'read', [], $mgr);
Boards::sync($board);
ok('«прочитано» оставляет карточки в своей колонке',
   $columnOf($ids[0]) === $work && $columnOf($ids[1]) === $work);

// «Убрать с доски» — и доска не возвращает их следующим открытием
$res = Boards::bulk($ids, 'remove', [], $mgr);
ok('снятие отчиталось по каждой карточке', $res['done'] === 2, json_encode($res));
ok('с доски они ушли', $cards() === 0, (string)$cards());
Boards::sync($board);
ok('и после открытия доски не вернулись', $cards() === 0, (string)$cards());
ok('а строки живы и помнят колонку',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE dismissed_at IS NOT NULL") === 2
   && $columnOf($ids[0]) === $work);

ok('снятой карточки нет и в размещении компании', Boards::companyPlacement($cpA) === []);

// Новое письмо возвращает карточку — во «Входящие»
letter(['thread_key' => 's:a2', 'counterparty_id' => $cpA, 'date_at' => date('Y-m-d H:i:s', time() + 60)]);
Boards::sync($board, true);
$inbox = (int)Boards::inboxColumn($board)['id'];
ok('новое письмо вернуло карточку', $cards() === 1, (string)$cards());
ok('и вернуло её во «Входящие»', $columnOf($ids[0]) === $inbox);
ok('вторую карточку той же компании интейк не завёл',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE counterparty_id=?", [$cpA]) === 1);
ok('а молчащая компания на доску не вернулась', $columnOf($ids[1]) === $work
   && Db::val("SELECT dismissed_at FROM board_cards WHERE id=?", [$ids[1]]) !== null);

// «Положить в колонку» тоже возвращает снятую карточку
Boards::addCard($work, ['counterparty_id' => $cpB]);
ok('«в колонку» возвращает снятую карточку', $cards() === 2, (string)$cards());

// =====================================================================  3

echo "\n== 3. Слияние компаний не сваливает карточку во «Входящие» ==\n";

$cpOld = Db::insert('counterparties', ['name' => 'ООО «Старая»']);
$cpNew = Db::insert('counterparties', ['name' => 'ООО «Новая»']);
letter(['thread_key' => 's:o1', 'counterparty_id' => $cpOld, 'date_at' => '2026-09-12 09:00:00']);
Boards::sync($board, true);
$cardOld = (int)Db::val("SELECT id FROM board_cards WHERE counterparty_id=?", [$cpOld]);
Boards::moveCard($cardOld, $work, PHP_INT_MAX);

Crm::merge($cpOld, $cpNew);
Boards::sync($board, true);
ok('вторая карточка во «Входящих» не появилась',
   (int)Db::val("SELECT COUNT(*) FROM board_cards d JOIN board_columns c ON c.id=d.column_id
                 WHERE c.board_id=? AND d.counterparty_id IN (?,?)", [$board, $cpOld, $cpNew]) === 1);
ok('карточка осталась в своей колонке', $columnOf($cardOld) === $work);
ok('и переехала на компанию-приёмник',
   (int)Db::val("SELECT counterparty_id FROM board_cards WHERE id=?", [$cardOld]) === $cpNew);
ok('размещение компании-приёмника её находит',
   count(Boards::companyPlacement($cpNew)) === 1);

// =====================================================================  4

echo "\n== 4. Заметка удаляется, веха сделки — нет ==\n";

$noteId = Crm::logEvent($cpA, 'note', 'Перезвонить после обеда',
                        ['manager_id' => $mgr, 'subject' => 'Заметка']);
$eventId = Crm::logEvent($cpA, 'out', 'КП отправлено клиенту',
                         ['manager_id' => $mgr, 'event_type' => 'kp_sent']);
$feed = Crm::chat($cpA);
ok('заметка видна в ленте карточки',
   in_array('note', array_column($feed, 'kind'), true), json_encode(array_column($feed, 'kind')));

$row = Db::one("SELECT direction, event_type FROM correspondence WHERE id=?", [$noteId]);
ok('заметка — это direction=note без event_type',
   $row['direction'] === 'note' && $row['event_type'] === null, json_encode($row));
$ev = Db::one("SELECT direction, event_type FROM correspondence WHERE id=?", [$eventId]);
ok('веха сделки под это правило не подпадает', !empty($ev['event_type']), json_encode($ev));

Db::q("DELETE FROM correspondence WHERE id=? AND direction='note' AND event_type IS NULL", [$noteId]);
ok('заметка удалилась', Db::one("SELECT id FROM correspondence WHERE id=?", [$noteId]) === null);
ok('а веха осталась', Db::one("SELECT id FROM correspondence WHERE id=?", [$eventId]) !== null);

// Заметка карточки доски приезжает в карточку компании
$cardA = (int)Db::val("SELECT id FROM board_cards WHERE counterparty_id=?", [$cpA]);
Boards::updateCard($cardA, ['note' => 'Ждёт счёт до пятницы']);
$place = Boards::companyPlacement($cpA);
ok('заметка с доски видна в карточке компании',
   ($place[0]['note'] ?? '') === 'Ждёт счёт до пятницы', json_encode($place[0] ?? []));
Boards::updateCard($cardA, ['note' => '']);
ok('и пустой текст её убирает',
   (Boards::companyPlacement($cpA)[0]['note'] ?? null) === null);

// =====================================================================  5

echo "\n== 5. Из переписки удаляется одно письмо ==\n";

$m1 = letter(['thread_key' => 's:del', 'counterparty_id' => $cpA, 'subject' => 'Первое',
              'date_at' => '2026-09-11 10:00:00']);
$m2 = letter(['thread_key' => 's:del', 'counterparty_id' => $cpA, 'subject' => 'Второе',
              'date_at' => '2026-09-11 11:00:00']);
$res = MailSync::deleteMessage($m1, $mgr);
ok('удалено ровно одно письмо',
   Db::one("SELECT id FROM mail_messages WHERE id=?", [$m1]) === null
   && Db::one("SELECT id FROM mail_messages WHERE id=?", [$m2]) !== null);
ok('и переписка не считается опустевшей', empty($res['thread_empty']), json_encode($res));

$res = MailSync::deleteMessage($m2, $mgr);
ok('последнее письмо — переписка пуста', !empty($res['thread_empty']), json_encode($res));

// =====================================================================  6

echo "\n== 6. Код: deprecated-вызовы и вложенные слои ==\n";

$php = [];
foreach (['lib', 'public', 'tests'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/' . $dir));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php' && !str_contains($f->getPathname(), '/pdfparser/')) {
            $php[] = $f->getPathname();
        }
    }
}
// Ищем ВЫЗОВ, а не слово: про снятый imagedestroy() написано в комментариях
// рядом с ним самим, и поиск подстрокой ловил бы собственное объяснение
$calls = function (string $file): bool {
    $tokens = @token_get_all((string)file_get_contents($file));
    foreach ($tokens as $i => $t) {
        if (!is_array($t) || $t[0] !== T_STRING || strtolower($t[1]) !== 'imagedestroy') continue;
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            if ($tokens[$j] === '(') return true;
            break;
        }
    }
    return false;
};
$withDestroy = array_values(array_filter($php, $calls));
ok('imagedestroy() в коде не вызывается', $withDestroy === [], implode(', ', $withDestroy));

$css = (string)file_get_contents(ROOT . '/public/assets/css/app.css');
ok('у переписки нет своей полосы прокрутки',
   !preg_match('/\.thread\s*\{[^}]*overflow-y:\s*(auto|scroll)/u', $css)
   && !str_contains($css, '.thread-inline .thread'), 'app.css');
ok('карточка-список ничего не обрезает',
   !preg_match('/\.card--flush\s*\{[^}]*overflow:\s*hidden/u', $css), 'app.css');
ok('старой разметки письма (.tmsg) больше нет', !str_contains($css, '.tmsg'), 'app.css');
ok('строка списка — flex, а не сетка с жёсткими колонками',
   preg_match('/\.mrow\s*\{[^}]*display:\s*flex/u', $css) === 1, 'app.css');

$js = (string)file_get_contents(ROOT . '/public/assets/js/app.js');
ok('рамка письма строится при раскрытии', str_contains($js, 'mountBodies('), 'app.js');
ok('и следит за своим документом', str_contains($js, 'ResizeObserver'), 'app.js');
ok('лента писем рисуется одной функцией на оба экрана',
   substr_count($js, 'threadHtml(') === 3, (string)substr_count($js, 'threadHtml('));
ok('поле ответа отправляет и разметку, а не только текст',
   str_contains($js, 'const {text, html} = this.composerBody(c);'), 'app.js');

$api = (string)file_get_contents(ROOT . '/public/api/mail.php');
ok('отправка приписывает цитату исходного письма',
   str_contains($api, 'MailText::withQuote($text, $html, $source)'), 'mail.php');
ok('и прогоняет нашу разметку через тот же фильтр, что и входящую',
   str_contains($api, "MailArchive::sanitizeHtml(\$html)"), 'mail.php');

$boardsApi = (string)file_get_contents(ROOT . '/public/api/boards.php');
ok('«убрать с доски» — снятие, а не удаление строки',
   str_contains($boardsApi, 'Boards::dismissCard('), 'boards.php');

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "Все проверки прошли.\n");
exit($fail ? 1 : 0);
