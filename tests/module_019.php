<?php
/**
 * Module 019 end to end, against a throwaway database and with no network:
 * a letter that goes into the archive instead of the bin, a mailbox that is
 * switched off instead of deleted, a mailbox that CAN be deleted even with an
 * archive hanging off it, and the Excel price column that is the default price
 * type rather than a second setting beside it.
 *
 * Run:  php tests/module_019.php
 *
 * It builds its own database in the system temp directory — `data/kp.db` is
 * never opened, so running this on a server cannot touch live data.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-019-' . getmypid() . '.db';
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
require_once ROOT . '/lib/catalog.php';
require_once ROOT . '/lib/catalog_import.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');

// ---------- fixtures: two mailboxes and four letters ----------
$boxA = Db::insert('mailboxes', ['name' => 'Яндекс', 'email' => 'info@atlant-armour.ru',
                                 'is_active' => 1, 'is_default' => 1, 'imap_host' => 'imap.yandex.ru']);
$boxB = Db::insert('mailboxes', ['name' => 'Gmail', 'email' => 'sale@gmail.com',
                                 'is_active' => 1, 'is_default' => 0, 'imap_host' => 'imap.gmail.com']);

function letter(int $box, string $subject, string $from, string $date): int {
    $id = Db::insert('mail_messages', [
        'mailbox_id' => $box, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
        'subject' => $subject, 'from_email' => $from, 'to_emails' => 'info@atlant-armour.ru',
        'body_text' => 'текст письма', 'is_read' => 0, 'date_at' => $date,
    ]);
    MailThreads::assign($id);
    return $id;
}

$kp     = letter($boxA, 'Запрос КП на шлемы',    'logist@voevoda.pro', '2026-09-01 10:00:00');
$paper  = letter($boxA, 'Продаём канцтовары',    'sale@kanc.ru',       '2026-09-02 10:00:00');
$gmail1 = letter($boxB, 'Счёт на оплату',        'buh@zavod.ru',       '2026-09-03 10:00:00');
$gmail2 = letter($boxB, 'Re: Счёт на оплату',    'buh@zavod.ru',       '2026-09-04 10:00:00');

echo "\n== Архив: письмо уходит с экрана, а не из ящика ==\n";
$before = MailThreads::query([])['total'];
MailSync::archiveMessage($paper, null);
$row = Db::one("SELECT * FROM mail_messages WHERE id=?", [$paper]);
ok('письмо осталось в базе', (bool)$row);
ok('помечено «не наш профиль»', ($row['category'] ?? '') === 'not_our_profile', (string)$row['category']);
ok('archived_at проставлен', trim((string)$row['archived_at']) !== '');
ok('цепочка ушла из списка', MailThreads::query([])['total'] === $before - 1);
ok('и видна во вкладке «Архив»', MailThreads::query(['archived' => 1])['total'] === 1);
ok('в счётчик непрочитанных не входит',
   MailThreads::unreadCount() === (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE direction='in' AND is_read=0 AND archived_at IS NULL"));
ok('MailArchive::query тоже его не показывает',
   !in_array($paper, array_column(MailArchive::query(['limit' => 50])['items'], 'id'), false));

MailSync::unarchiveMessage($paper);
$row = Db::one("SELECT * FROM mail_messages WHERE id=?", [$paper]);
ok('возврат в работу снимает архив', $row['archived_at'] === null);
ok('и «не наш профиль» с ним', ($row['category'] ?? '') === 'other', (string)$row['category']);
ok('цепочка вернулась в список', MailThreads::query([])['total'] === $before);

echo "\n== Цепочка целиком ==\n";
$res = MailSync::archiveThread((string)Db::val("SELECT thread_key FROM mail_messages WHERE id=?", [$gmail1]));
ok('в архив ушли оба письма переписки', $res['archived'] === 2, json_encode($res));

echo "\n== Ящик выключают, а не удаляют ==\n";
Mailboxes::setActive($boxA, false, 'hide');
$box = Mailboxes::get($boxA);
ok('ящик выключен', (int)$box['is_active'] === 0);
ok('ящик остался в базе со своими настройками', (string)$box['imap_host'] === 'imap.yandex.ru');
ok('основным стал другой ящик', (int)Db::val("SELECT is_default FROM mailboxes WHERE id=?", [$boxB]) === 1);
ok('его письма скрыты',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE mailbox_id=? AND archived_reason='mailbox_off'", [$boxA]) === 2);
ok('синхронизация его больше не берёт',
   !in_array($boxA, array_map(fn($b) => (int)$b['id'], Mailboxes::all(true)), true));

Mailboxes::setActive($boxA, true);
ok('включение возвращает письма',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE mailbox_id=? AND archived_at IS NOT NULL", [$boxA]) === 0);
ok('но «не наш профиль» из архива не всплывает',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE archived_reason='not_our_profile'") === 2);

echo "\n== Удаление ящика с архивом больше не падает на FOREIGN KEY ==\n";
$err = null;
try { $res = Mailboxes::delete($boxA, 'keep'); } catch (Throwable $e) { $err = $e->getMessage(); }
ok('ящик удалён без ошибки внешнего ключа', $err === null, (string)$err);
ok('письма остались', (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE id IN (?,?)", [$kp, $paper]) === 2);
ok('и потеряли только ящик', Db::val("SELECT mailbox_id FROM mail_messages WHERE id=?", [$kp]) === null);
ok('с экрана они ушли вместе с ящиком',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE id=? AND archived_reason='mailbox_off'", [$kp]) === 1);

$err = null;
try { Mailboxes::delete($boxB, 'delete'); } catch (Throwable $e) { $err = $e->getMessage(); }
ok('второй ящик удалён вместе с письмами', $err === null, (string)$err);
ok('писем этого ящика не осталось',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE id IN (?,?)", [$gmail1, $gmail2]) === 0);
ok('надгробия не ссылаются на удалённый ящик',
   (int)Db::val("SELECT COUNT(*) FROM mail_deleted WHERE mailbox_id IS NOT NULL") === 0);

echo "\n== Колонка цены Excel = тип цены по умолчанию ==\n";
$csv = sys_get_temp_dir() . '/kp-test-019-' . getmypid() . '.csv';
file_put_contents($csv, implode(';', ['UUID', 'Тип', 'Код', 'Наименование', 'Артикул', 'Единица измерения',
                                      'Цена: Розница', 'Цена: Опт', 'Цена: Опт безнал']) . "\n"
    . implode(';', ['u-1', 'Товар', '001', 'Шлем Протон СВМПЭ', 'PRT-1', 'шт', '39 000,00', '31 000,00', '29 500,00']) . "\n");
register_shutdown_function(fn() => @unlink($csv));

Settings::set('CATALOG_DEFAULT_PRICE_TYPE', 'Цена продажи');   // такой колонки в файле нет
$report = CatalogImport::run($csv, 'catalog.csv', []);
ok('позиция импортирована', $report['imported'] === 1, json_encode($report));
ok('цена взята из «Опт безнал»', $report['price_column'] === 'Опт безнал', (string)$report['price_column']);
ok('тип цены по умолчанию стал тем же', (string)Settings::get('CATALOG_DEFAULT_PRICE_TYPE', '') === 'Опт безнал');
ok('и колонка импорта тоже', (string)Settings::get('CATALOG_PRICE_COLUMN', '') === 'Опт безнал');

$p = Db::one("SELECT * FROM products_cache WHERE moysklad_id='u-1'");
$prices = Catalog::decodePrices($p['prices_json'] ?? null);
ok('в базу легли все три типа цен', count($prices) === 3, json_encode($prices, JSON_UNESCAPED_UNICODE));
ok('цена по умолчанию — 29 500', abs(Catalog::priceFor($p) - 29500.0) < 0.01, (string)Catalog::priceFor($p));
ok('типы цен видны в списке', in_array('Розница', Catalog::priceTypes(), true));

// А теперь оператор выбрал розницу — та же одна настройка меняет и КП
Settings::set('CATALOG_DEFAULT_PRICE_TYPE', 'Розница');
$report = CatalogImport::run($csv, 'catalog.csv', []);
ok('импорт взял выбранный тип цены', $report['price_column'] === 'Розница', (string)$report['price_column']);
$p = Db::one("SELECT * FROM products_cache WHERE moysklad_id='u-1'");
ok('и КП считается по ней', abs(Catalog::priceFor($p) - 39000.0) < 0.01, (string)Catalog::priceFor($p));

echo "\n" . ($fail ? "FAILED: $fail\n" : "Все проверки прошли\n");
exit($fail ? 1 : 0);
