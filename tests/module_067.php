<?php
/**
 * Модуль 067: issues #134–#146.
 *
 *   — поле «начните печатать» сужается до набранной модификации («Бр3 xl»);
 *   — «наушники» не находят «Переходники для наушников», вид товара — не прилагательное;
 *   — подбор помнит, чем отвечали на эти слова (отправленное КП, выбор менеджера);
 *   — СДЭК: вес из описания, коробки, ставка без ключа, тарифы по API (заглушка);
 *   — остатки в подсказках; интерфейс — по исходнику.
 *
 * Запуск:  php tests/module_067.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-067-' . getmypid() . '.db';
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
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/alternatives.php';
require_once ROOT . '/lib/match_memory.php';
require_once ROOT . '/lib/mail_compose.php';
require_once ROOT . '/lib/cdek.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');
$j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
$prod = fn(string $id, string $name, array $o = []) => Db::insert('products_cache', $o + [
    'moysklad_id' => $id, 'name' => $name, 'price' => 0, 'stock' => 0, 'reserved' => 0, 'product_type' => 'product']);

// ===================================================================== 1
echo "1. Поиск сужается до набранной модификации (#138)\n";
$prod('br3', 'Плита для бронежилета Бр3', ['article' => 'Br3', 'description' => 'Размеры S, M, L, XL. Вес 2,3 кг']);
foreach ([['S', 0, 22400], ['M', 0, 23900], ['L', 55, 25900], ['XL', 20, 26900]] as $i => [$size, $stock, $price]) {
    $prod("br3-$size", "Плита для бронежилета Бр3 (Размер: $size)", ['product_type' => 'variant', 'parent_id' => 'br3',
        'price' => $price, 'stock' => $stock, 'article' => "Br3-$size", 'characteristics' => "Размер: $size"]);
}
$prod('side', 'Боковая плита для бронежилета Бр3', ['description' => 'Размеры S, M, L, XL']);
foreach (['S', 'M', 'L'] as $size) {
    $prod("side-$size", "Боковая плита для бронежилета Бр3 (Размер: $size)", ['product_type' => 'variant',
        'parent_id' => 'side', 'stock' => 3, 'characteristics' => "Размер: $size"]);
}
ProductMatcher::forgetCatalog();
$suggest = function (string $q) {
    $items = ProductMatcher::search($q, 8);
    return Variants::expandSuggest($items, 40, $q);
};
$s = $suggest('Бр3 xl');
ok('«Бр3 xl» — только XL, без «весь товар» и без L/M/S', array_column($s, 'moysklad_id') === ['br3-XL'],
   $j(array_column($s, 'name')));
$s = $suggest('плита бр3');
$ids = array_column($s, 'moysklad_id');
ok('«плита бр3» — вся семья и строка «весь товар»', in_array('br3', $ids, true) && in_array('br3-S', $ids, true)
   && in_array('br3-XL', $ids, true), $j($ids));
$s = $suggest('Плита для бронежилета Бр3 (Размер: X');
ok('стёрли «L)» у XL — остаются размеры на «X»', array_column($s, 'moysklad_id') === ['br3-XL'], $j(array_column($s, 'name')));
$s = $suggest('Плита для бронежилета Бр3 (Размер:');
ok('стёрли размер целиком — снова вся семья', count(array_filter($s, fn($r) => ($r['parent_id'] ?? '') === 'br3')) === 4,
   $j(array_column($s, 'name')));
ok('короткое слово — только началом слова: «xl» не внутри «xxl»', !ProductMatcher::wordIn('xl', 'шлем xxl')
   && ProductMatcher::wordIn('xl', 'шлем xl') && ProductMatcher::wordIn('плит', 'плиты бр3'));
ok('короткое слово не ищется в описании (у товара «XL» только в описании)',
   !in_array('br3', array_column(ProductMatcher::search('бр3 xl', 8), 'moysklad_id'), true));
ok('кириллический размер: «бр3 хл» = XL', array_column($suggest('бр3 хл'), 'moysklad_id') === ['br3-XL']);
ok('предлоги не обязаны стоять в названии', ProductMatcher::searchWords('наушники с шумоподавлением') === ['наушники', 'шумоподавлением']);
ok('до 12 слов запроса', count(ProductMatcher::searchWords('a1 b2 c3 d4 e5 f6 g7 h8 i9 j10 k11 l12 m13')) === 12);

// ===================================================================== 2
echo "2. Наушники — не переходники для наушников (#142)\n";
$prod('peltor-adapt', 'Переходники для наушников Peltor (Вид рельсы: arc)', ['stock' => 8, 'price' => 3000,
    'description' => 'Переходники для крепления активных наушников на шлем']);
$prod('earmor', 'Earmor M31 MOD3 стрелковые наушники', ['stock' => 4, 'price' => 6000,
    'description' => 'Тактические наушники с активным шумоподавлением для стрельбы']);
ProductMatcher::forgetCatalog();
$q = 'Тактические наушники с активным шумоподавлением';
$c = ProductMatcher::findCandidates($q, 5);
ok('переходники не в кандидатах', !in_array('peltor-adapt', array_column($c, 'moysklad_id'), true), $j(array_column($c, 'name')));
ok('а наушники — первыми', ($c[0]['moysklad_id'] ?? '') === 'earmor', $j(array_column($c, 'name')));
$acc = ProductMatcher::findCandidates('переходник для наушников', 5);
ok('«переходник для наушников» находит переходники', ($acc[0]['moysklad_id'] ?? '') === 'peltor-adapt', $j(array_column($acc, 'name')));
$alt = Alternatives::candidates($q, 'earmor');
ok('аналог наушникам — не переходники', !in_array('peltor-adapt', array_column($alt, 'moysklad_id'), true), $j(array_column($alt, 'name')));
ok('плита для бронежилета не отвечает на «бронежилет»',
   !in_array('br3', array_column(ProductMatcher::findCandidates('бронежилет', 5), 'moysklad_id'), true));
ok('а на «плита бр3» — отвечает', (ProductMatcher::findCandidates('плита бр3', 5)[0]['moysklad_id'] ?? '') === 'br3');

// ===================================================================== 3
echo "3. Память подбора: чем отвечали на эти слова (#142)\n";
ok('ключ без порядка слов, размера и окончаний', MatchMemory::key('Тактические наушники с активным шумоподавлением')
   === MatchMemory::key('наушники тактический, активным шумоподавлением (размер L)'), MatchMemory::key($q));
ok('короткий мусор — пустой ключ', MatchMemory::key('шт') === '');
ok('модификация запоминается товаром', MatchMemory::remember('Плита Бр3 размер XL', 'br3-XL', 'manual')
   && (MatchMemory::recall('плита бр3')['moysklad_id'] ?? '') === 'br3');

// Старое письмо: менеджер поставил Earmor, КП ушло
Db::update('products_cache', ['stock' => 0], 'moysklad_id=?', ['earmor']);
$old = Db::insert('requests', ['source' => 'email', 'raw_text' => $q . ' - 4 шт', 'parsed_json' => $j(['items' => []])]);
$ri = Db::insert('request_items', ['request_id' => $old, 'position' => 1, 'raw_name' => $q, 'quantity' => 4,
    'moysklad_product_id' => 'earmor', 'product_name' => 'Earmor M31 MOD3 стрелковые наушники', 'price' => 6000, 'is_confirmed' => 1]);
$kp = Db::insert('proposals', ['request_id' => $old, 'number' => 'KP-TEST-1', 'status' => 'draft']);
Db::insert('proposal_items', ['proposal_id' => $kp, 'position' => 1, 'product_name' => 'Earmor M31 MOD3 стрелковые наушники',
    'moysklad_product_id' => 'earmor', 'quantity' => 4, 'price' => 6000, 'request_item_id' => $ri, 'requested_name' => $q]);
Db::insert('proposal_items', ['proposal_id' => $kp, 'position' => 2, 'product_name' => 'Тройной подсумок',
    'moysklad_product_id' => 'side', 'quantity' => 4, 'price' => 900, 'requested_name' => 'сумка на 3 магазина',
    'is_alternative' => 1]);
MailCompose::afterDocsSent([['kind' => 'kp', 'doc_id' => $kp, 'name' => 'КП.docx']], null, null, 'client@example.com');
ok('ушедшее КП записано в память', (MatchMemory::recall($q)['moysklad_id'] ?? '') === 'earmor');
ok('аналог в память не пишется', MatchMemory::recall('сумка на 3 магазина') === null);

// Новое письмо с теми же словами
$req = Db::insert('requests', ['source' => 'manual', 'raw_text' => $q . ' - 4 шт',
    'parsed_json' => $j(['items' => [['name' => $q, 'qty' => 4]]])]);
$m = ProductMatcher::matchItems([['name' => $q, 'qty' => 4]], false);
ok('подбор отвечает из памяти', ($m[0]['match']['moysklad_id'] ?? '') === 'earmor' && ($m[0]['match_source'] ?? '') === 'memory',
   $j($m[0]['match']['name'] ?? null));
ok('строка из памяти подтверждена и не спрашивает', !empty($m[0]['is_confirmed']) && empty($m[0]['needs_choice']));
ok('подсказка менеджеру — «как в прошлых КП»', str_contains((string)($m[0]['hint'] ?? ''), 'как в прошлых КП'));
ok('переходники не в «ещё похожие»', !in_array('peltor-adapt', array_column($m[0]['variants'] ?? [], 'moysklad_id'), true));

// Менеджер поправил: другой товар — память учится, свежая пара побеждает
$prod('comtac', 'Peltor ComTac XPI активные наушники', ['stock' => 5, 'price' => 40000]);
Db::update('products_cache', ['stock' => 4], 'moysklad_id=?', ['earmor']);
ProductMatcher::forgetCatalog();
$rows = RequestItems::ensure($req);
ok('новое письмо: строка на товаре из памяти', ($rows[0]['moysklad_product_id'] ?? '') === 'earmor'
   && ($rows[0]['match_source'] ?? '') === 'memory', $j($rows[0]['product_name'] ?? null));
$row = $rows[0];
sleep(1);
RequestItems::save($req, [array_merge($row, ['moysklad_product_id' => 'comtac', 'product_name' => 'Peltor ComTac XPI активные наушники',
    'is_confirmed' => 1, 'is_alternative' => 0])], 1);
ok('ручной выбор запомнен и побеждает старый', (MatchMemory::recall($q)['moysklad_id'] ?? '') === 'comtac');
Db::update('products_cache', ['is_archived' => 1], 'moysklad_id=?', ['comtac']);
ok('товар ушёл в архив — память берёт прежний живой', (MatchMemory::recall($q)['moysklad_id'] ?? '') === 'earmor');
ok('миграция v57: таблица и вес', Db::hasColumn('products_cache', 'weight')
   && (int)Db::val("SELECT COUNT(*) FROM sqlite_master WHERE name='match_memory'") === 1);
Db::q("DELETE FROM match_memory");
ok('заполнение из отправленных КП', MatchMemory::backfill() >= 1 && (MatchMemory::recall($q)['moysklad_id'] ?? '') === 'earmor');

// ===================================================================== 4
echo "4. Остаток — рядом с каждым товаром (#137)\n";
$picked = Variants::pickFor([
    ['moysklad_id' => 'a', 'name' => 'Шлем (Размер: L; Цвет: Coyote)', 'characteristics' => 'Размер: L; Цвет: Coyote', 'stock' => 5, 'reserved' => 0],
    ['moysklad_id' => 'b', 'name' => 'Шлем (Размер: L; Цвет: Multicam)', 'characteristics' => 'Размер: L; Цвет: Multicam', 'stock' => 3, 'reserved' => 0],
], 'L');
ok('«есть также» — с количеством', ($picked['other_choices'][0] ?? '') !== '' && str_contains($picked['other_choices'][0], '(3 шт.)'),
   $j($picked['other_choices'] ?? []));

// ===================================================================== 5
echo "5. СДЭК: вес, коробки, ставка и тарифы (#139)\n";
ok('вес «вес 2,3 кг»', Cdek::weightFromText('Плита. Вес 2,3 кг ± 0,1') === 2.3);
ok('масса в граммах', Cdek::weightFromText('Масса: 850 г, толщина 20 мм') === 0.85);
ok('«Вес без батареек: 275 гр.»', Cdek::weightFromText('Размеры: 120x95x118 мм. Вес без батареек: 275 гр.') === 0.275);
ok('«весь комплект» — не вес', Cdek::weightFromText('Весь комплект 5 шт в коробке 2 кг') === null);
ok('нет веса — null', Cdek::weightFromText('Шлем, размер L') === null);
ok('вес модификации — из описания товара', Cdek::weightFor('br3-XL') === 2.3);
Db::update('products_cache', ['weight' => 2.9], 'moysklad_id=?', ['br3']);
ok('поле «Вес» МойСклад важнее описания', Cdek::weightFor('br3-L') === 2.9);
ok('объёмный вес больше настоящего', Cdek::chargeable(['weight' => 1, 'l' => 50, 'w' => 40, 'h' => 30]) === 12.0);
ok('настоящий вес больше объёмного', Cdek::chargeable(['weight' => 9, 'l' => 33, 'w' => 25, 'h' => 15]) === 9.0);
ok('мест — по пределу коробки', Cdek::places(11.6, ['max' => 5]) === 3 && Cdek::places(5, ['max' => 5]) === 1
   && Cdek::places(0, ['max' => 5]) === 1);
$quote = Cdek::rateQuote([['weight' => 5.8, 'l' => 33, 'w' => 25, 'h' => 15], ['weight' => 5.8, 'l' => 33, 'w' => 25, 'h' => 15]], 300, 90);
ok('ставка: база + ₽/кг × вес к оплате', $quote['sum'] === round(300 + 90 * 11.6, 2) && $quote['places'] === 2, $j($quote));
ok('без ставки за кг — цены нет и сказано, чего не хватает', Cdek::rateQuote([['weight' => 1]], 300, 0)['sum'] === null
   && Cdek::rateQuote([['weight' => 1]], 300, 0)['missing'] === ['rate_kg']);
Settings::set('CDEK_BOXES', "CARTON_BOX_M; Коробка M; 33x25x15; 5\nмусорная строка\nBOX_BIG; Большая; 60х40х40; 25");
ok('коробки из настройки', array_column(Cdek::boxes(), 'code') === ['CARTON_BOX_M', 'BOX_BIG'] && Cdek::boxes()[1]['h'] === 40.0);
Settings::set('CDEK_BOXES', '');
ok('пустая настройка — встроенный список', Cdek::boxes() === Cdek::BOXES);
$w = Cdek::requestWeights($req);
ok('позиции подбора с весом штуки', count($w) >= 1 && array_key_exists('weight', $w[0]), $j($w));
ok('без ключа — выключен', !Cdek::enabled() && Cdek::state()['enabled'] === false && !isset(Cdek::state()['client_secret']));

$calls = [];
Cdek::$transport = function (string $method, string $url, $payload) use (&$calls) {
    $calls[] = [$method, $url, $payload];
    if (str_contains($url, '/oauth/token')) return [200, ['access_token' => 'tok-1', 'expires_in' => 3600]];
    if (str_contains($url, '/location/suggest/cities')) return [200, [['code' => 270, 'full_name' => 'Новосибирск, Новосибирская обл.']]];
    if (str_contains($url, '/calculator/tarifflist')) return [200, ['tariff_codes' => [
        ['tariff_code' => 139, 'tariff_name' => 'Посылка дверь-дверь', 'delivery_mode' => 1, 'delivery_sum' => 1500, 'period_min' => 3, 'period_max' => 5],
        ['tariff_code' => 136, 'tariff_name' => 'Посылка склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 900, 'period_min' => 2, 'period_max' => 4],
    ]]];
    if (str_contains($url, '/calculator/tariff')) return [200, ['total_sum' => 1050, 'delivery_sum' => 900, 'period_min' => 2, 'period_max' => 4]];
    return [404, ['errors' => [['message' => 'нет такого']]]];
};
Settings::set('CDEK_CLIENT_ID', 'acc');
Settings::set('CDEK_CLIENT_SECRET', 'sec');
ok('ключ задан — включён', Cdek::enabled());
$cities = Cdek::cities('новос');
ok('города по подсказке СДЭК', ($cities[0]['code'] ?? 0) === 270 && str_contains($cities[0]['name'], 'Новосибирск'), $j($cities));
$t = Cdek::tariffs(44, 270, [['weight' => 4.6, 'l' => 33, 'w' => 25, 'h' => 15]], 1);
ok('тарифы — дешёвые сверху, с режимом и сроком', ($t[0]['code'] ?? 0) === 136 && $t[0]['mode'] === 'склад-склад'
   && $t[0]['days_max'] === 4, $j($t));
$last = end($calls);
ok('посылка ушла в граммах и сантиметрах', ($last[2]['packages'][0]['weight'] ?? 0) === 4600 && ($last[2]['type'] ?? 0) === 1);
ok('токен один на час', count(array_filter($calls, fn($c) => str_contains($c[1], '/oauth/token'))) === 1);
$one = Cdek::tariff(136, 44, 270, [['weight' => 4.6, 'l' => 33, 'w' => 25, 'h' => 15]], 1, [['code' => 'CARTON_BOX_M', 'parameter' => '1']]);
ok('коробка — услугой, сумма с ней', $one['sum'] === 1050.0 && (end($calls)[2]['services'][0]['code'] ?? '') === 'CARTON_BOX_M');
Settings::set('CDEK_MARKUP', '10');
ok('наценка к цене СДЭК', Cdek::withMarkup(1000) === 1100.0);
Cdek::$transport = fn() => [401, ['errors' => [['message' => 'invalid token']]]];
Db::q("DELETE FROM settings WHERE key='cdek.token'");
$err = '';
try { Cdek::tariffs(44, 270, [['weight' => 1]], 1); } catch (Throwable $e) { $err = $e->getMessage(); }
ok('ошибка называет код и ответ СДЭК', str_contains($err, 'HTTP 401'), $err);
Cdek::$transport = null;
Settings::set('CDEK_CLIENT_ID', '');
Settings::set('CDEK_MARKUP', '0');

// ===================================================================== 6
echo "6. Интерфейс — по исходнику\n";
$idx = file_get_contents(ROOT . '/public/index.php');
$poll = file_get_contents(ROOT . '/public/api/notifications.php');
$settingsApi = file_get_contents(ROOT . '/public/api/settings.php');
ok('#134/#141 нажатие на текст свёрнутого письма раскрывает его', str_contains($js, 'onclick="App.openTmsgFromText(this, event)"')
   && str_contains($js, "if (!box || box.classList.contains('lmsg--open')) return;")
   && str_contains($css, '.lmsg__peek { -webkit-user-select: none; user-select: none; }'));
ok('#143 «✓ Прочитано» у письма и у переписки', str_contains($js, 'App.markMailRead(${m.id}, true, this)')
   && str_contains($js, "label: '✉ Непрочитанным'") && str_contains($js, "App.markThreadReadNow('\${key}', this)")
   && str_contains($js, "this.api('mail.php?action=read'"));
ok('#145 карточка и страница письма — на последнее письмо', str_contains($js, "focusOnOpen(cpId, key) {\n        this.focusLastLetter(key);")
   && str_contains($js, 'this.focusLastLetter(key, document.querySelector(\'#app [data-block="thread"]\'));')
   && !str_contains($js, 'focusReply('));
ok('#136 рамки сообщают настоящее предпочтение', str_contains($css, 'iframe { color-scheme: light dark; }'));
ok('#135 «фото в КП» — выбор с числом из настроек', str_contains($js, 'photoOptions(c.photos)')
   && str_contains($js, '`как в настройках${Number.isFinite(def)') && str_contains($settingsApi, "'kp_photos'"));
ok('#138 название — растущее поле, Enter берёт подсказку', str_contains($js, '<textarea data-field="product_name" rows="1"')
   && str_contains($js, 'growName(el)') && str_contains($js, 'nameKey(e, el)')
   && str_contains($css, 'field-sizing: content'));
ok('#137 остаток в «ещё похожие» и под товаром', str_contains($js, 'this.stockShort(v.stock)') && str_contains($js, 'data-stock-line'));
ok('#142 источник «как в прошлых КП»', str_contains($js, "memory: 'как в прошлых КП'"));
ok('#139 кнопка 🧮 справа от цены доставки и складка', str_contains($js, 'onclick="App.cdekToggle(this)">🧮</button>')
   && str_contains($js, '<div class="cdek" data-cdek hidden></div>') && str_contains($js, "cdek.php?action=calc"));
ok('#144 короткие подписи письма и счёта', str_contains($js, '↩ Ответить</button>')
   && str_contains($js, '<span class="btn__short">Счёт</span>') && str_contains($css, '.btn__txt { display: none; }'));
ok('автосохранение подбора не спотыкается о data-conditions самого хоста', str_contains($js, "if (e.target.closest('[data-match-photos]') || (cond && cond !== host)) return;")
   && !str_contains($js, "e.target.closest('[data-match-photos]') || e.target.closest('[data-conditions]')"));
ok('#146 сборка в опросе, полоса обновления и заглушка загрузки', str_contains($poll, "'build'")
   && str_contains($js, 'updateBar(') && str_contains($idx, 'Загружаем обновление интерфейса')
   && str_contains($js, "'Интерфейс обновлён — вы уже в новой версии'"));

echo $fail ? "\nFAILED: $fail\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
