<?php
/**
 * Модуль 037: срок из подбора переписывает срок в КП, письмо перенаправляется.
 *
 * Проверяется ровно то, ради чего модуль появился:
 *   — срок ожидания, выставленный в таблице подбора, доезжает до уже собранного КП;
 *   — вместе с ним переезжают скидка за ожидание, предоплата и «под заказ»;
 *   — пустое поле подбора не обнуляет то, что стоит в КП;
 *   — отправленное клиенту КП не переписывается ни при каких правках;
 *   — адрес перенаправления запоминается, не задваивается и удаляется из базы;
 *   — пересылка без адреса и по несуществующему письму не отправляется молча.
 *
 * Run:  php tests/module_037.php
 *
 * База создаётся в системном временном каталоге — `data/kp.db` не открывается,
 * так что запуск на сервере не может задеть живые данные.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-037-' . getmypid() . '.db';
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
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/kp_set.php';
require_once ROOT . '/lib/kp_terms.php';
require_once ROOT . '/lib/terms.php';
require_once ROOT . '/lib/forwards.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

// Ни модели, ни сети
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('KNOWLEDGE_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('KP_QR_CODE', '0');

Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved,
                                   unit, vat, product_type, source, updated_at)
       VALUES ('p-plate', 'Бронеплита Бр5', 'бронеплита бр5', 'BP5',
               18000.0, 0, 0, 'шт.', 22, 'product', 'api', datetime('now'))");

$cpId = Db::insert('counterparties', ['name' => 'АО «Концерн Крон»', 'contact_email' => 'knv@kronconcern.ru']);
$requestId = Db::insert('requests', [
    'source' => 'email', 'raw_text' => 'Бронеплита Бр5 — 40 шт', 'counterparty_id' => $cpId, 'status' => 'new',
    'parsed_json' => json_encode(['items' => [['name' => 'Бронеплита Бр5', 'qty' => 40]]],
                                 JSON_UNESCAPED_UNICODE),
]);

echo "\n1. КП собирается со сроком по умолчанию\n";

RequestItems::ensure($requestId);
$proposalId = KpSet::create($requestId, null);
foreach (RequestItems::toProposalItems(RequestItems::all($requestId)) as $i => $m) {
    Db::insert('proposal_items', KpSet::itemRow($m, $i + 1) + ['proposal_id' => $proposalId]);
}
Terms::prepareProposal($proposalId);
Db::q("UPDATE proposal_items SET wait_on=1 WHERE proposal_id=?", [$proposalId]);

$terms = KpTerms::forProposal(Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]));
ok('пока срок не трогали — три месяца', str_contains($terms, 'условий договора 3 месяца'), $terms);

echo "\n2. Другой срок в подборе переписывает срок в КП\n";

$row = Db::one("SELECT * FROM request_items WHERE request_id=?", [$requestId]);
RequestItems::save($requestId, [[
    'id' => $row['id'], 'raw_name' => $row['raw_name'], 'product_name' => $row['product_name'],
    'moysklad_product_id' => $row['moysklad_product_id'], 'quantity' => $row['quantity'],
    'unit' => $row['unit'], 'price' => $row['price'],
    'wait_on' => 1, 'wait_months' => 6, 'wait_discount' => 15, 'wait_prepay' => 50,
]]);
$rebuilt = KpSet::syncWaitFromRequest($requestId);
ok('КП пересобралось', $rebuilt === 1, (string)$rebuilt);

$item = Db::one("SELECT * FROM proposal_items WHERE proposal_id=?", [$proposalId]);
ok('срок ожидания переехал в строку КП', (int)$item['wait_months'] === 6, (string)$item['wait_months']);
ok('скидка за ожидание — тоже', (float)$item['wait_discount'] === 15.0, (string)$item['wait_discount']);
ok('и доля предоплаты', (int)$item['wait_prepay'] === 50, (string)$item['wait_prepay']);
ok('строка КП говорит про шесть месяцев',
   str_contains(Terms::note($item), 'срок ожидания 6 месяцев'), Terms::note($item));
ok('и предоплату половиной', str_contains(Terms::note($item), 'предоплата 50%'), Terms::note($item));

$terms = KpTerms::forProposal(Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]));
ok('срок исполнения в условиях — шесть месяцев',
   str_contains($terms, 'условий договора 6 месяцев'), $terms);
ok('и никаких прежних трёх', !str_contains($terms, '3 месяца'), $terms);

echo "\n3. Пустое поле подбора ничего не обнуляет\n";

RequestItems::save($requestId, [[
    'id' => $row['id'], 'raw_name' => $row['raw_name'], 'product_name' => $row['product_name'],
    'moysklad_product_id' => $row['moysklad_product_id'], 'quantity' => $row['quantity'],
    'unit' => $row['unit'], 'price' => $row['price'],
    'wait_on' => 1, 'wait_months' => '', 'wait_discount' => '', 'wait_prepay' => '',
]]);
KpSet::syncWaitFromRequest($requestId);
$item = Db::one("SELECT * FROM proposal_items WHERE proposal_id=?", [$proposalId]);
ok('срок в КП остался прежним', (int)$item['wait_months'] === 6, (string)$item['wait_months']);

echo "\n4. Отправленное КП не переписывается\n";

Db::update('proposals', ['status' => 'sent'], 'id=?', [$proposalId]);
RequestItems::save($requestId, [[
    'id' => $row['id'], 'raw_name' => $row['raw_name'], 'product_name' => $row['product_name'],
    'moysklad_product_id' => $row['moysklad_product_id'], 'quantity' => $row['quantity'],
    'unit' => $row['unit'], 'price' => $row['price'],
    'wait_on' => 1, 'wait_months' => 9, 'wait_discount' => 15, 'wait_prepay' => 50,
]]);
ok('подписанный документ не тронут', KpSet::syncWaitFromRequest($requestId) === 0);
$item = Db::one("SELECT * FROM proposal_items WHERE proposal_id=?", [$proposalId]);
ok('и срок в нём прежний', (int)$item['wait_months'] === 6, (string)$item['wait_months']);

echo "\n5. Адреса перенаправления живут в базе\n";

ok('сначала список пуст', Forwards::all() === []);

Forwards::remember('SNAB@kronconcern.ru', 'Снабжение');
Forwards::remember('snab@kronconcern.ru');
$list = Forwards::all();
ok('адрес запомнился один раз', count($list) === 1, (string)count($list));
ok('и в нижнем регистре', $list[0]['email'] === 'snab@kronconcern.ru', (string)$list[0]['email']);
ok('счётчик вырос', (int)$list[0]['uses'] === 2, (string)$list[0]['uses']);
ok('имя не потерялось', $list[0]['name'] === 'Снабжение', (string)$list[0]['name']);

Forwards::remember('Бухгалтерия <buh@kronconcern.ru>');
$list = Forwards::all();
ok('адрес из «Имя <адрес>» разобрался', count($list) === 2
   && in_array('buh@kronconcern.ru', array_column($list, 'email'), true),
   json_encode(array_column($list, 'email')));

Forwards::remember('не адрес вовсе');
ok('мусор в список не попал', count(Forwards::all()) === 2);

$buh = Db::one("SELECT id FROM forward_addresses WHERE email='buh@kronconcern.ru'");
Forwards::forget((int)$buh['id']);
ok('крестик убирает адрес из базы', count(Forwards::all()) === 1);

echo "\n6. Пересылка не уходит молча\n";

$mailId = Db::insert('mail_messages', [
    'direction' => 'in', 'subject' => 'Коммерческое предложение на бронепластины',
    'from_email' => 'knv@kronconcern.ru', 'from_name' => 'Кузнецов Никита Владимирович',
    'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'Добрый день! Прошу направить КП по плите.',
    'date_at' => date('Y-m-d H:i:s'), 'counterparty_id' => $cpId, 'thread_key' => 's:test',
]);

$err = '';
try { Forwards::send($mailId, '', '', 1); } catch (Throwable $e) { $err = $e->getMessage(); }
ok('без адреса пересылка отказывается', str_contains($err, 'адрес'), $err);

$err = '';
try { Forwards::send(999999, 'snab@kronconcern.ru', '', 1); } catch (Throwable $e) { $err = $e->getMessage(); }
ok('по несуществующему письму — тоже', str_contains($err, 'не найдено'), $err);

echo "\n" . ($fail ? "ПРОВАЛЕНО: $fail\n" : "Всё сошлось\n");
exit($fail ? 1 : 0);
