<?php
/**
 * Модуль 060: issues #92–#95.
 *
 *   — #92 отмеченные фото идут в КП все, без выбора — первые по настройке;
 *   — #94 КП уходит только из письма: подтверждение при вложении;
 *   — #93 последнее письмо компании удалено — карточка уходит с доски;
 *   — #95 🎤 в форме поддержки.
 *
 * Запуск:  php tests/module_060.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-060-' . getmypid() . '.db';
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
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/kp_confirm.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js   = file_get_contents(ROOT . '/public/assets/js/app.js');
$prop = file_get_contents(ROOT . '/public/api/proposals.php');
$mail = file_get_contents(ROOT . '/public/api/mail.php');

// ===================================================================== 1
echo "1. #92 фото: выбор не режется, по умолчанию — лимит\n";
$dir = ROOT . '/storage/product_images';
@mkdir($dir, 0755, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$files = [];
for ($i = 0; $i < 4; $i++) { $files[] = "$dir/t060-$i.png"; file_put_contents("$dir/t060-$i.png", $png); }
Db::insert('products_cache', ['moysklad_id' => 'ms-060', 'name' => 'Шлем', 'price' => 100,
                              'images_json' => json_encode($files)]);
$item = ['moysklad_product_id' => 'ms-060', 'selected_images' => null, 'images_json' => json_encode($files)];
ok('без выбора — первые по лимиту', count(KpContent::itemGallery($item, 2)) === 2,
   (string)count(KpContent::itemGallery($item, 2)));
$item['selected_images'] = json_encode(['local:0', 'local:1', 'local:2', 'local:3']);
ok('отмечено 4 при лимите 2 — в КП все 4', count(KpContent::itemGallery($item, 2)) === 4,
   (string)count(KpContent::itemGallery($item, 2)));
$item['selected_images'] = '[]';
ok('пустой выбор — без фото', KpContent::itemGallery($item, 2) === []);

Db::q("DELETE FROM settings WHERE key='kp_max_images_per_item'");
ok('лимит по умолчанию — 5', KpContent::photoLimit() === 5);
Db::q("INSERT INTO settings(key, value) VALUES('kp_max_images_per_item', '3')");
ok('лимит из настройки', KpContent::photoLimit() === 3);
$reqId = Db::insert('requests', ['source' => 'manual', 'raw_text' => 'шлем', 'email_from' => 'a@b.ru']);
$pid = Db::insert('proposals', ['request_id' => $reqId, 'number' => 'KP-060']);
ok('КП без своего числа — настройка', KpContent::photoLimit($pid) === 3);
Db::update('proposals', ['photos_per_item' => 1], 'id=?', [$pid]);
ok('своё число КП побеждает', KpContent::photoLimit($pid) === 1);
ok('KP_CARD_PHOTOS убрана', !isset(Settings::SPEC['KP_CARD_PHOTOS']));
ok('API отдаёт default_count', substr_count($prop . file_get_contents(ROOT . '/public/api/requests.php'), "'default_count'") === 2);
ok('пикеры отмечают первые default_count', substr_count($js, 'const chosen = this.photoChosen(d);') === 2
   && str_contains($js, 'd.available.slice(0, Math.max(0, n))'));

// ===================================================================== 2
echo "2. #94 КП уходит только из письма\n";
ok('кнопки «Подтвердить и отправить» нет', !str_contains($js, 'Подтвердить и отправить'));
ok('JS отправки мимо письма нет', !str_contains($js, 'confirmAndSend') && !str_contains($js, 'sendProposal')
   && !str_contains($js, 'kpAttachFiles'));
ok('API confirm/send убраны', !str_contains($prop, "case 'confirm':") && !str_contains($prop, "case 'send':"));
ok('вложение КП спрашивает про цену', str_contains($mail, 'KpConfirm::priceGate(') && str_contains($mail, 'KpConfirm::prepare('));
ok('attachDoc повторяет с no_price_ack', str_contains($js, "this.postWithNoPriceAck('mail.php?action=attach_doc'"));

$mgr = Db::insert('managers', ['name' => 'Тест', 'login' => 'm060', 'email' => 'm060@x.ru', 'password_hash' => 'x']);
$item1 = Db::insert('proposal_items', ['proposal_id' => $pid, 'position' => 1, 'product_name' => 'Шлем', 'price' => 0]);
$gap = KpConfirm::priceGate($pid, [], $mgr);
ok('позиция без цены — вопрос', is_array($gap) && count($gap['items']) === 1, json_encode($gap, JSON_UNESCAPED_UNICODE));
ok('ответ «да» — вопрос снят', KpConfirm::priceGate($pid, ['no_price_ack' => true], $mgr) === null);
ok('и не повторяется', KpConfirm::priceGate($pid, [], $mgr) === null);
Db::insert('proposal_items', ['proposal_id' => $pid, 'position' => 2, 'product_name' => 'Жилет', 'price' => 0]);
ok('новая позиция без цены — вопрос снова', is_array(KpConfirm::priceGate($pid, [], $mgr)));

Db::update('proposals', ['cover_letter' => 'Авто', 'cover_letter_final' => 'Правка менеджера'], 'id=?', [$pid]);
KpConfirm::prepare($pid, $mgr);
KpConfirm::prepare($pid, $mgr);
ok('статус draft → confirmed', Db::val("SELECT status FROM proposals WHERE id=?", [$pid]) === 'confirmed');
ok('урок из правки письма — один раз',
   (int)Db::val("SELECT COUNT(*) FROM corrections WHERE field='cover_letter' AND request_id=?", [$reqId]) === 1);
Db::update('proposals', ['status' => 'sent'], 'id=?', [$pid]);
KpConfirm::prepare($pid, $mgr);
ok('отправленное КП не откатывается в confirmed', Db::val("SELECT status FROM proposals WHERE id=?", [$pid]) === 'sent');

// ===================================================================== 3
echo "3. #93 последнее письмо компании — карточка уходит\n";
$cp = Db::insert('counterparties', ['name' => 'ООО «Завод»']);
$board = Boards::singleton();
$col = Boards::inboxColumn((int)$board['id']);
$card = Boards::addCard((int)$col['id'], ['counterparty_id' => $cp, 'title' => 'ООО «Завод»']);
$letter = fn(string $key) => Db::insert('mail_messages', [
    'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0, 'thread_key' => $key, 'counterparty_id' => $cp,
    'subject' => 'Запрос', 'from_email' => 'client@zavod.ru', 'to_emails' => 'info@atlant-armour.ru',
    'body_text' => 'текст', 'date_at' => '2026-09-24 10:00:00']);
$a = $letter('s:a'); $b = $letter('s:b');
$res = MailSync::deleteMessage($a, $mgr);
ok('письмо осталось — карточка на месте', empty($res['card_removed'])
   && (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE id=?", [$card]) === 1);
$res = MailSync::deleteMessage($b, $mgr);
ok('последнее удалено — карточки нет', !empty($res['card_removed'])
   && (int)Db::val("SELECT COUNT(*) FROM board_cards WHERE id=?", [$card]) === 0, json_encode($res));
ok('экран уходит на доску', str_contains($js, "this.goAfterDelete('mail/board');"));

// ===================================================================== 4
echo "4. #95 голос в поддержке\n";
ok('🎤 у «Коротко»', str_contains($js, "App.dictate(this, document.getElementById('supTitle'))"));
ok('🎤 у «Что случилось»', str_contains($js, "App.dictate(this, document.getElementById('supBody'))"));
ok('закрытие окна останавливает запись', str_contains($js, "if (el && rec && rec.state === 'recording') rec.stop();"));
ok('в пропавшее поле не распознаём', str_contains($js, '|| !target.isConnected)'));

foreach ($files as $f) @unlink($f);
echo $fail ? "\nПРОВАЛОВ: $fail\n" : "\nВСЁ ЗЕЛЁНОЕ\n";
exit($fail ? 1 : 0);
