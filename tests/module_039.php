<?php
/**
 * Модуль 039: подбор заводится по любой переписке, письмо уходит с подписью.
 *
 * Проверяется ровно то, ради чего модуль появился:
 *   — по переписке, из которой запрос не завели, подбор всё равно заводится;
 *   — позиции письма попадают в таблицу и находятся в каталоге;
 *   — второй запрос по той же переписке не заводится — таблица одна;
 *   — запрос виден со ВСЕХ писем цепочки, а не только с последнего;
 *   — подпись менеджера своя, общая — запасная, а из карточки — последняя;
 *   — подпись дописывается к письму один раз и не дублируется.
 *
 * Run:  php tests/module_039.php
 *
 * База создаётся в системном временном каталоге — `data/kp.db` не открывается,
 * так что запуск на сервере не может задеть живые данные.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-039-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);

// Порядок слагаемых здесь — это вся безопасность теста: `+` оставляет ключ
// ЛЕВОГО операнда, поэтому подмена DB_PATH должна идти первой.
$savedConfig = $hadConfig ? file_get_contents($configPath) : null;
$existing = $hadConfig ? (array)(require $configPath) : [];
$effective = ['DB_PATH' => $tmpDb] + $existing;
if ($effective['DB_PATH'] !== $tmpDb) {
    fwrite(STDERR, "tests: refusing to run against {$effective['DB_PATH']}\n");
    exit(2);
}
file_put_contents($configPath, "<?php return " . var_export($effective, true) . ";");
register_shutdown_function(function () use ($configPath, $savedConfig, $tmpDb) {
    if ($savedConfig === null) @unlink($configPath); else file_put_contents($configPath, $savedConfig);
    foreach ([$tmpDb, $tmpDb . '-wal', $tmpDb . '-shm'] as $f) @unlink($f);
});

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/mail_signature.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

// Ни модели, ни сети: ключей нет, и разбор письма падает — ровно тот случай,
// ради которого подбор обязан открыться всё равно
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('KNOWLEDGE_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');

Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved,
                                   unit, vat, product_type, source, updated_at)
       VALUES ('p-plate5', 'Бронеплита Бр5', 'бронеплита бр5', 'BP5',
               18000.0, 12, 0, 'шт.', 22, 'product', 'api', datetime('now'))");

$managerId = Db::insert('managers', [
    'login' => 'yana', 'password_hash' => 'x', 'name' => 'Яна Петрова',
    'phone' => '+79775084585', 'is_admin' => 0,
]);

$mailboxId = Db::insert('mailboxes', [
    'name' => 'info@atlant-armour.ru', 'email' => 'info@atlant-armour.ru', 'is_active' => 1,
]);

// Письмо, которое классификатор отнёс к «нам предлагают товар»: запроса у него
// нет, и именно на нём сервис раньше отказывал в подборе
$threadKey = 'thread-kron-1';
$first = Db::insert('mail_messages', [
    'mailbox_id' => $mailboxId, 'direction' => 'in', 'thread_key' => $threadKey,
    'message_id' => '<kron-1@kronconcern.ru>', 'subject' => 'Коммерческое предложение на приобретение бронепластин.',
    'from_email' => 'knv@kronconcern.ru', 'from_name' => 'Кузнецов Никита Владимирович',
    'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'Добрый день! Прошу предоставить КП.',
    'date_at' => '2026-09-16 16:19:20', 'category' => 'supplier_offer',
    'processed_at' => '2026-09-16 16:20:00',
]);
$last = Db::insert('mail_messages', [
    'mailbox_id' => $mailboxId, 'direction' => 'in', 'thread_key' => $threadKey,
    'message_id' => '<kron-2@kronconcern.ru>', 'subject' => 'Re: Коммерческое предложение на приобретение бронепластин.',
    'from_email' => 'knv@kronconcern.ru', 'from_name' => 'Кузнецов Никита Владимирович',
    'to_emails' => 'info@atlant-armour.ru',
    'body_text' => "Добрый день!\n\nПрошу направить коммерческое предложение:\nБронеплита Бр5 — 40 шт.",
    'date_at' => '2026-09-17 10:57:39', 'category' => 'supplier_offer',
    'processed_at' => '2026-09-17 10:58:00',
]);

echo "\n1. Подбор заводится по переписке, из которой запрос не завели\n";

$res = MailSync::requestFromThread($threadKey, (int)$managerId);
ok('запрос создан', $res['created'] === true && $res['request_id'] > 0, json_encode($res));

$req = Db::one("SELECT * FROM requests WHERE id=?", [(int)$res['request_id']]);
ok('заведён по последнему входящему письму',
   str_contains((string)$req['raw_text'], 'Бронеплита Бр5'), (string)$req['raw_text']);
ok('категория стала рабочей, а не «нам предлагают товар»',
   $req['category'] === 'kp_request', (string)$req['category']);
ok('и видно, что решение принял человек',
   $req['category_source'] === 'manager', (string)$req['category_source']);
ok('запрос за тем менеджером, который нажал', (int)$req['manager_id'] === (int)$managerId);

echo "\n2. Позиции письма нашлись в каталоге без единого вызова модели\n";

$items = RequestItems::all((int)$res['request_id']);
ok('строка подбора появилась', count($items) === 1, (string)count($items));
ok('и нашла нашу бронеплиту',
   ($items[0]['moysklad_product_id'] ?? '') === 'p-plate5', json_encode($items[0]['product_name'] ?? null, JSON_UNESCAPED_UNICODE));
ok('с количеством из письма', (int)($items[0]['quantity'] ?? 0) === 40, (string)($items[0]['quantity'] ?? 0));

echo "\n3. Запрос один на всю переписку\n";

ok('первое письмо цепочки тоже смотрит на него',
   (int)Db::val("SELECT request_id FROM mail_messages WHERE id=?", [$first]) === (int)$res['request_id']);
$again = MailSync::requestFromThread($threadKey, (int)$managerId);
ok('второй раз запрос не заводится',
   $again['created'] === false && $again['request_id'] === $res['request_id'], json_encode($again));
ok('и запрос в базе по-прежнему один',
   (int)Db::val("SELECT COUNT(*) FROM requests") === 1);

echo "\n4. Пустая переписка — это ошибка, а не молчаливый пустой запрос\n";

$threw = false;
try { MailSync::requestFromThread('нет-такой-цепочки', (int)$managerId); }
catch (InvalidArgumentException $e) { $threw = true; }
ok('несуществующая переписка отказывает', $threw);

echo "\n5. Подпись: своя, общая, из карточки\n";

ok('своей нет — собирается из имени и телефона',
   MailSignature::forManager((int)$managerId) === "С уважением,\nЯна Петрова\n+79775084585",
   MailSignature::forManager((int)$managerId));

Settings::set(MailSignature::SETTING, "С уважением,\nОтдел продаж «Атлант Армор»");
ok('общая подпись компании берётся раньше карточки',
   str_contains(MailSignature::forManager((int)$managerId), 'Отдел продаж'),
   MailSignature::forManager((int)$managerId));

$own = "С уважением, Яна, менеджер по оптовым заказам\n+79775084585";
MailSignature::save((int)$managerId, $own);
ok('своя подпись сильнее общей', MailSignature::forManager((int)$managerId) === $own);
ok('и в базе она лежит у менеджера',
   (string)Db::val("SELECT email_signature FROM managers WHERE id=?", [$managerId]) === $own);

$d = MailSignature::describe((int)$managerId);
ok('панель показывает, чья подпись стоит', $d['source'] === 'manager', (string)$d['source']);

echo "\n6. Подпись дописывается один раз\n";

$body = MailSignature::appendText('Добрый день! Направляем КП.', $own);
ok('подпись дописалась', str_contains($body, 'менеджер по оптовым заказам'), $body);
ok('текст письма не пострадал', str_starts_with($body, 'Добрый день! Направляем КП.'));
ok('второй раз не дописывается', MailSignature::appendText($body, $own) === $body);
ok('письмо с подписью, набранной руками, не задваивает её',
   MailSignature::appendText("Добрый день!\n\nС уважением, Яна, менеджер по оптовым заказам\n+7 (977) 508-45-85", $own)
     === "Добрый день!\n\nС уважением, Яна, менеджер по оптовым заказам\n+7 (977) 508-45-85");

$html = MailSignature::appendHtml('<p>Добрый день!</p>', $own);
ok('в HTML подпись стала абзацем', str_contains($html, '<p>С уважением, Яна'), $html);
ok('и переносы строк в нём сохранились', str_contains($html, '<br'), $html);
ok('в HTML второй раз тоже не дописывается', MailSignature::appendHtml($html, $own) === $html);

$empty = MailSignature::appendText('Добрый день!', '');
ok('пустая подпись ничего не портит', $empty === 'Добрый день!', $empty);

echo "\n" . ($fail ? "ПРОВАЛЕНО: $fail\n" : "Всё сошлось\n");
exit($fail ? 1 : 0);
