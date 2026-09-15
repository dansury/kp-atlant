<?php
/**
 * Модуль 025 — письма разделяются по отправителю, а не по домену.
 *
 * Карточка «Байтек Интернэшнл» собрала тринадцать разных покупателей: заявки
 * шли через сервис-ретранслятор, где у каждой свой адрес и общий домен, а
 * домен считался признаком компании. Проверяется:
 *
 *   — общий домен не склеивает компании, корпоративный — склеивает;
 *   — список общих доменов один на весь сервис: почта и карточки не расходятся;
 *   — свой домен дописывается в настройках;
 *   — сервис узнаёт общий домен сам, увидев на нём две разные компании;
 *   — короткое и полное название одной фирмы за компанию не считаются;
 *   — уже слипшаяся карточка разбирается по отправителям вместе с письмами,
 *     запросами и КП, а переписки пересобираются.
 *
 * Запуск:  php tests/module_025.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-025-' . getmypid() . '.db';
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
require_once ROOT . '/lib/mail_threads.php';

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
        'subject' => 'Запрос КП', 'from_email' => 'client@zavod.ru', 'from_name' => 'Клиент',
        'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'текст', 'is_read' => 0,
        'date_at' => '2026-09-10 10:00:00',
    ]);
}

// =====================================================================  1

echo "\n== 1. Общий домен не значит компанию ==\n";

ok('snipermail.ru — общий', MailDomains::isShared('snipermail.ru'));
ok('и по адресу тоже', MailDomains::isShared('ever@snipermail.ru'));
ok('публичная почта — общая', MailDomains::isShared('mail.ru') && MailDomains::isShared('gmail.com'));
ok('домен завода — нет', !MailDomains::isShared('uralelement.ru'));

ok('ключ переписки с общего домена — адрес',
   MailDomains::party('ever@snipermail.ru') === 'ever@snipermail.ru');
ok('а с корпоративного — домен',
   MailDomains::party('ivanov@uralelement.ru') === 'uralelement.ru');

ok('корпоративного домена у ретранслятора нет',
   Crm::corporateDomain('ever@snipermail.ru') === null);
ok('а у завода есть',
   Crm::corporateDomain('ivanov@uralelement.ru') === 'uralelement.ru');

// =====================================================================  2

echo "\n== 2. Список общих доменов один на весь сервис ==\n";

$mismatch = [];
foreach (['snipermail.ru', 'gmail.com', 'yandex.by', 'qq.com', 'vk.com', 'uralelement.ru'] as $d) {
    $viaCrm  = Crm::corporateDomain("kto-to@$d") === null;
    $viaMail = MailThreads::party(['direction' => 'in', 'from_email' => "kto-to@$d"]) === "kto-to@$d";
    if ($viaCrm !== $viaMail) $mismatch[] = $d;
}
ok('почта и карточки судят о домене одинаково', !$mismatch, implode(', ', $mismatch));
ok('publicEmailDomains берёт тот же список',
   in_array('snipermail.ru', publicEmailDomains(), true) && in_array('qq.com', publicEmailDomains(), true));

// =====================================================================  3

echo "\n== 3. Свой домен дописывается в настройках ==\n";

ok('пока не дописан — домен обычный', !MailDomains::isShared('tenders-relay.ru'));
Settings::set('CRM_SHARED_DOMAINS', 'tenders-relay.ru, zakupki-gate.ru');
ok('дописали — стал общим', MailDomains::isShared('tenders-relay.ru'));
ok('и второй тоже', MailDomains::isShared('kto-to@zakupki-gate.ru'));
Settings::set('CRM_SHARED_DOMAINS', '');

// =====================================================================  4

echo "\n== 4. Ретранслятор больше не собирает всех в одну карточку ==\n";

$a = Crm::resolveCounterparty(['email' => 'ever@snipermail.ru', 'name' => 'ИП Попков Артем Сергеевич']);
$b = Crm::resolveCounterparty(['email' => 'omsksbtender@snipermail.ru', 'name' => 'ООО «Байтек Интернэшнл»']);
$c = Crm::resolveCounterparty(['email' => 'daria080825@snipermail.ru', 'name' => '']);
ok('три заявки с ретранслятора — три карточки',
   $a && $b && $c && $a !== $b && $b !== $c && $a !== $c, "$a / $b / $c");
ok('домен ретранслятора на карточку не записан',
   !Db::val("SELECT COUNT(*) FROM counterparties WHERE email_domain='snipermail.ru'"));

$again = Crm::resolveCounterparty(['email' => 'ever@snipermail.ru', 'name' => 'ИП Попков Артем Сергеевич']);
ok('второе письмо того же ИП — та же карточка', $again === $a, "$again vs $a");

// =====================================================================  5

echo "\n== 5. Коллеги с одного завода остаются вместе ==\n";

$z1 = Crm::resolveCounterparty(['email' => 'snab@uralelement.ru', 'name' => 'АО «Уралэлемент»']);
$z2 = Crm::resolveCounterparty(['email' => 'buh@uralelement.ru', 'name' => '']);
ok('второй адрес завода — та же карточка', $z1 === $z2, "$z1 vs $z2");

$short = Crm::resolveCounterparty(['email' => 'info@baitek-int.ru', 'name' => 'Байтек']);
$long  = Crm::resolveCounterparty(['email' => 'sales@baitek-int.ru', 'name' => 'ООО «Байтек Интернэшнл»']);
ok('короткое и полное название одной фирмы не делят карточку', $short === $long, "$short vs $long");
ok('домен завода общим не признан', !MailDomains::isShared('baitek-int.ru'));

// Из поля From приходит имя человека, а не фирмы: сравнивать его с названием
// в карточке нельзя — иначе каждый второй коллега разваливал бы карточку
$p1 = Crm::resolveCounterparty(['email' => 'zakupki@technotrade.ru', 'name' => 'ООО «Технотрейд»']);
$p2 = Crm::resolveCounterparty(['email' => 'snab@technotrade.ru', 'name' => 'Пётр Иванов']);
ok('имя человека в поле From карточку не делит', $p1 === $p2, "$p1 vs $p2");
ok('и домен из-за него общим не стал', !MailDomains::isShared('technotrade.ru'));

// =====================================================================  6

echo "\n== 6. Сервис узнаёт общий домен сам ==\n";

$x1 = Crm::resolveCounterparty(['email' => 'zayavka1@gate-x.ru', 'name' => 'ООО «Ромашка»']);
ok('первая заявка домен ещё не выдала', !MailDomains::isShared('gate-x.ru'));

$x2 = Crm::resolveCounterparty(['email' => 'zayavka2@gate-x.ru', 'name' => 'ИП Сурков К.А.']);
ok('две разные фирмы на домене — домен признан общим', MailDomains::isShared('gate-x.ru'));
ok('и карточки получились разные', $x1 !== $x2, "$x1 vs $x2");
ok('домен снят и с первой карточки',
   !Db::val("SELECT COUNT(*) FROM counterparties WHERE email_domain='gate-x.ru'"));

$inn1 = Crm::resolveCounterparty(['email' => 'a@gate-y.ru', 'name' => 'Поставка', 'inn' => '7701234567']);
$inn2 = Crm::resolveCounterparty(['email' => 'b@gate-y.ru', 'name' => 'Поставка', 'inn' => '5024998877']);
ok('разные ИНН на одном домене — тоже общий домен', MailDomains::isShared('gate-y.ru'));
ok('и карточки разные', $inn1 !== $inn2, "$inn1 vs $inn2");

// =====================================================================  7

echo "\n== 7. Слипшаяся карточка разбирается по отправителям ==\n";

// Так это выглядело до правки: одна карточка, домен ретранслятора, трое разных
$messy = Db::insert('counterparties', [
    'name' => 'Общество с ограниченной ответственностью «Байтек Интернэшнл»',
    'name_normalized' => normalizeCompanyName('Байтек Интернэшнл'),
    'email_domain' => 'oldrelay.ru', 'contact_email' => 'baitek@oldrelay.ru',
]);
foreach ([['baitek@oldrelay.ru', 'Байтек', 'Запрос КП на каски'],
          ['popkov@oldrelay.ru', 'ИП Попков - Артем Сергеевич', 'Запрос КП'],
          ['daria@oldrelay.ru',  'Дарья',  'Запрос КП']] as [$addr, $who, $subj]) {
    letter(['from_email' => $addr, 'from_name' => $who, 'subject' => $subj,
            'counterparty_id' => $messy, 'body_text' => 'Просим выставить счёт']);
    Db::insert('contacts', ['counterparty_id' => $messy, 'name' => $who, 'email' => $addr]);
    Db::insert('requests', ['source' => 'email', 'raw_text' => 'нужен товар',
                            'counterparty_id' => $messy, 'email_from' => $addr, 'email_subject' => $subj]);
}
letter(['direction' => 'out', 'folder' => 'SENT', 'from_email' => 'info@atlant-armour.ru',
        'to_emails' => 'popkov@oldrelay.ru', 'subject' => 'Re: Запрос КП',
        'counterparty_id' => $messy, 'date_at' => '2026-09-11 10:00:00']);
MailThreads::backfill(true);

$before = Db::val("SELECT COUNT(DISTINCT thread_key) FROM mail_messages WHERE counterparty_id=?", [$messy]);
ok('до разделения «Запрос КП» от двоих — одна переписка', (int)$before === 2, "цепочек: $before");

$created = Crm::splitBySender($messy);
ok('отделились двое, свой адрес карточка оставила себе', count($created) === 2, json_encode($created));
ok('домен ретранслятора признан общим', MailDomains::isShared('oldrelay.ru'));

$popkov = Db::one("SELECT * FROM counterparties WHERE contact_email='popkov@oldrelay.ru'");
ok('карточка названа по подписи из письма',
   $popkov && str_contains((string)$popkov['name'], 'Попков'), (string)($popkov['name'] ?? '—'));
ok('письма ИП ушли за ним',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE counterparty_id=?", [$popkov['id']]) === 2);
ok('и запрос тоже',
   (int)Db::val("SELECT COUNT(*) FROM requests WHERE counterparty_id=?", [$popkov['id']]) === 1);
ok('исходное письмо мы ему же и писали',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE counterparty_id=? AND direction='out'",
                [$popkov['id']]) === 1);

ok('в исходной карточке остался один отправитель', count(Crm::sendersOf($messy)) === 1,
   json_encode(array_keys(Crm::sendersOf($messy))));
ok('домен с исходной карточки снят',
   Db::val("SELECT email_domain FROM counterparties WHERE id=?", [$messy]) === null);
ok('«Запрос КП» от двоих стал двумя переписками — не одной',
   Db::val("SELECT thread_key FROM mail_messages WHERE from_email='popkov@oldrelay.ru'")
   !== Db::val("SELECT thread_key FROM mail_messages WHERE from_email='daria@oldrelay.ru'"));

$next = Crm::resolveCounterparty(['email' => 'novy@oldrelay.ru', 'name' => 'ООО «Новый»']);
ok('следующее письмо с того же домена карточку не собирает заново',
   $next !== $messy && $next !== (int)$popkov['id'], (string)$next);

// Подчёркивание в адресе — буква, а не «любой символ»: соседний адрес,
// отличающийся ровно в этом месте, уехать вместе с ним не должен
$tricky = Db::insert('counterparties', [
    'name' => 'Сборная карточка', 'email_domain' => 'relay-u.ru', 'contact_email' => 'adm_postavka@relay-u.ru',
]);
foreach (['adm_postavka@relay-u.ru', 'admXpostavka@relay-u.ru'] as $addr) {
    letter(['from_email' => $addr, 'from_name' => $addr, 'counterparty_id' => $tricky,
            'subject' => 'Прайс', 'body_text' => 'прайс']);
}
Crm::splitBySender($tricky);
ok('подчёркивание в адресе не утащило соседа',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE counterparty_id=?", [$tricky]) === 1,
   (string)Db::val("SELECT COUNT(*) FROM mail_messages WHERE counterparty_id=?", [$tricky]));
ok('и сосед получил свою карточку',
   (int)Db::val("SELECT COUNT(*) FROM counterparties WHERE lower(contact_email)=?",
                ['admxpostavka@relay-u.ru']) === 1);

// =====================================================================  8

echo "\n== 8. Обновление чинит то, что уже слиплось ==\n";

// Карточка ровно в том виде, в каком её завела старая версия: домен
// ретранслятора записан в ключ, за ним три разных покупателя
$old = Db::insert('counterparties', [
    'name' => 'Общество с ограниченной ответственностью «Байтек Интернэшнл»',
    'name_normalized' => normalizeCompanyName('Байтек Интернэшнл'),
    'email_domain' => 'snipermail.ru', 'contact_email' => 'baitek@snipermail.ru',
]);
foreach (['baitek@snipermail.ru', 'ever@snipermail.ru', 'lutsenko@snipermail.ru'] as $addr) {
    letter(['from_email' => $addr, 'from_name' => $addr, 'subject' => 'Запрос КП',
            'counterparty_id' => $old, 'body_text' => 'Просим КП']);
}
ok('до обновления все трое в одной карточке', count(Crm::sendersOf($old)) === 3);

Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '25')");
runMigrations();

ok('после обновления в карточке остался один', count(Crm::sendersOf($old)) === 1,
   json_encode(array_keys(Crm::sendersOf($old))));
foreach (['ever@snipermail.ru', 'lutsenko@snipermail.ru'] as $addr) {
    $cards = Db::all("SELECT id FROM counterparties WHERE lower(contact_email)=? AND merged_into_id IS NULL", [$addr]);
    ok("$addr — своя карточка, и ровно одна", count($cards) === 1, 'карточек: ' . count($cards));
    ok("и письмо $addr уехало за ней",
       (int)Db::val("SELECT counterparty_id FROM mail_messages WHERE from_email=? ORDER BY id DESC LIMIT 1", [$addr])
       === (int)$cards[0]['id']);
}
ok('домен ретранслятора снят со всех карточек',
   !Db::val("SELECT COUNT(*) FROM counterparties WHERE email_domain='snipermail.ru'"));
ok('версия схемы поднялась', (int)Db::val("SELECT value FROM settings WHERE key='schema_version'") >= 26);

// =====================================================================

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "Все проверки прошли.\n");
exit($fail ? 1 : 0);
