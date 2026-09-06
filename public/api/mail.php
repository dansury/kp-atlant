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
            $summary = MailThreads::summary($key);
            if (!$summary) jsonError('Цепочка не найдена', 404);
            $messages = MailThreads::messages($key);
            // Plain text only — no remote content and no scripts from a letter
            foreach ($messages as &$m) unset($m['body_html']);
            unset($m);
            MailThreads::markRead($key);
            jsonData([
                'thread'    => $summary,
                'messages'  => $messages,
                'reply'     => MailThreads::replyContext($key),
                'mailboxes' => array_map(
                    fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'email' => $b['email']],
                    Mailboxes::forManager($manager)
                ),
            ]);

        case 'get':
            $msg = MailArchive::get((int)($_GET['id'] ?? 0));
            if (!$msg) jsonError('Not found', 404);
            unset($msg['body_html'], $msg['headers']);   // plain text only — no remote content, no scripts, no raw headers
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
            $counterpartyId = isset($input['counterparty_id']) ? (int)$input['counterparty_id'] : null;
            $requestId = isset($input['request_id']) ? (int)$input['request_id'] : null;
            $threadKey = trim((string)($input['thread_key'] ?? '')) ?: null;
            if (!empty($input['reply_to_id'])) {
                $src = MailArchive::get((int)$input['reply_to_id']);
                if ($src) {
                    $replyTo = $src['message_id'] ?: null;
                    $counterpartyId = $counterpartyId ?: ($src['counterparty_id'] ? (int)$src['counterparty_id'] : null);
                    $requestId = $requestId ?: ($src['request_id'] ? (int)$src['request_id'] : null);
                    // An answer stays in the thread it answers, whichever mailbox
                    // it leaves from — the manager may pick any of them
                    $threadKey = $threadKey ?: ($src['thread_key'] ?: null);
                }
            }

            $subject = (string)($input['subject'] ?? '');
            $res = Mailer::send([
                'to'              => $to,
                'cc'              => array_filter(array_map('trim', explode(',', (string)($input['cc'] ?? '')))),
                'subject'         => $subject,
                'text'            => $text,
                'mailbox_id'      => $input['mailbox_id'] ?? null,
                'manager_id'      => (int)$manager['id'],
                'counterparty_id' => $counterpartyId,
                'request_id'      => $requestId,
                'in_reply_to'     => $replyTo,
                'thread_key'      => $threadKey,
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
                'tov'             => is_file(ROOT . '/reference/tov.md') ? (string)file_get_contents(ROOT . '/reference/tov.md') : '',
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

        case 'mark_spam':
            // «Спам»: files the letter as spam, moves it into the mailbox's own
            // Spam/Junk folder on the server, and remembers the sender
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) jsonError('Не указано письмо');
            $res = MailSync::markAsSpam($id);
            Logger::info('mail', "Письмо #$id отмечено как спам менеджером", ['manager_id' => (int)$manager['id']]);
            jsonOk($res);

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
            header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
            header('Content-Disposition: ' . Attachments::contentDisposition($a['filename']));
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
