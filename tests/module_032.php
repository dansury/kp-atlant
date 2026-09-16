<?php
/**
 * Module 032: описание товара в поле подбора, в КП и мимо письма.
 *
 * Проверяется ровно то, ради чего модуль появился:
 *   — пустой комментарий строки приходит заполненным описанием из каталога,
 *     и берётся оно из `products_cache`, а не из МойСклад;
 *   — у модификации без своего описания — родительское;
 *   — нетронутое описание не оседает на строке и идёт за сменой товара;
 *   — карточка КП печатает описание ОДИН раз;
 *   — в промпт ответа клиенту описание не попадает.
 *
 * Run:  php tests/module_032.php
 *
 * База создаётся в системном временном каталоге — `data/kp.db` не открывается,
 * так что запуск на сервере не может задеть живые данные.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-' . getmypid() . '.db';
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
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/pdf.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

// Ни модели, ни сети
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('KNOWLEDGE_ENABLED', '0');
Settings::set('KP_QR_CODE', '0');
Settings::set('KP_SHOW_SITE_LINK', '0');

/** Товар в каталоге — так, как его кладёт синхронизация из МойСклад. */
function product(string $id, string $name, string $description, ?string $parentId = null): void {
    Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved,
                                       unit, description, vat, product_type, parent_id, source, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))",
        [$id, $name, mb_strtolower($name), strtoupper($id), 27000.0, 5, 0, 'шт.', $description, 5,
         $parentId === null ? 'product' : 'variant', $parentId, 'api']);
}

// Описание из МойСклад приходит размеченным и с разделами — карточка КП режет
// их на свои блоки, и в поле подбора должна попасть только описательная часть
$helmetDesc = "<p>Шлем <b>Протон</b> держит пистолетную пулю.</p>\n"
            . "<p>Характеристики:</p><ul><li>вес 1,4 кг</li></ul>\n"
            . "<p>Комплектация:</p><ul><li>чехол</li></ul>";
product('p-helmet', 'Баллистический шлем Протон', $helmetDesc);
product('p-helmet-l', 'Баллистический шлем Протон (размер L)', '', 'p-helmet');
product('p-nvg', 'Прибор ночного видения Филин', '<p>Монокуляр с креплением на шлем.</p>');

echo "\n1. Описание подставлено в поле подбора\n";

$cpId = Db::insert('counterparties', ['name' => 'ООО «Рубеж»', 'contact_email' => 'zakup@rubezh.ru']);
$requestId = Db::insert('requests', [
    'source' => 'email', 'raw_text' => 'Шлем Протон — 3 шт', 'counterparty_id' => $cpId, 'status' => 'new',
    'parsed_json' => json_encode(['items' => [['name' => 'Баллистический шлем Протон', 'qty' => 3]]], JSON_UNESCAPED_UNICODE),
]);
RequestItems::ensure($requestId);
$row = RequestItems::all($requestId)[0];

ok('строка нашла шлем', (string)$row['moysklad_product_id'] === 'p-helmet', (string)$row['product_name']);
ok('в пустом комментарии стоит описание', str_contains((string)$row['comment_text'], 'держит пистолетную пулю'),
   (string)$row['comment_text']);
ok('разметкой, а не тегами', !str_contains((string)$row['comment_text'], '<b>'), (string)$row['comment_text']);
ok('характеристики в поле не уехали — у КП для них свой блок',
   !str_contains((string)$row['comment_text'], 'вес 1,4 кг'), (string)$row['comment_text']);
ok('и комплектация тоже', !str_contains((string)$row['comment_text'], 'чехол'), (string)$row['comment_text']);

// В базе на строке по-прежнему пусто: описание живёт в каталоге, а не копией
ok('на строке в базе описание не лежит',
   Db::val("SELECT comment_text FROM request_items WHERE id=?", [$row['id']]) === null);

echo "\n2. У модификации — описание родителя\n";
$variant = RequestItems::catalogDescriptions(['p-helmet-l']);
ok('размер L описан словами товара', str_contains((string)($variant['p-helmet-l'] ?? ''), 'держит пистолетную пулю'),
   json_encode($variant, JSON_UNESCAPED_UNICODE));
ok('товара без описания в ответе нет', RequestItems::catalogDescriptions(['p-nobody']) === []);

echo "\n3. Кандидаты строки несут своё описание\n";

// «Ещё похожие» под строкой: выбрали другой товар — в поле его описание,
// и за ним не надо ходить на сервер второй раз
Db::update('request_items', ['match_variants' => json_encode([
    ['moysklad_id' => 'p-nvg', 'name' => 'Прибор ночного видения Филин'],
], JSON_UNESCAPED_UNICODE)], 'id=?', [$row['id']]);
$cand = RequestItems::all($requestId)[0]['variants'][0] ?? [];
ok('у кандидата есть описание', str_contains((string)($cand['description'] ?? ''), 'Монокуляр'),
   json_encode($cand, JSON_UNESCAPED_UNICODE));
Db::update('request_items', ['match_variants' => null], 'id=?', [$row['id']]);

echo "\n4. Нетронутое описание не оседает на строке\n";

$base = [
    'id' => $row['id'], 'raw_name' => $row['raw_name'], 'product_name' => $row['product_name'],
    'moysklad_product_id' => 'p-helmet', 'quantity' => 3, 'unit' => 'шт.', 'price' => 27000,
];
RequestItems::save($requestId, [$base + ['comment_text' => $row['comment_text']]]);
ok('вернувшееся как есть не сохранилось',
   Db::val("SELECT comment_text FROM request_items WHERE id=?", [$row['id']]) === null);
