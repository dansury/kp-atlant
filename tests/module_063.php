<?php
/**
 * Модуль 063: issue #121 — набранное не теряется, следующий шаг светится.
 *
 * Запуск:  php tests/module_063.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-063-' . getmypid() . '.db';
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
require_once ROOT . '/lib/field_drafts.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

$m1 = (int)Db::insert('managers', ['login' => 'a063', 'password_hash' => 'x', 'name' => 'A']);
$m2 = (int)Db::insert('managers', ['login' => 'b063', 'password_hash' => 'x', 'name' => 'B']);

echo "Черновики полей\n";
ok('сохраняется', FieldDrafts::save($m1, 'support.body', 'Не работает кнопка', 'abc'));
$all = FieldDrafts::all($m1);
ok('читается с исходным base', ($all['support.body']['v'] ?? '') === 'Не работает кнопка' && $all['support.body']['base'] === 'abc');
ok('время — в миллисекундах UTC', abs(($all['support.body']['at'] ?? 0) / 1000 - time()) < 120);
FieldDrafts::save($m1, 'support.body', 'Новый текст', 'abc');
ok('повторное сохранение заменяет, а не копит', count(FieldDrafts::all($m1)) === 1
   && FieldDrafts::all($m1)['support.body']['v'] === 'Новый текст');
ok('чужой менеджер не видит', FieldDrafts::all($m2) === []);
ok('пустой текст стирает черновик', !FieldDrafts::save($m1, 'support.body', "  \n ") && FieldDrafts::all($m1) === []);
FieldDrafts::save($m1, 'k', 'x');
FieldDrafts::clear($m1, 'k');
ok('clear удаляет', FieldDrafts::all($m1) === []);
ok('пустой ключ не сохраняется', !FieldDrafts::save($m1, '  ', 'x'));
FieldDrafts::save($m1, str_repeat('к', 300), str_repeat('т', FieldDrafts::MAX_BODY + 10));
$row = array_values(FieldDrafts::all($m1))[0] ?? ['v' => ''];
ok('длина ключа и текста ограничена', mb_strlen(array_key_first(FieldDrafts::all($m1))) === FieldDrafts::MAX_KEY
   && mb_strlen($row['v']) === FieldDrafts::MAX_BODY);
Db::q("UPDATE field_drafts SET updated_at = datetime('now', '-40 days')");
FieldDrafts::save($m2, 'fresh', 'y');
ok('старше 30 дней — вычищаются', FieldDrafts::all($m1) === [] && count(FieldDrafts::all($m2)) === 1);

echo "Интерфейс\n";
$js  = file_get_contents(ROOT . '/public/assets/js/app.js');
$css = file_get_contents(ROOT . '/public/assets/css/app.css');
ok('API черновиков существует', is_file(ROOT . '/public/api/drafts.php'));
ok('копия на устройстве сразу, сервер — с задержкой', str_contains($js, "localStorage.setItem('keep:' + key")
   && str_contains($js, "drafts.php?action=save"));
ok('куки — только запасной путь и не больше 6', str_contains($js, "mine.length >= 6") && str_contains($js, 'val.length > 3000'));
ok('поля ловятся наблюдателем, а не вызовом в каждой странице', str_contains($js, 'new MutationObserver(list =>')
   && str_contains($js, 'this.keepScan(n)'));
ok('устаревший черновик не затирает новый текст', str_contains($js, 'rec.base !== base'));
ok('восстановленное помечено и стирается', str_contains($js, 'Восстановлен несохранённый текст'));
ok('отправка чистит черновик', substr_count($js, 'this.keepClearIn(') >= 4 && str_contains($js, 'this.keepClear(el.dataset.keepKey)'));
ok('поддержка — свой ключ с любого экрана', str_contains($js, 'data-keep="support.body"'));
ok('обрыв сети назван и текст не теряется', str_contains($js, 'err.offline = true'));
ok('письмо дублируется на устройство', str_contains($js, 'composerKeepKey(c, key)') && str_contains($js, "'cmp:' +"));
ok('следующий шаг светится', str_contains($js, 'markNext(root)') && str_contains($css, '.btn--next'));
ok('подпись — ещё не письмо', str_contains($js, 'composerOwnText(c)'));
ok('к действию после перехода', str_contains($js, 'this.landOnAction(hash)'));
ok('условия КП сворачиваются и показывают выбранное', str_contains($js, '<details class="conditions fold"')
   && str_contains($js, 'condSummaryText(c)'));
ok('свёрнутое и раскрытое — разным цветом', str_contains($css, 'details.fold[open]') && str_contains($css, 'border: 1px dashed'));
ok('оформление письма на телефоне за «Aa»', str_contains($css, '.composer__tools--open'));
ok('установка — контуром, не заливкой', str_contains($css, '.btn--glow { background: var(--surface)'));
ok('без товара фото и пустое описание спрятаны', str_contains($css, '.match-row--nomatch .match-extra__photos'));

echo $fail ? "\nFAILED: $fail\n" : "\nall ok\n";
exit($fail ? 1 : 0);
