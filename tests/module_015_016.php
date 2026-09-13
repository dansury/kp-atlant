<?php
/**
 * Modules 015 and 016 end to end, against a throwaway database and with no
 * network: a letter from the site form becomes the visitor's letter, a form
 * filled by a bot never reaches the model, an undelivered answer is marked as
 * one, the formats the parser used to skip are read, a line with a shop link
 * needs no matching, and the КП opens in Word.
 *
 * Run:  php tests/module_015_016.php
 */
$tmpDb = sys_get_temp_dir() . '/kp-test-' . getmypid() . '.db';
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
require_once ROOT . '/lib/site_forms.php';
require_once ROOT . '/lib/mail_text.php';
require_once ROOT . '/lib/bounce.php';
require_once ROOT . '/lib/triage.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/attachments.php';
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/docx.php';

$fail = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
    if (!$cond) $fail++;
}

Settings::set('KNOWLEDGE_ENABLED', '0');
Settings::set('VECTOR_ENABLED', '0');
Settings::set('BITRIX_ENABLED', '0');
Settings::set('REQUISITES_AUTOSYNC', '0');

// --------------------------------------------------------------- fixtures
$formHuman = 'Заполнена форма "Задать вопрос" на сайте Атлант Армор (1998)
Имя посетителя: Константин Коссов
Телефон: +7 (985) 660-91-64
Email: kossov@example-corp.ru
Интересующий товар/услуга: Баллистические шлемы
Сообщение: Здравствуйте, интересует наличие баллистических шлемов в расцветке мультикам размер M
Запрос отправлен: 25.08.2026 10:57:52
Просмотр результата на сайте: http://atlant-armour.ru/bitrix/admin/form_result_edit.php?lang=ru&WEB_FORM_ID=6&RESULT_ID=1998';

$formPhoneOnly = 'Заполнена форма "Задать вопрос" на сайте Атлант Армор (1777)
Имя посетителя: Денис
Телефон: +7 (910) 741-15-77
Email:
Интересующий товар/услуга: Опт/Сотрудничество
Сообщение: Передайте мой контакт оптовому менеджеру
Запрос отправлен: 25.08.2026 10:57:52';

$formBot = 'Заполнена форма "Задать вопрос" на сайте Атлант Армор (1635)
Имя посетителя: 1
Телефон: 1
Email: testing@example.com: mailto:testing@example.com
Интересующий товар/услуга: 1
Сообщение: -1) OR 418=(SEL ECT 418 FR OM PG_SLEEP(15))--
Запрос отправлен: 01.08.2026 12:19:49';

$formOrder = 'Заполнена форма "Задать вопрос по заказу" на сайте Атлант Армор (1999)
Имя посетителя: Даниил Лазня
Телефон: +7 (918) 066-42-12
Email: laznyadan@mail.ru
Интересующий заказ: 7150
Сообщение: Подскажите номер заказа в СДЭК, пожалуйста
Запрос отправлен: 25.08.2026 21:11:36';

echo "\n1. Письмо с формы сайта — это письмо посетителя\n";
$f = SiteForm::parse($formHuman);
ok('форма распознана', $f !== null && $f['form'] === 'Задать вопрос');
ok('email посетителя вынут из тела', ($f['email'] ?? '') === 'kossov@example-corp.ru', (string)($f['email'] ?? ''));
ok('телефон вынут', ($f['phone'] ?? '') === '+7 (985) 660-91-64', (string)($f['phone'] ?? ''));
ok('сообщение вынуто целиком', str_contains((string)($f['message'] ?? ''), 'расцветке мультикам размер M'));
ok('ссылка на админку сайта в поля не попала', !str_contains(implode(' ', array_map('strval', $f)), 'form_result_edit'));

$msg = SiteForm::unwrap([
    'from' => 'atlant@atlant-armour.ru', 'from_name' => 'Атлант Армор',
    'subject' => 'Новый вопрос с сайта', 'body' => $formHuman,
]);
ok('отправителем стал посетитель', $msg['from'] === 'kossov@example-corp.ru', $msg['from']);
ok('тема говорит, о чём вопрос', str_starts_with($msg['subject'], 'Вопрос с сайта: '), $msg['subject']);
ok('канал письма помечен', ($msg['source_channel'] ?? '') === 'site_form');
ok('в теле остался вопрос клиента', str_contains($msg['body'], 'мультикам'));
ok('и данные формы рядом', str_contains($msg['body'], 'Телефон: +7 (985) 660-91-64'));

