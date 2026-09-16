<?php
/**
 * Отправленные письма забираются из ящиков — на выбрасываемой базе и без сети:
 *
 *   — ящик, у которого папку отправленных ещё ни разу не открывали, начинает
 *     с ПОСЛЕДНИХ писем, а не с самых старых;
 *   — уточнённое имя папки обнуляет счётчик UID: он принадлежал другой папке;
 *   — письмо, отправленное мимо сервиса, ложится на карточку компании и
 *     снимает с неё «клиент ждёт ответа» — датой самого письма;
 *   — письмо старше уже известного ответа не двигает отметку назад;
 *   — второй разбор того же письма не заводит вторую отметку в ленте.
 *
 * Запуск:  php tests/module_027.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-027-' . getmypid() . '.db';
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
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/mailsync.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');

$boxId = Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru',
                                  'is_active' => 1, 'is_default' => 1,
                                  'sync_sent' => 1, 'imap_folder_sent' => 'INBOX.Sent']);

/** Ящик в роли IMAP-сервера: отвечает ровно на то, что спрашивает синхронизация. */
final class FakeReader {
    public array $asked = [];
    public function fetchSince(int $since, int $limit): array {
        $this->asked[] = "since:$since";
        return [];
    }
    public function fetchLatest(int $limit): array {
        $this->asked[] = "latest:$limit";
        return [];
    }
}

// =====================================================================  1

echo "\n== 1. Первый заход в папку берёт последние письма ==\n";

$reader = new FakeReader();
MailSync::fetchBatch($reader, 0, 50);
ok('папку не забирали ни разу — берём последние письма', $reader->asked === ['latest:50'],
   implode(',', $reader->asked));

$reader = new FakeReader();
MailSync::fetchBatch($reader, 1204, 50);
ok('счётчик есть — берём всё, что новее его', $reader->asked === ['since:1204'],
   implode(',', $reader->asked));

// =====================================================================  2

echo "\n== 2. Уточнённая папка отправленных ==\n";

Db::update('mailboxes', ['last_uid_sent' => 900, 'backfill_done_sent' => 1], 'id=?', [$boxId]);
Mailboxes::rememberSentFolder($boxId, 'Отправленные', 'INBOX.Sent');
$box = Mailboxes::get($boxId);
ok('имя папки с сервера запомнено', $box['imap_folder_sent'] === 'Отправленные');
ok('счётчик прежней папки обнулён', (int)$box['last_uid_sent'] === 0);
ok('и архив этой папки качается заново', (int)$box['backfill_done_sent'] === 0);

Db::update('mailboxes', ['last_uid_sent' => 15], 'id=?', [$boxId]);
Mailboxes::rememberSentFolder($boxId, 'Отправленные', 'Отправленные');
ok('имя не изменилось — счётчик на месте', (int)Mailboxes::get($boxId)['last_uid_sent'] === 15);

// =====================================================================  3

echo "\n== 3. Ответ, написанный мимо сервиса ==\n";

$cpId = Db::insert('counterparties', ['name' => 'ООО «Завод»', 'email_domain' => 'zavod.ru',
                                      'name_normalized' => normalizeCompanyName('ООО «Завод»'),
                                      'contact_email' => 'client@zavod.ru']);
Crm::logEvent($cpId, 'in', 'Пришлите КП на шлемы', ['at' => '2026-09-14 09:00:00']);
$state = fn() => Crm::answerState(
    (string)Db::val("SELECT last_inbound_at FROM counterparties WHERE id=?", [$cpId]) ?: null,
    (string)Db::val("SELECT last_outbound_at FROM counterparties WHERE id=?", [$cpId]) ?: null);
ok('клиент написал — карточка ждёт ответа', $state()['unanswered']);

$sentId = Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'out', 'folder' => 'Отправленные', 'uid' => 17,
    'subject' => 'Re: Шлемы', 'from_email' => 'info@atlant-armour.ru',
    'to_emails' => 'client@zavod.ru', 'body_text' => 'Отправил с телефона, КП во вложении',
    'date_at' => '2026-09-14 19:30:00', 'is_read' => 1,
]);
MailSync::registerOutbound($sentId);

$row = Db::one("SELECT * FROM mail_messages WHERE id=?", [$sentId]);
ok('письмо легло на карточку компании', (int)$row['counterparty_id'] === $cpId);
ok('и отмечено в ленте', !empty($row['correspondence_id']));
ok('клиент больше не ждёт ответа', !$state()['unanswered']);
ok('ответ датирован собой, а не синхронизацией',
   (string)Db::val("SELECT last_outbound_at FROM counterparties WHERE id=?", [$cpId]) === '2026-09-14 19:30:00');

$feed = Crm::chat($cpId);
ok('письмо не засоряет ленту заметок и вех', $feed === []);
ok('но в полной ленте оно есть', count(Crm::chat($cpId, 50, 0, true)) === 2);

$corrBefore = (int)Db::val("SELECT COUNT(*) FROM correspondence WHERE counterparty_id=?", [$cpId]);
MailSync::registerOutbound($sentId);
ok('повторный разбор того же письма ничего не дублирует',
   (int)Db::val("SELECT COUNT(*) FROM correspondence WHERE counterparty_id=?", [$cpId]) === $corrBefore);

// =====================================================================  4

echo "\n== 4. Старое письмо не двигает отметку назад ==\n";

$oldId = Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'out', 'folder' => 'Отправленные', 'uid' => 3,
    'subject' => 'Прайс', 'from_email' => 'info@atlant-armour.ru',
    'to_emails' => 'client@zavod.ru', 'body_text' => 'Прайс за прошлый год',
    'date_at' => '2025-02-01 12:00:00', 'is_read' => 1,
]);
MailSync::registerOutbound($oldId);
ok('позапрошлогоднее письмо отметку не сдвинуло',
   (string)Db::val("SELECT last_outbound_at FROM counterparties WHERE id=?", [$cpId]) === '2026-09-14 19:30:00');
ok('но на карточке компании оно есть',
   (int)Db::val("SELECT counterparty_id FROM mail_messages WHERE id=?", [$oldId]) === $cpId);

// =====================================================================

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
