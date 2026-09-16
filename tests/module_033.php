<?php
/**
 * Письмо, которое пишут, и контрагент, которого нет в МойСклад — на выбрасываемой
 * базе и без сети:
 *
 *   — черновик ПЕРВОГО письма компании сохраняется (раньше сервер отказывал:
 *     у такого письма нет ни id письма-ответа, ни ключа цепочки);
 *   — черновик заводит карточку в «В работе», а не во «Входящих»;
 *   — данные для карточки берутся из тела письма: компания по ИНН из подписи,
 *     ИНН попадает на карточку компании, тема и начало текста — на карточку доски;
 *   — карточка из «Входящие» переезжает в работу, а поставленную менеджером
 *     колонку черновик не трогает;
 *   — стёртый черновик уносит свою карточку, но карточку с перепиской — нет;
 *   — отправленное письмо оставляет карточку на месте и перестаёт быть черновиком,
 *     а «Входящие» не заводят второй карточки той же компании;
 *   — ИНН из входящего письма (в том числе из вложения) виден в подсказке
 *     «контрагента нет в МойСклад», а переписка неизвестного отправителя
 *     переезжает на заведённую карточку вместе с запросом и контактом.
 *
 * Запуск:  php tests/module_033.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-033-' . getmypid() . '.db';
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
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/drafts.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');

$managerId = Db::insert('managers', ['login' => 'manager', 'name' => 'Менеджер',
                                     'email' => 'm@atlant-armour.ru', 'password_hash' => 'x']);
$boxId = Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru',
                                  'is_active' => 1, 'is_default' => 1]);

$board = Boards::singleton();
$columns = fn() => Db::all("SELECT * FROM board_columns WHERE board_id=? ORDER BY position, id", [$board['id']]);
$columnOf = function (int $cardId): string {
    return (string)Db::val("SELECT c.title FROM board_cards d JOIN board_columns c ON c.id=d.column_id WHERE d.id=?", [$cardId]);
};

// =====================================================================  1

echo "\n== 1. «В работе» — названная колонка ==\n";

$work = Boards::workColumn((int)$board['id']);
ok('колонка черновиков найдена по признаку, а не по номеру',
   $work !== null && $work['kind'] === 'work' && $work['title'] === 'В работе');
ok('интейк остался отдельной колонкой',
   (Boards::inboxColumn((int)$board['id'])['title'] ?? '') === 'Входящие');

// Переименованная колонка остаётся колонкой черновиков
Boards::saveColumn((int)$board['id'], (int)$work['id'], 'В процессе', null);
ok('переименование не уводит черновики в другую колонку',
   (int)(Boards::workColumn((int)$board['id'])['id'] ?? 0) === (int)$work['id']);
Boards::saveColumn((int)$board['id'], (int)$work['id'], 'В работе', null);

// =====================================================================  2

echo "\n== 2. Черновик первого письма компании ==\n";

$body = '<p>Добрый день! Готовы поставить шлемы ЗШ-1 в количестве 20 шт.</p>'
      . '<p>С уважением, ООО «Ромашка», ИНН 7810964292, тел. +7 999 123-45-67</p>';
$res = MailDrafts::save([
    'to'      => 'zakupki@romashka.ru',
    'subject' => 'Шлемы ЗШ-1',
    'body'    => $body,
], $managerId);

ok('черновик письма без цепочки и без письма-ответа сохранился', !empty($res['saved']) && !empty($res['draft_id']));
ok('карточка встала в «В работе»', ($res['column'] ?? '') === 'В работе', (string)($res['column'] ?? ''));
ok('карточка одна и она черновик',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE draft_id=?", [(int)$res['draft_id']]) === 1);

$cpId = (int)($res['counterparty_id'] ?? 0);
ok('компания опознана по подписи письма', $cpId > 0);
ok('ИНН из тела письма попал на карточку компании',
   (string)Db::val("SELECT inn FROM counterparties WHERE id=?", [$cpId]) === '7810964292');
ok('телефон из подписи тоже подтянулся',
   trim((string)Db::val("SELECT contact_phone FROM counterparties WHERE id=?", [$cpId])) !== '');
ok('адрес получателя записан в черновик',
   (string)Db::val("SELECT to_email FROM mail_drafts WHERE id=?", [(int)$res['draft_id']]) === 'zakupki@romashka.ru');

$cardId = (int)$res['card_id'];
$decorated = null;
foreach (Boards::get((int)$board['id'])['columns'] as $col) {
    foreach ($col['cards'] as $card) if ((int)$card['id'] === $cardId) $decorated = $card;
}
ok('карточка знает компанию', $decorated && (int)$decorated['counterparty_id'] === $cpId);
ok('на карточке видно тему письма',
   $decorated && ($decorated['draft']['subject'] ?? '') === 'Шлемы ЗШ-1');
ok('и начало текста, а не пустой прямоугольник',
   $decorated && str_contains((string)($decorated['draft']['preview'] ?? ''), 'шлемы ЗШ-1'));
ok('заголовок карточки — компания, а не адрес',
   $decorated && str_contains((string)$decorated['title'], 'Ромашка'), (string)($decorated['title'] ?? ''));

// Тот же композер пишет дальше — карточка и черновик остаются одни
$again = MailDrafts::save([
    'draft_id'        => (int)$res['draft_id'],
    'counterparty_id' => $cpId,
    'to'              => 'zakupki@romashka.ru',
    'subject'         => 'Шлемы ЗШ-1',
    'body'            => $body . '<p>Срок — две недели.</p>',
], $managerId);
ok('правка черновика не заводит второй', (int)$again['draft_id'] === (int)$res['draft_id']);
ok('и второй карточки тоже', (int)Db::val("SELECT COUNT(*) FROM board_cards") === 1);
ok('черновик возвращается в поле по компании',
   (int)(MailDrafts::find(['counterparty_id' => $cpId], $managerId)['id'] ?? 0) === (int)$res['draft_id']);
ok('чужой менеджер чужого черновика не видит',
   MailDrafts::find(['counterparty_id' => $cpId], $managerId + 99) === null);

// =====================================================================  3

echo "\n== 3. Колонка, в которую карточку поставил человек ==\n";

// Компания, которая уже написала нам и стоит во «Входящих»
$zavodId = Crm::resolveCounterparty(['name' => 'АО «Завод»', 'email' => 'snab@zavod.ru']);
Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 10,
    'thread_key' => 'th-zavod', 'subject' => 'Запрос цены', 'from_email' => 'snab@zavod.ru',
    'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'Пришлите цену на бронежилеты',
    'counterparty_id' => $zavodId, 'date_at' => date('Y-m-d H:i:s'), 'is_read' => 0,
]);
Boards::sync((int)$board['id'], true);
$zavodCard = (int)Db::val("SELECT id FROM board_cards WHERE counterparty_id=?", [$zavodId]);
ok('входящее письмо положило карточку во «Входящие»', $columnOf($zavodCard) === 'Входящие');

MailDrafts::save([
    'thread_key'      => 'th-zavod',
    'counterparty_id' => $zavodId,
    'to'              => 'snab@zavod.ru',
    'subject'         => 'Re: Запрос цены',
    'body'            => '<p>Высылаем цену.</p>',
], $managerId);
ok('ответ переводит карточку из «Входящие» в работу', $columnOf($zavodCard) === 'В работе');
ok('и не заводит второй карточки той же компании',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE counterparty_id=?", [$zavodId]) === 1);

// Карточку двигают руками — черновик её не возвращает
$sentCol = (int)Db::val("SELECT id FROM board_columns WHERE board_id=? AND title='КП отправлено'", [$board['id']]);
Boards::moveCard($zavodCard, $sentCol, 0);
MailDrafts::save([
    'thread_key'      => 'th-zavod',
    'counterparty_id' => $zavodId,
    'to'              => 'snab@zavod.ru',
    'subject'         => 'Re: Запрос цены',
    'body'            => '<p>Высылаем цену и сроки.</p>',
], $managerId);
ok('колонку, выбранную менеджером, черновик не трогает', $columnOf($zavodCard) === 'КП отправлено');

// =====================================================================  4

echo "\n== 4. Стёртый черновик ==\n";

$zavodDraft = (int)MailDrafts::find(['thread_key' => 'th-zavod'], $managerId)['id'];
MailDrafts::save(['draft_id' => $zavodDraft, 'thread_key' => 'th-zavod', 'body' => '<p><br></p>'], $managerId);
ok('пустой черновик стёрся', MailDrafts::find(['thread_key' => 'th-zavod'], $managerId) === null);
ok('но карточка компании с перепиской осталась',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE id=?", [$zavodCard]) === 1);
ok('и осталась там, куда её поставили', $columnOf($zavodCard) === 'КП отправлено');

$temp = MailDrafts::save(['to' => 'new@example.org', 'body' => '<p>Черновик на пробу</p>'], $managerId);
MailDrafts::save(['draft_id' => (int)$temp['draft_id'], 'body' => ''], $managerId);
ok('карточка, которая жила одним черновиком, ушла с доски',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE id=?", [(int)$temp['card_id']]) === 0);

// =====================================================================  5

echo "\n== 5. Письмо ушло ==\n";

$draftId = (int)$again['draft_id'];
$sentId = MailArchive::storeOutgoing([
    'mailbox_id' => $boxId, 'subject' => 'Шлемы ЗШ-1', 'from_email' => 'info@atlant-armour.ru',
    'to' => 'zakupki@romashka.ru', 'text' => 'Готовы поставить шлемы ЗШ-1', 'html' => '<p>…</p>',
    'counterparty_id' => $cpId, 'manager_id' => $managerId,
]);
MailDrafts::sent(['draft_id' => $draftId, 'counterparty_id' => $cpId], $managerId, [
    'thread_key'      => (string)Db::val("SELECT thread_key FROM mail_messages WHERE id=?", [$sentId]),
    'counterparty_id' => $cpId,
    'mail_message_id' => $sentId,
    'title'           => 'ООО «Ромашка»',
    'manager_id'      => $managerId,
]);

ok('черновик отправленного письма больше не черновик',
   (int)Db::val("SELECT COUNT(*) FROM mail_drafts WHERE id=?", [$draftId]) === 0);
ok('карточка осталась на доске', (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE id=?", [$cardId]) === 1);
ok('и осталась в «В работе»', $columnOf($cardId) === 'В работе');
ok('карточка перестала быть черновиком',
   Db::val("SELECT draft_id FROM board_cards WHERE id=?", [$cardId]) === null);
ok('письмо привязано к карточке компании',
   (int)Db::val("SELECT counterparty_id FROM board_cards WHERE id=?", [$cardId]) === $cpId);

Boards::sync((int)$board['id'], true);
ok('«Входящие» не завели второй карточки уже написанной компании',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE counterparty_id=?", [$cpId]) === 1);

// =====================================================================  6

echo "\n== 6. Контрагент, которого нет в МойСклад ==\n";

$letter = 'Добрый день! Просим выставить счёт на 10 бронежилетов.' . "\n"
        . 'ООО «Вектор», ИНН 5024097229, КПП 502401001';
$mailId = Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 20,
    'thread_key' => 'th-vector', 'subject' => 'Счёт', 'from_email' => 'ivanov@vector-npo.ru',
    'from_name' => 'Иванов Иван', 'to_emails' => 'info@atlant-armour.ru',
    'body_text' => $letter, 'date_at' => date('Y-m-d H:i:s'), 'is_read' => 0,
]);
$hint = Crm::moyskladHint(null, Crm::letterText(Db::one("SELECT id, body_text FROM mail_messages WHERE id=?", [$mailId])),
                          ['name' => 'Иванов Иван', 'email' => 'ivanov@vector-npo.ru']);
ok('ИНН найден в подписи письма', $hint['inn'] === '5024097229', $hint['inn']);
ok('и помечен как взятый из письма', $hint['inn_from_letter'] === true);
ok('название компании — из письма, а не адрес', str_contains($hint['name'], 'Вектор'), $hint['name']);
ok('карточки в МойСклад нет — предлагаем завести', $hint['linked'] === false);

// ИНН, лежащий во вложенной карточке предприятия, тоже находится
Db::insert('attachments', [
    'mail_message_id' => $mailId, 'filename' => 'Карточка предприятия.pdf', 'path' => '/dev/null',
    'mime' => 'application/pdf', 'size' => 10, 'extracted_text' => 'ИНН 7743013902 КПП 774301001',
]);
$plain = Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 21,
    'thread_key' => 'th-plain', 'subject' => 'Вопрос', 'from_email' => 'sales@plain.ru',
    'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'Есть ли шлемы в наличии?',
    'date_at' => date('Y-m-d H:i:s'), 'is_read' => 0,
]);
$withFile = Crm::moyskladHint(null, Crm::letterText(Db::one("SELECT id, body_text FROM mail_messages WHERE id=?", [$mailId])), []);
ok('ИНН из вложения тоже виден', in_array($withFile['inn'], ['5024097229', '7743013902'], true));
$noInn = Crm::moyskladHint(null, Crm::letterText(Db::one("SELECT id, body_text FROM mail_messages WHERE id=?", [$plain])), []);
ok('письма без ИНН честно говорят, что ИНН нет', $noInn['inn'] === '' && $noInn['linked'] === false);

// Заводим карточку и переносим на неё переписку — как это делает кнопка
$vectorId = (int)Crm::resolveCounterparty(['inn' => $hint['inn'], 'name' => $hint['name'], 'email' => $hint['email']]);
$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => $letter, 'status' => 'new']);
Db::update('mail_messages', ['request_id' => $reqId], 'id=?', [$mailId]);
$moved = Crm::attachThread('th-vector', $vectorId);
ok('переписка переехала на заведённую карточку', $moved === 1
   && (int)Db::val("SELECT counterparty_id FROM mail_messages WHERE id=?", [$mailId]) === $vectorId);
ok('запрос из письма — тоже на карточке',
   (int)Db::val("SELECT counterparty_id FROM requests WHERE id=?", [$reqId]) === $vectorId);
ok('отправитель стал контактом компании',
   (int)Db::val("SELECT COUNT(*) FROM contacts WHERE counterparty_id=? AND email=?",
                [$vectorId, 'ivanov@vector-npo.ru']) === 1);

$linked = Crm::moyskladHint($vectorId, '', []);
ok('у заведённой карточки ИНН уже свой, не из письма',
   $linked['inn'] === '5024097229' && $linked['inn_from_letter'] === false);
Db::update('counterparties', ['moysklad_id' => 'ms-777'], 'id=?', [$vectorId]);
$after = Crm::moyskladHint($vectorId, '', []);
ok('привязанная карточка больше ничего не предлагает',
   $after['linked'] === true && $after['moysklad_id'] === 'ms-777');
ok('девятизначный «ИНН» не принимается', Crm::cleanInn('123456789') === null);

// =====================================================================

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
