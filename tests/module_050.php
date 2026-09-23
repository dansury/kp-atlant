<?php
/**
 * Модуль 050 — на выбрасываемой базе и без сети:
 *
 *   — «Прочитано» на карточке помнит дату последнего письма, а не часы сервера:
 *     письмо, написанное ДО нажатия, но пришедшее после, поднимает карточку;
 *   — интерфейс (по исходнику): список как в Gmail, цвета статусов, меню «⋯»,
 *     одна шапка экрана, группы настроек, доступность с клавиатуры.
 *
 * Запуск:  php tests/module_050.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-050-' . getmypid() . '.db';
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
require_once ROOT . '/lib/boards.php';
require_once ROOT . '/lib/mailsync.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}
Settings::set('TRIAGE_ENABLED', '0');

// =====================================================================  1

echo "\n== 1. Новое письмо поднимает прочитанную карточку ==\n";

$mgr = Db::insert('managers', ['login' => 'yana', 'name' => 'Яна', 'password_hash' => 'x', 'is_admin' => 1]);
$box = Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru', 'is_active' => 1, 'is_default' => 1]);
$cp  = Db::insert('counterparties', ['name' => 'ООО «Завод»', 'email_domain' => 'zavod.ru']);
$letter = fn(string $at) => Db::insert('mail_messages', [
    'mailbox_id' => $box, 'direction' => 'in', 'folder' => 'INBOX', 'uid' => 0, 'subject' => 'Запрос',
    'from_email' => 'client@zavod.ru', 'to_emails' => 'info@atlant-armour.ru', 'body_text' => 'текст',
    'is_read' => 0, 'counterparty_id' => $cp, 'thread_key' => 't:z', 'date_at' => $at,
]);
$letter(date('Y-m-d H:i:s', time() - 3600));

$boardId = (int)Boards::singleton()['id'];
Boards::sync($boardId, true);
$cardId = (int)Db::val("SELECT id FROM board_cards WHERE counterparty_id=?", [$cp]);
$card = fn() => Boards::get($boardId)['columns'][0]['cards'][0] ?? [];
ok('клиент написал последним — карточка ждёт ответа', !empty($card()['unanswered']));

Boards::bulk([$cardId], 'read', [], $mgr);
ok('«Прочитано» гасит карточку', empty($card()['unanswered']));
ok('и запоминает дату письма, а не часы сервера',
   (string)Db::val("SELECT seen_at FROM board_cards WHERE id=?", [$cardId]) === (string)$card()['last_at']);

// Письмо написано за минуту до нажатия (часы отправителя), пришло после
$letter(date('Y-m-d H:i:s', time() - 60));
ok('письмо, написанное до нажатия, но новее прочитанного, поднимает карточку', !empty($card()['unanswered']));

// =====================================================================  2

echo "\n== 2. Интерфейс (по исходнику) ==\n";
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');

ok('у писем есть вид списком', str_contains($js, "if (seg === 'list' || (!seg && this.mailView() === 'list')) return this.pageMailList("));
ok('список идёт по дате последнего письма', str_contains($js, 'return x === y ? b.id - a.id : (x < y ? 1 : -1);'));
ok('поднявшаяся строка подсвечивается', str_contains($js, "cls.push('grow--risen')") && str_contains($css, '.grow--risen'));
ok('статус строки — цвет и название', str_contains($js, '<span class="stag" style="--col:'));
ok('фильтры и отметки работают на обоих видах', !str_contains($js, "document.querySelectorAll('.bcard').forEach(el => {\n            if (el.hidden) return;"));
ok('шапка колонки окрашена цветом статуса', str_contains($css, 'color-mix(in srgb, var(--col) 16%, var(--surface))'));
ok('край карточки — цвет статуса', str_contains($css, '.bcol .bcard { border-left: 3px solid var(--col); }'));
ok('цвет колонки меняется из меню', str_contains($js, 'App.boardColumnColor(') && str_contains($js, 'saveColumnColor('));
ok('удаление колонки — в меню, отдельно и красным', str_contains($js, '{label: \'Удалить колонку\', onclick: `App.boardDeleteColumn(${c.id})`, danger: true}'));
ok('одна главная кнопка на «Письмах»', substr_count($js, '<button class="btn btn--primary btn--sm" onclick="App.mailCompose()">✉ Написать</button>') === 1);
ok('шапка экрана одна на все', substr_count($js, 'this.pageHead({') >= 8, (string)substr_count($js, 'this.pageHead({'));
ok('кнопок «← На доску» больше нет', !str_contains($js, '← На доску') && !str_contains($js, '← К доске'));
ok('настройки — группами', str_contains($js, 'SETTINGS_GROUPS') && str_contains($js, 'aria-label="Разделы настроек"'));
ok('текстовые действия получают фокус', str_contains($js, "a[onclick]:not([href]):not([tabindex])"));
ok('уменьшенная анимация уважается', str_contains($css, '@media (prefers-reduced-motion: reduce)'));
ok('ошибка поля — под полем', str_contains($js, "this.fieldError('reqText'"));

echo $fail ? "\nПРОВАЛЕНО проверок: $fail\n" : "\nВсе проверки прошли\n";
exit($fail ? 1 : 0);
