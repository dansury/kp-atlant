<?php
/**
 * Модуль 059: голосовой ввод письма (решение из kraskiweb) и issues #86–#90.
 *
 *   — WebM(Opus) перекладывается в Ogg(Opus), SpeechKit получает Ogg;
 *   — «под заказ», снятое руками, не возвращают ни подбор, ни общие условия, ни пересборка;
 *   — общий товар с остатком на модификациях — не «под заказ»;
 *   — трек СДЭК ищется во всех колонках, кроме «Закрыто», карточка фиолетовая;
 *   — интерфейс: 🎤 в полях письма, «Написать» на всю страницу, скриншот Ctrl+V, зачёркивание в КП.
 *
 * Запуск:  php tests/module_059.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-059-' . getmypid() . '.db';
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
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/kp_set.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/payments.php';
require_once ROOT . '/lib/speech.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');

// ===================================================================== 1
echo "1. WebM(Opus) → Ogg(Opus)\n";
// Минимальный WebM: EBML-заголовок, Segment → Tracks(Opus) + Cluster с двумя кадрами
$el = function (string $id, string $data): string {
    return $id . "\x01" . substr(pack('J', strlen($data)), 1) . $data;   // 8-байтовый размер
};
$opusHead = 'OpusHead' . "\x01\x01" . pack('v', 312) . pack('V', 48000) . "\x00\x00" . "\x00";
$track = $el("\xAE", $el("\xD7", "\x01") . $el("\x86", 'A_OPUS') . $el("\x63\xA2", $opusHead));
$frame = "\xF8" . str_repeat("\x55", 40);                                    // TOC: 20 мс, моно
$block = fn(int $tc) => $el("\xA3", "\x81" . pack('n', $tc) . "\x80" . $frame);
$webm = $el("\x1A\x45\xDF\xA3", $el("\x42\x82", 'webm'))
      . $el("\x18\x53\x80\x67", $el("\x16\x54\xAE\x6B", $track)
          . $el("\x1F\x43\xB6\x75", $el("\xE7", "\x00") . $block(0) . $block(20)));
$ogg = AudioRemux::webmOpusToOggOpus($webm);
ok('WebM переложился в Ogg', is_string($ogg) && str_starts_with($ogg, 'OggS'));
ok('в Ogg — OpusHead, OpusTags и кадры', $ogg && str_contains($ogg, 'OpusHead') && str_contains($ogg, 'OpusTags')
   && substr_count($ogg, $frame) === 2);
ok('не WebM — null', AudioRemux::webmOpusToOggOpus('RIFF....WAVE') === null);

// ===================================================================== 2
echo "\n2. Speech: SpeechKit получает Ogg, ошибки говорят словами\n";
$tmp = sys_get_temp_dir() . '/kp-059-voice-' . getmypid() . '.webm';
file_put_contents($tmp, $webm);
register_shutdown_function(fn() => @unlink($tmp));

$e = '';
try { Speech::transcribe($tmp, 'audio/webm'); } catch (RuntimeException $x) { $e = $x->getMessage(); }
ok('без ключа Yandex — объяснение, а не пустое поле', str_contains($e, 'SpeechKit') && str_contains($e, 'Folder ID'), $e);

Settings::set('YANDEX_API_KEY', 'AQVN-test-key');
Settings::set('YANDEX_FOLDER_ID', 'b1gtest');
$calls = [];
Speech::$http = function ($bytes, $fmt) use (&$calls) {
    $calls[] = [$fmt, substr($bytes, 0, 4)];
    return ['code' => 200, 'body' => json_encode(['result' => 'Добрый день, высылаем КП'], JSON_UNESCAPED_UNICODE)];
};
ok('распознанный текст', Speech::transcribe($tmp, 'audio/webm;codecs=opus') === 'Добрый день, высылаем КП');
ok('SpeechKit получил Ogg, не WebM', $calls === [['oggopus', 'OggS']], json_encode($calls));

$calls = [];
Speech::$http = function ($bytes, $fmt) use (&$calls) {
    $calls[] = $fmt;
    return $fmt === 'oggopus' ? ['code' => 400, 'body' => '{"error_message":"bad audio"}'] : ['code' => 200, 'body' => '{"result":"ок"}'];
};
ok('Ogg не принят — второй заход с исходными байтами', Speech::transcribe($tmp, 'audio/mpeg') === 'ок' && $calls === ['mp3']);

Speech::$http = fn() => ['code' => 401, 'body' => '{"error_message":"Unknown api key"}'];
$e = '';
try { Speech::transcribe($tmp, 'audio/mpeg'); } catch (RuntimeException $x) { $e = $x->getMessage(); }
ok('HTTP-ошибка — код и ответ API', str_contains($e, '401') && str_contains($e, 'Unknown api key'), $e);
ok('ошибка в журнале', (int)Db::val("SELECT COUNT(*) FROM app_log WHERE channel='speech' AND level='error'") > 0);

Speech::$http = fn() => ['code' => 200, 'body' => '{"result":""}'];
$e = '';
try { Speech::transcribe($tmp, 'audio/mpeg'); } catch (RuntimeException $x) { $e = $x->getMessage(); }
ok('тишина — «речь не распознана»', str_contains($e, 'не распознана'), $e);
ok('формат по MIME', Speech::format('audio/mpeg') === 'mp3' && Speech::format('audio/ogg;codecs=opus') === 'oggopus');

// ===================================================================== 3
echo "\n3. «Под заказ» (issue #86)\n";
Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved, unit, product_type, source, updated_at)
       VALUES ('p-vest', 'Жилет разгрузочный', 'жилет разгрузочный', 'VEST', 5000, 0, 0, 'шт.', 'product', 'api', datetime('now'))");
foreach ([['vv-s', 3], ['vv-m', 0]] as [$id, $stock]) {
    Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved, unit, product_type, parent_id, source, updated_at)
           VALUES (?, ?, ?, 'VEST', 5000, ?, 0, 'шт.', 'variant', 'p-vest', 'api', datetime('now'))", [$id, "Жилет $id", "жилет $id", $stock]);
}
Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved, unit, product_type, source, updated_at)
       VALUES ('p-none', 'Шлем', 'шлем', 'HLM', 9000, 0, 0, 'шт.', 'product', 'api', datetime('now'))");
$cpId = Db::insert('counterparties', ['name' => 'ООО «Щит»', 'contact_email' => 'buyer@shield.test']);
$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 'жилет 1 шт, шлем 1 шт', 'counterparty_id' => $cpId,
                                 'status' => 'new', 'parsed_json' => json_encode(['items' => []])]);
$rows = RequestItems::save($reqId, [
    ['raw_name' => 'жилет', 'quantity' => 1, 'moysklad_product_id' => 'p-vest', 'product_name' => 'Жилет разгрузочный', 'price' => 5000, 'stock' => 0],
    ['raw_name' => 'шлем', 'quantity' => 1, 'moysklad_product_id' => 'p-none', 'product_name' => 'Шлем', 'price' => 9000, 'stock' => 0,
     'wait_on' => 0, 'wait_manual' => 1],
]);
ok('общий товар: остаток — по модификациям', (int)$rows[0]['stock'] === 3 && (int)$rows[0]['is_backorder'] === 0, json_encode([$rows[0]['stock'], $rows[0]['is_backorder']]));
ok('остаток сохранён сервером, не браузером', (int)Db::val("SELECT stock FROM request_items WHERE id=?", [$rows[0]['id']]) === 3);
ok('галочку сняли руками — запомнено', (int)$rows[1]['wait_manual'] === 1 && (int)$rows[1]['wait_on'] === 0);

$rows = RequestItems::applyConditions($reqId, ['wait_on' => 1]);
ok('общие условия «под заказ» не трогают ручную строку', (int)$rows[1]['wait_on'] === 0);

$rows = RequestItems::choose($reqId, (int)$rows[0]['id'], 'p-vest');
ok('выбор общего товара — остаток модификаций', (int)$rows[0]['stock'] === 3);

Settings::set('KP_WAIT_AUTO', 1);
ok('KP_WAIT_AUTO не поднимает ручную галочку', !isset(Terms::prepare(['moysklad_product_id' => 'p-none', 'stock_available' => 0,
    'stock_reserved' => 0, 'wait_on' => 0, 'wait_manual' => 1, 'wait_months' => 3, 'wait_discount' => 10, 'wait_prepay' => 100])['wait_on']));
ok('…а не тронутую — поднимает', (Terms::prepare(['moysklad_product_id' => 'p-none', 'stock_available' => 0,
    'stock_reserved' => 0, 'wait_on' => 0, 'wait_months' => 3, 'wait_discount' => 10, 'wait_prepay' => 100])['wait_on'] ?? 0) === 1);

$pid = KpSet::create($reqId, null);
KpSet::rebuildItems($pid);
$pi = Db::one("SELECT * FROM proposal_items WHERE proposal_id=? AND moysklad_product_id='p-none'", [$pid]);
ok('«Пересобрать КП» — снятая галочка не вернулась', $pi && (int)$pi['wait_on'] === 0 && (int)$pi['wait_manual'] === 1,
   json_encode([$pi['wait_on'] ?? null, $pi['wait_manual'] ?? null]));
$vest = Db::one("SELECT * FROM proposal_items WHERE proposal_id=? AND moysklad_product_id='p-vest'", [$pid]);
ok('общий товар в КП — не «под заказ»', $vest && Terms::note($vest) === '' && (string)($vest['notes'] ?? '') !== 'под заказ',
   json_encode([$vest['notes'] ?? null, $vest['stock_available'] ?? null]));
ok('интерфейс ставит галочку сам только без ручного решения', str_contains($js, "const manual = Number(i.wait_manual) === 1;")
   && str_contains($js, 'data-field="wait_manual"'));
ok('правка «под заказ» в КП доезжает до подбора',
   str_contains(file_get_contents(ROOT . '/public/api/proposals.php'), "'wait_manual' => 1],"));

// ===================================================================== 4
echo "\n4. Трек СДЭК на любой карточке, кроме «Закрыто» (issue #88)\n";
$managerId = Db::insert('managers', ['login' => 'yana', 'password_hash' => 'x', 'name' => 'Яна', 'is_admin' => 1]);
$board = Boards::singleton();
$work = Boards::workColumn((int)$board['id']);
$cardId = Boards::addCard((int)$work['id'], ['counterparty_id' => $cpId]);
Db::insert('orders', ['moysklad_id' => 'ms-o1', 'name' => '2010322940883-1', 'sum' => 5000, 'counterparty_id' => $cpId, 'manager_id' => $managerId]);
Fulfillment::$fetchDemands = fn($id) => [];
Fulfillment::$fetchOrder = fn($id) => ['attributes' => ['СЛУЖБА ДОСТАВКИ' => 'СДЭК', 'ТРЕК-НОМЕР' => '2010322940883']];
$r = Fulfillment::checkShipments();
ok('заказ карточки «В работе» проверен', $r['checked'] === 1 && $r['shipped'] === 1, json_encode($r));
$draft = Db::one("SELECT * FROM mail_drafts WHERE kind='shipment'");
ok('в черновике — ссылка СДЭК с трек-номером',
   $draft && str_contains((string)$draft['body'], 'https://www.cdek.ru/ru/tracking/?order_id=2010322940883'));
$card = null;
foreach (Boards::get((int)$board['id'])['columns'] as $c) foreach ($c['cards'] as $cc) if ($cc['counterparty_id'] == $cpId) $card = $cc;
ok('карточка помечена как СДЭК', $card && $card['attention'] && $card['cdek']);
ok('фиолетовая подсветка строки и карточки', str_contains($js, "cls.push('grow--cdek')") && str_contains($js, "cls.push('bcard--cdek')")
   && str_contains($css, '.grow--cdek') && str_contains($css, '.bcard--cdek'));

$cp2 = Db::insert('counterparties', ['name' => 'ООО «Закрыто»']);
$closed = Db::one("SELECT id FROM board_columns WHERE board_id=? AND kind='closed'", [(int)$board['id']]);
Boards::addCard((int)$closed['id'], ['counterparty_id' => $cp2]);
Db::insert('orders', ['moysklad_id' => 'ms-o2', 'name' => '77', 'sum' => 1, 'counterparty_id' => $cp2]);
$asked = [];
Fulfillment::$fetchOrder = function ($id) use (&$asked) { $asked[] = $id; return ['attributes' => []]; };
$r = Fulfillment::checkShipments();
ok('«Закрыто» не проверяется', !in_array('ms-o2', $asked, true), json_encode($asked));

// ===================================================================== 5
echo "\n5. Интерфейс (по исходнику)\n";
// + два поля поддержки (модуль 060)
ok('🎤 в поле ответа и в окне ответа', substr_count($js, "onclick=\"App.dictate(this,") === 4);
ok('запись → mail.php?action=transcribe → текст на место курсора',
   str_contains($js, "/api/mail.php?action=transcribe") && str_contains($js, 'this.insertDictation(target, d.text'));
ok('эндпоинт распознавания', str_contains(file_get_contents(ROOT . '/public/api/mail.php'), "case 'transcribe':"));
ok('«Написать» — страница с редактором ответа', str_contains($js, "if (seg === 'compose') return this.pageMailCompose();")
   && str_contains($js, "location.hash = '#mail/compose'"));
ok('скриншот вставляется в любом месте окна поддержки', str_contains($js, "box.addEventListener('paste', e => this.supportPaste(e))")
   && str_contains($js, "drop.addEventListener('drop'"));
ok('обращение администратора сразу в GitHub', str_contains(file_get_contents(ROOT . '/public/api/support.php'),
   '$gh = Support::approve((int)$res[\'id\'], (int)$manager[\'id\']);'));
ok('КП: зачёркивание классом .was снимается', str_contains($js, 'this.kpUnstrike(doc)') && str_contains($js, "e.classList.remove('was')"));

echo $fail ? "\n$fail FAILED\n" : "\nall ok\n";
exit($fail ? 1 : 0);