$order = SiteForm::unwrap(['from' => 'atlant@atlant-armour.ru', 'subject' => 'Новый вопрос по заказу с сайта', 'body' => $formOrder]);
ok('номер заказа попал в тему', str_contains($order['subject'], '7150'), $order['subject']);

echo "\n2. Две заявки с одной темой — две переписки\n";
$k1 = MailThreads::keyFor(['subject' => $msg['subject'], 'direction' => 'in', 'from_email' => $msg['from']]);
$other = SiteForm::unwrap(['from' => 'atlant@atlant-armour.ru', 'subject' => 'Новый вопрос с сайта',
                           'body' => str_replace('kossov@example-corp.ru', 'other@zavod.ru', $formHuman)]);
$k2 = MailThreads::keyFor(['subject' => $other['subject'], 'direction' => 'in', 'from_email' => $other['from']]);
ok('ключи цепочек различаются', $k1 !== $k2);

echo "\n3. Заявка без адреса — это звонок, а не черновик\n";
$call = SiteForm::unwrap(['from' => 'atlant@atlant-armour.ru', 'subject' => 'Новый вопрос с сайта', 'body' => $formPhoneOnly]);
ok('помечена как «нужен звонок»', (int)($call['needs_call'] ?? 0) === 1);
ok('отправитель не подменён — отвечать некуда', $call['from'] === 'atlant@atlant-armour.ru');
ok('тема уникальна по номеру заявки', str_contains($call['subject'], '№1777'), $call['subject']);
ok('у категории callback нет промпта ответа', Triage::route('callback')[0] === null);
ok('но запрос она заводит', Triage::createsRequest('callback'));

echo "\n4. Форму заполнил бот\n";
$bot = SiteForm::parse($formBot);
ok('спам распознан', SiteForm::spamReason($bot) !== null, (string)SiteForm::spamReason($bot));
$botMsg = SiteForm::unwrap(['from' => 'atlant@atlant-armour.ru', 'subject' => 'Новый вопрос с сайта', 'body' => $formBot]);
ok('отправитель бота не подменяется', $botMsg['from'] === 'atlant@atlant-armour.ru');
$verdict = Triage::prefilter(['subject' => 'Новый вопрос с сайта', 'body_text' => $formBot,
                              'from_email' => 'atlant@atlant-armour.ru', 'headers' => '']);
ok('префильтр отсекает до вызова модели', ($verdict['category'] ?? '') === 'spam', json_encode($verdict, JSON_UNESCAPED_UNICODE));
ok('человек с формы префильтр проходит',
   Triage::prefilter(['subject' => 'Вопрос с сайта: наличие', 'body_text' => $formHuman,
                      'from_email' => 'kossov@example-corp.ru', 'headers' => '']) === null);

echo "\n5. HTML-письмо без text/plain\n";
$css = '<html><head><style>@media only screen{html{min-height:100%;background:#f7f7f7}}</style></head>'
     . '<body><p>Добрый день!</p><p>Нужен счёт на 5 шлемов.</p></body></html>';
$text = MailText::fromHtml($css);
ok('CSS в текст не попал', !str_contains($text, 'min-height'), mb_substr($text, 0, 40));
ok('текст письма остался', str_contains($text, 'Нужен счёт на 5 шлемов'));

echo "\n6. Цитата и баннер не идут в промпт\n";
$quoted = "Добрый день! Пришлите счёт на 3 шлема.\n\nвт, 25 авг. 2026 г. в 21:11, <atlant@atlant-armour.ru>:\n> Ваш заказ принят\n> Сумма 100 000";
ok('цитата отрезана', !str_contains(MailText::forAnalysis($quoted), 'Сумма 100 000'));
ok('свой текст остался', str_contains(MailText::forAnalysis($quoted), 'Пришлите счёт на 3 шлема'));
ok('баннер шлюза убран',
   !str_contains(MailText::forAnalysis("ВНЕШНЯЯ ПОЧТА: если отправитель неизвестен, не открывайте\nПрошу счёт"), 'ВНЕШНЯЯ ПОЧТА'));
