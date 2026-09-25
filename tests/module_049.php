<?php
/**
 * Модуль 049 — на выбрасываемой базе и без сети:
 *
 *   — режим доставки у каждого КП: в стоимость, строкой, оплачивается отдельно;
 *   — «оплачивается отдельно»: нет в таблице и итоге, фраза в условиях, цена в письме;
 *   — подбор → КП: режим едет в неотправленное КП без «Пересобрать»;
 *   — интерфейс (по исходнику): нет поля письма в настройках КП, нет «усл. 1»,
 *     КП над письмом, вкладки справа с точкой заметок.
 *
 * Запуск:  php tests/module_049.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-049-' . getmypid() . '.db';
$configPath = dirname(__DIR__) . '/config.php';
$hadConfig = file_exists($configPath);

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
require_once ROOT . '/lib/kp_terms.php';
require_once ROOT . '/lib/kp_text.php';
require_once ROOT . '/lib/kp_set.php';
require_once ROOT . '/lib/delivery_share.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');
$api = file_get_contents(ROOT . '/public/api/proposals.php');

Settings::set('KP_QR_CODE', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('KP_DELIVERY_MODE', 'included');

// ===================================================================== 1
echo "\n1. Режим доставки: своё значение или настройка\n";
ok('нет своего — из настройки', DeliveryShare::mode([]) === 'included');
ok('своё побеждает настройку', DeliveryShare::mode(['delivery_mode' => 'separate']) === 'separate');
ok('чужое слово — настройка', DeliveryShare::mode(['delivery_mode' => 'xxx']) === 'included');
Settings::set('KP_DELIVERY_MODE', 'separate');
ok('настройка принимает separate', DeliveryShare::mode([]) === 'separate');
Settings::set('KP_DELIVERY_MODE', 'included');

$sep = ['delivery_on' => 1, 'delivery_price' => 1500, 'delivery_mode' => 'separate'];
ok('фраза письма с ценой', DeliveryShare::letterLine($sep) === 'Доставка оплачивается отдельно, её стоимость — 1 500 руб.',
   DeliveryShare::letterLine($sep));
ok('без цены — фразы нет', DeliveryShare::letterLine(['delivery_price' => 0] + $sep) === '');
ok('в стоимости — фразы нет', DeliveryShare::letterLine(['delivery_mode' => 'included'] + $sep) === '');
$letter = DeliveryShare::appendToLetter("Добрый день!\nКП во вложении.", $sep);
ok('дописана в конец письма', str_ends_with($letter, "\n\nДоставка оплачивается отдельно, её стоимость — 1 500 руб."), $letter);
ok('второй раз не дописывается', DeliveryShare::appendToLetter($letter, $sep) === $letter);

// ===================================================================== 2
echo "\n2. Условия КП по режиму\n";
$raw = "хранение, {delivery_in_price}подготовку.\n{delivery_separate_clause}Сроки";
ok('отдельно: своя фраза', str_contains(KpTerms::fill($raw, ['delivery_mode' => 'separate']),
   "Доставка в стоимость не включена и оплачивается отдельно.\nСроки"));
ok('строкой: СДЭК', str_contains(KpTerms::fill($raw, ['delivery_mode' => 'line']), 'по тарифам СДЭК'));
ok('в стоимости: «доставку, »', str_contains(KpTerms::fill($raw, ['delivery_mode' => 'included']), 'хранение, доставку, подготовку'));

// ===================================================================== 3
echo "\n3. Счёт и текст КП\n";
$rows = [['moysklad_product_id' => 'p1', 'price' => 1000, 'quantity' => 2, 'product_name' => 'Шлем']];
$inv = DeliveryShare::invoicePositions($sep + ['vat_rate' => 5], $rows);
ok('отдельно: в счёт не идёт и не «потеряна»', count($inv['positions']) === 1 && !$inv['delivery_missing']);
ok('отдельно: цена позиции без доли доставки', (float)$inv['positions'][0]['price'] === 1000.0);
$inv = DeliveryShare::invoicePositions(['delivery_mode' => 'line'] + $sep + ['vat_rate' => 5], $rows);
ok('строкой без услуги в настройках — «потеряна»', $inv['delivery_missing']);

$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 't', 'status' => 'processing']);
$pid = Db::insert('proposals', ['request_id' => $reqId, 'status' => 'draft', 'vat_rate' => 5,
    'delivery_on' => 1, 'delivery_name' => 'Доставка', 'delivery_price' => 1500, 'delivery_mode' => 'separate']);
Db::insert('proposal_items', ['proposal_id' => $pid, 'position' => 1, 'product_name' => 'Шлем',
    'quantity' => 2, 'unit' => 'шт.', 'price' => 1000]);
$text = KpText::render($pid)['text'];
ok('текст КП: доставки строкой нет', !preg_match('/^\d+\. Доставка/mu', $text), $text);
ok('текст КП: итог без доставки', str_contains($text, '2 000 руб.') && !str_contains($text, '3 500'));
Db::update('proposals', ['delivery_mode' => 'line'], 'id=?', [$pid]);
$text = KpText::render($pid)['text'];
ok('строкой: доставка в тексте и в итоге', str_contains($text, '2. Доставка — 1 500 руб.') && str_contains($text, '3 500'), $text);

// ===================================================================== 4
echo "\n4. Подбор → КП\n";
$d = RequestItems::saveDelivery($reqId, ['name' => 'Доставка СДЭК', 'price' => 900, 'mode' => 'separate']);
ok('режим запомнен на запросе', $d['mode'] === 'separate' && $d['price'] == 900.0);
$d = RequestItems::saveDelivery($reqId, ['name' => 'Доставка СДЭК', 'price' => 900]);
ok('без режима — прежний не теряется', $d['mode'] === 'separate');
KpSet::syncWaitFromRequest($reqId);
$p = Db::one("SELECT * FROM proposals WHERE id=?", [$pid]);
ok('неотправленное КП получило доставку подбора',
   $p['delivery_mode'] === 'separate' && (float)$p['delivery_price'] === 900.0 && $p['delivery_name'] === 'Доставка СДЭК');
$sentId = Db::insert('proposals', ['request_id' => $reqId, 'status' => 'sent', 'delivery_on' => 1,
    'delivery_price' => 100, 'delivery_mode' => 'line']);
KpSet::syncWaitFromRequest($reqId);
ok('отправленное КП не тронуто', Db::val("SELECT delivery_mode FROM proposals WHERE id=?", [$sentId]) === 'line');

// ===================================================================== 5
echo "\n5. Интерфейс (по исходнику)\n";
ok('в полном редакторе КП нет поля письма', !str_contains($js, 'id="coverLetter"'));
ok('в «Тексте по полям» нет письма', !str_contains($api, "'Сопроводительное письмо'"));
ok('строка доставки без «усл. 1»', !preg_match('/<span class="muted">усл\.<\/span>/u', $js));
ok('режим доставки выбирается в строке', str_contains($js, 'data-delivery-mode'));
ok('КП встаёт над письмом', str_contains($js, "composer.insertAdjacentElement('beforebegin'"));
// Модуль 062 (issue #119) убрал вкладки справа: лента — между письмами, «Информация» — по названию
ok('пояснение ленты — в подсказках', str_contains($js, "'events':       ['Документы и заметки в ленте'")
   && !str_contains($js, '<div class="note" style="margin-bottom:8px">Заметки для коллег'));
ok('заметки — строками ленты, без вкладки с точкой', str_contains($js, "tl--note") && !str_contains($js, 'data-notes-dot'));
ok('без вкладок справа карточка в одну колонку', str_contains($css, '.letter--company:not(:has(> .letter__side > [data-thread-items]))')
   && !str_contains($css, '#companySide { position: fixed;'));

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
