<?php
/**
 * Модуль 023 целиком, на выбрасываемой базе и без сети:
 *
 *   — «под заказ» считается тремя величинами, а не фразой, и скидки множатся
 *     друг на друга;
 *   — цена, поставленная руками, переживает повторный подбор;
 *   — модификация без своей цены берёт цену родителя ПО ТИПУ цены;
 *   — подбор находит товар по описанию, но описание не выигрывает у названия;
 *   — «Покупатель уточняется» в документ не идёт;
 *   — каждый товар печатается с новой страницы, подписи под QR нет;
 *   — предпросмотр КП собирает пропавший файл заново;
 *   — спам уходит с экрана и уносит карточку доски;
 *   — поиск находит письмо по адресу в копии, по имени отправителя и по
 *     имени вложения — в том числе в архиве;
 *   — черновик ответа переживает закрытую вкладку;
 *   — письма удалённого ящика возвращаются на экран;
 *   — КП собирается текстом письма, без QR;
 *   — модуль Битрикса больше не обещает автозагрузчику несуществующий класс.
 *
 * Запуск:  php tests/module_023.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе,
 * так что прогон на сервере не может задеть живые данные.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-023-' . getmypid() . '.db';
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
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/catalog.php';
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/markup.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/terms.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/kp_text.php';
require_once ROOT . '/lib/outbox.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/signatures.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('MATCH_VECTOR_WEIGHT', '0');
Settings::set('ALTERNATIVES_ENABLED', '0');
Settings::set('KP_QR_CODE', '0');

// =====================================================================  1

echo "\n== 1. «Под заказ»: срок, скидка за ожидание и предоплата ==\n";

$base = ['price' => 100.0, 'moysklad_product_id' => 'ms-1', 'stock_available' => 0, 'stock_reserved' => 0,
         'wait_on' => 0, 'wait_months' => null, 'wait_discount' => null, 'wait_prepay' => null,
         'discount_percent' => 0];

ok('позиция без остатка опознана как «под заказ»', Terms::isBackorder($base));
ok('пока условия выключены — цена прежняя', Terms::price($base) === 100.0, (string)Terms::price($base));
ok('и строки условий нет', Terms::note($base) === '');

$prepared = Terms::prepare($base);
ok('значения по умолчанию проставлены', ($prepared['wait_months'] ?? null) === 3
    && (float)($prepared['wait_discount'] ?? 0) === 10.0 && (int)($prepared['wait_prepay'] ?? 0) === 100,
   json_encode($prepared, JSON_UNESCAPED_UNICODE));
ok('но выключатель сам не поднялся', !array_key_exists('wait_on', $prepared));

$on = array_merge($base, $prepared, ['wait_on' => 1]);
ok('со включёнными условиями цена падает на 10%', Terms::price($on) === 90.0, (string)Terms::price($on));
ok('и документ говорит об этом словами',
   Terms::note($on) === 'под заказ, срок ожидания 3 месяца, скидка за ожидание 10%, полная предоплата',
   Terms::note($on));

// Скидки множатся друг на друга, а не складываются: 0.95 × 0.90 = 0.855
$both = array_merge($on, ['discount_percent' => 5]);
ok('ручная скидка и ожидание считаются друг на друга', Terms::price($both) === 85.5, (string)Terms::price($both));
ok('итоговая скидка — 14.5%, а не 15', Terms::totalDiscount($both) === 14.5, (string)Terms::totalDiscount($both));

$inStock = array_merge($base, ['stock_available' => 7]);
ok('товар на складе условий ожидания не получает', Terms::prepare($inStock) === []);

// Настройка «включать самим» поднимает выключатель — и только она
Settings::set('KP_WAIT_AUTO', '1');
ok('с настройкой «включать самим» выключатель поднимается',
   (int)(Terms::prepare($base)['wait_on'] ?? 0) === 1);
Settings::set('KP_WAIT_AUTO', '0');

// =====================================================================  2

echo "\n== 2. Цена модификации и цена, поставленная руками ==\n";

Settings::set('CATALOG_DEFAULT_PRICE_TYPE', 'Опт безнал');
Db::insert('products_cache', [
    'moysklad_id' => 'p-helmet', 'name' => 'Баллистический шлем Протон', 'name_normalized' => 'баллистический шлем протон',
    'article' => 'PRT-1', 'price' => 39000, 'unit' => 'шт.', 'product_type' => 'product',
    'prices_json' => json_encode(['Розница' => 39000, 'Опт безнал' => 29500], JSON_UNESCAPED_UNICODE),
    'stock' => 4, 'reserved' => 0,
]);
Db::insert('products_cache', [
    'moysklad_id' => 'v-helmet-l', 'name' => 'Баллистический шлем Протон (Размер: L)',
    'name_normalized' => 'баллистический шлем протон размер l', 'article' => 'PRT-1-L',
    'price' => 0, 'unit' => 'шт.', 'product_type' => 'variant', 'parent_id' => 'p-helmet',
    // У модификации есть своя розница, но НЕТ опта — а КП выставляется по опту
    'prices_json' => json_encode(['Розница' => 40000], JSON_UNESCAPED_UNICODE),
    'stock' => 0, 'reserved' => 0, 'characteristics' => 'Размер: L',
]);

$variant = Db::one("SELECT * FROM products_cache WHERE moysklad_id='v-helmet-l'");
ok('у модификации берётся оптовая цена РОДИТЕЛЯ', Catalog::priceFor($variant) === 29500.0,
   (string)Catalog::priceFor($variant));
ok('своя розница модификации не теряется', Catalog::priceFor($variant, null, 'Розница') === 40000.0,
   (string)Catalog::priceFor($variant, null, 'Розница'));

$parent = Db::one("SELECT * FROM products_cache WHERE moysklad_id='p-helmet'");
ok('у товара — его собственная оптовая', Catalog::priceFor($parent) === 29500.0, (string)Catalog::priceFor($parent));

// =====================================================================  3

echo "\n== 3. Подбор идёт и по описанию ==\n";

ProductMatcher::forgetCatalog();
Db::insert('products_cache', [
    'moysklad_id' => 'p-nvg', 'name' => 'Прибор ночного видения Филин',
    'name_normalized' => 'прибор ночного видения филин', 'article' => 'NV-7',
    'price' => 120000, 'unit' => 'шт.', 'product_type' => 'product', 'stock' => 2, 'reserved' => 0,
    // Слова «монокуляр» в названии нет — оно есть только в описании
    'description' => '<ul><li>Монокуляр ночного видения поколения 2+</li><li>Крепление на шлем</li></ul>',
]);
ProductMatcher::forgetCatalog();

$found = ProductMatcher::findCandidates('монокуляр', 5);
ok('товар найден по слову из описания', (bool)array_filter($found, fn($r) => $r['moysklad_id'] === 'p-nvg'),
   json_encode(array_column($found, 'name'), JSON_UNESCAPED_UNICODE));
$nvg = array_values(array_filter($found, fn($r) => $r['moysklad_id'] === 'p-nvg'))[0] ?? [];
ok('и подписан как найденный по описанию', ($nvg['source'] ?? '') === 'description', (string)($nvg['source'] ?? ''));

// Совпадение в названии всегда сильнее совпадения в описании
$byName = ProductMatcher::findCandidates('прибор ночного видения филин', 5);
ok('по названию тот же товар набирает больше', ($byName[0]['score'] ?? 0) > ($nvg['score'] ?? 1),
   ($byName[0]['score'] ?? 0) . ' > ' . ($nvg['score'] ?? 1));

Settings::set('MATCH_DESC_WEIGHT', '0');
ProductMatcher::forgetCatalog();
ok('с нулевым весом описание не ищется',
   !array_filter(ProductMatcher::findCandidates('монокуляр', 5), fn($r) => $r['moysklad_id'] === 'p-nvg'));
Settings::set('MATCH_DESC_WEIGHT', '0.75');
ProductMatcher::forgetCatalog();

ok('разметка описания приходит в подбор текстом',
   Markup::toPlainText('<ul><li>Монокуляр <b>ночного</b> видения</li></ul>') === 'Монокуляр ночного видения',
   Markup::toPlainText('<ul><li>Монокуляр <b>ночного</b> видения</li></ul>'));

// Описание вышло на первый план: на «монокуляр» первым выпадал составной товар,
// который перечисляет монокуляр в составе, а сам товар и его модификация не
// проходили порога — Жаккар топит короткий запрос в длинном имени каталога
Db::insert('products_cache', [
    'moysklad_id' => 'p-mono', 'name' => 'Монокуляр тепловизионный Пульсар Аксион XM30F',
    'name_normalized' => '', 'article' => 'AX-30', 'price' => 190000, 'unit' => 'шт.',
    'product_type' => 'product', 'stock' => 3, 'reserved' => 0,
    'description' => 'Тепловизор с матрицей 320x240, дальность обнаружения 1300 м.',
]);
Db::insert('products_cache', [
    'moysklad_id' => 'v-mono', 'name' => 'Монокуляр тепловизионный Пульсар Аксион XM30F (Цвет: чёрный)',
    'name_normalized' => '', 'article' => 'AX-30-B', 'price' => 0, 'unit' => 'шт.',
    'product_type' => 'variant', 'parent_id' => 'p-mono', 'stock' => 2, 'reserved' => 0,
    'characteristics' => 'Цвет: чёрный',
]);
Db::insert('products_cache', [
    'moysklad_id' => 'p-kit', 'name' => 'Комплект разведчика «Сумрак»',
    'name_normalized' => '', 'article' => 'KIT-7', 'price' => 420000, 'unit' => 'компл.',
    'product_type' => 'bundle', 'stock' => 1, 'reserved' => 0,
    'description' => '<ul><li>Монокуляр тепловизионный Пульсар Аксион XM30F — 1 шт</li>'
                   . '<li>Рюкзак тактический — 1 шт</li></ul>',
]);
ProductMatcher::forgetCatalog();

$mono = ProductMatcher::findCandidates('монокуляр аксион', 5);
$ids  = array_column($mono, 'moysklad_id');
ok('на «монокуляр аксион» первым идёт сам товар', ($ids[0] ?? '') === 'p-mono',
   json_encode($ids, JSON_UNESCAPED_UNICODE));
ok('модификация — следом за ним', array_search('v-mono', $ids, true) === 1,
   json_encode($ids, JSON_UNESCAPED_UNICODE));
ok('комплект из описания — последним', array_search('p-kit', $ids, true) === count($ids) - 1,
   json_encode($ids, JSON_UNESCAPED_UNICODE));
ok('и он не потерялся вовсе — это подсказка, а не мусор', in_array('p-kit', $ids, true));

// На одно слово: имя, потом описание товара, потом описание комплекта
$byWord = ProductMatcher::findCandidates('монокуляр', 5);
$ids    = array_column($byWord, 'moysklad_id');
ok('порядок рядов тот же', array_search('p-mono', $ids, true) < array_search('p-nvg', $ids, true)
   && array_search('p-nvg', $ids, true) < array_search('p-kit', $ids, true),
   json_encode($ids, JSON_UNESCAPED_UNICODE));

// Ряд важнее оценки: комплект набрал описанием БОЛЬШЕ и всё равно стоит ниже
$kit = array_values(array_filter($byWord, fn($r) => $r['moysklad_id'] === 'p-kit'))[0] ?? [];
ok('оценка комплекта выше, а место — ниже', ($kit['score'] ?? 0) > ($byWord[0]['score'] ?? 1),
   ($byWord[0]['score'] ?? 0) . ' по названию против ' . ($kit['score'] ?? 0) . ' по описанию');

// Одно слово из письма НЕ выбирает конкретную позицию за менеджера
$picked = ProductMatcher::matchItems([['name' => 'монокуляр', 'qty' => 1]], false);
ok('«монокуляр» подставился сам товар, а не комплект',
   ($picked[0]['match']['moysklad_id'] ?? '') === 'p-mono', (string)($picked[0]['match']['name'] ?? ''));
ok('но «ок» ему не поставлено', ($picked[0]['is_confirmed'] ?? true) === false);

// Строка, найденная ТОЛЬКО описанием, не подтверждается сама
$only = ProductMatcher::matchItems([['name' => 'рюкзак тактический', 'qty' => 1]], false);
ok('найденное по описанию подставляется', ($only[0]['match']['moysklad_id'] ?? '') === 'p-kit',
   (string)($only[0]['match']['name'] ?? ''));
ok('и остаётся неподтверждённым', ($only[0]['is_confirmed'] ?? true) === false);
ok('и помечено источником «по описанию»', ($only[0]['match_source'] ?? '') === 'description',
   (string)($only[0]['match_source'] ?? ''));

// Название прежде описания: «Бронеплита … БР-3» — это плита, а не бронежилет,
// у которого плиты в описании и в хвосте имени
Db::insert('products_cache', [
    'moysklad_id' => 'p-plate3', 'name' => 'Бронеплита Атлант Бр3 СВМПЭ',
    'name_normalized' => '', 'article' => 'BP-3', 'price' => 18000, 'unit' => 'шт.',
    'product_type' => 'product', 'stock' => 4, 'reserved' => 0,
    'description' => 'Плита из полиэтилена, размер 250х300 мм.',
]);
Db::insert('products_cache', [
    'moysklad_id' => 'p-vest5', 'name' => 'Бронежилет штурмовой Атлант Бр5 (с бронеплитами)',
    'name_normalized' => '', 'article' => 'BZ-5', 'price' => 111000, 'unit' => 'шт.',
    'product_type' => 'product', 'stock' => 0, 'reserved' => 0,
    'description' => 'Бронеплита 30x25 см, защита по классу Бр5, модули из СВМПЭ.',
]);
Db::insert('products_cache', [
    'moysklad_id' => 'p-vest2', 'name' => 'Бронежилет Атлант базовый (без бронеплит)',
    'name_normalized' => '', 'article' => 'BZ-2', 'price' => 49000, 'unit' => 'шт.',
    'product_type' => 'product', 'stock' => 5, 'reserved' => 0,
    'description' => 'Карманы под бронеплита 30x25 см, пакеты СВМПЭ.',
]);
ProductMatcher::forgetCatalog();

$plate = ProductMatcher::matchItems([['name' => 'Бронеплита 30x25 см СВМПЭ БР-3', 'qty' => 8]], false)[0];
ok('«Бронеплита … БР-3» находит плиту по названию',
   ($plate['match']['moysklad_id'] ?? '') === 'p-plate3', (string)($plate['match']['name'] ?? ''));
ok('источник — слова названия, а не описание', ($plate['match_source'] ?? '') === 'words',
   (string)($plate['match_source'] ?? ''));
ok('бронежилет Бр5 с чужим классом не предлагается',
   !in_array('p-vest5', array_column($plate['variants'], 'moysklad_id'), true),
   json_encode(array_column($plate['variants'], 'name'), JSON_UNESCAPED_UNICODE));
ok('найденное описанием не «равнозначно» найденному названием', $plate['needs_choice'] === false);

// Тот же вид товара стоит выше чужого, найденного теми же словами названия
$head = array_column(ProductMatcher::findCandidates('бронеплита атлант', 5), 'moysklad_id');
ok('на «бронеплита атлант» первой идёт плита', ($head[0] ?? '') === 'p-plate3',
   json_encode($head, JSON_UNESCAPED_UNICODE));

// =====================================================================  4

echo "\n== 4. Строка подбора: комментарий, ручная цена, условия ==\n";

$cpId = Db::insert('counterparties', ['name' => 'ООО «Рубеж»', 'contact_email' => 'zakup@rubezh.ru']);
$requestId = Db::insert('requests', [
    'source' => 'email', 'raw_text' => "Шлем Протон — 3 шт", 'counterparty_id' => $cpId, 'status' => 'new',
    'parsed_json' => json_encode(['items' => [['name' => 'Шлем Протон', 'qty' => 3]]], JSON_UNESCAPED_UNICODE),
]);
RequestItems::ensure($requestId);
$rows = RequestItems::all($requestId);
ok('строка подбора заведена', count($rows) === 1, (string)count($rows));

$row = $rows[0];
RequestItems::save($requestId, [[
    'id' => $row['id'], 'raw_name' => $row['raw_name'], 'product_name' => 'Баллистический шлем Протон',
    'moysklad_product_id' => 'p-helmet', 'quantity' => 3, 'unit' => 'шт.', 'price' => 27000,
    'is_confirmed' => 1, 'price_is_manual' => 1, 'discount_percent' => 5,
    'comment_text' => '<b>Шлем</b> поставляется с подвесной системой',
    'wait_on' => 1, 'wait_months' => 2, 'wait_discount' => 10, 'wait_prepay' => 100,
]]);
$saved = RequestItems::all($requestId)[0];
ok('цена сохранена как ручная', (int)$saved['price_is_manual'] === 1);
ok('комментарий лёг разметкой, а не тегами', str_contains((string)$saved['comment_text'], '**Шлем**'),
   (string)$saved['comment_text']);
ok('цена после скидок — 27000 × 0.95 × 0.90', (float)$saved['effective_price'] === 23085.0,
   (string)$saved['effective_price']);
ok('условия ожидания напечатаны словами', str_contains((string)$saved['wait_note'], 'срок ожидания 2 месяца'),
   (string)$saved['wait_note']);

// Повторный подбор уточняет строку, но не трогает ни подтверждённую позицию,
// ни цену, которую поставил человек
RequestItems::rematch($requestId, false);
$after = RequestItems::all($requestId)[0];
ok('ручная цена пережила повторный подбор', (float)$after['price'] === 27000.0, (string)$after['price']);

$block = RequestItems::matchedBlock(RequestItems::all($requestId));
ok('подобранное уходит в промпт ответа', str_contains($block, 'Баллистический шлем Протон'));
// Описание товара в письмо не идёт — его место в карточке КП (модуль 031)
ok('а комментарий по товару — нет', !str_contains($block, 'подвесной системой'), $block);

// =====================================================================  5

echo "\n== 5. Печать КП ==\n";

Db::insert('legal_entities', ['is_active' => 1, 'full_name' => 'ООО «Атлант Армор»', 'short_name' => 'Атлант Армор',
                              'inn' => '7700000000', 'pays_vat' => 1, 'city' => 'Москва', 'address' => 'ул. Складская, 1']);

// Покупатель, у которого вместо названия адрес почты
$emailCp = Db::insert('counterparties', ['name' => 'zakupki@uralelement.ru', 'contact_email' => 'zakupki@uralelement.ru']);
$kpId = Db::insert('proposals', ['request_id' => $requestId, 'counterparty_id' => $emailCp, 'vat_rate' => 5]);
foreach ([['Баллистический шлем Протон', 'p-helmet', 100.0], ['Прибор ночного видения Филин', 'p-nvg', 200.0]] as $i => [$name, $ms, $price]) {
    Db::insert('proposal_items', [
        'proposal_id' => $kpId, 'position' => $i + 1, 'product_name' => $name, 'moysklad_product_id' => $ms,
        'unit' => 'шт.', 'quantity' => 1, 'price' => $price,
        'description_text' => 'Описание позиции', 'stock_available' => 0, 'stock_reserved' => 0,
    ]);
}
Requisites::freeze($kpId);
$html = PdfGenerator::html($kpId);

ok('«Покупатель уточняется» в документ не идёт', !str_contains($html, Requisites::BUYER_UNKNOWN));
ok('и адрес почты вместо названия тоже', !str_contains($html, 'zakupki@uralelement.ru'));
ok('второй товар — с новой страницы', str_contains($html, 'class="card card--break"'));
ok('а первый — нет', substr_count($html, 'class="card card--break"') === 1,
   (string)substr_count($html, 'class="card card--break"'));
ok('подписи «Наведите камеру телефона» нет', !str_contains($html, 'Наведите камеру'));

Settings::set('KP_PAGE_BREAK', '0');
ok('настройка разрыва страниц выключается',
   !str_contains(PdfGenerator::html($kpId), 'class="card card--break"'));
Settings::set('KP_PAGE_BREAK', '1');

// Условия ожидания печатаются и двигают цену
Terms::prepareProposal($kpId);
Db::q("UPDATE proposal_items SET wait_on=1 WHERE proposal_id=?", [$kpId]);
$waited = PdfGenerator::html($kpId);
ok('в таблице появились условия ожидания', str_contains($waited, 'скидка за ожидание 10%'));
// Исходная цена и цена со скидкой теперь в двух разных колонках, а не
// зачёркиванием в одной (issue #60)
ok('цена со скидкой — в своей колонке', str_contains($waited, 'class="price discount">90 руб.'));
ok('позиция на 100 руб. напечатана по 90', str_contains($waited, '90 руб.'), 'см. таблицу позиций');

// Лимит фотографий — на самом КП, а не только в настройках
Db::update('proposals', ['photos_per_item' => 2], 'id=?', [$kpId]);
ok('лимит фото хранится на КП', (int)Db::val("SELECT photos_per_item FROM proposals WHERE id=?", [$kpId]) === 2);

// КП текстом письма: то же самое, без QR
$text = KpText::render($kpId);
ok('КП собирается текстом', str_contains($text['text'], 'Баллистический шлем Протон'));
ok('с итогом', str_contains($text['text'], 'Итого:'), $text['text']);
ok('и без картинок и QR', !str_contains($text['text'], 'data:image'));

// =====================================================================  6

echo "\n== 6. Спам уходит с экрана вместе с карточкой доски ==\n";

$boxId = Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru',
                                  'is_active' => 1, 'is_default' => 1]);
$spamId = Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
    'subject' => 'Выгодное предложение', 'from_email' => 'spam@example.com', 'from_name' => 'Рассылка',
    'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'купите слона', 'is_read' => 0,
    'date_at' => '2026-09-10 10:00:00', 'thread_key' => 's:spam',
]);
$board = Db::insert('boards', ['name' => 'Письма']);
$col = Db::insert('board_columns', ['board_id' => $board, 'title' => 'Входящие', 'position' => 1, 'kind' => 'inbox']);
Db::insert('board_cards', ['column_id' => $col, 'title' => 'Выгодное предложение',
                           'thread_key' => 's:spam', 'mail_message_id' => $spamId]);

MailSync::markAsSpam($spamId);
$spam = Db::one("SELECT * FROM mail_messages WHERE id=?", [$spamId]);
ok('письмо помечено спамом', ($spam['category'] ?? '') === 'spam');
ok('и ушло с экрана', trim((string)$spam['archived_at']) !== '', (string)$spam['archived_at']);
ok('карточка доски исчезла вместе с ним',
   (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE thread_key='s:spam'") === 0);

MailSync::unarchiveMessage($spamId);
ok('возврат в работу снимает и спам',
   (string)Db::val("SELECT category FROM mail_messages WHERE id=?", [$spamId]) === 'other');

// =====================================================================  7

echo "\n== 7. Поиск, которому всё равно, где лежит письмо ==\n";

$hidden = Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
    'subject' => 'Запрос цен', 'from_email' => 'logist@voevoda.pro', 'from_name' => 'Петрова Анна',
    'to_emails' => 'info@atlant-armour.ru', 'cc_emails' => 'buh@voevoda.pro',
    'body_text' => 'просьба выставить счёт', 'is_read' => 1,
    'date_at' => '2026-09-11 10:00:00', 'thread_key' => 's:voevoda',
]);
Db::insert('attachments', ['mail_message_id' => $hidden, 'filename' => 'Спецификация-2026.pdf',
                           'path' => 'storage/x.pdf', 'size' => 10, 'mime' => 'application/pdf',
                           'extracted_text' => 'бронежилет Страж, 12 шт']);

$find = fn(string $q) => array_column(MailArchive::query(['q' => $q, 'archived' => 'all'])['items'], 'id');
ok('находится по адресу в копии', in_array($hidden, $find('buh@voevoda.pro'), true));
ok('по имени отправителя', in_array($hidden, $find('Петрова'), true));
ok('по имени вложения', in_array($hidden, $find('Спецификация'), true));
ok('по тексту внутри вложения', in_array($hidden, $find('Страж'), true));
ok('по части адреса', in_array($hidden, $find('voevoda'), true));
ok('два слова ищутся как «и то, и другое»', in_array($hidden, $find('Петрова счёт'), true));
ok('а несовпадающая пара не находится', !in_array($hidden, $find('Петрова слона'), true));

// Архив ищется вместе с работой
MailSync::archiveMessage($hidden, null);
ok('архивное письмо поиск всё равно находит', in_array($hidden, $find('Петрова'), true));
ok('а обычный список — нет',
   !in_array($hidden, array_column(MailArchive::query(['q' => 'Петрова'])['items'], 'id'), true));

ok('цепочка находится по слову из вложения',
   in_array('s:voevoda', array_column(MailThreads::query(['q' => 'Страж', 'archived' => 'all'])['items'], 'thread_key'), true));

ok('запрос режется на слова', MailArchive::searchTerms('  Петрова   счёт ') === ['Петрова', 'счёт'],
   json_encode(MailArchive::searchTerms('  Петрова   счёт '), JSON_UNESCAPED_UNICODE));
ok('кавычки держат фразу целой', MailArchive::searchTerms('"запрос цен" шлем') === ['запрос цен', 'шлем'],
   json_encode(MailArchive::searchTerms('"запрос цен" шлем'), JSON_UNESCAPED_UNICODE));

// =====================================================================  8

echo "\n== 8. Письма удалённого ящика и повторный импорт mbox ==\n";

$lost = Db::insert('mail_messages', [
    'mailbox_id' => null, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
    'subject' => 'Старое письмо из mbox', 'from_email' => 'old@client.ru',
    'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'история переписки', 'is_read' => 1,
    'date_at' => '2025-01-01 10:00:00', 'thread_key' => 's:old',
    'archived_at' => '2026-09-01 10:00:00', 'archived_reason' => 'mailbox_off',
]);
ok('письмо ящика, которого нет, спрятано', (string)Db::val("SELECT archived_reason FROM mail_messages WHERE id=?", [$lost]) === 'mailbox_off');
ok('кнопка возвращает его на экран', MailArchive::restoreOrphaned() >= 1);
ok('и оно снова в работе', Db::val("SELECT archived_at FROM mail_messages WHERE id=?", [$lost]) === null);

// Форсированный импорт кладёт письмо, даже когда такое уже есть
$msg = ['from' => 'old@client.ru', 'from_name' => 'Клиент', 'to' => 'info@atlant-armour.ru',
        'subject' => 'Старое письмо из mbox', 'body' => 'история переписки', 'date' => '2025-01-01 10:00:00',
        'message_id' => '<dup-1@client.ru>', 'folder' => 'INBOX', 'uid' => 0, 'seen' => true];
$box = Mailboxes::get($boxId);
$first = MailArchive::storeIncoming($box, $msg, 'in', true);
ok('первое письмо легло', $first > 0);
ok('второе — дубликат', MailArchive::storeIncoming($box, $msg, 'in', true) === 0);
ok('а с «не считаться с дубликатами» — легло', MailArchive::storeIncoming($box, $msg, 'in', true, true) > 0);

ok('колонка force у импорта есть', Db::hasColumn('mbox_imports', 'force'));

// =====================================================================  9

echo "\n== 9. Черновик ответа не теряется ==\n";

$mgr = Db::insert('managers', ['name' => 'Иванов И.И.', 'login' => 'ivanov',
                               'email' => 'i@atlant-armour.ru', 'password_hash' => 'x']);
Db::insert('mail_drafts', ['mail_message_id' => $hidden, 'thread_key' => 's:voevoda', 'manager_id' => $mgr,
                           'body' => '<p>Добрый день! Готовим ответ…</p>', 'subject' => 'Re: Запрос цен']);
$draft = Db::one("SELECT * FROM mail_drafts WHERE mail_message_id=? AND manager_id=?", [$hidden, $mgr]);
ok('черновик живёт у письма', (bool)$draft && str_contains((string)$draft['body'], 'Готовим ответ'));
ok('и у каждого менеджера свой',
   (int)Db::val("SELECT COUNT(*) FROM mail_drafts WHERE mail_message_id=?", [$hidden]) === 1);

// ===================================================================== 10

echo "\n== 10. Свои файлы к письму ==\n";

$tmp = tempnam(sys_get_temp_dir(), 'kp-out');
file_put_contents($tmp, 'PDF');
$accepted = Outbox::accept(['name' => '../../Счёт №5/2026.pdf', 'tmp_name' => $tmp, 'size' => 3,
                            'error' => UPLOAD_ERR_OK], $mgr);
ok('имя файла обезврежено', !str_contains($accepted['filename'], '/') && !str_contains($accepted['filename'], '..'),
   $accepted['filename']);
ok('человеческое имя сохранено', str_contains($accepted['filename'], 'Счёт'), $accepted['filename']);
$paths = array_column(Outbox::resolve([$accepted['name']], $mgr), 'path');
ok('файл находится по своему имени', count($paths) === 1 && is_file($paths[0]));
ok('а в письмо уходит человеческое имя, без служебной приставки',
   Outbox::displayName($accepted['name']) === $accepted['filename'],
   Outbox::displayName($accepted['name']));
ok('чужой путь не достать', Outbox::resolve(['../../../etc/passwd'], $mgr) === []);
ok('и файл другого менеджера тоже', Outbox::resolve([$accepted['name']], $mgr + 1) === []);
array_map('unlink', $paths);

// ===================================================================== 11

echo "\n== 11. Модуль Битрикса больше не обещает несуществующий класс ==\n";

$moduleDir = ROOT . '/bitrix-module/atlant.kpsync';
$include = (string)file_get_contents($moduleDir . '/include.php');
preg_match_all("~'([^']+)'\s*=>\s*'([^']+\.php)'~", $include, $m, PREG_SET_ORDER);
$missing = [];
foreach ($m as $pair) {
    if (!is_file($moduleDir . '/' . $pair[2])) $missing[] = $pair[2];
}
ok('каждый автозагружаемый класс лежит файлом', $missing === [], implode(', ', $missing));

$config = (string)file_get_contents($moduleDir . '/lib/config.php');
foreach (['MODULE_ID', 'DEFAULTS', 'function get', 'function enabled', 'function tokenOk',
          'function iblockIds', 'function exportLimit', 'function base'] as $needed) {
    ok("Config::$needed на месте", str_contains($config, $needed));
}
// Всё, что options.php и kp.php спрашивают у настроек, класс умеет отвечать
$optionKeys = ['ENABLED', 'TOKEN', 'IBLOCK_IDS', 'ARTICLE_PROP', 'SEARCH_BY_NAME', 'ACTIVE_ONLY',
               'SITE_URL', 'EXPORT_LIMIT'];
$absent = array_values(array_filter($optionKeys, fn($k) => !str_contains($config, "'$k'")));
ok('у каждого поля настроек есть значение по умолчанию', $absent === [], implode(', ', $absent));

echo "\n" . ($fail ? "$fail FAILED\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