$onlyQuote = "> Здравствуйте, вот наш запрос\n> Шлем Термит 5 шт";
ok('письмо из одной цитаты не теряется', str_contains(MailText::forAnalysis($onlyQuote), 'Шлем Термит'));

echo "\n7. Недоставленный ответ\n";
Db::insert('mailboxes', ['name' => 'Основной', 'email' => 'info@atlant-armour.ru', 'is_active' => 1, 'is_default' => 1,
                         'imap_host' => 'imap.example.com', 'imap_user' => 'info@atlant-armour.ru']);
$outId = Db::insert('mail_messages', [
    'mailbox_id' => 1, 'direction' => 'out', 'folder' => 'SENT', 'uid' => 0,
    'message_id' => '<answer-1@atlant>', 'subject' => 'Счёт на оплату',
    'from_email' => 'info@atlant-armour.ru', 'to_emails' => 'buyer@zavod.ru',
    'body_text' => 'Счёт во вложении', 'date_at' => date('Y-m-d H:i:s'), 'sent_state' => 'appended',
]);
$report = Bounce::detect([
    'from' => 'mailer-daemon@googlemail.com', 'subject' => 'Delivery Status Notification (Failure)',
    'headers' => "Content-Type: multipart/report; report-type=delivery-status\n",
    'body' => "Final-Recipient: rfc822; buyer@zavod.ru\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 no such user\nMessage-ID: <answer-1@atlant>",
]);
ok('отчёт о недоставке распознан', $report !== null);
ok('адрес получателя вынут', ($report['recipient'] ?? '') === 'buyer@zavod.ru');
ok('ошибка постоянная', (bool)($report['permanent'] ?? false));
$marked = Bounce::applyTo($report);
ok('помечено именно то письмо', $marked && (int)$marked['id'] === $outId);
ok('состояние отправки стало bounced',
   Db::val("SELECT sent_state FROM mail_messages WHERE id=?", [$outId]) === 'bounced');
ok('обычное письмо клиента отчётом не считается',
   Bounce::detect(['from' => 'buyer@zavod.ru', 'subject' => 'Письмо не доставлено до склада',
                   'headers' => '', 'body' => 'Курьер не доехал']) === null);

echo "\n8. Один адрес для всех исходящих\n";
Db::insert('mailboxes', ['name' => 'Gmail', 'email' => 'atlantarmourmed@gmail.com', 'is_active' => 1, 'is_default' => 0,
                         'imap_host' => 'imap.gmail.com', 'imap_user' => 'atlantarmourmed@gmail.com']);
Settings::set('MAIL_OUTGOING_FROM', 'info@atlant-armour.ru');
$box = Mailboxes::outgoing(2, null);
ok('ответ уходит с общего адреса, а не из выбранного ящика',
   $box && $box['email'] === 'info@atlant-armour.ru', (string)($box['email'] ?? ''));
Settings::set('MAIL_OUTGOING_FROM', '');
ok('без настройки работает прежний выбор ящика', (int)(Mailboxes::outgoing(2, null)['id'] ?? 0) === 2);
Settings::set('MAIL_OUTGOING_FROM', 'info@atlant-armour.ru');
ok('наш собственный адрес узнаётся', Crm::isOurAddress('info@atlant-armour.ru'));
ok('адрес клиента — не наш', !Crm::isOurAddress('buyer@zavod.ru'));

echo "\n9. Форматы вложений, которые раньше пропускались\n";
$rtf = "{\\rtf1\\ansi\\ansicpg1251\\deff0{\\fonttbl{\\f0 Arial;}}\\f0 \\'CA\\'E0\\'F0\\'F2\\'EE\\'F7\\'EA\\'E0\\par \\'C8\\'CD\\'CD 7810964292\\par}";
$rtfPath = sys_get_temp_dir() . '/kp-test-card.rtf';
file_put_contents($rtfPath, $rtf);
[$rtfText, $rtfStatus] = Attachments::extractText($rtfPath, 'application/rtf', 'Карточка.rtf');
ok('RTF читается', str_contains($rtfText, 'ИНН 7810964292'), $rtfText);
@unlink($rtfPath);

