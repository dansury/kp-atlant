<?php
/**
 * API: mail — the archive of every incoming and outgoing message, and sending
 * from the mailboxes the manager has access to (module 004).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/mailsync.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/triage.php';
require_once ROOT . '/lib/mail_threads.php';
require_once ROOT . '/lib/attachments.php';
require_once ROOT . '/lib/outbox.php';
require_once ROOT . '/lib/drafts.php';

$manager = requireAuth();
$action  = $_GET['action'] ?? '';
$input   = in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true) ? getInput() : [];

try {
    switch ($action) {

        case 'list':
            $data = MailArchive::query([
                'mailbox_id'      => $_GET['mailbox_id'] ?? null,
                'direction'       => $_GET['direction'] ?? null,
                'counterparty_id' => $_GET['counterparty_id'] ?? null,
                'unread'          => !empty($_GET['unread']),
                'category'        => $_GET['category'] ?? null,
                'q'               => trim((string)($_GET['q'] ?? '')),
                // «all» — поиск по всему архиву разом: менеджер ищет письмо,
                // а не раздел, в который оно попало (модуль 023)
                'archived'        => ($_GET['archived'] ?? '') === 'all' ? 'all' : !empty($_GET['archived']),
                'limit'           => $_GET['limit'] ?? 50,
                'offset'          => $_GET['offset'] ?? 0,
            ]);
            $data['mailboxes'] = array_map(
                fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'email' => $b['email'], 'last_error' => $b['last_error']],
                Mailboxes::forManager($manager)
            );
            jsonData($data);

        // ---- Threads (module 010): one conversation, every mailbox ----

        case 'threads':
            $data = MailThreads::query([
                'mailbox_id'      => $_GET['mailbox_id'] ?? null,
                'direction'       => $_GET['direction'] ?? null,
                'counterparty_id' => $_GET['counterparty_id'] ?? null,
                'unread'          => !empty($_GET['unread']),
                'category'        => $_GET['category'] ?? null,
                'q'               => trim((string)($_GET['q'] ?? '')),
                // «all» — поиск по всему архиву разом: менеджер ищет письмо,
                // а не раздел, в который оно попало (модуль 023)
                'archived'        => ($_GET['archived'] ?? '') === 'all' ? 'all' : !empty($_GET['archived']),
                'limit'           => $_GET['limit'] ?? 50,
                'offset'          => $_GET['offset'] ?? 0,
            ]);
            $data['mailboxes'] = array_map(
                fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'email' => $b['email'], 'last_error' => $b['last_error']],
                Mailboxes::forManager($manager)
            );
            jsonData($data);

        case 'thread':
            $key = trim((string)($_GET['key'] ?? ''));
            if ($key === '') jsonError('Не указана цепочка');
            // Цепочка целиком в архиве всё равно открывается — иначе из вкладки
            // «Архив» некуда нажать
            $summary = MailThreads::summary($key);
            // Архивную цепочку открываем целиком — иначе из вкладки «Архив»
            // некуда нажать. В рабочей переписке архивных писем не показываем:
            // выключенный ящик уходит с экрана вместе со своими письмами
            $archivedThread = false;
            if (!$summary) {
                $summary = MailThreads::summary($key, true);
                $archivedThread = (bool)$summary;
            }
            if (!$summary) jsonError('Цепочка не найдена', 404);
            $messages = MailThreads::messages($key, $archivedThread || !empty($_GET['archived']));
            // HTML is sanitized (allowlist, no scripts, no remote stylesheets) and
            // rendered client-side inside a sandboxed iframe — plain text stays the
            // fallback for a letter that has no HTML part at all
            foreach ($messages as &$m) {
                $safe = MailArchive::sanitizeHtml((string)($m['body_html'] ?? ''));
                if ($safe !== '') $m['body_html'] = $safe; else unset($m['body_html']);
            }
            unset($m);
            // Прочитанной переписку делает менеджер, а не экран (модуль 020).
            // Карточка компании раскрывает свежую переписку сама, чтобы письмо
            // было видно без нажатия, — и это раскрытие снимало «непрочитано»
            // со всей цепочки ещё до того, как её кто-нибудь прочёл. Отметка
            // ставится только тогда, когда её попросили: `read=1`.
            if (!empty($_GET['read'])) MailThreads::markRead($key);
            // Контрагент письма: есть ли он у нас, есть ли он в МойСклад и с
            // каким ИНН его туда заводить (модуль 033)
            $lastIn = null;
            foreach ($messages as $m) if (($m['direction'] ?? '') === 'in') $lastIn = $m;

            jsonData([
                'thread'    => $summary,
                'messages'  => $messages,
                'reply'     => MailThreads::replyContext($key),
                'moysklad'  => Crm::moyskladHint(
                    !empty($summary['counterparty_id']) ? (int)$summary['counterparty_id'] : null,
                    Crm::letterText($lastIn),
                    ['name' => (string)($lastIn['real_from_name'] ?? $lastIn['from_name'] ?? ''),
                     'email' => (string)($lastIn['real_from_email'] ?? $lastIn['from_email'] ?? '')]
                ),
                'mailboxes' => array_map(
                    fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'email' => $b['email']],
                    Mailboxes::forManager($manager)
                ),
            ]);

        /**
         * «Прочитано» отдельным действием (модуль 020): карточка компании
         * раскрывает переписку сама, а отметку ставит человек — когда
         * действительно её открыл.
         */
        case 'thread_read':
            $key = trim((string)($input['key'] ?? $_GET['key'] ?? ''));
            if ($key === '') jsonError('Не указана цепочка');
            MailThreads::markRead($key);
            jsonOk(['unread' => MailThreads::unreadCount()]);

        case 'get':
            $msg = MailArchive::get((int)($_GET['id'] ?? 0));
            if (!$msg) jsonError('Not found', 404);
            unset($msg['headers']);   // raw headers never need to reach the browser
            $safeHtml = MailArchive::sanitizeHtml((string)($msg['body_html'] ?? ''));
            if ($safeHtml !== '') $msg['body_html'] = $safeHtml; else unset($msg['body_html']);
            MailArchive::markRead((int)$msg['id']);
            jsonData($msg);

        case 'read':
            MailArchive::markRead((int)($input['id'] ?? 0), (bool)($input['read'] ?? true));
            jsonOk();

        case 'sync':
            $id = (int)($input['mailbox_id'] ?? $_GET['mailbox_id'] ?? 0);
            jsonOk(['report' => MailSync::run($id ?: null)]);

        case 'send':
            $to = trim((string)($input['to'] ?? ''));
            if ($to === '') jsonError('Укажите адрес получателя');
            $text = (string)($input['text'] ?? '');
            if (trim($text) === '') jsonError('Письмо пустое');

            // Replying keeps the thread and the company card of the original message
            $replyTo = null;
            $source = null;
            $counterpartyId = isset($input['counterparty_id']) ? (int)$input['counterparty_id'] : null;
            $requestId = isset($input['request_id']) ? (int)$input['request_id'] : null;
            $threadKey = trim((string)($input['thread_key'] ?? '')) ?: null;
            if (!empty($input['reply_to_id'])) {
                $src = MailArchive::get((int)$input['reply_to_id']);
                if ($src) {
                    $source = $src;
                    $replyTo = $src['message_id'] ?: null;
                    $counterpartyId = $counterpartyId ?: ($src['counterparty_id'] ? (int)$src['counterparty_id'] : null);
                    $requestId = $requestId ?: ($src['request_id'] ? (int)$src['request_id'] : null);
                    // An answer stays in the thread it answers, whichever mailbox
                    // it leaves from — the manager may pick any of them
                    $threadKey = $threadKey ?: ($src['thread_key'] ?: null);
                }
            }

            // Черновик этого письма уже знает компанию, которой пишут, —
            // даже если форма её не передала (модуль 033)
            $draftKeys = [
                'draft_id'        => $input['draft_id'] ?? 0,
                'mail_message_id' => $input['reply_to_id'] ?? 0,
                'thread_key'      => $threadKey ?? '',
                'counterparty_id' => $counterpartyId ?: 0,
            ];
            $draft = MailDrafts::find($draftKeys, (int)$manager['id']);
            if (!$counterpartyId && $draft && !empty($draft['counterparty_id'])) {
                $counterpartyId = (int)$draft['counterparty_id'];
            }

            $subject = (string)($input['subject'] ?? '');

            // Оформление, которое менеджер видел в поле, уходит клиенту: жирный,
            // списки и ссылки перестали срезаться по дороге. Чужого тут нет —
            // но разметка всё равно проходит тот же фильтр, что и входящая.
            $html = trim((string)($input['html'] ?? ''));
            $html = $html !== ''
                ? MailArchive::sanitizeHtml($html)
                : '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';

            // Ответ несёт письмо, на которое отвечает (модуль 031): клиенту не
            // приходится вспоминать, о каком заказе речь, а нам — пересказывать
            // его же вопрос своими словами.
            $quoted = MailText::withQuote($text, $html, $source);
            $text = $quoted['text'];
            $html = $quoted['html'];

            $res = Mailer::send([
                'to'              => $to,
                'cc'              => array_filter(array_map('trim', explode(',', (string)($input['cc'] ?? '')))),
                'subject'         => $subject,
                'text'            => $text,
                'html'            => $html,
                'mailbox_id'      => $input['mailbox_id'] ?? null,
                'manager_id'      => (int)$manager['id'],
                'counterparty_id' => $counterpartyId,
                'request_id'      => $requestId,
                'in_reply_to'     => $replyTo,
                'thread_key'      => $threadKey,
                // Менеджер мог переделать документ руками и приложить свой
                'attachments'     => Outbox::resolve((array)($input['files'] ?? []), (int)$manager['id']),
            ]);

            // Отправленное письмо — уже не черновик, но его карточка остаётся
            // на доске и в своей колонке (модуль 033)
            MailDrafts::sent($draftKeys, (int)$manager['id'], [
                'thread_key'      => (string)(Db::val("SELECT thread_key FROM mail_messages WHERE id=?",
                                                      [(int)$res['archive_id']]) ?: $threadKey),
                'counterparty_id' => $counterpartyId,
                'mail_message_id' => (int)$res['archive_id'],
                'title'           => $counterpartyId
                    ? (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$counterpartyId]) ?: $to)
                    : $to,
                'manager_id'      => (int)$manager['id'],
            ]);

            // The company chat shows the same message, so nothing is invisible there
            if ($counterpartyId) {
                Crm::logEvent($counterpartyId, 'out', $text, [
                    'request_id' => $requestId,
                    'subject'    => $subject,
                    'email_to'   => $to,
                    'manager_id' => (int)$manager['id'],
                    'event_type' => 'mail_sent',
                ]);
            }
            // «Отправлено» is not the whole truth when the copy never reached the
            // server's «Отправленные» — say so instead of letting it be found later
            jsonOk($res + ['warning' => $res['sent_state'] === 'failed'
                ? 'Письмо ушло, но копия не попала в «Отправленные»: ' . (string)$res['sent_error']
                : null]);

        case 'draft_reply':
            // «Создать ответ»: the draft is generated here and only here — the mail
            // sync just notifies, it never spends a model call on an unread letter.
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id && !empty($input['request_id'])) {
                $id = (int)Db::val(
                    "SELECT id FROM mail_messages WHERE request_id=? AND direction='in' ORDER BY id DESC LIMIT 1",
                    [(int)$input['request_id']]
                );
            }
            $msg = MailArchive::get($id);
            if (!$msg) jsonError('Письмо не найдено', 404);
            if ($msg['direction'] !== 'in') jsonError('Ответ создаётся только на входящее письмо');

            require_once ROOT . '/lib/parser.php';

            $attachText = '';
            foreach (Db::all("SELECT filename, extracted_text FROM attachments WHERE mail_message_id=?", [$id]) as $a) {
                if (!empty($a['extracted_text'])) {
                    $attachText .= "--- Вложение: {$a['filename']} ---\n" . $a['extracted_text'] . "\n\n";
                }
            }

            // Earlier letters of the same company (or the same address) give the model
            // the context a manager would scroll through before answering
            $thread = $msg['counterparty_id']
                ? Db::all("SELECT direction, date_at, body_text FROM mail_messages
                           WHERE counterparty_id=? AND id<>? ORDER BY date_at DESC, id DESC LIMIT 5",
                          [(int)$msg['counterparty_id'], $id])
                : Db::all("SELECT direction, date_at, body_text FROM mail_messages
                           WHERE from_email=? AND id<>? ORDER BY date_at DESC, id DESC LIMIT 5",
                          [(string)$msg['from_email'], $id]);

            // The category picks the prompt and the fact sources (module 006).
            // The manager may override it right in the reply dialog.
            $category = trim((string)($input['category'] ?? $msg['category'] ?? ''));
            if (!isset(Triage::CATEGORIES[$category])) $category = 'other';

            $ctx = [
                'org_name'        => $msg['counterparty_name'] ?? '',
                'attachments'     => $attachText,
                'thread'          => array_reverse($thread),
                'counterparty_id' => $msg['counterparty_id'] ?? null,
                'email_rules'     => (string)(Db::val("SELECT content FROM email_rules ORDER BY id DESC LIMIT 1") ?: ''),
                'tov'             => Tov::read(),
                // Positions of this letter the catalog never answered. The draft
                // says so in the client's own words instead of dropping them
                // silently, which is all that used to happen (module 018).
                'unmatched'       => !empty($msg['request_id'])
                    ? (RequestItems::ensure((int)$msg['request_id']) ? RequestItems::unmatched((int)$msg['request_id']) : [])
                    : [],
                // «Создать ответ» пишет ответ по тому, ЧТО УЖЕ ПОДОБРАНО:
                // те же позиции, те же цены, те же комментарии, что уйдут
                // в КП (модуль 023)
                'matched'         => !empty($msg['request_id'])
                    ? RequestItems::all((int)$msg['request_id']) : [],
                // Позиции, которыми мы не занимаемся: ответ про них молчит и
                // ничего не обещает (модуль 022)
                'out_of_scope'    => !empty($msg['request_id'])
                    ? RequestItems::outOfScope((int)$msg['request_id']) : [],
            ];
            // The manager may pick the model right in the reply window. The choice
            // holds for this one request; it never becomes a stored setting, and
            // a hand-picked model answers alone — no silent fallback to another.
            $modelSpec = trim((string)($input['model'] ?? ''));
            if ($modelSpec !== '' && (string)Settings::get('LLM_MODEL_PICKER', '1') === '1') {
                LLM::useModelSpec($modelSpec);
            }

            // A draft prepared at sync time (TRIAGE_AUTO_DRAFT) is used once and
            // cleared — pressing the button again must regenerate, not repeat.
            $text = '';
            if (empty($input['category']) && $modelSpec === '' && trim((string)($msg['draft_text'] ?? '')) !== '') {
                $text = (string)$msg['draft_text'];
                Db::update('mail_messages', ['draft_text' => null], 'id=?', [$id]);
            } else {
                $text = Triage::enabled()
                    ? Triage::draft($msg, $category, $ctx)
                    : RequestParser::generateReply($msg, $ctx);
            }

            [$promptKey] = Triage::route($category);
            $promptKey = $promptKey ?? 'mail_reply';
            if (!empty($input['category']) && $input['category'] !== ($msg['category'] ?? null)) {
                // Менеджер поправил классификатор перед генерацией ответа —
                // правка чинит и это письмо, и все следующие похожие (модуль 022)
                Triage::correct($msg, $category, (int)$manager['id'], (string)($input['category_comment'] ?? ''));
                Db::update('mail_messages', ['category' => $category], 'id=?', [$id]);
                if (!empty($msg['request_id'])) {
                    Db::update('requests', ['category' => $category, 'category_source' => 'manager'],
                               'id=?', [(int)$msg['request_id']]);
                }
            }

            $used = LLM::currentModel();
            Logger::info('mail', "Черновик ответа на письмо #$id создан (" . Triage::label($category) . ')', [
                'mail_message_id' => $id, 'manager_id' => (int)$manager['id'], 'prompt' => $promptKey,
                'model' => $used['provider'] . ':' . $used['model'],
            ]);
            jsonData([
                'mail_message_id' => $id,
                'text'            => $text,
                'category'        => $category,
                'category_label'  => Triage::label($category),
                'prompt'          => $promptKey,
                'model'           => $used['model'],
                'provider'        => $used['provider'],
                'subject'         => preg_replace('/^(Re:\s*)?/iu', 'Re: ', (string)$msg['subject']),
                'to'              => (string)$msg['from_email'],
            ]);

        // ---- Свои файлы к письму (модуль 023) ----

        case 'upload':
            if (empty($_FILES['file'])) jsonError('Файл не передан');
            jsonOk(['file' => Outbox::accept($_FILES['file'], (int)$manager['id'])]);

        /**
         * Приложить к письму то, что сервис собрал сам: счёт из МойСклад или
         * файл КП (issue #38). Загружать их через браузер незачем — они уже
         * лежат на сервере, и менеджеру нужен один щелчок, а не «скачать,
         * потом приложить».
         */
        case 'attach_doc': {
            $kind = (string)($input['kind'] ?? '');
            $id   = (int)($input['id'] ?? 0);
            if (!$id) jsonError('Не указан документ');

            if ($kind === 'invoice') {
                require_once ROOT . '/lib/sync.php';
                require_once ROOT . '/lib/invoice_name.php';
                $inv = Db::one("SELECT * FROM invoices WHERE id=?", [$id]);
                if (!$inv) jsonError('Счёт не найден', 404);
                $path = MsSync::ensureInvoicePdf($id);
                if (!$path || !is_file($path)) jsonError('Печатная форма счёта недоступна в МойСклад', 502);
                // Имя по шаблону из настроек, и менеджер мог поправить его в
                // самом письме — присланное побеждает (модуль 029)
                $name = safeAttachmentName((string)($input['filename'] ?? ''))
                     ?: InvoiceName::forInvoice($id);
            } elseif ($kind === 'kp' || $kind === 'kp_docx') {
                require_once ROOT . '/lib/pdf.php';
                require_once ROOT . '/lib/docx.php';
                if (!Db::val("SELECT 1 FROM proposals WHERE id=?", [$id])) jsonError('КП не найдено', 404);
                if ($kind === 'kp_docx') {
                    $path = DocxGenerator::generate($id);
                    $name = DocxGenerator::filename($id);
                } else {
                    $path = (string)Db::val("SELECT pdf_path FROM proposals WHERE id=?", [$id]);
                    if (!$path || !is_file($path)) { PdfGenerator::generate($id); $path = (string)Db::val("SELECT pdf_path FROM proposals WHERE id=?", [$id]); }
                    $name = PdfGenerator::fileName($id, 'pdf');
                }
                if (!$path || !is_file($path)) jsonError('Файл КП не собрался', 500);
            } else {
                jsonError('Неизвестный документ');
            }

            jsonOk(['file' => Outbox::adopt($path, $name, (int)$manager['id'])]);
        }

        // ---- Черновик письма: вкладку закрыли — текст остался (модули 023, 033) ----

        case 'draft_get': {
            $row = MailDrafts::find([
                'draft_id'        => $_GET['draft_id'] ?? 0,
                'mail_message_id' => $_GET['id'] ?? 0,
                'thread_key'      => $_GET['thread_key'] ?? '',
                'counterparty_id' => $_GET['counterparty_id'] ?? 0,
            ], (int)$manager['id']);
            jsonData(['draft' => $row ?: null]);
        }

        case 'draft_save': {
            // Черновик сохраняется у ЛЮБОГО письма, в том числе у первого письма
            // компании: раньше без id письма или ключа цепочки набранный текст
            // просто не уходил на сервер (модуль 033)
            jsonOk(MailDrafts::save([
                'draft_id'        => $input['draft_id'] ?? 0,
                'mail_message_id' => $input['id'] ?? 0,
                'thread_key'      => $input['thread_key'] ?? '',
                'counterparty_id' => $input['counterparty_id'] ?? 0,
                'to'              => $input['to'] ?? '',
                'subject'         => $input['subject'] ?? '',
                'body'            => $input['body'] ?? '',
            ], (int)$manager['id']));
        }

        case 'draft_clear': {
            MailDrafts::clear([
                'draft_id'        => $input['draft_id'] ?? 0,
                'mail_message_id' => $input['id'] ?? 0,
                'thread_key'      => $input['thread_key'] ?? '',
                'counterparty_id' => $input['counterparty_id'] ?? 0,
            ], (int)$manager['id']);
            jsonOk();
        }

        case 'mark_spam':
            // «Спам»: files the letter as spam, moves it into the mailbox's own
            // Spam/Junk folder on the server, and remembers the sender
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) jsonError('Не указано письмо');
            $res = MailSync::markAsSpam($id);
            Logger::info('mail', "Письмо #$id отмечено как спам менеджером", ['manager_id' => (int)$manager['id']]);
            jsonOk($res);

        // ---- «В архив»: письмо уходит с экрана, но остаётся в ящике (модуль 019) ----

        case 'archive':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) jsonError('Не указано письмо');
            $res = MailSync::archiveMessage($id, (int)$manager['id']);
            jsonOk($res + ['warning' => $res['move_error']
                ? 'Письмо убрано из панели, но на сервере осталось в прежней папке: ' . (string)$res['move_error']
                : null]);

        case 'archive_thread':
            $key = trim((string)($input['thread_key'] ?? $_GET['thread_key'] ?? ''));
            if ($key === '') jsonError('Не указана цепочка');
            $res = MailSync::archiveThread($key, (int)$manager['id']);
            jsonOk($res + ['warning' => $res['move_error']
                ? 'Часть писем осталась на сервере в прежней папке: ' . (string)$res['move_error']
                : null]);

        case 'unarchive':
            $id  = (int)($input['id'] ?? $_GET['id'] ?? 0);
            $key = trim((string)($input['thread_key'] ?? ''));
            if (!$id && $key === '') jsonError('Не указано письмо');
            jsonOk($id ? MailSync::unarchiveMessage($id) : MailSync::unarchiveThread($key));

        case 'delete':
            // «Удалить»: out of the archive here and into «Корзина» on the server,
            // so the letter does not come back with the next sync
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) jsonError('Не указано письмо');
            $res = MailSync::deleteMessage($id, (int)$manager['id']);
            jsonOk($res + ['warning' => $res['server_error']
                ? 'Письмо удалено в панели, но на почтовом сервере осталось: ' . (string)$res['server_error']
                : null]);

        case 'delete_thread':
            $key = trim((string)($input['thread_key'] ?? $_GET['thread_key'] ?? ''));
            if ($key === '') jsonError('Не указана цепочка');
            $res = MailSync::deleteThread($key, (int)$manager['id']);
            Logger::info('mail', "Переписка удалена ({$res['deleted']} писем)",
                         ['thread_key' => $key, 'manager_id' => (int)$manager['id']]);
            jsonOk($res + ['warning' => $res['server_error']
                ? 'Часть писем осталась на почтовом сервере: ' . (string)$res['server_error']
                : null]);

        /**
         * Групповое действие над отмеченными переписками (issue: разбор архива).
         *
         * То же, что групповые операции на доске, но по ключам цепочек: в
         * архиве карточек нет, а разбирать его партиями нужно ровно так же.
         */
        case 'bulk_threads': {
            $keys = array_values(array_filter(array_map('strval', (array)($input['keys'] ?? []))));
            $op   = (string)($input['op'] ?? '');
            if (!$keys) jsonError('Не отмечено ни одной переписки');

            $done = 0; $failed = 0; $errors = [];
            foreach ($keys as $key) {
                try {
                    switch ($op) {
                        case 'unarchive': MailSync::unarchiveThread($key); break;
                        case 'archive':   MailSync::archiveThread($key, (int)$manager['id']); break;
                        case 'read':      MailThreads::markRead($key); break;
                        case 'delete':    MailSync::deleteThread($key, (int)$manager['id']); break;
                        case 'spam':
                            foreach (Db::all("SELECT id FROM mail_messages WHERE thread_key=? AND direction='in'",
                                             [$key]) as $m) {
                                MailSync::markAsSpam((int)$m['id']);
                            }
                            break;
                        default: jsonError('Неизвестная операция: ' . $op);
                    }
                    $done++;
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = $e->getMessage();
                }
            }
            Logger::info('mail', "Групповая операция «{$op}» по $done перепискам",
                         ['manager_id' => (int)$manager['id'], 'failed' => $failed]);
            jsonOk(['done' => $done, 'failed' => $failed, 'errors' => array_slice($errors, 0, 5)]);
        }

        case 'categories':
            // For the «тип запроса» selector in the reply dialog
            jsonData(['categories' => array_map(
                fn($k) => ['key' => $k, 'label' => Triage::label($k), 'answerable' => Triage::route($k)[0] !== null,
                           'creates_request' => Triage::createsRequest($k)],
                array_keys(Triage::CATEGORIES)
            )]);

        case 'link':
            // Attach an archived message to a company card by hand
            $id = (int)($input['id'] ?? 0);
            $cp = (int)($input['counterparty_id'] ?? 0);
            if (!$id || !$cp) jsonError('Нужны письмо и контрагент');
            Db::update('mail_messages', ['counterparty_id' => $cp], 'id=?', [$id]);
            jsonOk();

        case 'attachment':
            $a = Db::one("SELECT * FROM attachments WHERE id=?", [(int)($_GET['id'] ?? 0)]);
            if (!$a) jsonError('Not found', 404);
            $path = ROOT . '/' . $a['path'];
            if (!is_file($path)) jsonError('File missing on disk', 404);
            $mime = (string)($a['mime'] ?: 'application/octet-stream');
            // Item 5: inline preview without a download. An attachment's MIME
            // comes from the letter itself (attacker-controlled) — a file
            // claiming text/html or image/svg+xml served inline would execute
            // in our own origin, so «inline» is honoured only for types a
            // browser can merely display, never run. DOCX/XLSX preview reads
            // the bytes through fetch() client-side and never navigates here,
            // so it needs no inline disposition at all.
            $inlineSafe = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
            $inline = $inlineSafe && !empty($_GET['inline']);
            header('Content-Type: ' . $mime);
            header('Content-Disposition: ' . Attachments::contentDisposition($a['filename'], $inline));
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;

        default:
            jsonError('Unknown action', 400);
    }
} catch (Throwable $e) {
    Logger::exception('mail', $e, ['action' => $action]);
    jsonError($e->getMessage(), 500);
}

/**
 * Имя вложения, набранное руками (модуль 029). Пустое — пусть решает шаблон;
 * всё, что ломает файловую систему и заголовок письма, вычищается, а `.pdf`
 * дописывается: менеджер правит имя, а не расширение.
 */
function safeAttachmentName(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') return '';
    $name = (string)preg_replace('#[\\\\/:*?"<>|\r\n]+#u', '_', $name);
    $name = trim($name, '_ .');
    if ($name === '') return '';
    if (!preg_match('/\.pdf$/iu', $name)) $name .= '.pdf';
    return mb_substr($name, 0, 180);
}
