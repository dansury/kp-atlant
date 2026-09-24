<?php
/**
 * Модуль 052 — на выбрасываемой базе и без сети:
 *
 *   — доп. поле «СОТРУДНИК» строится по типу поля: строка, сотрудник, справочник;
 *   — сотрудник находится по ФИО в любом порядке и с инициалом;
 *   — количество в подборе — целое;
 *   — приложенный файл отдаётся только из папки своего менеджера;
 *   — интерфейс (по исходнику): кнопки под КП, чип со скачиванием, «+ Позиция»
 *     внизу, сворачивание строк, закреплённые «?», стрелки вкладок.
 *
 * Запуск:  php tests/module_052.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-052-' . getmypid() . '.db';
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
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/outbox.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');
$inv = file_get_contents(ROOT . '/public/api/invoices.php');
$mail = file_get_contents(ROOT . '/public/api/mail.php');

echo "Доп. поля документа по типу\n";
$known = [
    'СОТРУДНИК' => ['id' => 'a1', 'type' => 'employee', 'required' => true, 'dictionary' => ''],
    'Менеджер'  => ['id' => 'a2', 'type' => 'string', 'required' => false, 'dictionary' => ''],
    'Отдел'     => ['id' => 'a3', 'type' => 'customentity', 'required' => false, 'dictionary' => 'd9'],
];
$emp = ['href' => 'https://api.moysklad.ru/api/remap/1.2/entity/employee/e1', 'type' => 'employee'];
$missing = [];
$body = MoySklad::attributesBody('invoiceout',
    ['СОТРУДНИК' => 'Яна Петрова', 'Менеджер' => 'Яна Петрова', 'Отдел' => 'Опт', 'Нет такого' => 'x'],
    $known, $missing,
    fn(array $a) => $emp,
    fn(string $d, string $n) => $d === 'd9' && $n === 'Опт' ? ['href' => 'x/customentity/d9/o1'] : null);
ok('сотрудник — ссылкой на employee', ($body[0]['value']['meta'] ?? null) === $emp);
ok('мета поля — счёта, а не заказа', str_ends_with($body[0]['meta']['href'], '/entity/invoiceout/metadata/attributes/a1'));
ok('строка — как есть', ($body[1]['value'] ?? '') === 'Яна Петрова');
ok('справочник — элемент с тем же названием', ($body[2]['value']['meta']['href'] ?? '') === 'x/customentity/d9/o1');
ok('неизвестное поле — в missing', $missing === ['доп. поле «Нет такого»'], implode('; ', $missing));
$missing = [];
$body = MoySklad::attributesBody('invoiceout', ['СОТРУДНИК' => 'Яна'], $known, $missing,
    fn(array $a) => null, fn() => null);
ok('сотрудник не найден — поле не идёт, имя в missing', !$body && $missing === ['доп. поле «СОТРУДНИК»']);

echo "Сотрудник по ФИО\n";
$e = ['fullName' => 'Петрова Яна Сергеевна', 'name' => 'Петрова Я. С.', 'lastName' => 'Петрова', 'firstName' => 'Яна'];
ok('«Яна Петрова» = Петрова Яна', MoySklad::sameEmployee(['fullName' => 'Петрова Яна'], 'Яна Петрова'));
ok('«Яна Петрова» = фамилия + первая буква имени', MoySklad::sameEmployee($e, 'Яна Петрова'));
ok('ё = е', MoySklad::sameEmployee(['fullName' => 'Семёнов Пётр'], 'Петр Семенов'));
ok('чужая фамилия — нет', !MoySklad::sameEmployee($e, 'Яна Иванова'));
ok('пустое имя — нет', !MoySklad::sameEmployee($e, ''));

echo "Счёт: поле и окно МойСклад\n";
ok('счёт получает те же доп. поля, что и заказ', substr_count($inv, "'attributes'      => \$employeeAttr") === 2);
ok('менеджер берётся из карточки (uid, почта)', str_contains($inv, 'SELECT name, email, moysklad_uid FROM managers'));
ok('непривязанная компания отвечает ms_unlinked', str_contains($inv, "'ms_unlinked' =>"));
ok('клиент открывает окно заведения и повторяет счёт', str_contains($js, 'this.msCreateForm(ms)')
    && str_contains($js, '_msAfter = () => this.kpInvoice(id, btn, orgId)'));

echo "Количество — целое\n";
ok('2.4 → 2', RequestItems::qty(2.4) === 2);
ok('2,6 → 3', RequestItems::qty('2,6') === 3);
ok('0.3 → 1 (не ноль)', RequestItems::qty(0.3) === 1);
ok('0 и минус → 0', RequestItems::qty(0) === 0 && RequestItems::qty(-5) === 0);
$reqId = Db::insert('requests', ['source' => 'email', 'raw_text' => 't', 'status' => 'processing']);
RequestItems::save($reqId, [['raw_name' => 'Шлем', 'product_name' => 'Шлем', 'quantity' => '2.7', 'unit' => 'шт.', 'price' => 10]]);
ok('сохранённое количество целое', (string)Db::val("SELECT quantity FROM request_items WHERE request_id=?", [$reqId]) === '3',
   (string)Db::val("SELECT quantity FROM request_items WHERE request_id=?", [$reqId]));
ok('поле количества — шаг 1 и без дробей', str_contains($js, 'step="1" min="0" inputmode="numeric" data-field="quantity"')
    && str_contains($js, 'out.quantity = this.intQty(out.quantity)'));

echo "Приложенный файл скачивается\n";
$src = sys_get_temp_dir() . '/kp052-' . getmypid() . '.pdf';
file_put_contents($src, '%PDF-1.4 test');
$f = Outbox::adopt($src, 'КП №5.pdf', 7);
ok('свой файл находится', Outbox::path($f['name'], 7) !== null);
ok('чужой менеджер его не достанет', Outbox::path($f['name'], 8) === null);
ok('«../» не выводит из папки', Outbox::path('../7/' . $f['name'], 8) === null && Outbox::path('..', 7) === null);
ok('имя без служебной приставки', Outbox::displayName($f['name']) === 'КП №5.pdf');
@unlink(Outbox::path($f['name'], 7));
@unlink($src);
ok('outbox_file отдаёт вложением', str_contains($mail, "case 'outbox_file'")
    && str_contains($mail, 'Attachments::contentDisposition($name, false)'));
ok('чип вложения — ссылка на скачивание', str_contains($js, 'action=outbox_file&name=')
    && !str_contains($js, 'class="chip" data-cmp-file'));

echo "Окно КП\n";
ok('под листом: PDF и Word в письмо, скачать', str_contains($js, "App.kpAttach(\${id}, 'kp', this)")
    && str_contains($js, "App.kpAttach(\${id}, 'kp_docx', this)") && str_contains($js, 'class="kp-docbar"'));
ok('«Все настройки КП» убрано', !str_contains($js, 'Все настройки КП'));
ok('правка листа сохраняется перед вложением', (bool)preg_match('/async kpAttach[^}]+kpPageSave/s', $js));
ok('счёт под подбором прикладывается', str_contains($js, "App.attachDoc('invoice', \${i.id}, this)"));

echo "Таблица подбора\n";
ok('«+ Позиция» и внизу', str_contains($js, 'class="match-add-bottom"'));
ok('строка сворачивается, сводка есть', str_contains($js, 'App.toggleRowFold(this)') && str_contains($js, 'data-row-summary'));
ok('«Свернуть подобранные»', str_contains($js, 'App.foldPickedRows(this)'));
ok('свёрнутая строка прячет поля в CSS', str_contains($css, '.match-row--folded > :not(.match-row__name) { display: none !important; }'));
ok('«?» аналога приклеен к галочке', (bool)preg_match('/<span class="hint-pin">\s*<label[^>]*>\s*<input[^>]*is_alternative/s', $js));
ok('.hint-pin не переносится', str_contains($css, '.hint-pin { display: inline-flex;') && str_contains($css, 'white-space: nowrap'));

echo "Вкладки справа\n";
ok('на десктопе стрелка вправо', str_contains($js, "rail ? (folded ? '◂' : '▸')"));

echo $fail ? "\nFAILED: $fail\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
