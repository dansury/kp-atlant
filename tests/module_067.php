<?php
/**
 * Модуль 067: issues #134–#139.
 *
 *   — свёрнутое письмо раскрывается по тексту, у письма без текстовой части есть начало;
 *   — поле «фото в КП» объясняет себя;
 *   — бумага в рамке остаётся белой при «Тёмной теме для сайтов»;
 *   — остаток у позиции, в «ещё похожие» и в подсказке модификаций;
 *   — название позиции видно целиком, слова сужают список до нужной модификации;
 *   — расчёт доставки по тарифам СДЭК (заглушка вместо API) и оценка без него.
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
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/variants.php';
require_once ROOT . '/lib/alternatives.php';
require_once ROOT . '/lib/cdek.php';
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/catalog_import.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');
$j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);

// Каталог: шлем размер×цвет, плита Бр3 с размерами, боковая плита с весом МойСклад
$ins = function (array $r) {
    $r += ['reserved' => 0, 'unit' => 'шт.', 'product_type' => 'product', 'source' => 'moysklad', 'is_archived' => 0,
           'price' => 0, 'stock' => 0];
    $r['name_normalized'] = mb_strtolower(preg_replace('/[\s\-\"\'«»()]+/u', ' ', $r['name']));
    Db::insert('products_cache', $r);
};
$ins(['moysklad_id' => 'helm', 'name' => 'Баллистический шлем Атом Арамид', 'article' => 'ATOM',
      'description' => '<p>Шлем из арамида. Подвесная система по типу Wendy. Вес шлема 1,45 кг.</p>']);
foreach (['M(56-59)' => ['Multicam' => 0, 'Coyote' => 2, 'Mox' => 1], 'L(60-62)' => ['Multicam' => 3, 'Mox' => 5]] as $size => $colors) {
    foreach ($colors as $c => $st) {
        $ins(['moysklad_id' => "helm-$size-$c", 'name' => "Баллистический шлем Атом Арамид (Размер шлема: $size; Цвет: $c)",
              'article' => "ATOM-$size-$c", 'price' => 50000, 'stock' => $st, 'product_type' => 'variant', 'parent_id' => 'helm',
              'characteristics' => "Размер шлема: $size; Цвет: $c"]);
    }
}
$ins(['moysklad_id' => 'p3', 'name' => 'Плита для бронежилета Бр3', 'article' => 'Br3',
      'description' => '<p>Бронеплита. Размеры S–XL. Масса плиты 2,3–2,5 кг.</p>']);
foreach (['S' => 0, 'L' => 55, 'XL' => 20] as $sz => $st) {
    $ins(['moysklad_id' => "p3-$sz", 'name' => "Плита для бронежилета Бр3 (Размер: $sz)", 'article' => "Br3-$sz",
          'price' => 25900, 'stock' => $st, 'product_type' => 'variant', 'parent_id' => 'p3', 'characteristics' => "Размер: $sz"]);
}
$ins(['moysklad_id' => 'side3', 'name' => 'Боковая плита для бронежилета Бр3', 'article' => 'SBr3', 'weight' => 0.9]);
foreach (['XL' => 0, 'XXL' => 3] as $sz => $st) {
    $ins(['moysklad_id' => "side3-$sz", 'name' => "Боковая плита для бронежилета Бр3 (Размер: $sz)", 'article' => "SBr3-$sz",
          'price' => 9900, 'stock' => $st, 'product_type' => 'variant', 'parent_id' => 'side3', 'characteristics' => "Размер: $sz"]);
}
ProductMatcher::forgetCatalog();

// ===================================================================== 1
echo "1. #134 письмо раскрывается нажатием по тексту\n";
ok('нажатие по свёрнутому письму его раскрывает', str_contains($js, 'onclick="App.tmsgClick(event, this)"')
   && (bool)preg_match('/tmsgClick\(e, box\) \{\s*if \(box\.classList\.contains\(\'lmsg--open\'\)\) return;/', $js));
ok('шапка, кнопки и ссылки делают своё', str_contains($js, "e.target.closest('.lmsg__head, button, a, input, textarea, select, summary, details')"));
ok('письмо без текстовой части показывает начало из HTML', str_contains($js, "(m.body_text || this.htmlText(m.body_html))")
   && str_contains($js, "new DOMParser().parseFromString(String(html), 'text/html').body.textContent"));
ok('у свёрнутого письма текст выглядит нажимаемым', str_contains($css, '.lmsg:not(.lmsg--open) .lmsg__text, .lmsg:not(.lmsg--open) .lmsg__tags { cursor: pointer; }'));

// ===================================================================== 2
echo "\n2. #135 поле «фото» объясняет себя\n";
ok('подпись «фото в КП, шт.»', str_contains($js, '<label>фото в КП, шт.'));
ok('в поле — число по умолчанию, а не обрезанное «как в настройках»', str_contains($js, 'placeholder="${Number(this.ui.kp_photos ?? 5)}"'));
ok('у поля свой «?»', str_contains($js, "\${this.hint('kp-photos')}") && str_contains($js, "'kp-photos':    ['Фото в КП',"));
ok('число по умолчанию отдаёт сервер', str_contains(file_get_contents(ROOT . '/public/api/settings.php'), "'kp_photos'          => KpContent::photoLimit(),"));

// ===================================================================== 3
echo "\n3. #136 бумага белая при «Тёмной теме для сайтов»\n";
ok('рамки письма и листа КП — со схемой dark у самого iframe', str_contains($css, '.html-frame, .kp-page { color-scheme: dark; }'));
ok('внутри рамки по-прежнему only light', str_contains($js, '<meta name="color-scheme" content="only light"><style>')
   && str_contains($js, ':root{color-scheme:only light}'));

// ===================================================================== 4
echo "\n4. #137 остаток везде, где предлагается товар\n";
ok('у позиции — «в наличии N» или «нет в наличии — под заказ»', str_contains($js, '${this.stockBadge(i)}')
   && str_contains($js, "Number(free) > 0 ? `в наличии \${Number(free)} \${unit || 'шт.'}` : 'нет в наличии — под заказ'"));
ok('выбор из списка обновляет число', str_contains($js, "this.setStockBadge(row, p.stock, p.unit);")
   && str_contains($js, "this.setStockBadge(row, null);"));
ok('«ещё похожие» — со своими количествами', str_contains($js, "<span class=\"stock-tail\">(\${this.freeOf(v) > 0 ? this.freeOf(v) + ' шт.' : 'нет'})</span>"));
ok('свёрнутая строка тоже говорит, сколько есть', str_contains($js, "free === null ? '' : ' · ' + this.esc(this.stockText(free, i.unit))"));
ok('резерв кандидата вычитается', str_contains($js, 'return Number(p.stock) - (Number(p.reserved) || 0);'));
$pick = Variants::resolveRow(['variant_label' => 'L', 'moysklad_product_id' => 'helm', 'raw_name' => 'шлем Атом размер L', 'quantity' => 1]);
ok('подсказка модификации — с числами', str_contains((string)($pick['match_hint'] ?? ''), 'шт.)') &&
   (bool)preg_match('/есть также: .+ — \d+ шт\./u', (string)($pick['match_hint'] ?? '')), (string)($pick['match_hint'] ?? ''));

// ===================================================================== 5
echo "\n5. #138 название целиком, слова сужают список\n";
ok('название позиции — растущее поле в несколько строк', str_contains($js, '<textarea data-field="product_name" rows="1"')
   && str_contains($css, 'field-sizing: content') && str_contains($js, "growField(el) {"));
ok('Enter не рвёт название, а берёт первую подсказку', str_contains($js, 'nameKey(e) {') && str_contains($js, "onkeydown=\"App.nameKey(event)\""));
ok('подсветка следующего шага видит новое поле', str_contains($js, "host.querySelectorAll('[data-match-rows] [data-field=\"product_name\"]')"));
$suggest = fn(string $q) => array_column(Variants::expandSuggest(ProductMatcher::search($q, 8, 16), 40, $q), 'moysklad_id');
ok('«Бр3 xl» — только XL, и XXL не попадает', $suggest('Бр3 xl') === ['p3', 'p3-XL', 'side3', 'side3-XL'], implode(',', $suggest('Бр3 xl')));
$cut = 'Баллистический шлем Атом Арамид (Размер шлема: M(56-59); Цвет:';
ok('стёрли цвет — все цвета этого размера', $suggest($cut) === ['helm', 'helm-M(56-59)-Coyote', 'helm-M(56-59)-Mox', 'helm-M(56-59)-Multicam'],
   implode(',', $suggest($cut)));
ok('размер в конце длинного имени не отрезается', count(ProductMatcher::search($cut, 20, 16)) < count(ProductMatcher::search($cut, 20)));
ok('«атом mox» — Mox во всех размерах', $suggest('атом mox') === ['helm', 'helm-L(60-62)-Mox', 'helm-M(56-59)-Mox'], implode(',', $suggest('атом mox')));
ok('«плита бр3» — семья целиком, как раньше', count(array_filter($suggest('плита бр3'), fn($id) => str_starts_with($id, 'p3-'))) === 3);
ok('недописанное слово — началом: «бр3 x» — и XL, и XXL', in_array('side3-XXL', $suggest('бр3 x'), true) && in_array('p3-XL', $suggest('бр3 x'), true));
ok('без запроса — прежнее поведение: вся семья', count(Variants::expandSuggest([Db::one("SELECT * FROM products_cache WHERE moysklad_id='p3'")])) === 4);
$api = file_get_contents(ROOT . '/public/api/products.php');
ok('API подсказки спрашивает до 16 слов и отдаёт запрос фильтру', str_contains($api, 'ProductMatcher::search($q, $limit, 16)')
   && str_contains($api, 'Variants::expandSuggest($items, max(12, $limit * 5), $q)'));

// ===================================================================== 6
echo "\n6. #139 вес, коробка, город\n";
foreach ([['Вес: 2,5 кг', 2.5], ['Масса плиты — 2,3–2,5 кг', 2.5], ['весом 800 г', 0.8], ['Вес (кг): 3', 3.0],
          ['Вес брутто 1 200 г', 1.2], ['навесной модуль 2 кг', null], ['габариты 30×20 см', null]] as [$t, $want]) {
    ok("вес из текста: «{$t}»", Cdek::weightFromText($t) === $want, var_export(Cdek::weightFromText($t), true));
}
foreach ([['355000, г. Ставрополь, ул. Ленина, д. 5', 'Ставрополь'], ['Россия, Москва, ул. Тверская, 1', 'Москва'],
          ['Московская обл., г.Химки', 'Химки'], ['Нижний Новгород', 'Нижний Новгород'], ['', '']] as [$a, $want]) {
    ok("город из адреса: «{$a}»", Cdek::cityFromAddress($a) === $want, Cdek::cityFromAddress($a));
}
$boxes = Cdek::boxes((string)Settings::SPEC['CDEK_BOXES'][4]);
ok('коробки СДЭК из настроек, по возрастанию предельного веса', count($boxes) === 8 && $boxes[0]['name'] === 'XS'
   && $boxes[0]['max'] === 0.5 && end($boxes)['max'] === 30.0, $j(array_column($boxes, 'name')));
ok('15 кг — одна коробка XL (до 18)', (Cdek::pickBox(15.4, $boxes)['box']['name'] ?? '') === 'XL' && Cdek::pickBox(15.4, $boxes)['count'] === 1);
ok('70 кг — три коробки по 30', (Cdek::pickBox(70, $boxes)['box']['name'] ?? '') === 'Коробка 30 кг' && Cdek::pickBox(70, $boxes)['count'] === 3);
ok('оплачиваемый вес — больший из веса и объёмного', Cdek::billable(['kg' => 5, 'l' => 60, 'w' => 35, 'h' => 30]) === 12.6
   && Cdek::billable(['kg' => 15.4, 'l' => 60, 'w' => 35, 'h' => 30]) === 15.4);
$est = Cdek::estimate([['kg' => 15.4, 'l' => 60, 'w' => 35, 'h' => 30]]);
ok('оценка: база + ставка × вес', $est['sum'] === 1324.0 && $est['billable_kg'] === 15.4, $j($est));

ok('вес МойСклад берётся первым', Cdek::productWeight('side3') === [0.9, 'МойСклад']);
ok('у модификации — вес её товара из МойСклад', Cdek::productWeight('side3-XL') === [0.9, 'МойСклад']);
ok('без веса МойСклад — вес из описания, верх диапазона', Cdek::productWeight('p3-L') === [2.5, 'описание']);
ok('нет нигде — честное «не знаю»', Cdek::productWeight('') === [null, '']);

$cp = Db::insert('counterparties', ['name' => 'Снаряга-Юг', 'legal_address' => '355000, г. Ставрополь, ул. Ленина, д. 5']);
$req = Db::insert('requests', ['source' => 'email', 'raw_text' => 'шлем и плиты', 'counterparty_id' => $cp, 'status' => 'new']);
Db::insert('request_items', ['request_id' => $req, 'position' => 1, 'raw_name' => 'шлем', 'quantity' => 2,
    'moysklad_product_id' => 'helm-M(56-59)-Mox', 'product_name' => 'Баллистический шлем Атом Арамид (Размер шлема: M(56-59); Цвет: Mox)']);
Db::insert('request_items', ['request_id' => $req, 'position' => 2, 'raw_name' => 'плита бр3', 'quantity' => 5,
    'moysklad_product_id' => 'p3-L', 'product_name' => 'Плита для бронежилета Бр3 (Размер: L)']);
Db::insert('request_items', ['request_id' => $req, 'position' => 3, 'raw_name' => 'кофе', 'quantity' => 9, 'is_out_of_scope' => 1]);
$pre = Cdek::prefill($req);
ok('в посылку идут позиции, «не наша номенклатура» — нет', count($pre['items']) === 2);
ok('вес = вес единицы × количество', abs($pre['weight'] - 15.4) < 0.001, (string)$pre['weight']);
ok('город получателя — из юридического адреса', $pre['to'] === 'Ставрополь');
ok('коробка подобрана под вес', $pre['box'] === 'XL' && $pre['count'] === 1);
ok('без ключей — режим оценки', $pre['api'] === false);

// ===================================================================== 7
echo "\n7. #139 API СДЭК (заглушка вместо сети)\n";
Settings::set('CDEK_CLIENT_ID', 'acc-1');
Settings::set('CDEK_CLIENT_SECRET', 'sec-1');
Settings::set('CDEK_API_URL', 'https://api.test/v2');
$calls = [];
$tokens = 0;
$expired = false;
Cdek::$transport = function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$tokens, &$expired) {
    $path = (string)parse_url($url, PHP_URL_PATH);
    $calls[] = [$method, $path, $body, $headers, (string)parse_url($url, PHP_URL_QUERY)];
    if ($path === '/v2/oauth/token') { $tokens++; return [200, json_encode(['access_token' => 'tok-' . $tokens, 'expires_in' => 3599])]; }
    if ($expired) { $expired = false; return [401, '{"errors":[{"message":"token expired"}]}']; }
    if ($path === '/v2/location/suggest/cities') {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $all = ['Москва' => 44, 'Ставрополь' => 270];
        $out = [];
        foreach ($all as $n => $c) if (mb_stripos($n, $q['name']) === 0) $out[] = ['code' => $c, 'full_name' => "$n, Россия"];
        return [200, json_encode($out, JSON_UNESCAPED_UNICODE)];
    }
    if ($path === '/v2/calculator/tarifflist') {
        $in = json_decode($body, true);
        if (($in['to_location']['code'] ?? 0) === 999) return [400, '{"requests":[{"errors":[{"code":"v2_invalid","message":"Город не обслуживается"}]}]}'];
        return [200, json_encode(['tariff_codes' => [
            ['tariff_code' => 137, 'tariff_name' => 'Посылка склад-дверь', 'delivery_mode' => 3, 'delivery_sum' => 1370, 'period_min' => 3, 'period_max' => 5],
            ['tariff_code' => 136, 'tariff_name' => 'Посылка склад-склад', 'delivery_mode' => 4, 'delivery_sum' => 1016, 'period_min' => 3, 'period_max' => 4],
        ]], JSON_UNESCAPED_UNICODE)];
    }
    return [404, '{"errors":[{"message":"no route"}]}'];
};
ok('ключи есть — есть и API', Cdek::hasApi());
$cities = Cdek::cities('Став');
ok('подсказка городов СДЭК', $cities === [['code' => 270, 'name' => 'Ставрополь, Россия']], $j($cities));
ok('токен выдан по client_credentials формой', $calls[0][1] === '/v2/oauth/token'
   && str_contains((string)$calls[0][2], 'grant_type=client_credentials') && str_contains((string)$calls[0][2], 'client_id=acc-1')
   && in_array('Content-Type: application/x-www-form-urlencoded', $calls[0][3], true));
ok('запрос несёт токен', in_array('Authorization: Bearer tok-1', $calls[1][3], true));
$tariffs = Cdek::tariffs('Москва', 'Ставрополь', Cdek::packages([['kg' => 15.4, 'l' => 60, 'w' => 35, 'h' => 30]]), 'delivery');
$calc = array_values(array_filter($calls, fn($c) => $c[1] === '/v2/calculator/tarifflist'))[0] ?? null;
$sent = json_decode((string)($calc[2] ?? ''), true);
ok('тело tarifflist: тип договора, коды городов, граммы и сантиметры', ($sent['type'] ?? 0) === 2
   && ($sent['from_location']['code'] ?? 0) === 44 && ($sent['to_location']['code'] ?? 0) === 270
   && ($sent['packages'][0] ?? []) === ['weight' => 15400, 'length' => 60, 'width' => 35, 'height' => 30], $j($sent));
ok('тарифы — от дешёвого к дорогому, со сроками', array_column($tariffs, 'sum') === [1016.0, 1370.0]
   && $tariffs[0]['days_min'] === 3 && $tariffs[0]['days_max'] === 4 && $tariffs[0]['name'] === 'Посылка склад-склад');
ok('договор «интернет-магазин» — type 1', Cdek::CONTRACTS['im'] === 1);
ok('токен один на час: второй расчёт не просит новый', $tokens === 1);
Cdek::$transport && (function () { $r = new ReflectionProperty(Cdek::class, 'token'); $r->setValue(null, null); })();
Cdek::tariffs('44', '270', Cdek::packages([['kg' => 1]]), 'delivery');
ok('и после перезапуска — токен из базы, пока жив', $tokens === 1);
$expired = true;
Cdek::tariffs('44', '270', Cdek::packages([['kg' => 1]]), 'delivery');
ok('отозванный токен — один повтор с новым', $tokens === 2);
$err = '';
try { Cdek::tariffs('44', '999', Cdek::packages([['kg' => 1]]), 'delivery'); } catch (Throwable $e) { $err = $e->getMessage(); }
ok('ошибка СДЭК — его же словами', str_contains($err, 'Город не обслуживается'), $err);
$err = '';
try { Cdek::tariffs('44', '270', Cdek::packages([['kg' => 1]]), 'none'); } catch (Throwable $e) { $err = $e->getMessage(); }
ok('без договора тарифы не спрашиваются', str_contains($err, 'тип договора'), $err);
ok('пустые посылки отброшены', Cdek::packages([['kg' => 0], ['kg' => 'x'], ['kg' => 2, 'l' => 10]]) === [['kg' => 2.0, 'l' => 10, 'w' => 0, 'h' => 0]]);
Cdek::$transport = null;

// ===================================================================== 8
echo "\n8. Вес в каталоге, настройки, интерфейс\n";
ok('колонка веса в каталоге (схема v57)', in_array('weight', array_column(Db::all("PRAGMA table_info(products_cache)"), 'name'), true)
   && (int)Db::val("SELECT value FROM settings WHERE key='schema_version'") >= 57);
$map = new ReflectionMethod(MoySklad::class, 'mapProduct');
$map->setAccessible(true);
ok('МойСклад: «Вес» карточки едет в каталог', ($map->invoke(null, ['id' => 'x', 'name' => 'Плита', 'weight' => 2.4])['weight'] ?? null) === 2.4);
ok('Excel: колонка «Вес» читается', (new ReflectionClassConstant(CatalogImport::class, 'COLUMNS'))->getValue()['Вес'] === 'weight');
ok('группа настроек «Доставка СДЭК»', (Settings::GROUPS['cdek'] ?? '') === 'Доставка СДЭК'
   && Settings::SPEC['CDEK_CLIENT_SECRET'][3] === true && Settings::SPEC['CDEK_CLIENT_ID'][3] === false);
ok('договор — выбор с «не указан — спрашивать»', str_starts_with(Settings::SPEC['CDEK_CONTRACT'][2], 'select:=не указан'));
ok('кнопка расчёта — справа от ручной цены доставки', (bool)preg_match('/data-delivery-price[^>]*>\s*<!--[^>]*-->\s*<button type="button" class="btn btn--outline btn--sm" data-dcalc-btn/s', $js));
ok('панель раскрывается и наполняется с сервера', str_contains($js, 'deliveryCalcToggle(btn) {') && str_contains($js, "delivery.php?action=prefill&request_id="));
ok('«Взять» — как ручная правка: событие input, итог и сохранение', str_contains($js, "price.dispatchEvent(new Event('input', {bubbles: true}));"));
ok('без API — оценка и ссылка на калькулятор СДЭК', str_contains($js, 'Это ставка из настроек, а не тариф СДЭК')
   && str_contains($js, 'https://www.cdek.ru/ru/calculate/'));
$dapi = file_get_contents(ROOT . '/public/api/delivery.php');
ok('API расчёта: prefill, cities, quote — за входом', substr_count($dapi, 'requireAuth();') === 3
   && str_contains($dapi, "case 'quote':") && str_contains($dapi, "'mode' => 'estimate'"));

echo "\n" . ($fail ? "$fail FAILED\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