$docPath = sys_get_temp_dir() . '/kp-test-card.doc';
file_put_contents($docPath, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 120) . 'WordDocument'
    . str_repeat("\x00", 32) . mb_convert_encoding('Карта партнёра ООО Элтемикс Лаб  ИНН 7729655053  КПП 772901001', 'UTF-16LE', 'UTF-8'));
[$docText] = Attachments::extractText($docPath, 'application/msword', 'Карта партнера.doc');
ok('старый .doc больше не пропускается', str_contains($docText, 'Карта партнёра'), mb_substr($docText, 0, 60));
@unlink($docPath);

echo "\n10. Реквизиты из вложенной карточки предприятия\n";
$cpId = Db::insert('counterparties', ['name' => 'ООО Элтемикс Лаб']);
Crm::fillRequisitesFromAttachments($cpId, [[
    'filename' => 'Карта партнера ООО Элтемикс лаб.doc',
    'extracted_text' => "Полное наименование: ООО «Элтемикс Лаб»\nИНН 7729655053 КПП 772901001\nОГРН 1127746215230\nЮридический адрес: 117218, г. Москва, ул. Кржижановского, д. 29\nТел: +7 495 000-00-00",
]]);
$cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$cpId]);
ok('ИНН заполнен из файла', ($cp['inn'] ?? '') === '7729655053', (string)($cp['inn'] ?? ''));
ok('КПП заполнен', ($cp['kpp'] ?? '') === '772901001');
ok('юридический адрес заполнен', str_contains((string)($cp['legal_address'] ?? ''), 'Кржижановского'));

echo "\n11. ЭДО из подписи клиента\n";
Crm::rememberEdo($cpId, 'Мы работаем с ЭДО. Идентификатор участника ЭДО (GUID) КонтурДиадок '
    . '2BM-7727473370-772701001-202111290821548642984. Большая просьба отправлять документы на бумажном носителе.', null);
$cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$cpId]);
ok('идентификатор ЭДО сохранён', str_starts_with((string)($cp['edo_id'] ?? ''), '2BM-7727473370'), (string)($cp['edo_id'] ?? ''));
ok('оператор распознан', ($cp['edo_operator'] ?? '') === 'Диадок', (string)($cp['edo_operator'] ?? ''));
ok('бумажные дубли отмечены', (int)($cp['needs_paper_docs'] ?? 0) === 1);

echo "\n12. Номер заказа из темы письма\n";
Db::insert('orders', ['moysklad_id' => 'ms-order-1', 'name' => '6764', 'counterparty_id' => $cpId,
                      'state_name' => 'Собран', 'sum' => 100000, 'moment' => date('Y-m-d H:i:s')]);
$found = Catalog::orders('Атлант Армор: Новый заказ N6764', '');
ok('«Новый заказ N6764» находит заказ', count($found) === 1 && $found[0]['name'] === '6764');

