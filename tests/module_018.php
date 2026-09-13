<?php
/**
 * Module 018 end to end, against a throwaway database and with no network:
 * a КП that prices nothing is not confirmed on the same click as one that does,
 * positions the catalog never answered are named out loud, the cover letter is
 * bound to the names of the table, an e-mail address never prints as the buyer,
 * and «Подобрать нейросетью» keeps its hands off a confirmed line and says what
 * it did.
 *
 * Run:  php tests/module_018.php
 *
 * It builds its own database in the system temp directory — `data/kp.db` is
 * never opened, so running this on a server cannot touch live data.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);

// Same guard as module_013: the override key has to come FIRST in the union,
// or a server whose config.php names the real DB_PATH would be written to.
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
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/alternatives.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/request_shape.php';
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/parser.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

// Never call a model or the network in this test
Settings::set('ALT_USE_LLM', '0');
Settings::set('ALT_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('KNOWLEDGE_ENABLED', '0');

// ---------- catalog ----------
$products = [
    ['ms-1', 'Наушники активные ELECTRONIC EARMUFFS', 'EM32', 12, 0, 18500,
     'Активные наушники. Защита слуха 24 дБ. Питание 2xAAA.'],
    ['ms-2', 'Шлем Атлант АШ-1', 'ASH1', 4, 0, 31000,
     'Шлем баллистический. Класс защиты Бр2. Размер M.'],
];
foreach ($products as [$id, $name, $art, $stock, $reserved, $price, $desc]) {
    Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved, unit, description, vat, product_type, source, updated_at)
           VALUES (?,?,?,?,?,?,?,'шт.',?,5,'product','test',datetime('now'))",
          [$id, $name, mb_strtolower($name), $art, $price, $stock, $reserved, $desc]);
}
ProductMatcher::forgetCatalog();

echo "\n1. КП без цены не подтверждается тем же кликом, что нормальное\n";
$cpId = Db::insert('counterparties', ['name' => 'ООО «Рубеж»', 'inn' => '7701234567', 'contact_email' => 'z@rubezh.ru']);
// proposals.request_id is NOT NULL — every КП in this test hangs off one letter
$baseRequest = Db::insert('requests', ['source' => 'email', 'raw_text' => 'Прошу выставить КП',
    'counterparty_id' => $cpId, 'status' => 'new']);
$emptyKp = Db::insert('proposals', ['request_id' => $baseRequest, 'counterparty_id' => $cpId, 'vat_rate' => 5]);
$gapsEmpty = KpContent::priceGaps($emptyKp);
ok('КП без позиций опознано как пустое', $gapsEmpty['empty']);
ok('итог пустого КП — ноль', $gapsEmpty['total'] === 0.0, (string)$gapsEmpty['total']);

$kpId = Db::insert('proposals', ['request_id' => $baseRequest, 'counterparty_id' => $cpId, 'vat_rate' => 5]);
Db::insert('proposal_items', ['proposal_id' => $kpId, 'position' => 1, 'product_name' => 'Шлем Атлант АШ-1',
    'requested_name' => 'шлем АШ-1', 'moysklad_product_id' => 'ms-2', 'unit' => 'шт.',
    'quantity' => 2, 'price' => 31000]);
Db::insert('proposal_items', ['proposal_id' => $kpId, 'position' => 2, 'product_name' => 'Рация неизвестной модели',
    'requested_name' => 'рация Motorola DP4400', 'unit' => 'шт.', 'quantity' => 5, 'price' => 0]);

$gaps = KpContent::priceGaps($kpId);
ok('позиция без цены найдена', count($gaps['items']) === 1, (string)count($gaps['items']));
ok('и названа по номеру строки', (int)$gaps['items'][0]['position'] === 2, (string)$gaps['items'][0]['position']);
ok('КП с позициями пустым не считается', !$gaps['empty']);
ok('итог считается по строкам с ценой', $gaps['total'] === 62000.0, (string)$gaps['total']);

// «Итого: 0,00 руб.» — the case SC-005 let through before
$zeroKp = Db::insert('proposals', ['request_id' => $baseRequest, 'counterparty_id' => $cpId, 'vat_rate' => 5]);
Db::insert('proposal_items', ['proposal_id' => $zeroKp, 'position' => 1, 'product_name' => 'Бронежилет',
    'unit' => 'шт.', 'quantity' => 10, 'price' => 0]);
$zeroGaps = KpContent::priceGaps($zeroKp);
ok('КП на 0,00 руб. — сплошная дыра', count($zeroGaps['items']) === 1 && $zeroGaps['total'] === 0.0);

echo "\n2. Позиции без совпадения названы, а не потеряны\n";
$unmatched = KpContent::unmatchedRows($kpId);
ok('строка без товара попала в блок', count($unmatched) === 1, (string)count($unmatched));
ok('и словами клиента, а не нашими', $unmatched[0]['requested'] === 'рация Motorola DP4400', $unmatched[0]['requested']);
ok('количество сохранено', (float)$unmatched[0]['quantity'] === 5.0, (string)$unmatched[0]['quantity']);

// A row the manager confirmed by hand is his decision, not a hole
Db::q("UPDATE proposal_items SET is_confirmed=1 WHERE proposal_id=? AND position=2", [$kpId]);
ok('подтверждённая менеджером строка дырой не считается', KpContent::unmatchedRows($kpId) === []);
Db::q("UPDATE proposal_items SET is_confirmed=0 WHERE proposal_id=? AND position=2", [$kpId]);

Requisites::freeze($kpId);
$html = PdfGenerator::html($kpId);
ok('блок в документе есть', str_contains($html, 'Позиции запроса, по которым нужно уточнение'));
ok('и в нём фраза клиента', str_contains($html, 'рация Motorola DP4400'));
// A КП whose every line found a product has no such block at all
$cleanKp = Db::insert('proposals', ['request_id' => $baseRequest, 'counterparty_id' => $cpId, 'vat_rate' => 5]);
Db::insert('proposal_items', ['proposal_id' => $cleanKp, 'position' => 1, 'product_name' => 'Шлем Атлант АШ-1',
    'requested_name' => 'шлем АШ-1', 'moysklad_product_id' => 'ms-2', 'unit' => 'шт.', 'quantity' => 2, 'price' => 31000]);
Requisites::freeze($cleanKp);
ok('в КП без дыр блока нет', !str_contains(PdfGenerator::html($cleanKp), 'по которым нужно уточнение'));
ok('а КП на 0,00 руб. — сплошь дыра', count(KpContent::unmatchedRows($zeroKp)) === 1);

// The same block reaches Word — one template, two files (module 016)
require_once ROOT . '/lib/docx.php';
$docxPath = DocxGenerator::generate($kpId);
$zip = new ZipArchive();
$zip->open($docxPath);
$docXml = (string)$zip->getFromName('word/document.xml');
$zip->close();
ok('блок дошёл и до Word', str_contains($docXml, 'рация Motorola DP4400'));
@unlink($docxPath);

echo "\n3. Письмо и таблица КП называют товар одинаково\n";
$coverPrompt = Prompts::registry()['cover_letter'][3];
ok('промпт письма привязан к названиям таблицы', str_contains($coverPrompt, 'ДОСЛОВНО'));
ok('и запрещает называть товар иначе', str_contains($coverPrompt, 'чем таблица'));
ok('и требует назвать несопоставленные позиции', str_contains($coverPrompt, 'Не нашли в каталоге'));
// The two lists the letter is written from: the table's own names on one side,
// the client's unanswered words on the other, and never the same line in both
$kpItems = Db::all("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY position", [$kpId]);
$lists = RequestParser::coverLetterLists($kpItems, $unmatched);
ok('в перечень письма идёт название из таблицы КП',
   str_contains($lists['positions'], 'Шлем Атлант АШ-1'), $lists['positions']);
ok('а не то, как клиент попросил', !str_contains($lists['positions'], 'шлем АШ-1'));
ok('несопоставленная строка в перечень позиций не попала',
   !str_contains($lists['positions'], 'Motorola') && !str_contains($lists['positions'], 'Рация'));
ok('она названа во втором блоке, словами клиента',
   str_contains($lists['unmatched'], 'рация Motorola DP4400'), $lists['unmatched']);

$replyBlock = RequestItems::unmatchedBlock($unmatched);
ok('блок для черновика ответа собирается', str_contains($replyBlock, 'рация Motorola DP4400'));
ok('и запрещает выдумывать замену', str_contains($replyBlock, 'не придумывай им замену'));
ok('пустой список блока не даёт', RequestItems::unmatchedBlock([]) === '');

echo "\n4. Покупатель — организация, а не e-mail\n";
ok('название из подписи цитируемого письма',
   Crm::companyFromText("> С уважением,\n> Иванов И.И.\n> АО \"Уралэлемент\"\n> тел. 8-800") === 'АО «Уралэлемент»',
   Crm::companyFromText("АО \"Уралэлемент\""));
ok('кавычки-ёлочки тоже', Crm::companyFromText('Просим КП. ООО «Ромашка-Плюс», ИНН 123') === 'ООО «Ромашка-Плюс»');
ok('без кавычек тоже', Crm::companyFromText('ЗАО Технотрейд просит выставить счёт') === 'ЗАО Технотрейд');
ok('наша собственная подпись не берётся',
   Crm::companyFromText('С уважением, ИП Сурков К.А.') === '', Crm::companyFromText('ИП Сурков К.А.'));
ok('в письме без организации ничего не выдумывается', Crm::companyFromText('Здравствуйте, пришлите прайс') === '');

$resolved = Crm::resolveCounterparty([
    'inn' => '', 'name' => '', 'email' => 'zakupki@uralelement.ru',
    'text' => "Прошу выставить КП.\n\nС уважением,\nначальник отдела снабжения\nАО \"Уралэлемент\"",
]);
$card = Db::one("SELECT * FROM counterparties WHERE id=?", [$resolved]);
ok('карточка открыта на название, а не на адрес', $card['name'] === 'АО «Уралэлемент»', (string)$card['name']);

// A card that already holds an e-mail must not print it as the buyer
$emailCp = Db::insert('counterparties', ['name' => 'zakupki@uralelement.ru', 'contact_email' => 'zakupki@uralelement.ru']);
$emailKp = Db::insert('proposals', ['request_id' => $baseRequest, 'counterparty_id' => $emailCp, 'vat_rate' => 5]);
Db::insert('proposal_items', ['proposal_id' => $emailKp, 'position' => 1, 'product_name' => 'Шлем Атлант АШ-1',
    'moysklad_product_id' => 'ms-2', 'unit' => 'шт.', 'quantity' => 1, 'price' => 31000]);
$snap = Requisites::snapshot($emailKp);
ok('e-mail опознан как не-название', $snap['buyer']['name_is_email']);
ok('в документ идёт «Покупатель уточняется»', $snap['buyer']['name'] === Requisites::BUYER_UNKNOWN, $snap['buyer']['name']);
ok('что было в карточке — сохранено для менеджера', $snap['buyer']['name_source'] === 'zakupki@uralelement.ru');
Requisites::freeze($emailKp);
$emailHtml = PdfGenerator::html($emailKp);
ok('адреса в реквизитах покупателя нет', !str_contains($emailHtml, 'zakupki@uralelement.ru')
                                         || !str_contains($emailHtml, 'Покупатель</div>'), 'см. блок «Покупатель»');
ok('вместо него — «Покупатель уточняется»', str_contains($emailHtml, Requisites::BUYER_UNKNOWN));

$named = Requisites::snapshot($kpId);
ok('нормальное название не трогается', $named['buyer']['name'] === 'ООО «Рубеж»' && !$named['buyer']['name_is_email']);

echo "\n5. Подбор не трогает подтверждённые строки и отчитывается\n";
$requestId = Db::insert('requests', [
    'source' => 'email', 'raw_text' => "Наушники активные ELECTRONIC\t3\nШлем АШ-1\t2\nРация Motorola DP4400\t5",
    'counterparty_id' => $cpId, 'status' => 'new',
    'parsed_json' => json_encode(['items' => [
        // Совпадение уверенное, но не «сам подтвердил» — ровно та строка, ради
        // которой кнопка и нажимается
        ['name' => 'Наушники активные ELECTRONIC', 'qty' => 3, 'raw_text' => 'Наушники активные 3 шт'],
        ['name' => 'Шлем Атлант АШ-1', 'qty' => 2, 'raw_text' => 'Шлем АШ-1 2 шт'],
        ['name' => 'Рация Motorola DP4400', 'qty' => 5, 'raw_text' => 'Рация Motorola DP4400 5 шт'],
    ]], JSON_UNESCAPED_UNICODE),
]);
$rows = RequestItems::ensure($requestId, false);
ok('три строки', count($rows) === 3, (string)count($rows));

// The manager puts the helmet in by hand and ticks it off
$helmet = null;
foreach ($rows as $r) if (str_contains((string)$r['raw_name'], 'Шлем')) $helmet = $r;
Db::update('request_items', ['product_name' => 'Шлем Атлант АШ-1 (размер L)', 'moysklad_product_id' => 'ms-2',
    'price' => 33000, 'is_confirmed' => 1], 'id=?', [$helmet['id']]);

$report = RequestItems::rematchReport($requestId, false);
$after = Db::one("SELECT * FROM request_items WHERE id=?", [$helmet['id']]);
ok('✓ менеджера на месте', (int)$after['is_confirmed'] === 1);
ok('и его название не переписано', $after['product_name'] === 'Шлем Атлант АШ-1 (размер L)', (string)$after['product_name']);
ok('и его цена не переписана', (float)$after['price'] === 33000.0, (string)$after['price']);
ok('отчёт считает нетронутые строки', $report['kept'] === 1, (string)$report['kept']);
ok('пересмотрены только открытые', $report['repicked'] === 2, (string)$report['repicked']);
ok('наушники подобраны заново', $report['found'] === 1, (string)$report['found']);
ok('и о рации сказано, что не нашлось', $report['empty'] === 1, (string)$report['empty']);
ok('строк по-прежнему три', count($report['items']) === 3, (string)count($report['items']));

// Nothing left open: the run reports that instead of looking like a success
Db::q("UPDATE request_items SET is_confirmed=1 WHERE request_id=?", [$requestId]);
$noop = RequestItems::rematchReport($requestId, false);
ok('нечего пересматривать — так и сказано', $noop['repicked'] === 0 && $noop['kept'] === 3,
   "repicked={$noop['repicked']} kept={$noop['kept']}");

// The unmatched radio is what the reply draft and the КП will name
Db::q("UPDATE request_items SET is_confirmed=0 WHERE request_id=? AND (moysklad_product_id IS NULL OR moysklad_product_id='')",
      [$requestId]);
$open = RequestItems::unmatched($requestId);
ok('несопоставленная строка запроса видна отдельно', count($open) === 1, (string)count($open));
ok('и это рация', str_contains($open[0]['requested'], 'Motorola'), $open[0]['requested']);

echo "\n" . ($fail ? "$fail FAILED\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
