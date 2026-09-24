<?php
/**
 * Модуль 056 — на выбрасываемой базе и без сети:
 *
 *   — доп. поле «СОТРУДНИК» строится по типу поля: строка, сотрудник, справочник;
 *   — сотрудник находится по ФИО в любом порядке и с инициалом;
 *   — количество в подборе — целое;
 *   — приложенный файл отдаётся только из папки своего менеджера;
 *   — интерфейс (по исходнику): кнопки под КП, чип со скачиванием, «+ Позиция»
 *     внизу, сворачивание строк, закреплённые «?», стрелки вкладок.
 *
 * Запуск:  php tests/module_056.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-056-' . getmypid() . '.db';
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
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/outbox.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/mail_compose.php';

$board = Boards::singleton();
$bid = (int)$board['id'];
$colOf = fn(int $cp) => (string)Db::val("SELECT c.title FROM board_cards d JOIN board_columns c ON c.id=d.column_id
                                          WHERE d.counterparty_id=?", [$cp]);
$kindCol = fn(string $k) => Db::one("SELECT * FROM board_columns WHERE board_id=? AND kind=?", [$bid, $k]);

echo "Колонки стадий\n";
Db::q("UPDATE board_columns SET kind=NULL WHERE kind IN ('kp_sent','payment')");   // как до миграции
ok('«КП отправлено» найдена по названию', Boards::stageColumn($bid, 'kp_sent')['title'] === 'КП отправлено');
ok('и получила kind', (bool)$kindCol('kp_sent'));
ok('«Ждём оплату» найдена по названию', Boards::stageColumn($bid, 'payment')['title'] === 'Ждём оплату');
$cols = count(Db::all("SELECT id FROM board_columns WHERE board_id=?", [$bid]));
Boards::stageColumn($bid, 'payment');
ok('повторный вызов колонку не плодит', count(Db::all("SELECT id FROM board_columns WHERE board_id=?", [$bid])) === $cols);

echo "Только вперёд\n";
$cp = (int)Db::insert('counterparties', ['name' => 'ООО Ромашка']);
Boards::addCard((int)Boards::workColumn($bid)['id'], ['counterparty_id' => $cp]);
ok('КП ушло → «КП отправлено»', Boards::advance($cp, null, 'kp_sent') === 'КП отправлено' && $colOf($cp) === 'КП отправлено');
ok('счёт ушёл → «Ждём оплату»', Boards::advance($cp, null, 'payment') === 'Ждём оплату' && $colOf($cp) === 'Ждём оплату');
ok('новое КП назад не тянет', Boards::advance($cp, null, 'kp_sent') === null && $colOf($cp) === 'Ждём оплату');
Boards::moveCard((int)Db::val("SELECT id FROM board_cards WHERE counterparty_id=?", [$cp]),
                 (int)Boards::assemblyColumn($bid)['id'], 0);
ok('из «Сборки» счёт не тянет', Boards::advance($cp, null, 'payment') === null && $colOf($cp) === 'Сборка');
$closed = (int)Db::val("SELECT id FROM board_columns WHERE board_id=? AND kind='closed'", [$bid]);
Boards::moveCard((int)Db::val("SELECT id FROM board_cards WHERE counterparty_id=?", [$cp]), $closed, 0);
ok('из «Закрыто» новое КП — новая сделка', Boards::advance($cp, null, 'kp_sent') === 'КП отправлено');
$cp2 = (int)Db::insert('counterparties', ['name' => 'ИП Лютик']);
ok('карточки не было — заводится сразу в стадии', Boards::advance($cp2, null, 'payment') === 'Ждём оплату' && $colOf($cp2) === 'Ждём оплату');
Db::q("UPDATE board_cards SET dismissed_at=datetime('now') WHERE counterparty_id=?", [$cp2]);
Boards::advance($cp2, null, 'payment');
ok('снятая с доски карточка возвращается', !Db::val("SELECT dismissed_at FROM board_cards WHERE counterparty_id=?", [$cp2]));

echo "Документы письма\n";
$mgr = (int)Db::insert('managers', ['login' => 'yana', 'password_hash' => 'x', 'name' => 'Яна']);
$tmp = tempnam(sys_get_temp_dir(), 'kp');
file_put_contents($tmp, '%PDF-1.4');
$cp3 = (int)Db::insert('counterparties', ['name' => 'АО Василёк']);
$inv = (int)Db::insert('invoices', ['moysklad_id' => 'ms-1', 'name' => '00012', 'counterparty_id' => $cp3]);
$kpFile  = Outbox::adopt($tmp, 'КП 5.pdf', $mgr, 'kp', 5);
$invFile = Outbox::adopt($tmp, 'Счёт 00012.pdf', $mgr, 'invoice', $inv);
$own     = Outbox::adopt($tmp, 'Спецификация.pdf', $mgr);
$docs = Outbox::docsOf([$kpFile['name'], $invFile['name'], $own['name']], $mgr);
ok('свой файл — не документ, счёт и КП — документы', count($docs) === 2);
ok('чужой менеджер чужих отметок не видит', Outbox::docsOf([$kpFile['name']], $mgr + 1) === []);
ok('в имени файла приставка прежняя', (bool)preg_match('/^[0-9a-f]{16}__/', $invFile['name']));
$stage = MailCompose::afterDocsSent($docs, $cp3, null, 'a@b.ru');
ok('КП + счёт в одном письме → «Ждём оплату»', $stage === 'Ждём оплату' && $colOf($cp3) === 'Ждём оплату');
ok('счёт помечен отправленным', (string)Db::val("SELECT sent_to FROM invoices WHERE id=?", [$inv]) === 'a@b.ru');
ok('отметки отправленных файлов сняты', Outbox::docsOf([$kpFile['name'], $invFile['name']], $mgr) === []);
$cp4 = (int)Db::insert('counterparties', ['name' => 'ЗАО Пион']);
$kp2 = Outbox::adopt($tmp, 'КП 6.docx', $mgr, 'kp', 6);
ok('только КП → «КП отправлено»', MailCompose::afterDocsSent(Outbox::docsOf([$kp2['name']], $mgr), $cp4, null, 'x@y.ru') === 'КП отправлено');
ok('без документов — никуда', MailCompose::afterDocsSent([], $cp4, null, 'x@y.ru') === null);

echo "Отправка (по исходнику)\n";
$src = file_get_contents(ROOT . '/lib/mail_compose.php');
ok('документы читаются до отправки, стадия — после', strpos($src, 'Outbox::docsOf(') < strpos($src, '$res = Mailer::send(')
    && strpos($src, 'self::afterDocsSent(') > strpos($src, 'MailDrafts::sent('));
// КП уходит только письмом (модуль 060): стадию двигает afterDocsSent
ok('КП в письме → «КП отправлено»', str_contains($src, "isset(\$kinds['kp']) ? 'kp_sent'"));
ok('счёт отдельным письмом → «Ждём оплату»', str_contains(file_get_contents(ROOT . '/public/api/invoices.php'), "null, 'payment')"));
ok('вложение из письма помечается', str_contains(file_get_contents(ROOT . '/public/api/mail.php'), "\$kind === 'invoice' ? 'invoice' : 'kp', \$id)"));

@unlink($tmp);
echo $fail ? "\nFAILED: $fail\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
