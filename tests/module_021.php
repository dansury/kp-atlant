<?php
/**
 * Module 021 end to end, against a throwaway database and with no network:
 * an mbox file that becomes correspondence on the company cards, the same
 * letter that never lands twice — not from a second mailbox, not from the
 * «Отправленные» folder, not from a second import — and the logo an operator
 * uploads instead of the bundled one.
 *
 * Run:  php tests/module_021.php
 *
 * It builds its own database in the system temp directory — `data/kp.db` is
 * never opened, so running this on a server cannot touch live data.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-021-' . getmypid() . '.db';
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
require_once ROOT . '/lib/mime.php';
require_once ROOT . '/lib/mbox.php';
require_once ROOT . '/lib/branding.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('KNOWLEDGE_ENABLED', '0');

// Files the test writes go to the temp dir, never into the repository's storage
$sandbox = sys_get_temp_dir() . '/kp-test-021-files-' . getmypid();
@mkdir($sandbox, 0755, true);
register_shutdown_function(function () use ($sandbox) {
    foreach (glob($sandbox . '/*') ?: [] as $f) @unlink($f);
    @rmdir($sandbox);
});

// ---------- fixtures ----------
$boxId = Db::insert('mailboxes', ['name' => 'Gmail', 'email' => 'atlantarmourmed@gmail.com',
                                  'is_active' => 1, 'is_default' => 1, 'imap_host' => 'imap.gmail.com']);
$box = Mailboxes::get($boxId);
$cpId = Db::insert('counterparties', [
    'name' => 'ООО «Технотрейд»', 'name_normalized' => normalizeCompanyName('ООО «Технотрейд»'),
    'email_domain' => 'technotrade.ru',
]);

/** One mbox record, built the way Gmail writes it. */
function mboxLetter(string $from, string $to, string $subject, string $body,
                    string $date, string $messageId, string $labels = '', array $opts = []): string {
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $out = "From $from " . date('D M j H:i:s Y', strtotime($date)) . "\n";
    $out .= "Message-ID: <$messageId>\n";
    if ($labels !== '') $out .= "X-Gmail-Labels: $labels\n";
    $out .= "Date: " . date('r', strtotime($date)) . "\n";
    $out .= "From: " . ($opts['from_name'] ?? 'Пётр Иванов') . " <$from>\n";
    $out .= "To: <$to>\n";
    $out .= "Subject: $encodedSubject\n";
    $out .= "MIME-Version: 1.0\n";

    if (!empty($opts['attachment'])) {
        $boundary = 'b0undary' . substr(md5($messageId), 0, 8);
        $out .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\n\n";
        $out .= "--$boundary\n";
        $out .= "Content-Type: text/plain; charset=windows-1251\n";
        $out .= "Content-Transfer-Encoding: quoted-printable\n\n";
        $out .= quoted_printable_encode((string)mb_convert_encoding($body, 'Windows-1251', 'UTF-8')) . "\n";
        $out .= "--$boundary\n";
        $out .= "Content-Type: application/pdf; name=\"=?UTF-8?B?" . base64_encode($opts['attachment'][0]) . "?=\"\n";
        $out .= "Content-Disposition: attachment; filename=\"=?UTF-8?B?" . base64_encode($opts['attachment'][0]) . "?=\"\n";
        $out .= "Content-Transfer-Encoding: base64\n\n";
        $out .= chunk_split(base64_encode($opts['attachment'][1]), 76, "\n");
        $out .= "--$boundary--\n";
    } else {
        $out .= "Content-Type: text/plain; charset=UTF-8\n\n";
        $out .= $body . "\n";
    }
    return $out . "\n";
}

echo "== Разбор MIME без ext/imap ==\n";
$raw = mboxLetter('zakupki@technotrade.ru', 'atlantarmourmed@gmail.com',
    'Запрос КП на бронежилеты', "Здравствуйте!\nПришлите, пожалуйста, коммерческое предложение на 20 бронежилетов класса Бр4.",
    '2022-09-05 10:03:11', 'tt-1@technotrade.ru', 'Inbox', ['attachment' => ['Спецификация №12.pdf', "%PDF-1.4\nfake pdf body\n"]]);
