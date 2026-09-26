<?php
/**
 * Модуль 064: issues #123–#129 — тема, аналог без причины, наш ИНН,
 * телефон не остаётся уменьшенным, PDF в окне, «В работе» над «Входящими»,
 * меню в экране, заметки наверху.
 *
 * Запуск:  php tests/module_064.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-064-' . getmypid() . '.db';
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
require_once ROOT . '/lib/variants.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/notes.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js    = file_get_contents(ROOT . '/public/assets/js/app.js');
$css   = file_get_contents(ROOT . '/public/assets/css/app.css');
$index = file_get_contents(ROOT . '/public/index.php');

// ===================================================================== 2
echo "#124 аналог снимается, когда строка стоит на запрошенном товаре\n";
$prod = fn(string $id, string $name, array $o = []) => Db::insert('products_cache', $o + [
    'moysklad_id' => $id, 'name' => $name, 'price' => 0, 'stock' => 0, 'reserved' => 0, 'product_type' => 'product']);
$prod('p3', 'Плита для бронежилета Бр3');
foreach (['M' => 0, 'XL' => 20] as $size => $stock) {
    $prod("p3-$size", "Плита для бронежилета Бр3 (Размер: $size)", ['price' => 26900, 'stock' => $stock,
        'product_type' => 'variant', 'parent_id' => 'p3', 'characteristics' => "Размер: $size"]);
}
$prod('side-M', 'Боковая плита для бронежилета Бр3 (Размер: M)', ['price' => 10000, 'stock' => 80]);

ok('clearAnalogue снимает флаг, доказательство и примечание машины',
   RequestItems::clearAnalogue(['notes' => 'аналог: соответствует запросу по 1 из 1']) ===
   ['is_alternative' => 0, 'alt_of' => null, 'alt_specs_json' => null, 'notes' => null]);
ok('своё примечание менеджера остаётся', RequestItems::clearAnalogue(['notes' => 'позвонить'])['notes'] === 'позвонить');

$req = Db::insert('requests', ['source' => 'email', 'raw_text' => '5 плит для бронежилета Бр3, размер XL']);
$analogue = fn(string $product, int $confirmed) => Db::insert('request_items', [
    'request_id' => $req, 'position' => 1, 'raw_name' => 'плита для бронежилета Бр3 (размер XL)', 'quantity' => 5,
    'moysklad_product_id' => $product, 'product_name' => 'x', 'price' => 10000, 'stock' => 80,
    'variant_label' => 'XL', 'is_confirmed' => $confirmed, 'is_alternative' => 1,
    'alt_of' => 'Плита для бронежилета Бр3', 'notes' => 'аналог: соответствует запросу по 1 из 1',
    'alt_specs_json' => json_encode(['matched' => [], 'differs' => [], 'reason' => 'соответствует', 'source' => 'rules']),
    'match_variants' => json_encode([['moysklad_id' => 'p3', 'name' => 'Плита для бронежилета Бр3',
                                      'source' => 'было подобрано, нет в наличии']])]);

// Менеджер вернул строку на XL — старое «Аналог. Нет в наличии» висело (скриншот issue #124)
$a = $analogue('p3-XL', 1);
RequestItems::ensure($req);
$r = Db::one("SELECT * FROM request_items WHERE id=?", [$a]);
ok('строка на модификации запрошенного товара — не аналог', (int)$r['is_alternative'] === 0
   && $r['alt_specs_json'] === null && $r['alt_of'] === null && $r['notes'] === null, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('остаток — свой, 20', (int)$r['stock'] === 20);
Db::q("DELETE FROM request_items WHERE request_id=?", [$req]);

// Не утверждённый аналог — товар снова есть: строка возвращается на размер клиента
$a = $analogue('side-M', 0);
RequestItems::ensure($req);
$r = Db::one("SELECT * FROM request_items WHERE id=?", [$a]);
ok('аналог вернулся на запрошенный размер XL', $r['moysklad_product_id'] === 'p3-XL' && (int)$r['is_alternative'] === 0,
   json_encode($r, JSON_UNESCAPED_UNICODE));
ok('цена — модификации', (float)$r['price'] === 26900.0);
Db::q("DELETE FROM request_items WHERE request_id=?", [$req]);

// Утверждённый менеджером аналог при наличии оригинала остаётся — это его решение
$a = $analogue('side-M', 1);
RequestItems::ensure($req);
ok('утверждённый аналог не трогается', Db::val("SELECT moysklad_product_id FROM request_items WHERE id=?", [$a]) === 'side-M');

// Выбор из кандидатов — выбор менеджера, не наша замена
RequestItems::choose($req, $a, 'p3-XL');
$r = Db::one("SELECT * FROM request_items WHERE id=?", [$a]);
ok('choose() снимает аналог', (int)$r['is_alternative'] === 0 && $r['alt_specs_json'] === null);
Db::q("DELETE FROM request_items WHERE request_id=?", [$req]);

// Сохранение таблицы с другим товаром — браузер прислал is_alternative=1, сервер его снимает
$a = $analogue('side-M', 1);
$row = Db::one("SELECT * FROM request_items WHERE id=?", [$a]);
RequestItems::save($req, [['id' => $a, 'raw_name' => $row['raw_name'], 'quantity' => 5, 'moysklad_product_id' => 'p3-XL',
    'product_name' => 'Плита для бронежилета Бр3 (Размер: XL)', 'price' => 26900, 'is_alternative' => 1,
    'alt_of' => 'Плита для бронежилета Бр3', 'notes' => 'аналог: соответствует', 'is_confirmed' => 1]]);
$r = Db::one("SELECT * FROM request_items WHERE request_id=?", [$req]);
ok('save() со сменой товара снимает аналог', (int)$r['is_alternative'] === 0 && $r['alt_of'] === null && $r['notes'] === null,
   json_encode($r, JSON_UNESCAPED_UNICODE));
// Та же строка, товар не менялся, галочку поставил человек — остаётся
RequestItems::save($req, [['id' => (int)$r['id'], 'raw_name' => $r['raw_name'], 'quantity' => 5,
    'moysklad_product_id' => 'p3-XL', 'product_name' => $r['product_name'], 'price' => 26900, 'is_alternative' => 1,
    'alt_of' => 'Плита Бр3 ХЛ', 'is_confirmed' => 1]]);
ok('галочка «аналог» от человека сохраняется', (int)Db::val("SELECT is_alternative FROM request_items WHERE request_id=?", [$req]) === 1);

// ===================================================================== 6
echo "#128 наш ИНН клиента не опознаёт\n";
Db::insert('legal_entities', ['is_active' => 1, 'entity_type' => 'ooo', 'full_name' => 'ООО "АТЛАНТ АРМОР"',
    'short_name' => 'ООО "АТЛАНТ АРМОР"', 'inn' => '9731154370', 'city' => 'Москва']);
Crm::forgetOurs();
$ours = "от: ООО \"АТЛАНТ АРМОР\"\nИНН 9731154370 / КПП 773101001\nОГРН 1257700368595";
ok('ИНН в нашем письме — не найден', !isset(Crm::requisitesFromText($ours)['inn']));
ok('клиентский ИНН после нашего — найден', (Crm::requisitesFromText($ours . "\nПокупатель: ИНН 7707083893")['inn'] ?? '') === '7707083893');
ok('наше название — не компания', Crm::companyFromText($ours) === '');
ok('isOurInn', Crm::isOurInn('9731154370') && !Crm::isOurInn('7707083893'));

// Старая беда: карточка с нашим ИНН уже есть
$own = Db::insert('counterparties', ['name' => "ООО 'АТЛАНТ АРМОР'", 'name_normalized' => 'атлант армор',
    'inn' => '9731154370', 'email_domain' => 'dressie.ai']);
ok('по нашему ИНН карточка не находится', Crm::findCounterparty(['inn' => '9731154370', 'name' => 'ООО "АТЛАНТ АРМОР"']) === null);
ok('письмо с нашими реквизитами не заводит нашу карточку заново',
   Crm::resolveCounterparty(['inn' => '9731154370', 'text' => $ours, 'email' => 'kk@dressie.ai']) !== $own);

$mb = Db::insert('mailboxes', ['name' => 'a', 'email' => 'atlant@atlant-armour.ru', 'is_active' => 1]);
$msg = Db::insert('mail_messages', ['mailbox_id' => $mb, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 1,
    'subject' => 'Dressie', 'from_email' => 'kk@dressie.ai', 'body_text' => 'реквизиты', 'thread_key' => 's:dressie',
    'counterparty_id' => $own, 'date_at' => date('Y-m-d H:i:s')]);
$col = (int)Db::val("SELECT id FROM board_columns LIMIT 1");
if ($col) Db::insert('board_cards', ['column_id' => $col, 'counterparty_id' => $own, 'title' => '']);
ok('releaseOwnCards отпускает письма', Crm::releaseOwnCards() === 1
   && Db::val("SELECT counterparty_id FROM mail_messages WHERE id=?", [$msg]) === null);
ok('карточка доски — на переписку', !$col || Db::val("SELECT thread_key FROM board_cards WHERE counterparty_id IS NULL AND thread_key='s:dressie'") === 's:dressie');
ok('подсказка МойСклад не предлагает наш ИНН', Crm::moyskladHint($own, $ours)['inn'] === '');

// ===================================================================== 8
echo "#129 заметки наверху\n";
Db::q("DELETE FROM mail_messages");
Db::insert('mail_messages', ['mailbox_id' => $mb, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 2,
    'subject' => 'Без компании', 'from_email' => 'a@b.ru', 'body_text' => 'x', 'thread_key' => 's:t1', 'date_at' => date('Y-m-d H:i:s')]);
$m = (int)Db::insert('managers', ['login' => 'n064', 'password_hash' => 'x', 'name' => 'Яна']);
$n1 = Notes::add(null, 's:t1', $m, "Позвонить\nв понедельник");
ok('заметка к письму без компании', count(Notes::forCard(null, 's:t1')) === 1
   && Notes::forCard(null, 's:t1')[0]['body'] === "Позвонить\nв понедельник" && Notes::forCard(null, 's:t1')[0]['manager_name'] === 'Яна');
$cp = Crm::resolveCounterparty(['name' => 'ООО «Ромашка»', 'email' => 'a@b.ru']);
Crm::attachThread('s:t1', $cp);
ok('переехала на карточку вместе с перепиской', count(Notes::forCard($cp)) === 1 && Notes::forCard(null, 's:t1') === []);
$n2 = Notes::add($cp, '', $m, 'вторая');
ok('новые — сверху', (int)Notes::forCard($cp)[0]['id'] === $n2);
ok('пустая заметка не пишется', (function () use ($cp, $m) {
    try { Notes::add($cp, '', $m, '  '); return false; } catch (InvalidArgumentException $e) { return true; } })());
Crm::logEvent($cp, 'out', 'КП', ['event_type' => 'kp_sent']);
$ev = (int)Db::val("SELECT id FROM correspondence WHERE event_type='kp_sent'");
ok('веху сделки удалить нельзя', !Notes::delete($ev));
ok('заметку — можно', Notes::delete($n2) && count(Notes::forCard($cp)) === 1);

// ===================================================================== UI
echo "Интерфейс\n";
ok('#125 viewport с minimum-scale=1', str_contains($index, 'minimum-scale=1'));
ok('#125 окно гасит клавиатуру перед закрытием', (bool)preg_match('/closeModal\(\) \{[^}]*activeElement\.blur\(\)/s', $js));
ok('#123 тема ставится до стилей', strpos($index, "dataset.theme") < strpos($index, '<link rel="stylesheet"'));
ok('#123 тёмные токены', str_contains($css, 'html[data-theme="dark"] {') && str_contains($css, 'color-scheme: dark'));
ok('#123 письмо и КП — бумага, не затемняются', substr_count($js, 'content="only light"') >= 2);
ok('#123 переключатель', str_contains($js, 'cycleTheme()') && str_contains($js, "localStorage.setItem('theme'"));
ok('#126 pdf.js там, где браузер не рисует PDF', str_contains($js, 'pdfjs-dist@3.11.174') && str_contains($js, 'canShowPdf()')
   && !str_contains($js, '<iframe class="pdf-frame preview-frame" src="${url}"></iframe>'));
ok('#127 «В работе» над «Входящими» парой', str_contains($js, 'bcol-pair') && str_contains($css, '.bcol-pair > .bcol--work { order: -1; }'));
ok('#129 меню не уезжает за край', str_contains($js, 'placeMenu(m)') && str_contains($js, "addEventListener('toggle'"));
ok('#129 кнопки шапки — справа от названия', str_contains($js, 'pagehead--card') && str_contains($css, '.pagehead__top'));
ok('#129 заметки наверху и без модального окна', str_contains($js, 'notesHtml(') && !str_contains($js, 'noteForm('));
ok('#129 заметок нет в ленте', str_contains($js, "m.kind !== 'note'"));

echo $fail ? "\nFAILED: $fail\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