echo "\n13. Ссылка на карточку товара вместо подбора\n";
Db::q("INSERT INTO products_cache (moysklad_id, name, name_normalized, article, price, stock, reserved, unit, site_url, vat, product_type, source, updated_at)
       VALUES ('ms-h1','Баллистический шлем Термит Арамид','баллистический шлем термит арамид','TERM',34900,5,0,'шт.',
               'https://atlant-armour.ru/catalog/sredstva-zashchity/shlemyQGM/ballisticheskiy-shlem-termit-aramid/',5,'product','test',datetime('now'))");
ProductMatcher::forgetCatalog();
$byLink = ProductMatcher::byShopLink('нужно 7 шт https://atlant-armour.ru/catalog/sredstva-zashchity/shlemyQGM/ballisticheskiy-shlem-termit-aramid/?oid=6018');
ok('позиция найдена по ссылке', $byLink !== null && $byLink['moysklad_id'] === 'ms-h1');
ok('источник совпадения назван', ($byLink['source'] ?? '') === 'site_url');
ok('чужая ссылка ничего не находит', ProductMatcher::byShopLink('смотрите https://example.com/x') === null);

echo "\n14. КП открывается в Word\n";
$docxPath = sys_get_temp_dir() . '/kp-test-' . getmypid() . '.docx';
(new Html2Docx())->write(
    '<html><body><div class="title">Коммерческое предложение</div>'
    . '<table class="items"><thead><tr><th>№</th><th>Наименование</th><th>Кол-во</th><th>Цена</th></tr></thead>'
    . '<tbody><tr><td>1</td><td>Шлем Термит Арамид<br><span class="stock-warning">под заказ</span></td><td>7</td><td>34 900,00 руб.</td></tr>'
    . '<tr><td colspan="4">Итого: 244 300,00 руб.</td></tr></tbody></table>'
    . '<ul class="fits"><li>соответствует: класс Бр2</li></ul></body></html>',
    $docxPath, ['title' => 'КП']
);
ok('файл создан', is_file($docxPath) && filesize($docxPath) > 1000);
$zip = new ZipArchive();
ok('это валидный zip-пакет', $zip->open($docxPath) === true);
$docXml = $zip->getFromName('word/document.xml');
ok('обязательные части на месте',
   $zip->getFromName('[Content_Types].xml') !== false && $zip->getFromName('word/_rels/document.xml.rels') !== false
   && $zip->getFromName('word/styles.xml') !== false && $docXml !== false);
$zip->close();
ok('document.xml — корректный XML', simplexml_load_string((string)$docXml) !== false);
ok('таблица позиций в документе есть', str_contains((string)$docXml, '<w:tbl>'));
ok('текст позиции на месте', str_contains((string)$docXml, 'Шлем Термит Арамид'));
ok('объединённая ячейка итога размечена', str_contains((string)$docXml, '<w:gridSpan w:val="4"/>'));
ok('CSS шаблона в документ не попал', !str_contains((string)$docXml, 'font-size'));
@unlink($docxPath);

echo "\n15. Письмо проходит весь путь в архив уже развёрнутым\n";
$box = Db::one("SELECT * FROM mailboxes WHERE id=1");
$stored = MailArchive::storeIncoming($box, [
    'uid' => 501, 'folder' => 'INBOX', 'message_id' => '<form-1@site>',
    'from' => 'atlant@atlant-armour.ru', 'from_name' => 'Атлант Армор',
    'to' => 'info@atlant-armour.ru', 'subject' => 'Новый вопрос с сайта',
    'body' => $formHuman, 'date' => date('Y-m-d H:i:s'),
], 'in');
$row = Db::one("SELECT * FROM mail_messages WHERE id=?", [$stored]);
ok('в архиве отправитель — посетитель', ($row['from_email'] ?? '') === 'kossov@example-corp.ru', (string)($row['from_email'] ?? ''));
ok('канал записан', ($row['source_channel'] ?? '') === 'site_form');
ok('поля формы сохранены', str_contains((string)($row['form_json'] ?? ''), 'Коссов'));

$stored2 = MailArchive::storeIncoming($box, [
    'uid' => 502, 'folder' => 'INBOX', 'message_id' => '<form-2@site>',
    'from' => 'atlant@atlant-armour.ru', 'subject' => 'Новый вопрос с сайта',
    'body' => $formOrder, 'date' => date('Y-m-d H:i:s'),
], 'in');
$row2 = Db::one("SELECT * FROM mail_messages WHERE id=?", [$stored2]);
ok('две заявки — две цепочки, а не одна',
   ($row['thread_key'] ?? 'a') !== ($row2['thread_key'] ?? 'b'));
ok('и обе не привязаны к нашему домену',
   !Crm::isOurAddress((string)$row['from_email']) && !Crm::isOurAddress((string)$row2['from_email']));

echo "\n" . ($fail ? "$fail FAILED\n" : "ВСЁ ЗЕЛЁНОЕ\n");
exit($fail ? 1 : 0);