$msg = Mime::parseMessage($raw);
ok('тема из =?UTF-8?B?', $msg['subject'] === 'Запрос КП на бронежилеты', $msg['subject']);
ok('адрес отправителя', $msg['from'] === 'zakupki@technotrade.ru', $msg['from']);
ok('имя отправителя', $msg['from_name'] === 'Пётр Иванов', $msg['from_name']);
ok('Message-ID без угловых скобок', $msg['message_id'] === 'tt-1@technotrade.ru', $msg['message_id']);
ok('дата письма разобрана', $msg['date'] === '2022-09-05 10:03:11', $msg['date']);
ok('текст из windows-1251 + QP', str_contains($msg['body'], 'коммерческое предложение на 20 бронежилетов'), mb_substr($msg['body'], 0, 80));
ok('вложение одно', count($msg['attachments']) === 1, (string)count($msg['attachments']));
ok('имя файла из RFC 2047', ($msg['attachments'][0]['filename'] ?? '') === 'Спецификация №12.pdf', $msg['attachments'][0]['filename'] ?? '');
ok('байты файла целы', str_contains((string)($msg['attachments'][0]['content'] ?? ''), 'fake pdf body'));

// «From » в начале строки тела — не разделитель, а текст письма
ok('строка тела «From the desk» не разделитель', !MboxImport::isSeparator("From the desk of Peter\n"));
ok('настоящий разделитель узнан', MboxImport::isSeparator("From zakupki@technotrade.ru Mon Sep  5 10:03:11 2022\n"));

echo "\n== Импорт mbox: письма, файлы, карточка компании ==\n";
$mboxDir = MboxImport::dir();
$mboxFile = 'test-' . getmypid() . '.mbox';
$mboxPath = $mboxDir . '/' . $mboxFile;
register_shutdown_function(fn() => @unlink($mboxPath));

$out = mboxLetter('zakupki@technotrade.ru', 'atlantarmourmed@gmail.com',
        'Запрос КП на бронежилеты', "Здравствуйте!\nПришлите, пожалуйста, коммерческое предложение на 20 бронежилетов класса Бр4.",
        '2022-09-05 10:03:11', 'tt-1@technotrade.ru', 'Inbox', ['attachment' => ['Спецификация №12.pdf', "%PDF-1.4\nfake pdf body\n"]])
    . mboxLetter('atlantarmourmed@gmail.com', 'zakupki@technotrade.ru',
        'Re: Запрос КП на бронежилеты', "Добрый день!\nВо вложении коммерческое предложение по Вашему запросу. Готовы отгрузить со склада.",
        '2022-09-06 09:12:00', 'our-1@gmail.com', 'Sent')
    . mboxLetter('news@spamdigest.example', 'atlantarmourmed@gmail.com',
        'Дайджест недели', "Тут лежит рассылка, которую никто не читает, но в архиве она есть и место занимает.",
        '2022-09-07 06:00:00', 'spam-1@spamdigest.example', 'Inbox');
file_put_contents($mboxPath, $out);

$import = MboxImport::register($mboxFile, ['mailbox_id' => $boxId]);
$res = MboxImport::step((int)$import['id'], 20, 100);
ok('импорт дошёл до конца файла', !empty($res['done']), json_encode($res['import']['percent'] ?? null));
ok('импортировано три письма', $res['imported'] === 3, json_encode($res));

