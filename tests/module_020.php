<?php
/**
 * Module 020 end to end, against a throwaway database and with no network:
 * переписка, которую прочитал человек, а не экран; лента заметок без писем;
 * описание товара разметкой вместо тегов; позиция, свёрнутая как «нет в
 * наличии»; и логотип слева вверху КП.
 *
 * Run:  php tests/module_020.php
 *
 * It builds its own database in the system temp directory — `data/kp.db` is
 * never opened, so running this on a server cannot touch live data.
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-020-' . getmypid() . '.db';
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
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/mail_threads.php';
require_once ROOT . '/lib/markup.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/requisites.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('TRIAGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');
Settings::set('KP_QR_CODE', '0');

echo "\n== HTML из МойСклад становится Markdown, Markdown — документом ==\n";

$fromMoySklad = '<ul><li>Класс защиты Бр1.</li><li>Материал - СВМПЭ.</li>'
              . '<li>Вес 1,4-1,5&nbsp;кг.</li><li>Подвесная система подгоняется <b>индивидуально</b>.</li></ul>'
              . '<p>Шлем поставляется в чехле.</p>';
$md = Markup::toMarkdown($fromMoySklad);

ok('тегов в тексте не осталось', !str_contains($md, '<li>') && !str_contains($md, '<p>'), $md);
ok('список остался списком', str_contains($md, '- Класс защиты Бр1.'), $md);
ok('жирный стал разметкой', str_contains($md, '**индивидуально**'), $md);
ok('неразрывный пробел стал пробелом', str_contains($md, '1,4-1,5 кг.'), $md);
ok('абзац за списком уцелел', str_contains($md, 'Шлем поставляется в чехле.'), $md);
ok('повторный прогон ничего не меняет', Markup::toMarkdown($md) === $md);

$plain = "Ширина < 40 мм\nВес 2 кг";
ok('обычный текст не трогается', Markup::toMarkdown($plain) === $plain, Markup::toMarkdown($plain));

$html = Markup::markdownToHtml($md);
ok('в документ уходит настоящий список', str_contains($html, '<ul><li>Класс защиты Бр1.</li>'), $html);
ok('жирный вернулся тегом', str_contains($html, '<b>индивидуально</b>'), $html);
ok('«<» из текста экранирован, а не стал тегом',
   str_contains(Markup::markdownToHtml($plain), 'Ширина &lt; 40 мм'), Markup::markdownToHtml($plain));
ok('javascript-ссылка в КП не попадает',
   !str_contains(Markup::markdownToHtml('[тык](javascript:alert(1))'), 'javascript:'),
   Markup::markdownToHtml('[тык](javascript:alert(1))'));
ok('обычная ссылка попадает',
   str_contains(Markup::markdownToHtml('[сайт](https://atlant-armour.ru)'), '<a href="https://atlant-armour.ru">'));

// Заголовок раздела, завёрнутый в <p>, раньше не находился и весь текст уезжал
// в «описание» одним куском вместе с тегами
$split = KpContent::splitDescription(
    '<p>Шлем общевойсковой.</p><p>Характеристики:</p><ul><li>Бр2</li></ul><p>Комплектация:</p><ul><li>Чехол</li></ul>'
);
ok('описание отделилось', $split['description'] === 'Шлем общевойсковой.', $split['description']);
ok('характеристики отделились', $split['specs'] === '- Бр2', $split['specs']);
ok('комплектация отделилась', $split['included'] === '- Чехол', $split['included']);

echo "\n== Открытая переписка ещё не прочитанная ==\n";

$box = Db::insert('mailboxes', ['name' => 'Яндекс', 'email' => 'info@atlant-armour.ru',
                                'is_active' => 1, 'is_default' => 1, 'imap_host' => 'imap.yandex.ru']);
$cpId = Crm::resolveCounterparty(['inn' => '7701234567', 'name' => 'ООО «Техно»',
                                  'email' => 'zakup@techno.ru', 'contact_person' => 'Пётр']);

function letter020(int $box, int $cp, string $subject, string $dir, string $date, string $body): int {
    $id = Db::insert('mail_messages', [
        'mailbox_id' => $box, 'direction' => $dir, 'folder' => $dir === 'in' ? 'INBOX' : 'Sent', 'uid' => 0,
        'subject' => $subject,
        'from_email' => $dir === 'in' ? 'zakup@techno.ru' : 'info@atlant-armour.ru',
        'to_emails'  => $dir === 'in' ? 'info@atlant-armour.ru' : 'zakup@techno.ru',
        'body_text' => $body, 'is_read' => 0, 'date_at' => $date, 'counterparty_id' => $cp,
    ]);
    MailThreads::assign($id);
    return $id;
}

$in1 = letter020($box, $cpId, 'Запрос КП на шлемы', 'in',  '2026-09-01 10:00:00', 'Нужны шлемы, 20 шт.');
$key = (string)Db::val("SELECT thread_key FROM mail_messages WHERE id=?", [$in1]);

$summary = MailThreads::summary($key);
ok('свежая переписка непрочитана', (int)$summary['unread'] === 1);
ok('и она ждёт ответа', $summary['last_direction'] === 'in');

// Карточка компании раскрывает её сама: письма читаются, отметка не ставится
$messages = MailThreads::messages($key);
ok('письма отдаются без отметки', (int)Db::val("SELECT is_read FROM mail_messages WHERE id=?", [$in1]) === 0,
   'messages() сама ничего не помечает; ' . count($messages) . ' письмо');
ok('счётчик непрочитанных не поехал', MailThreads::unreadCount() === 1);

// Явное раскрытие — вот теперь прочитано
MailThreads::markRead($key);
ok('нажатие менеджера ставит отметку', (int)Db::val("SELECT is_read FROM mail_messages WHERE id=?", [$in1]) === 1);
ok('и счётчик обнулился', MailThreads::unreadCount() === 0);

echo "\n== Письма — в переписке, лента — заметки и вехи ==\n";

// Так их кладёт синхронизация почты и «Отправить»
Crm::logEvent($cpId, 'in',  'Нужны шлемы, 20 шт.', ['subject' => 'Запрос КП на шлемы', 'email_from' => 'zakup@techno.ru']);
Crm::logEvent($cpId, 'out', 'Высылаем КП во вложении.', ['subject' => 'КП', 'email_to' => 'zakup@techno.ru',
                                                          'event_type' => 'kp_sent']);
Crm::logEvent($cpId, 'note', 'Просили счёт на ООО, не на ИП', ['subject' => 'Заметка']);
Crm::logEvent($cpId, 'note', 'Счёт 123 выставлен в МойСклад', ['event_type' => 'invoice_created', 'subject' => 'Счёт 123']);

$feed = Crm::chat($cpId);
$kinds = array_count_values(array_column($feed, 'kind'));
ok('письмо из ленты ушло', !isset($kinds['letter']), json_encode($kinds));
ok('заметка осталась', ($kinds['note'] ?? 0) === 1, json_encode($kinds));
ok('вехи остались', ($kinds['event'] ?? 0) === 2, json_encode($kinds));
ok('счётчик ленты считает то же самое', Crm::chatCount($cpId) === 3, (string)Crm::chatCount($cpId));
ok('вся история по-прежнему доступна', Crm::chatCount($cpId, true) === 4, (string)Crm::chatCount($cpId, true));
ok('письмо никуда не делось из базы',
   (int)Db::val("SELECT COUNT(*) FROM correspondence WHERE counterparty_id=? AND direction='in'", [$cpId]) === 1);

// Ответ клиенту по-прежнему снимает «ждёт ответа» — лента сузилась, учёт нет
$state = Crm::answerState(
    Db::val("SELECT last_inbound_at FROM counterparties WHERE id=?", [$cpId]),
    Db::val("SELECT last_outbound_at FROM counterparties WHERE id=?", [$cpId])
);
ok('отвеченная компания не подсвечена', !$state['unanswered'], json_encode($state));

echo "\n== Свёрнутая позиция: не в таблице, но и не молча ==\n";

Db::insert('legal_entities', [
    'is_active' => 1, 'short_name' => 'ООО «Атлант»', 'full_name' => 'ООО «Атлант Армор»',
    'inn' => '7799001122', 'city' => 'Москва', 'address' => 'ул. Заводская, 1',
    'email' => 'info@atlant-armour.ru', 'phone' => '+7 495 000-00-00', 'signatory_name' => 'Иванов И.И.',
]);

$requestId = Db::insert('requests', ['source' => 'email', 'raw_text' => 'Нужны шлемы, 20 шт. И топор пожарный поясной, 5 шт.',
                                     'counterparty_id' => $cpId, 'status' => 'processing', 'type' => 'kp']);
$proposalId = Db::insert('proposals', ['request_id' => $requestId, 'counterparty_id' => $cpId,
                                       'status' => 'draft', 'vat_rate' => 5, 'execution_days' => 30]);

$helmet = Db::insert('proposal_items', [
    'proposal_id' => $proposalId, 'position' => 1, 'product_name' => 'Шлем Протон СВМПЭ',
    'requested_name' => 'Шлем баллистический', 'moysklad_product_id' => 'ms-1',
    'unit' => 'шт.', 'quantity' => 20, 'price' => 39000, 'stock_available' => 25, 'stock_reserved' => 0,
    'description_text' => $fromMoySklad,   // ещё с тегами: миграция их перепишет
]);
$axe = Db::insert('proposal_items', [
    'proposal_id' => $proposalId, 'position' => 2, 'product_name' => 'Топор пожарный поясной',
    'requested_name' => 'Топор пожарный поясной', 'moysklad_product_id' => 'ms-2',
    'unit' => 'шт.', 'quantity' => 5, 'price' => 2000, 'stock_available' => 0, 'stock_reserved' => 0,
]);

$before = KpContent::priceGaps($proposalId);
ok('пока обе позиции считаются', abs($before['total'] - (20 * 39000 + 5 * 2000)) < 0.01, (string)$before['total']);

// Топора у нас нет — менеджер сворачивает позицию
Db::update('proposal_items', ['is_excluded' => 1], 'id=?', [$axe]);

$printed = KpContent::printedItems($proposalId);
ok('в документ идёт одна позиция', count($printed) === 1, (string)count($printed));
ok('и это шлем', (int)$printed[0]['id'] === $helmet);

$after = KpContent::priceGaps($proposalId);
ok('«Итого» пересчиталось без неё', abs($after['total'] - 20 * 39000) < 0.01, (string)$after['total']);
ok('КП не считается пустым', !$after['empty']);

$unmatched = KpContent::unmatchedRows($proposalId);
ok('свёрнутая позиция названа отдельным блоком', count($unmatched) === 1, json_encode($unmatched, JSON_UNESCAPED_UNICODE));
ok('словами клиента', $unmatched[0]['requested'] === 'Топор пожарный поясной');
ok('и помечена как свёрнутая, а не как ненайденная', $unmatched[0]['excluded'] === true);

$rows = KpContent::matchTableRows($proposalId);
ok('строка запроса из таблицы соответствия не пропала', count($rows) === 2, (string)count($rows));
$axeRow = $rows[1];
ok('в ответе по ней стоит «уточняем»', $axeRow['availability'] === 'уточняем', $axeRow['availability']);
ok('и товара мы там не обещаем', $axeRow['offered'] === '', $axeRow['offered']);

echo "\n== Документ: разметка, свёрнутая позиция и логотип ==\n";

// Миграция уже прогналась на пустой базе, поэтому описание с тегами дописано
// выше вручную — приводим его тем же способом, каким это делает сохранение
Db::update('proposal_items', ['description_text' => Markup::toMarkdown($fromMoySklad)], 'id=?', [$helmet]);
Settings::set('KP_MATCH_TABLE', 'always');
Requisites::freeze($proposalId);

$doc = PdfGenerator::html($proposalId);

ok('описание напечаталось списком', str_contains($doc, '<li>Класс защиты Бр1.</li>'), '');
ok('тегов из МойСклад в документе нет', !str_contains($doc, '&lt;li&gt;'), '');
ok('шлем в таблице есть', str_contains($doc, 'Шлем Протон СВМПЭ'));
ok('топора в ценовой таблице нет',
   !preg_match('#<td class="sum">[^<]*2\s?000,00#u', $doc), '');
ok('но в блоке уточнений он назван',
   str_contains($doc, 'Позиции запроса, по которым нужно уточнение') && str_contains($doc, 'Топор пожарный поясной'));
ok('итого посчитано по одной позиции', str_contains($doc, '780 000 руб.'), '');

ok('встроенный логотип лежит в репозитории', is_file(ROOT . '/public/assets/img/logo-default.png'));
ok('и печатается слева вверху',
   str_contains($doc, '<img src="data:image/png;base64,') && str_contains($doc, 'class="logo"'), '');

// Пустой `logo_path` в базе — не причина печатать КП без знака
Db::q("UPDATE legal_entities SET logo_path='' WHERE is_active=1");
ok('пустой путь в настройках не отменяет логотип',
   str_contains(PdfGenerator::html($proposalId), 'class="logo"'));

echo "\n== Тот же документ в Word ==\n";
require_once ROOT . '/lib/docx.php';
$docxPath = DocxGenerator::generate($proposalId);
$zip = new ZipArchive();
ok('пакет .docx открывается', $zip->open($docxPath) === true);
$docXml = (string)$zip->getFromName('word/document.xml');
$media = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string)$zip->getNameIndex($i);
    if (str_starts_with($name, 'word/media/')) $media[] = $name;
}
$zip->close();
ok('document.xml — корректный XML', simplexml_load_string($docXml) !== false);
ok('описание в Word — пункты списка, а не теги',
   str_contains($docXml, 'Класс защиты Бр1.') && !str_contains($docXml, '&lt;li&gt;'));
ok('логотип вложен в пакет картинкой', count($media) >= 1, implode(', ', $media));
ok('свёрнутая позиция названа и в Word', str_contains($docXml, 'Топор пожарный поясной'));
@unlink($docxPath);

echo "\n== Миграция переписывает описания, которые уже лежат в КП ==\n";

$legacy = Db::insert('proposal_items', [
    'proposal_id' => $proposalId, 'position' => 3, 'product_name' => 'Наколенники',
    'unit' => 'шт.', 'quantity' => 1, 'price' => 1000,
    'specs_text' => '<ul><li>Полимер</li><li>Размер один</li></ul>',
]);
Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '21')");
runMigrations();
$fixed = (string)Db::val("SELECT specs_text FROM proposal_items WHERE id=?", [$legacy]);
ok('старые характеристики переписаны в разметку', $fixed === "- Полимер\n- Размер один", $fixed);
ok('колонка «свёрнуто» на месте после повторного прогона',
   (int)Db::val("SELECT is_excluded FROM proposal_items WHERE id=?", [$axe]) === 1);

echo "\n" . ($fail ? "FAILED: $fail\n" : "Все проверки прошли\n");
exit($fail ? 1 : 0);
