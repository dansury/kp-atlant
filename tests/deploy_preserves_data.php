<?php
/**
 * Does a redeploy from GitHub wipe what the service has learned?
 *
 * `data/kp.db` holds the manager's corrections, the edited prompts, the
 * knowledge cache and every setting — none of it is in git, so it exists only
 * on the server. `data/.gitkeep` and `storage/*\/.gitkeep` ARE in git, so the
 * purge walks into those directories and, without ALWAYS_KEEP, deletes
 * everything it finds there.
 *
 * This test runs pull.php's OWN copyTree/purgeExtra against a simulated server,
 * with `keep_files` deliberately left empty — the way an operator who never
 * read DEPLOY.md would have it.
 *
 * Run:  php tests/deploy_preserves_data.php
 */
require __DIR__ . '/pull_functions.php';

$base = sys_get_temp_dir() . '/deploytest-' . getmypid();
$src = "$base/repo";      // what the archive from GitHub holds
$dst = "$base/public_html"; // the live server

function mk(string $path, string $body = 'x'): void {
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $body);
}
$fail = 0;
function ok(string $what, bool $cond) {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . "\n";
    if (!$cond) $fail++;
}

// The repository, exactly as it is: data/ and storage/ exist, but only as .gitkeep
mk("$src/lib/db.php", 'new code');
mk("$src/data/.gitkeep", '');
mk("$src/storage/attachments/.gitkeep", '');
mk("$src/storage/signatures/.gitkeep", '');
mk("$src/public/index.php", 'new');

// The live server, with everything that only exists there
mk("$dst/lib/db.php", 'old code');
mk("$dst/lib/removed_in_new_version.php", 'obsolete');
mk("$dst/data/.gitkeep", '');
mk("$dst/data/kp.db", 'ЗДЕСЬ ВСЁ: corrections, prompts, knowledge, настройки');
mk("$dst/data/kp.db-wal", 'wal');
mk("$dst/storage/signatures/signature.png", 'подпись руководителя');
mk("$dst/storage/attachments/zayavka.xlsx", 'вложение клиента');
mk("$dst/config.php", 'реальные ключи');
mk("$dst/pull-config.php", 'deploy config');
mk("$dst/public/index.php", 'old');

// The deploy, exactly as pull.php runs it: copy, then purge with keep_files EMPTY —
// the operator never filled that field in.
$copied = 0;
$keep = array_values(array_unique(array_merge(ALWAYS_KEEP, [])));
copyTree($src, $dst, $keep, $copied);
$f = 0; $d = 0;
purgeExtra($src, $dst, $keep, $f, $d);

echo "\nПосле деплоя (keep_files не заполнен):\n";
ok('база данных на месте',        file_exists("$dst/data/kp.db"));
ok('её WAL на месте',             file_exists("$dst/data/kp.db-wal"));
ok('содержимое базы не тронуто',  @file_get_contents("$dst/data/kp.db") === 'ЗДЕСЬ ВСЁ: corrections, prompts, knowledge, настройки');
ok('подпись руководителя на месте', file_exists("$dst/storage/signatures/signature.png"));
ok('вложения клиентов на месте',  file_exists("$dst/storage/attachments/zayavka.xlsx"));
ok('config.php с ключами не перезаписан', @file_get_contents("$dst/config.php") === 'реальные ключи');
ok('pull-config.php на месте',    file_exists("$dst/pull-config.php"));
echo "\nИ при этом деплой всё-таки произошёл:\n";
ok('код обновился',               @file_get_contents("$dst/lib/db.php") === 'new code');
ok('удалённый из репозитория файл убран', !file_exists("$dst/lib/removed_in_new_version.php"));

exec('rm -rf ' . escapeshellarg($base));
echo "\n" . ($fail ? "$fail FAILED\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