$in = Db::one("SELECT * FROM mail_messages WHERE message_id='tt-1@technotrade.ru'");
$reply = Db::one("SELECT * FROM mail_messages WHERE message_id='our-1@gmail.com'");
ok('входящее письмо в архиве', (bool)$in);
ok('наш ответ помечен исходящим', ($reply['direction'] ?? '') === 'out', (string)($reply['direction'] ?? ''));
ok('направление взято из X-Gmail-Labels', ($reply['folder'] ?? '') === 'SENT', (string)($reply['folder'] ?? ''));
ok('письма прочитаны — счётчик непрочитанных не растёт',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE is_read=0") === 0);
ok('история не будит конвейер запросов',
   (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE processed_at IS NULL") === 0);
ok('запросов КП из истории не создано', (int)Db::val("SELECT COUNT(*) FROM requests") === 0);

ok('письмо легло на карточку контрагента', (int)($in['counterparty_id'] ?? 0) === $cpId,
   (string)($in['counterparty_id'] ?? 'null'));
ok('наш ответ — на ту же карточку', (int)($reply['counterparty_id'] ?? 0) === $cpId,
   (string)($reply['counterparty_id'] ?? 'null'));
ok('рассылке карточку не завели', $in && (int)Db::val("SELECT COUNT(*) FROM counterparties") === 1,
   (string)Db::val("SELECT COUNT(*) FROM counterparties"));
ok('переписка в хронологическом порядке',
   (string)Db::val("SELECT date_at FROM mail_messages WHERE counterparty_id=? ORDER BY date_at LIMIT 1", [$cpId]) === '2022-09-05 10:03:11');
ok('цепочка одна на запрос и ответ',
   (int)Db::val("SELECT COUNT(DISTINCT thread_key) FROM mail_messages WHERE counterparty_id=?", [$cpId]) === 1,
   (string)Db::val("SELECT COUNT(DISTINCT thread_key) FROM mail_messages WHERE counterparty_id=?", [$cpId]));

$att = Db::one("SELECT * FROM attachments WHERE mail_message_id=?", [$in['id']]);
ok('вложение сохранено', (bool)$att, json_encode($att['filename'] ?? null, JSON_UNESCAPED_UNICODE));
ok('у вложения есть хеш содержимого', !empty($att['content_hash']));
ok('файл лежит на диске', $att && is_file(ROOT . '/' . $att['path']));
// Файлы теста не остаются в storage/ — их удаляем все, а не только последний
register_shutdown_function(function () {
    foreach (Db::all("SELECT path FROM attachments") as $row) @unlink(ROOT . '/' . $row['path']);
});

echo "\n== Дедупликация: то же самое письмо второй раз ==\n";
MboxImport::reset((int)$import['id']);
$again = MboxImport::step((int)$import['id'], 20, 100);
ok('повторный импорт ничего не добавил', $again['imported'] === 0, json_encode($again));
ok('и посчитал дубликаты', $again['duplicates'] === 3, json_encode($again));
ok('писем в архиве по-прежнему три', (int)Db::val("SELECT COUNT(*) FROM mail_messages") === 3);

// Тот же текст и тот же файл, но Message-ID переписан шлюзом и пришло во второй ящик
$boxTwoId = Db::insert('mailboxes', ['name' => 'Яндекс', 'email' => 'info@atlant-armour.ru',
                                     'is_active' => 1, 'imap_host' => 'imap.yandex.ru']);
$boxTwo = Mailboxes::get($boxTwoId);
$copy = Mime::parseMessage(mboxLetter('zakupki@technotrade.ru', 'atlantarmourmed@gmail.com',
    'Запрос КП на бронежилеты', "Здравствуйте!\nПришлите, пожалуйста, коммерческое предложение на 20 бронежилетов класса Бр4.",
    '2022-09-05 10:03:11', 'rewritten-by-gateway@relay.example', 'Inbox',
    ['attachment' => ['Спецификация №12.pdf', "%PDF-1.4\nfake pdf body\n"]]));
$copy['uid'] = 0;
$copy['folder'] = 'INBOX';
ok('копия с другим Message-ID не попала во второй ящик',
   MailArchive::storeIncoming($boxTwo, $copy, 'in', true) === 0);

Settings::set('MAIL_DEDUP', '0');
$idOff = MailArchive::storeIncoming($boxTwo, $copy, 'in', true);
ok('с выключенной дедупликацией копия сохраняется', $idOff > 0, (string)$idOff);
if ($idOff) Db::q("DELETE FROM mail_messages WHERE id=?", [$idOff]);
Settings::set('MAIL_DEDUP', '1');

// Короткое письмо узнаётся только по Message-ID: два «Спасибо!» — это два письма
$thanks = ['uid' => 0, 'folder' => 'INBOX', 'message_id' => 'thanks-1@technotrade.ru',
           'subject' => 'Re: Запрос КП на бронежилеты', 'from' => 'zakupki@technotrade.ru',
           'to' => 'atlantarmourmed@gmail.com', 'body' => 'Спасибо!', 'date' => '2022-09-08 10:00:00'];
ok('первое «Спасибо!» сохранено', MailArchive::storeIncoming($box, $thanks, 'in', true) > 0);
$thanks2 = ['message_id' => 'thanks-2@technotrade.ru', 'date' => '2022-09-20 10:00:00'] + $thanks;
ok('второе «Спасибо!» — отдельное письмо, а не дубликат',
   MailArchive::storeIncoming($box, $thanks2, 'in', true) > 0);
ok('одинаковое короткое письмо с тем же Message-ID — дубликат',
   MailArchive::storeIncoming($box, $thanks, 'in', true) === 0);

echo "\n== Отпечатки старого архива ==\n";
Db::q("UPDATE mail_messages SET dedup_hash=NULL");
$hashes = MailArchive::backfillFingerprints(100, 5.0);
ok('хеши досчитаны', $hashes['left'] === 0, json_encode($hashes));
ok('у длинного письма есть отпечаток',
   (string)Db::val("SELECT dedup_hash FROM mail_messages WHERE message_id='tt-1@technotrade.ru'") !== '-');
ok('короткое письмо помечено как «нечего хешировать»',
   (string)Db::val("SELECT dedup_hash FROM mail_messages WHERE message_id='thanks-1@technotrade.ru'") === '-');

echo "\n== Шаги импорта: курсор по байтам ==\n";
MboxImport::reset((int)$import['id']);
Db::q("DELETE FROM mail_messages");
$step1 = MboxImport::step((int)$import['id'], 20, 1);
ok('первый шаг взял ровно одно письмо', $step1['imported'] === 1, json_encode($step1));
ok('и не дошёл до конца файла', empty($step1['done']));
$offset = (int)$step1['import']['byte_offset'];
ok('курсор стоит на начале второго письма', $offset > 0 && $offset < filesize($mboxPath), (string)$offset);
$step2 = MboxImport::step((int)$import['id'], 20, 100);
ok('второй шаг дочитал остальные', $step2['imported'] === 2, json_encode($step2));
ok('и файл закончился', !empty($step2['done']));
ok('писем ровно три, без половинок', (int)Db::val("SELECT COUNT(*) FROM mail_messages") === 3);

echo "\n== Логотипы: КП, приложение, favicon ==\n";
ok('по умолчанию КП печатает встроенный знак',
   str_ends_with(Branding::resolve('kp'), 'public/assets/img/logo.png')
   || str_ends_with(Branding::resolve('kp'), 'public/assets/img/logo-default.png'), Branding::resolve('kp'));
ok('загруженного лого ещё нет', Branding::uploaded('kp') === null);

$png = $sandbox . '/logo.png';
$im = imagecreatetruecolor(120, 40);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 20, 20));
imagepng($im, $png);
imagedestroy($im);

Branding::store('kp', ['tmp_name' => $png, 'name' => 'logo.png'], ['move' => false]);
ok('загруженное лого стало лого КП', Branding::uploaded('kp') !== null, (string)Branding::uploaded('kp'));
ok('и КП берёт именно его', Branding::resolve('kp') === Branding::uploaded('kp'));
ok('юрлицо помнит путь к лого',
   (string)Db::val("SELECT logo_path FROM legal_entities WHERE is_active=1") === Branding::uploaded('kp'));
ok('версия меняется вместе с файлом', Branding::version('kp') !== '0');

Branding::remove('kp');
ok('после сброса возвращается встроенный знак', Branding::uploaded('kp') === null);
ok('и путь в юрлице очищен',
   (string)Db::val("SELECT logo_path FROM legal_entities WHERE is_active=1") === '');
ok('favicon отдаётся всегда — хотя бы встроенный', is_file(Branding::resolve('favicon')), Branding::resolve('favicon'));
ok('иконка приложения тоже', is_file(Branding::resolve('app')), Branding::resolve('app'));

echo "\n" . ($fail ? "FAILED: $fail\n" : "Все проверки прошли\n");
exit($fail ? 1 : 0);
