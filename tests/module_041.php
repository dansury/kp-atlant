<?php
/**
 * Модуль 041: письмо нужной формы, промпты учатся на правках.
 *
 * Проверяется ровно то, ради чего модуль появился:
 *   — письмо начинается обращением по имени и отчеству, и только одним;
 *   — обращение берётся из ФИО, а не из адреса и не из названия отдела;
 *   — отправленное письмо встаёт в пару к письму клиента в «Исправлениях»;
 *   — свод правил подмешивается в промпт отдельным блоком и не задваивается;
 *   — версия промпта из истории возвращается, а нынешняя уходит в историю.
 *
 * Run:  php tests/module_041.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-041-' . getmypid() . '.db';
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
require_once ROOT . '/lib/letter_shape.php';
require_once ROOT . '/lib/learning.php';
require_once ROOT . '/lib/prompts.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

echo "\n1. Обращение по имени и отчеству\n";

ok('ФИО целиком → имя и отчество',
   LetterShape::greeting('Кузнецов Никита Владимирович') === 'Никита Владимирович, добрый день!',
   LetterShape::greeting('Кузнецов Никита Владимирович'));
ok('имя и фамилия → имя', LetterShape::greeting('Яна Петрова') === 'Яна, добрый день!',
   LetterShape::greeting('Яна Петрова'));
ok('фамилия и имя → тоже имя', LetterShape::greeting('Петрова Яна') === 'Яна, добрый день!',
   LetterShape::greeting('Петрова Яна'));
ok('адрес обращением не становится', LetterShape::greeting('info@atlant-armour.ru') === 'Добрый день!');
ok('отдел закупок — тоже', LetterShape::greeting('Отдел закупок') === 'Добрый день!');
ok('никого не знаем — просто «Добрый день!»', LetterShape::greeting(null) === 'Добрый день!');

echo "\n2. Приветствие в письме ровно одно\n";

$model = "Здравствуйте!\n\nПрикладываем файл КП по вашему запросу. Позиции Бр5 нет в наличии.";
$letter = LetterShape::apply($model, 'Кузнецов Никита Владимирович');
ok('своё приветствие встало первым',
   str_starts_with($letter, 'Никита Владимирович, добрый день!'), mb_substr($letter, 0, 40));
ok('чужое убрано', !str_contains($letter, 'Здравствуйте'), $letter);
ok('суть письма на месте', str_contains($letter, 'Прикладываем файл КП'));
ok('повторное применение ничего не задваивает',
   LetterShape::apply($letter, 'Кузнецов Никита Владимирович') === $letter);
ok('форма письма есть в инструкции промпта',
   str_contains(LetterShape::instruction(), 'Прикладываем файл КП')
   && str_contains(LetterShape::instruction(), 'Прикладываем счёт'));

echo "\n3. Отправленное письмо — образец для промптов\n";

$mgr = (int)Db::insert('managers', ['login' => 'yana', 'password_hash' => 'x', 'name' => 'Яна']);
$id = Learning::recordSent([
    'subject'        => 'Запрос КП на бронеплиты',
    'question'       => 'Прошу направить КП на плиты Бр2.',
    'auto_answer'    => 'Здравствуйте! Высылаем прайс.',
    'correct_answer' => 'Никита Владимирович, добрый день!' . "\n\nПрикладываем файл КП по вашему запросу.",
    'manager_id'     => $mgr,
]);
ok('пара сохранена', $id > 0, (string)$id);
$row = Db::one("SELECT * FROM learning_samples WHERE id=?", [$id]);
ok('это отдельный вид правки', $row['kind'] === 'sent', (string)$row['kind']);
ok('письмо клиента рядом с ответом',
   str_contains((string)$row['question'], 'Бр2') && str_contains((string)$row['correct_answer'], 'КП'));
ok('и видно, что предлагала модель', str_contains((string)$row['auto_answer'], 'прайс'));
ok('то же письмо второй раз не пишется',
   Learning::recordSent(['question' => 'Прошу направить КП на плиты Бр2.',
                         'correct_answer' => 'Никита Владимирович, добрый день!' . "\n\nПрикладываем файл КП по вашему запросу."]) === 0);
ok('вид правок виден в панели',
   in_array('sent', array_column(Learning::query()['kinds'], 'key'), true));

echo "\n4. Свод правил подмешивается в промпт\n";

$block = "- Пиши срок поставки прямо в первом абзаце.\n- Не обещай наличие, которого нет в подборе.";
$before = Prompts::text('reply_kp');
$after = Prompts::append('reply_kp', $block, $mgr);
ok('блок дописан', str_contains($after, 'Не обещай наличие'), mb_substr($after, -80));
ok('и подписан, откуда он взялся', str_contains($after, Prompts::LEARNED_HEADER));
ok('прежний текст промпта на месте', str_contains($after, mb_substr(trim($before), 0, 40)));
ok('второй раз тот же блок не дописывается',
   Prompts::append('reply_kp', $block, $mgr) === $after);

echo "\n5. Версия из истории возвращается\n";

Prompts::save('reply_kp', "Совсем другой промпт.", $mgr);
ok('сейчас стоит новый текст', Prompts::text('reply_kp') === 'Совсем другой промпт.');
$history = Prompts::history('reply_kp');
// Первая правка встроенного промпта истории не оставляет — возвращать его
// есть чем и без неё, кнопкой «Вернуть встроенный»; в историю попадает всё,
// что было сохранено в базу
ok('история не пуста', count($history) >= 1, (string)count($history));

$withBlock = array_values(array_filter($history, fn($h) => str_contains((string)$h['content'], 'Не обещай наличие')))[0] ?? null;
ok('версия с блоком нашлась в истории', $withBlock !== null);
Prompts::restore('reply_kp', (int)$withBlock['id'], $mgr);
ok('она вернулась в промпт', str_contains(Prompts::text('reply_kp'), 'Не обещай наличие'));
ok('а «совсем другой промпт» ушёл в историю',
   (bool)array_filter(Prompts::history('reply_kp'), fn($h) => $h['content'] === 'Совсем другой промпт.'));

$threw = false;
try { Prompts::restore('reply_kp', 999999, $mgr); } catch (InvalidArgumentException $e) { $threw = true; }
ok('несуществующая версия не возвращается', $threw);

echo "\n" . ($fail ? "ПРОВАЛЕНО: $fail\n" : "Всё сошлось\n");
exit($fail ? 1 : 0);
