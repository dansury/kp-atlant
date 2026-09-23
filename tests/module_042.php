<?php
/**
 * Модуль 042 (issue #60) — на выбрасываемой базе и без сети:
 *
 *   — «не наша номенклатура» больше не стирает незасохранённые правки других
 *     строк подбора (та же защита — у смены условий и выбора равнозначного);
 *   — «Сформировать КП» не заводит второй документ на тот же запрос;
 *   — условия «Цены и условия — на все позиции» приоритетно запоминаются за
 *     КОНТРАГЕНТОМ, а не только за менеджером;
 *   — доска «Закрыто» не тянет тело последнего письма каждой карточки, и у
 *     колонки можно задать лимит показанных карточек;
 *   — доставка по умолчанию распределяется по позициям, а не строкой, и текст
 *     условий говорит об этом тем же документом;
 *   — GitHub issue от обращения в поддержку не несёт служебный футер.
 *
 * Запуск:  php tests/module_042.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-042-' . getmypid() . '.db';
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
require_once ROOT . '/lib/terms.php';
require_once ROOT . '/lib/kp_terms.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/support.php';
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/pdf.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');

$mgr = Db::insert('managers', ['login' => 'yana', 'name' => 'Яна',
                               'email' => 'yana@atlant-armour.ru', 'password_hash' => 'x', 'is_admin' => 1]);

// =====================================================================  1

echo "\n== 1. app.js: смена области/условий/выбора не сбрасывает незасохранённые строки ==\n";

$js = (string)file_get_contents(ROOT . '/public/assets/js/app.js');
ok('setItemScope сохраняет строки перед перерисовкой',
   (bool)preg_match('/async setItemScope\(btn, itemId, outOfScope\) \{.*?items_save.*?items_scope/s', $js));
ok('applyConditions делает то же самое',
   (bool)preg_match('/async applyConditions\(btn\) \{.*?items_save.*?items_conditions/s', $js));
ok('chooseMatch (сохранённая строка) — тоже',
   (bool)preg_match('/const requestId = Number\(host && host\.dataset\.requestId\);\s*try \{\s*.*?items_save.*?items_choose/s', $js));
ok('выбор равнозначного варианта сразу подставляет описание',
   str_contains($js, "this.fillCatalogComment(row, btn.dataset.description"));

// =====================================================================  2

echo "\n== 2. «Сформировать КП» не плодит второй документ на тот же запрос ==\n";

$api = (string)file_get_contents(ROOT . '/public/api/proposals.php');
ok('generate проверяет уже существующее КП запроса перед созданием нового',
   (bool)preg_match(
       "/case 'generate':.*?SELECT id FROM proposals WHERE request_id=\\? ORDER BY id DESC LIMIT 1.*?buildProposal\\(/s",
       $api
   ));

// =====================================================================  3

echo "\n== 3. Условия подбора — приоритетно за контрагентом ==\n";

$cpX = Db::insert('counterparties', ['name' => 'ООО «Икс»']);
$cpY = Db::insert('counterparties', ['name' => 'ООО «Игрек»']);

// Менеджер закрыл прошлое КП розницей — это условия ПО УМОЛЧАНИЮ
Terms::remember($mgr, ['price_type' => 'Розница', 'discount' => 0, 'wait_on' => 0,
                        'wait_months' => 3, 'wait_discount' => 10, 'wait_prepay' => 100]);
ok('без контрагента — условия менеджера', Terms::conditions($mgr)['price_type'] === 'Розница');
ok('у контрагента, с которым ещё не работали, — те же условия менеджера',
   Terms::conditions($mgr, $cpX)['price_type'] === 'Розница');

// Для конкретного контрагента X менеджер один раз выставил опт со скидкой —
// это должно запомниться ЗА КОНТРАГЕНТОМ и не сбиться следующим КП кому-то ещё
Terms::remember($mgr, ['price_type' => 'Опт безнал', 'discount' => 7, 'wait_on' => 0,
                        'wait_months' => 3, 'wait_discount' => 10, 'wait_prepay' => 100], $cpX);
$forX = Terms::conditions($mgr, $cpX);
ok('условия контрагента X запомнены', $forX['price_type'] === 'Опт безнал' && (float)$forX['discount'] === 7.0,
   json_encode($forX, JSON_UNESCAPED_UNICODE));

// А менеджер, тем временем, посчитал КП кому-то ещё розницей — своё,
// «просто последнее», условие у него сменилось
Terms::remember($mgr, ['price_type' => 'Розница', 'discount' => 0, 'wait_on' => 0,
                        'wait_months' => 3, 'wait_discount' => 10, 'wait_prepay' => 100]);
ok('у менеджера теперь снова розница', Terms::conditions($mgr)['price_type'] === 'Розница');
ok('но контрагент X всё равно открывается своим оптом — приоритет выше',
   Terms::conditions($mgr, $cpX)['price_type'] === 'Опт безнал');
ok('контрагент Y (свой не запоминал) открывается последним условием менеджера',
   Terms::conditions($mgr, $cpY)['price_type'] === 'Розница');

// =====================================================================  4

echo "\n== 4. Доска «Закрыто»: без тела письма, с лимитом карточек ==\n";

$boxId = Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru',
                                  'is_active' => 1, 'is_default' => 1]);
$cpClosed = Db::insert('counterparties', ['name' => 'ООО «Закрытая сделка»']);
$cpOpen   = Db::insert('counterparties', ['name' => 'ООО «Открытая сделка»']);
Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
    'counterparty_id' => $cpClosed, 'subject' => 'Секретная тема', 'from_email' => 'a@zavod.ru',
    'from_name' => 'Клиент', 'to_emails' => 'info@atlant-armour.ru',
    'body_text' => 'Секретное тело письма, которое закрытая карточка не должна показывать',
    'is_read' => 1, 'date_at' => '2026-09-10 10:00:00',
]);
Db::insert('mail_messages', [
    'mailbox_id' => $boxId, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0,
    'counterparty_id' => $cpOpen, 'subject' => 'Открытая тема', 'from_email' => 'b@zavod.ru',
    'from_name' => 'Клиент', 'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'Тело письма',
    'is_read' => 1, 'date_at' => '2026-09-10 10:00:00',
]);

$boardId = Boards::createBoard('Тест 042');
$columns = Db::all("SELECT * FROM board_columns WHERE board_id=? ORDER BY position", [$boardId]);
$closedCol = null;
foreach ($columns as $c) if ($c['kind'] === 'closed') $closedCol = $c;
ok('заводская колонка «Закрыто» несёт kind=closed', $closedCol !== null);

$inboxCol = Boards::inboxColumn($boardId);
Db::insert('board_cards', ['column_id' => $closedCol['id'], 'title' => 'ООО «Закрытая сделка»', 'counterparty_id' => $cpClosed]);
Db::insert('board_cards', ['column_id' => $inboxCol['id'], 'title' => 'ООО «Открытая сделка»', 'counterparty_id' => $cpOpen]);

$got = Boards::get($boardId);
$closedCards = [];
$inboxCards = [];
foreach ($got['columns'] as $c) {
    if ($c['id'] === (int)$closedCol['id']) $closedCards = $c['cards'];
    if ($c['id'] === (int)$inboxCol['id']) $inboxCards = $c['cards'];
}
ok('закрытая карточка тему/превью письма не несёт',
   ($closedCards[0]['company']['subject'] ?? null) === null
   && ($closedCards[0]['company']['preview'] ?? null) === null,
   json_encode($closedCards[0]['company'] ?? null, JSON_UNESCAPED_UNICODE));
ok('но счётчик писем у неё есть — это не сломано, просто без тела',
   (int)($closedCards[0]['company']['letters'] ?? 0) === 1);
ok('открытая карточка тему и превью несёт как раньше',
   ($inboxCards[0]['company']['subject'] ?? '') === 'Открытая тема');

// Лимит карточек на колонку
for ($i = 1; $i <= 3; $i++) {
    $name = 'ООО «Ещё ' . $i . '»';
    $cp = Db::insert('counterparties', ['name' => $name]);
    Db::insert('board_cards', ['column_id' => $inboxCol['id'], 'title' => $name, 'counterparty_id' => $cp]);
}
Boards::saveColumn($boardId, (int)$inboxCol['id'], $inboxCol['title'], null, null, 2);
$limited = Boards::get($boardId);
foreach ($limited['columns'] as $c) if ($c['id'] === (int)$inboxCol['id']) $inboxCards = $c['cards'];
ok('лимит колонки обрезает список карточек', count($inboxCards) === 2, (string)count($inboxCards));
Boards::saveColumn($boardId, (int)$inboxCol['id'], $inboxCol['title'], null, null, 0);
$unlimited = Boards::get($boardId);
foreach ($unlimited['columns'] as $c) if ($c['id'] === (int)$inboxCol['id']) $inboxCards = $c['cards'];
ok('лимит 0 снимает ограничение', count($inboxCards) === 4, (string)count($inboxCards));

// =====================================================================  5

echo "\n== 5. Доставка: по умолчанию в цене товара, распределена по позициям ==\n";

$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 'test', 'status' => 'processing', 'counterparty_id' => $cpOpen]);
$proposalId = Db::insert('proposals', ['request_id' => $reqId, 'counterparty_id' => $cpOpen,
                                       'manager_id' => $mgr, 'delivery_on' => 1,
                                       'delivery_name' => 'Доставка', 'delivery_price' => 300,
                                       'terms_text' => KpTerms::defaultText()]);
Db::insert('proposal_items', ['proposal_id' => $proposalId, 'position' => 1,
                              'product_name' => 'Товар А', 'unit' => 'шт.', 'quantity' => 1,
                              'price' => 1000, 'is_confirmed' => 1]);
Db::insert('proposal_items', ['proposal_id' => $proposalId, 'position' => 2,
                              'product_name' => 'Товар Б', 'unit' => 'шт.', 'quantity' => 1,
                              'price' => 3000, 'is_confirmed' => 1]);
Requisites::freeze($proposalId);

$html = PdfGenerator::html($proposalId);
ok('отдельной строки доставки нет', !str_contains($html, '>Доставка<'));
ok('текст условий включает доставку в перечень расходов',
   str_contains($html, 'хранение, доставку, подготовку'));
ok('и не говорит про отдельную оплату', !str_contains($html, 'не включена'));
// 1000 + 3000 + 300 = 4300, доля А = 1000/4000*300 = 75, доля Б — остаток 225
ok('доля первой позиции верна (1000 из 4000 → +75)', str_contains($html, '1 075 руб.'), $html === '' ? '' : 'см. документ');
ok('доля второй позиции — остаток копеек (+225)', str_contains($html, '3 225 руб.'));
ok('«Итого» — сумма товаров и доставки, как и раньше', str_contains($html, 'Итого: 4 300 руб.'));

Settings::set('KP_DELIVERY_MODE', 'line');
$htmlLine = PdfGenerator::html($proposalId);
ok('настройка «строкой» возвращает отдельную строку', str_contains($htmlLine, '>Доставка<'));
ok('и прежние цены позиций без надбавки', str_contains($htmlLine, '1 000 руб.') && str_contains($htmlLine, '3 000 руб.'));
ok('«Итого» то же самое', str_contains($htmlLine, 'Итого: 4 300 руб.'));
Settings::forget('KP_DELIVERY_MODE');

// =====================================================================  6

echo "\n== 6. Обращение в поддержку: без служебного футера в GitHub issue ==\n";

$body = Support::issueBody([
    'body' => 'Не грузится каталог', 'manager_name' => 'Пётр', 'created_at' => '2026-09-18 12:37:36',
    'page' => '#settings/support', 'kind' => 'bug', 'files' => [],
]);
ok('жалоба напечатана', str_contains($body, 'Не грузится каталог'));
ok('футера «Отправил · Когда · Экран» нет', !str_contains($body, 'Отправил:') && !str_contains($body, 'Экран:'));
ok('подписи «Заведено из панели Атлант» нет', !str_contains($body, 'Заведено из панели Атлант'));

echo "\n" . ($fail ? "ПРОВАЛЕНО проверок: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
