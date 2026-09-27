<?php
/**
 * Модуль 069: вики отвечает на письма — страницы по категории, датированные цены.
 *
 * Запуск:  php tests/module_069.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-069-' . getmypid() . '.db';
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
require_once ROOT . '/lib/triage.php';
require_once ROOT . '/lib/knowledge.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

// Offline: no Yandex vectors, no GitHub (an empty repo makes sync() fail and be logged)
Settings::set('YANDEX_API_KEY', '');
Settings::set('YANDEX_FOLDER_ID', '');
Settings::set('KNOWLEDGE_ENABLED', '1');
Settings::set('KNOWLEDGE_REPO', '');

$docs = [
    'GRAPH/wiki/Опт и работа с юрлицами.md' => "---\ntags: [services/b2b]\n---\n# Опт и работа с юрлицами\n\n## Оплата и договор\nРаботаем только по 100% предоплате. Гарантийное письмо и отсрочка не принимаются. Договор — по нашей стандартной форме.\n\n## Печать и ЭДО\nСчёт без печати: скан с подписью или подписанный документ через ЭДО Контур.Диадок.\n",
    'GRAPH/wiki/Гарантия, обмен и возврат.md' => "# Гарантия, обмен и возврат\n\n## Обмен размера\nОбмен размера возможен за счёт покупателя, если вещь не носили и сохранены бирки.\n",
    'GRAPH/wiki/Вопросы и ответы из почты.md' => "# Вопросы и ответы из почты\n\n## Оплата, счета, договор\n- (07.2025) Отгрузите по гарантийному письму? → Нет; отгрузка день в день по платёжке.\n- (03.2024) Можно в рассрочку? → Рассрочки нет.\n",
    'GRAPH/wiki/Шлемы.md' => "# Шлемы\n\n## Размеры шлемов\nРазмеры шлемов АТОМ: S 54–56, M 56–59, L 60–62. Цена на 09.2026 — 45 000 ₽. Письмо с вопросом о шлеме — по таблице.\n",
];
foreach ($docs as $path => $content) {
    preg_match('/^# (.+)$/mu', $content, $m);
    Db::insert('knowledge_docs', ['path' => $path, 'title' => $m[1], 'tags' => '', 'content' => $content,
        'sha' => md5($content), 'size' => strlen($content), 'updated_at' => date('Y-m-d H:i:s')]);
}
Knowledge::reindex();

echo "\n== Страницы для ответов ==\n";
ok('настройка объявлена', isset(Settings::SPEC['KNOWLEDGE_REPLY_PAGES'])
   && Settings::SPEC['KNOWLEDGE_REPLY_PAGES'][2] === 'textarea');
$ret = Knowledge::replyPages('reply_return');
ok('возврат читает гарантию, потом почтовые ответы', $ret === ['Гарантия, обмен и возврат', 'Вопросы и ответы из почты'],
   implode(' | ', $ret));
ok('«*» — только ответы на письма', Knowledge::replyPages('mail_reply') === ['Вопросы и ответы из почты']
   && Knowledge::replyPages('cover_letter') === [] && Knowledge::replyPages('match_pick') === []);

$letter = "Добрый день! Можете отгрузить по гарантийному письму, оплатим через месяц?";
$pinned = Knowledge::search($letter, 4800, 'reply_wholesale');
ok('закреплённые разделы идут первыми', $pinned && $pinned[0]['source'] === 'pinned',
   implode(' | ', array_map(fn($p) => $p['title'] . ' (' . $p['source'] . ')', $pinned)));
ok('страница категории — раньше общей', str_starts_with($pinned[0]['title'], 'Опт и работа с юрлицами'));
ok('правило опта в подборе', (bool)array_filter($pinned, fn($p) => str_contains($p['title'], 'Оплата и договор')));
ok('разделы не повторяются', count($pinned) === count(array_unique(array_column($pinned, 'title'))));
ok('закреплённое не больше половины бюджета', array_sum(array_map(fn($p) => $p['source'] === 'pinned'
   ? mb_strlen($p['text']) : 0, $pinned)) <= 2400 + 10);

$off = Knowledge::search('Какой вес у шлема АТОМ?', 4800, 'reply_return');
ok('страница без общего слова с письмом не закрепляется', !array_filter($off, fn($p) => $p['source'] === 'pinned'),
   implode(' | ', array_column($off, 'title')));

Settings::set('KNOWLEDGE_REPLY_PAGES', '');
ok('пустая настройка — подбор модуля 005', !array_filter(Knowledge::search($letter, 4800, 'reply_wholesale'),
   fn($p) => $p['source'] === 'pinned'));
Settings::set('KNOWLEDGE_REPLY_PAGES', "reply_return: Гарантия, обмен и возврат; Возвраты старое");
$pages = array_column(Knowledge::status()['tasks'], 'pages', 'key')['reply_return'];
ok('статус видит найденную и пропавшую страницу', $pages === [
   ['page' => 'Гарантия, обмен и возврат', 'found' => true], ['page' => 'Возвраты старое', 'found' => false]]);
Settings::set('KNOWLEDGE_REPLY_PAGES', "reply_return: гарантия, обмен и возврат.md");
ok('страница по имени файла, без учёта регистра', Knowledge::status()['tasks'][array_search('reply_return',
   array_column(Knowledge::status()['tasks'], 'key'))]['pages'][0]['found'] === true);
Db::q("DELETE FROM settings WHERE key LIKE '%KNOWLEDGE_REPLY_PAGES%'");

echo "\n== Датированные факты ==\n";
$block = Knowledge::context('Какие размеры у шлема АТОМ и сколько стоит?', 'reply_product');
ok('блок собран из промпта', str_contains($block, '===== БАЗА ЗНАНИЙ ATLANT ARMOUR =====')
   && str_contains($block, 'Размеры шлемов') && !str_contains($block, '{{sections}}'));
ok('цены вики — история, а не текущие', str_contains($block, 'Цену или ставку из базы знаний клиенту как действующую не называй'));
ok('промпт обёртки редактируется', isset(Prompts::registry()['knowledge_block']));
ok('без совпадений блока нет', Knowledge::context('zzzz qqqq', 'reply_product') === '');

echo "\n== Интерфейс ==\n";
ok('проверка подбора пишет в категории задачи', Triage::categoryOf('reply_return') === 'return_exchange'
   && Triage::categoryOf('mail_reply') === 'other');
$js = file_get_contents(ROOT . '/public/assets/js/app.js');
ok('поле на вкладке «База знаний»', str_contains($js, "KB_REPLY_KEYS: ['KNOWLEDGE_REPLY_PAGES']")
   && str_contains($js, "this.hint('knowledge-reply')") && str_contains($js, "'knowledge-reply': ["));
ok('пропавшая страница видна красным', str_contains($js, 'нет в вики, переименована?'));
ok('закреплённый раздел подписан', str_contains($js, "i.source === 'pinned'"));

echo $fail ? "\nFAILED: $fail\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