ok('но в поле оно снова стоит',
   str_contains((string)RequestItems::all($requestId)[0]['comment_text'], 'держит пистолетную пулю'));

RequestItems::save($requestId, [$base + ['comment_text' => '<b>Шлем</b> поставляется с подвесной системой']]);
$saved = RequestItems::all($requestId)[0];
ok('свой текст сохранён разметкой', str_contains((string)$saved['comment_text'], '**Шлем**'),
   (string)$saved['comment_text']);

// Подбор поставил другой товар — описание идёт за ним, а не остаётся от прежнего
RequestItems::save($requestId, [array_merge($base, [
    'moysklad_product_id' => 'p-nvg', 'product_name' => 'Прибор ночного видения Филин',
    'comment_text' => $row['comment_text'],
])]);
$moved = RequestItems::all($requestId)[0];
ok('на новой позиции — её описание', str_contains((string)$moved['comment_text'], 'Монокуляр'),
   (string)$moved['comment_text']);
ok('и ни слова от прежней', !str_contains((string)$moved['comment_text'], 'пистолетную пулю'),
   (string)$moved['comment_text']);

echo "\n5. В карточке КП описание печатается один раз\n";

Db::insert('legal_entities', ['is_active' => 1, 'full_name' => 'ООО «Атлант Армор»', 'short_name' => 'Атлант Армор',
                              'inn' => '7700000000', 'pays_vat' => 1, 'city' => 'Москва', 'address' => 'ул. Складская, 1']);
$kpId = Db::insert('proposals', ['request_id' => $requestId, 'counterparty_id' => $cpId, 'vat_rate' => 5]);
$itemId = Db::insert('proposal_items', [
    'proposal_id' => $kpId, 'position' => 1, 'product_name' => 'Баллистический шлем Протон',
    'moysklad_product_id' => 'p-helmet', 'unit' => 'шт.', 'quantity' => 3, 'price' => 27000.0, 'stock_available' => 5,
]);
KpContent::enrichItems($kpId);
$kpItem = Db::one("SELECT * FROM proposal_items WHERE id=?", [$itemId]);
ok('описание карточки заполнено', str_contains((string)$kpItem['description_text'], 'держит пистолетную пулю'),
   (string)$kpItem['description_text']);
ok('характеристики — своим полем', str_contains((string)$kpItem['specs_text'], 'вес 1,4 кг'),
   (string)$kpItem['specs_text']);
ok('копией в комментарий описание не легло', trim((string)($kpItem['comment_text'] ?? '')) === '',
   (string)$kpItem['comment_text']);

$html = PdfGenerator::html($kpId);
ok('в документе описание один раз', substr_count($html, 'держит пистолетную пулю') === 1,
   (string)substr_count($html, 'держит пистолетную пулю'));

// Комментарий, написанный менеджером, вытесняет описание — но не удваивает его
Db::update('proposal_items', ['comment_text' => 'Идёт с подвесной системой нового образца'], 'id=?', [$itemId]);
$html = PdfGenerator::html($kpId);
ok('слова менеджера напечатаны', str_contains($html, 'подвесной системой нового образца'));
ok('и описание из каталога их не дублирует', !str_contains($html, 'держит пистолетную пулю'), 'описание осталось');

// Описание, заполненное в `comment_text` прежним кодом, снимается на сборке
Db::update('proposal_items', ['comment_text' => Markup::toMarkdown($helmetDesc)], 'id=?', [$itemId]);
KpContent::enrichItems($kpId);
ok('старая копия описания снята',
   trim((string)Db::val("SELECT comment_text FROM proposal_items WHERE id=?", [$itemId])) === '');

echo "\n6. В строку КП описание не копируется\n";

RequestItems::save($requestId, [array_merge($base, ['comment_text' => $row['comment_text']])]);
$toKp = RequestItems::toProposalItems(RequestItems::all($requestId));
ok('позиция для КП собрана', count($toKp) === 1, (string)count($toKp));
ok('подставленное описание в неё не поехало', ($toKp[0]['comment_text'] ?? null) === null,
   (string)($toKp[0]['comment_text'] ?? 'null'));

RequestItems::save($requestId, [array_merge($base, ['comment_text' => 'Идёт с подвесной системой нового образца'])]);
$toKp = RequestItems::toProposalItems(RequestItems::all($requestId));
ok('а слова менеджера — поехали', str_contains((string)($toKp[0]['comment_text'] ?? ''), 'подвесной системой'),
   (string)($toKp[0]['comment_text'] ?? 'null'));

echo "\n7. В письмо клиенту описание не уходит\n";

RequestItems::save($requestId, [$base + ['comment_text' => $row['comment_text']]]);
$block = RequestItems::matchedBlock(RequestItems::all($requestId));
ok('подобранное в промпте есть', str_contains($block, 'Баллистический шлем Протон'));
ok('цена в промпте есть', str_contains($block, '27 000,00'));
ok('описания товара в промпте нет', !str_contains($block, 'пистолетную пулю'));

RequestItems::save($requestId, [$base + ['comment_text' => 'Идёт с подвесной системой нового образца']]);
$block = RequestItems::matchedBlock(RequestItems::all($requestId));
ok('и комментарий менеджера тоже не уходит в письмо',
   !str_contains($block, 'подвесной системой'));

echo "\n" . ($fail ? "ПРОВАЛОВ: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
