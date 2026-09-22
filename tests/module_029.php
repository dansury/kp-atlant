<?php
/**
 * Модуль 029 — карточка читается сверху вниз, счёт живёт под письмом:
 *
 *   — переписки приходят СТАРЫЕ СВЕРХУ, и видно, чьё последнее письмо;
 *   — заказы и счета идут одной лентой с заметками, со ссылками в МойСклад,
 *     и каждый помнит свой запрос — чтобы чужое можно было приглушить;
 *   — в карточке живёт несколько организаций, счёт выставляется на выбранную;
 *   — имя файла счёта собирается по шаблону из настроек;
 *   — ошибка уровня `error` доходит до администратора уведомлением;
 *   — слаг модели Yandex, которого у провайдера нет, в запрос не уходит.
 *
 * Запуск:  php tests/module_029.php
 *
 * База своя, в системной временной папке: `data/kp.db` не открывается вовсе.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-029-' . getmypid() . '.db';
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
require_once ROOT . '/lib/invoice_name.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');

$mgr = Db::insert('managers', ['login' => 'yana', 'name' => 'Яна',
                               'password_hash' => 'x', 'is_admin' => 1]);
$cpId = Db::insert('counterparties', [
    'name' => 'АО «ТИКО-Пластик»', 'inn' => '5214007595',
    'email_domain' => 'novaroll.ru', 'moysklad_id' => 'ms-tiko',
    'contact_person' => 'Каменева Анна Андреевна', 'contact_email' => 'kameneva.aa@novaroll.ru',
]);

// =====================================================================  1

echo "\n== 1. Схема доросла до организаций в карточке ==\n";

ok('версия схемы поднялась', (int)Db::val("SELECT value FROM settings WHERE key='schema_version'") >= 29,
   (string)Db::val("SELECT value FROM settings WHERE key='schema_version'"));
ok('таблица организаций есть', Db::hasColumn('counterparty_orgs', 'moysklad_id'));
ok('счёт помнит свою организацию', Db::hasColumn('invoices', 'org_id'));
ok('и заказ тоже', Db::hasColumn('orders', 'org_id'));

// =====================================================================  2

echo "\n== 2. Две организации в одной карточке ==\n";

$orgs = Crm::orgs($cpId);
ok('пока одна — сама карточка', count($orgs) === 1 && $orgs[0]['id'] === 0 && $orgs[0]['primary'],
   $orgs[0]['name'] ?? '');

// «Прошу счёт на 15 штук в адрес АО ТИКО-Пластик, 2 штуки в адрес ООО Нова Ролл Пак»
$novaId = Crm::addOrg($cpId, [
    'name' => 'ООО «Нова Ролл Пак»', 'inn' => '5038121998', 'kpp' => '503801001',
    'moysklad_id' => 'ms-nova', 'edo_id' => '2BM-5038121998-503801001-201609300834440895988',
]);
$orgs = Crm::orgs($cpId);
ok('организаций стало две', count($orgs) === 2, implode(' + ', array_column($orgs, 'name')));
ok('карточка по-прежнему первая', $orgs[0]['primary'] === true && $orgs[1]['primary'] === false);
ok('у второй свой ИНН', $orgs[1]['inn'] === '5038121998', $orgs[1]['inn']);
ok('и свой идентификатор ЭДО', str_starts_with($orgs[1]['edo_id'], '2BM-5038121998'));

ok('организация находится по id', (Crm::org($cpId, $novaId)['name'] ?? '') === 'ООО «Нова Ролл Пак»');
ok('нулевой id — это карточка', (Crm::org($cpId, 0)['name'] ?? '') === 'АО «ТИКО-Пластик»');
ok('чужого id в карточке нет', Crm::org($cpId, 999999) === null);

// =====================================================================  3

echo "\n== 3. Имя файла счёта клиент найдёт у себя в папке ==\n";

// Наше юрлицо база заводит сама при первом запуске — имя файла берёт его
Db::q("UPDATE legal_entities SET short_name='Атлант Армор' WHERE is_active=1");

$invId = Db::insert('invoices', [
    'counterparty_id' => $cpId, 'moysklad_id' => 'ms-inv-1', 'name' => '00042',
    'moment' => '2026-09-16 11:56:02', 'sum' => 120000,
]);
$name = InvoiceName::forInvoice($invId);
ok('имя начинается со счёта и нашей компании', str_starts_with($name, 'Счет_от_Атлант_Армор_'), $name);
ok('в имени компания контрагента', str_contains($name, 'ТИКО-Пластик'), $name);
ok('и дата в формате ДД.ММ.ГГ', str_contains($name, '16.09.26'), $name);
ok('расширение дописано само', str_ends_with($name, '.pdf'), $name);
ok('ничего, что ломает файловую систему', !preg_match('#[\\\\/:*?"<>|]#', $name), $name);

// Счёт на вторую организацию называется её именем, а не именем карточки
Db::update('invoices', ['org_id' => $novaId], 'id=?', [$invId]);
$nameNova = InvoiceName::forInvoice($invId);
ok('счёт на вторую организацию назван ею', str_contains($nameNova, 'Нова_Ролл_Пак'), $nameNova);
Db::update('invoices', ['org_id' => null], 'id=?', [$invId]);

// Свой шаблон — своё имя
Settings::set('INVOICE_FILE_NAME', 'Счет_{number}_{client}');
ok('шаблон из настроек работает', InvoiceName::forInvoice($invId) === 'Счет_00042_АО_ТИКО-Пластик.pdf',
   InvoiceName::forInvoice($invId));
Settings::set('INVOICE_FILE_NAME', 'Счет_от_{company}_для_{client}_{date}');

// Контактное лицо вместо компании, когда компании нет
$soloId = Db::insert('counterparties', ['name' => '', 'contact_person' => 'Пётр Иванов']);
ok('без названия фирмы в имени ФИО', str_contains(
    InvoiceName::build(['company' => 'Атлант Армор',
                        'client'  => InvoiceName::clientName($soloId),
                        'date'    => '16.09.26']), 'Пётр_Иванов'));

// =====================================================================  4

echo "\n== 4. Заказы и счета — одной лентой, каждый со своим запросом ==\n";

$reqA = Db::insert('requests', ['source' => 'email', 'counterparty_id' => $cpId,
                                'raw_text' => 'жгут-турникет', 'status' => 'new']);
$reqB = Db::insert('requests', ['source' => 'email', 'counterparty_id' => $cpId,
                                'raw_text' => 'шлемы', 'status' => 'new']);

$orderA = Db::insert('orders', ['counterparty_id' => $cpId, 'request_id' => $reqA,
                                'moysklad_id' => 'ms-ord-a', 'name' => '00101',
                                'moment' => '2026-09-15 10:00:00', 'sum' => 45000,
                                'state_name' => 'Резерв', 'applicable' => 1]);
Db::insert('orders', ['counterparty_id' => $cpId, 'request_id' => $reqB,
                      'moysklad_id' => 'ms-ord-b', 'name' => '00102',
                      'moment' => '2026-09-16 10:00:00', 'sum' => 30000, 'applicable' => 1]);
Db::update('invoices', ['order_id' => $orderA, 'moment' => '2026-09-15 12:00:00'], 'id=?', [$invId]);

$docs = Crm::documents($cpId);
ok('в ленте три документа', count($docs) === 3, (string)count($docs));
ok('старое сверху, свежее снизу',
   $docs[0]['created_at'] <= $docs[1]['created_at'] && $docs[1]['created_at'] <= $docs[2]['created_at'],
   implode(' → ', array_column($docs, 'created_at')));

$byName = array_column($docs, null, 'title');
ok('заказ ведёт в МойСклад',
   str_contains($byName['Заказ 00101']['url'] ?? '', 'customerorder/edit?id=ms-ord-a'),
   $byName['Заказ 00101']['url'] ?? '');
ok('счёт ведёт в МойСклад',
   str_contains($byName['Счёт 00042']['url'] ?? '', 'invoiceout/edit?id=ms-inv-1'),
   $byName['Счёт 00042']['url'] ?? '');
ok('у счёта есть печатная форма', str_contains($byName['Счёт 00042']['pdf_url'] ?? '', 'action=pdf'));

ok('заказ помнит свой запрос', ($byName['Заказ 00101']['request_id'] ?? 0) === $reqA);
ok('и второй — свой', ($byName['Заказ 00102']['request_id'] ?? 0) === $reqB);
// Счёт наследует запрос через заказ: приглушать его надо вместе с заказом
ok('счёт унаследовал запрос заказа', ($byName['Счёт 00042']['request_id'] ?? 0) === $reqA,
   (string)($byName['Счёт 00042']['request_id'] ?? 0));
ok('резерв заказа приехал вместе с ним', is_array($byName['Заказ 00101']['reserve'] ?? null));

// =====================================================================  5

echo "\n== 5. Переписки: старые сверху, и видно, чьё последнее письмо ==\n";

$boxId = Db::insert('mailboxes', ['name' => 'info', 'email' => 'info@atlant-armour.ru', 'is_active' => 1]);
$mk = function (string $key, string $when, string $dir) use ($cpId, $boxId) {
    Db::insert('mail_messages', [
        'mailbox_id' => $boxId, 'counterparty_id' => $cpId, 'thread_key' => $key,
        'message_id' => $key . $when, 'direction' => $dir, 'subject' => 'запрос счёта',
        'from_email' => $dir === 'in' ? 'kameneva.aa@novaroll.ru' : 'info@atlant-armour.ru',
        'to_emails'  => $dir === 'in' ? 'info@atlant-armour.ru' : 'kameneva.aa@novaroll.ru',
        'body_text' => 'текст', 'date_at' => $when, 'is_read' => 1,
    ]);
};
$mk('s:old', '2026-09-10 09:00:00', 'in');     // старая переписка, ответили
$mk('s:old', '2026-09-10 10:00:00', 'out');
$mk('s:new', '2026-09-15 17:09:15', 'in');     // свежая, последнее слово за клиентом
$mk('s:new', '2026-09-16 11:56:02', 'out');    // …а потом за нами

require_once ROOT . '/lib/mail_threads.php';
$threads = MailThreads::query(['counterparty_id' => $cpId, 'limit' => 50])['items'];
usort($threads, fn($a, $b) => strcmp((string)$a['last_at'], (string)$b['last_at']));
ok('переписок две', count($threads) === 2, (string)count($threads));
ok('старая сверху', $threads[0]['thread_key'] === 's:old', $threads[0]['thread_key']);
ok('свежая снизу', $threads[1]['thread_key'] === 's:new', $threads[1]['thread_key']);
ok('последнее письмо свежей переписки — наше', $threads[1]['last_direction'] === 'out',
   (string)$threads[1]['last_direction']);

// Письма ВНУТРИ переписки идут в ту же сторону: старое сверху
$messages = MailThreads::messages('s:new');
ok('внутри переписки тоже старое сверху',
   ($messages[0]['date_at'] ?? '') === '2026-09-15 17:09:15', $messages[0]['date_at'] ?? '');
ok('последнее письмо — последнее в списке',
   (end($messages)['date_at'] ?? '') === '2026-09-16 11:56:02');

// =====================================================================  6

echo "\n== 6. Ошибка доходит до администратора ==\n";

Db::q("DELETE FROM notifications");
Logger::error('moysklad', 'МойСклад не принял счёт: 502');
$n = Db::one("SELECT * FROM notifications WHERE type='app_error' ORDER BY id DESC LIMIT 1");
ok('уведомление создано', $n !== null);
ok('и адресовано администратору', (int)($n['manager_id'] ?? 0) === $mgr);
ok('в заголовке — откуда ошибка', str_contains((string)($n['title'] ?? ''), 'moysklad'), (string)($n['title'] ?? ''));
ok('в теле — текст ошибки', str_contains((string)($n['body'] ?? ''), 'не принял счёт'));
ok('ссылка ведёт в журнал ошибок', ($n['url'] ?? '') === '/#settings/logs/error', (string)($n['url'] ?? ''));

// Повтор той же ошибки внутри окна дедупликации не звонит второй раз
$before = (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='app_error'");
Logger::error('moysklad', 'МойСклад не принял счёт: 502');
ok('повтор не звонит второй раз',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='app_error'") === $before);

// Предупреждение — не ошибка: о нём не звонят
Logger::warning('mail', 'Ящик отвечает медленно');
ok('предупреждение не будит администратора',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='app_error'") === $before);

// Выключенная настройка молчит
Settings::set('NOTIFY_ERRORS', '0');
Logger::error('llm', 'Совсем другая ошибка');
ok('выключенные уведомления молчат',
   (int)Db::val("SELECT COUNT(*) FROM notifications WHERE type='app_error'") === $before);
Settings::set('NOTIFY_ERRORS', '1');

// =====================================================================  7

echo "\n== 7. Модель Yandex, которой у провайдера нет ==\n";

require_once ROOT . '/lib/llm.php';
LLM::forgetYandexModels();
LLM::init(['YANDEX_API_KEY' => 'k', 'YANDEX_FOLDER_ID' => 'b1g', 'YANDEX_MODEL' => 'gemma-3-4b-it',
           'LLM_PROVIDER_PRIORITY' => 'yandex']);

ok('непроверенный слаг не объявляется несуществующим', LLM::isKnown('yandex', 'gemma-3-4b-it'));

$catalog = LLM::catalog('yandex');
$states = array_column($catalog, 'state', 'id');
ok('каталог говорит, что слаги не проверены', ($states['gemma-3-4b-it'] ?? '') === 'unknown',
   (string)($states['gemma-3-4b-it'] ?? ''));

// Провайдер ответил «unknown model» — слаг вычёркивается, и больше не уходит
$mark = new ReflectionMethod(LLM::class, 'markYandexMissing');
$mark->setAccessible(true);
$mark->invoke(null, 'gemma-3-4b-it');

ok('вычеркнутый слаг известен как отсутствующий', !LLM::isKnown('yandex', 'gemma-3-4b-it'));

$slug = new ReflectionMethod(LLM::class, 'yandexSlug');
$slug->setAccessible(true);
ok('в запрос уходит рабочая модель, а не 404', $slug->invoke(null) === 'yandexgpt',
   (string)$slug->invoke(null));

$catalog = LLM::catalog('yandex');
$row = array_column($catalog, null, 'id')['gemma-3-4b-it'];
ok('в списке он помечен', $row['state'] === 'missing', $row['state']);
ok('и подписан по-человечески', str_contains($row['label'], 'нет в этом облаке'), $row['label']);

// Адрес модели собирается с папкой — без неё Yandex и отвечает «unknown model»
$uri = new ReflectionMethod(LLM::class, 'yandexModelUri');
$uri->setAccessible(true);
ok('адрес модели несёт folder id', $uri->invoke(null, 'b1g', 'yandexgpt') === 'gpt://b1g/yandexgpt/latest',
   (string)$uri->invoke(null, 'b1g', 'yandexgpt'));

// Проверенный слаг работает как раньше
LLM::init(['YANDEX_API_KEY' => 'k', 'YANDEX_FOLDER_ID' => 'b1g', 'YANDEX_MODEL' => 'yandexgpt-lite',
           'LLM_PROVIDER_PRIORITY' => 'yandex']);
ok('нетронутый слаг уходит как есть', $slug->invoke(null) === 'yandexgpt-lite', (string)$slug->invoke(null));

// «Забыть проверку» возвращает список к исходному
LLM::forgetYandexModels();
ok('после «забыть» слаг снова не осуждён', LLM::isKnown('yandex', 'gemma-3-4b-it'));

echo "\n" . ($fail ? "ПРОВАЛОВ: $fail\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
