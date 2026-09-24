<?php
/**
 * Mail sync: pulls every mailbox — INBOX and «Отправленные» — into the archive,
 * then turns fresh inbound messages into КП requests exactly as before.
 */
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/notifier.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/triage.php';
require_once __DIR__ . '/mail_text.php';
require_once __DIR__ . '/bounce.php';

final class MailSync {

    /** Сколько раз пробовать разобрать письмо моделью, прежде чем завести его правилами. */
    private const TRIAGE_TRIES = 3;

    /**
     * Sync all active mailboxes (or one). Returns a per-mailbox report.
     *
     * `$opts['budget']` — сколько секунд отдать разбору писем моделью
     * (модуль 040). Кнопка «Забрать почту» ждёт человека: письма должны
     * появиться на доске СРАЗУ, а не после того, как модель прочитает
     * полсотни писем по тридцать секунд каждое. Что не успели разобрать —
     * разберёт следующий заход или cron, письмо от этого не теряется.
     *
     * Выключенный ящик не опрашивается, даже когда его назвали по номеру:
     * «отключён» значит отключён, а не «отключён, пока не нажмут кнопку».
     * `$opts['force']` — для проверки ящика из настроек.
     */
    public static function run(?int $mailboxId = null, array $opts = []): array {
        $boxes = $mailboxId ? array_filter([Mailboxes::get($mailboxId)]) : Mailboxes::all(true);
        if (empty($opts['force'])) {
            $boxes = array_filter($boxes, fn($b) => (int)($b['is_active'] ?? 1) === 1);
        }
        $report = [];
        foreach ($boxes as $box) {
            $report[] = self::syncMailbox($box, $opts);
        }
        return $report;
    }

    public static function syncMailbox(array $box, array $opts = []): array {
        $res = ['mailbox_id' => (int)$box['id'], 'name' => $box['name'], 'in' => 0, 'out' => 0,
                'requests' => 0, 'error' => null, 'sent_error' => null];
        if (!EmailReader::available()) {
            $res['error'] = 'Расширение PHP imap не установлено на сервере';
            Db::update('mailboxes', ['last_error' => $res['error'], 'last_check_at' => date('Y-m-d H:i:s')], 'id=?', [$box['id']]);
            Logger::error('mail', $res['error'], ['mailbox_id' => $box['id']]);
            return $res;
        }

        $limit = max(1, (int)Settings::get('MAIL_FETCH_LIMIT', 50));
        try {
            $res['in'] = self::syncFolder($box, (string)($box['imap_folder_in'] ?: 'INBOX'), 'in', 'last_uid_in', $limit);
        } catch (Throwable $e) {
            $res['error'] = $e->getMessage();
            Db::update('mailboxes', ['last_error' => $e->getMessage(), 'last_check_at' => date('Y-m-d H:i:s')], 'id=?', [$box['id']]);
            Logger::exception('mail', $e, ['mailbox_id' => $box['id'], 'mailbox' => $box['name']]);
            return $res;
        }

        // «Отправленные» — отдельная попытка: недоступная папка отправленных
        // роняла синхронизацию целиком, и входящие письма после неё не
        // становились запросами. Ошибка видна в «Настройки → Почта», но разбор
        // входящих она больше не отменяет.
        try {
            $res['out'] = self::syncSent($box, $limit);
        } catch (Throwable $e) {
            $res['sent_error'] = $e->getMessage();
            Logger::exception('mail', $e, ['mailbox_id' => $box['id'], 'mailbox' => $box['name'], 'folder' => 'sent']);
        }
        Db::update('mailboxes', [
            'last_error'    => $res['sent_error'] ? 'Отправленные: ' . $res['sent_error'] : null,
            'last_check_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$box['id']]);

        if (!empty($box['create_requests'])) {
            $budget = array_key_exists('budget', $opts) ? (float)$opts['budget'] : 0.0;
            $res['requests'] = self::processInbound((int)$box['id'], $budget);
            // Сколько писем ждут разбора: «письмо пришло, но ещё не разобрано»
            // должно быть видно, а не выглядеть как «письма нет»
            $res['pending'] = (int)Db::val(
                "SELECT COUNT(*) FROM mail_messages WHERE mailbox_id=? AND direction='in' AND processed_at IS NULL",
                [(int)$box['id']]);
        }
        return $res;
    }

    /**
     * Письма, отправленные мимо сервиса, — с телефона, из Outlook, из веб-почты.
     *
     * Имя папки в настройках — догадка («INBOX.Sent» по умолчанию), а у Яндекса
     * и Mail.ru папка называется «Отправленные». Поэтому имя, которое не
     * открылось, не приговор: спрашиваем у сервера его собственный список и
     * запоминаем то, что действительно есть, — ровно как при отправке письма.
     */
    private static function syncSent(array $box, int $limit): int {
        if (empty($box['sync_sent']) || !Settings::get('MAIL_SYNC_SENT', 1)) return 0;

        $configured = trim((string)($box['imap_folder_sent'] ?? ''));
        if ($configured !== '') {
            try {
                return self::syncFolder($box, $configured, 'out', 'last_uid_sent', $limit);
            } catch (Throwable $e) {
                $folder = self::resolveSentFolder($box, $configured);
                // Сервер не знает другого имени — папка молчит по своей причине
                if ($folder === '' || $folder === $configured) throw $e;
            }
        } else {
            $folder = self::resolveSentFolder($box, '');
            if ($folder === '') throw new RuntimeException('на сервере не нашлась папка «Отправленные»');
        }

        Mailboxes::rememberSentFolder((int)$box['id'], $folder, $configured);
        $box = Mailboxes::get((int)$box['id']) ?: ($box + ['imap_folder_sent' => $folder]);
        return self::syncFolder($box, $folder, 'out', 'last_uid_sent', $limit);
    }

    /** Имя папки отправленных по списку самого сервера; '' — не нашлось. */
    private static function resolveSentFolder(array $box, string $configured): string {
        $reader = new EmailReader(Mailboxes::cfg($box));
        $reader->connect((string)($box['imap_folder_in'] ?: 'INBOX'));
        try {
            return (string)($reader->findSentFolder($configured) ?? '');
        } finally {
            $reader->close();
        }
    }

    /** Archive everything newer than the stored UID watermark. */
    private static function syncFolder(array $box, string $folder, string $direction, string $uidColumn, int $limit): int {
        $reader = new EmailReader(Mailboxes::cfg($box));
        $reader->connect($folder);
        $since = (int)($box[$uidColumn] ?? 0);
        $messages = self::fetchBatch($reader, $since, $limit);

        $stored = 0;
        $maxUid = $since;
        foreach ($messages as $msg) {
            $maxUid = max($maxUid, (int)$msg['uid']);
            $id = MailArchive::storeIncoming($box, $msg, $direction);
            if (!$id) continue;
            $stored++;
            self::storeAttachments($id, $msg['attachments'] ?? []);
            // An answer pulled out of «Отправленные» never goes through the
            // request pipeline, so nothing else would ever put it on the card of
            // the company it was written to (module 021).
            if ($direction === 'out') self::registerOutbound($id);
        }
        $reader->close();

        if ($maxUid > $since) Db::update('mailboxes', [$uidColumn => $maxUid], 'id=?', [$box['id']]);
        if ($stored) Logger::info('mail', "Ящик «{$box['name']}»: $folder — новых писем $stored", ['mailbox_id' => $box['id']]);
        return $stored;
    }

    /**
     * Что забрать из папки за один заход.
     *
     * Папку, которую ещё ни разу не забирали, начинаем с ПОСЛЕДНИХ писем, а не
     * с самых старых: в «Отправленных» за четыре года лежат тысячи писем, и
     * сегодняшний ответ с телефона пришёл бы через сотню синхронизаций. История
     * — это «Скачать весь архив», она идёт своим курсором.
     *
     * Отделено от соединения, чтобы проверять без IMAP-сервера: $reader нужен
     * только с fetchSince / fetchLatest.
     */
    public static function fetchBatch($reader, int $since, int $limit): array {
        return $since > 0 ? $reader->fetchSince($since, $limit) : $reader->fetchLatest($limit);
    }

    /**
     * Ответ, написанный мимо сервиса, — это ответ.
     *
     * Письмо из «Отправленных» кладётся на карточку компании и отмечается в
     * ленте её датой: иначе менеджер отвечает клиенту с телефона, а карточка
     * весь день горит «клиент ждёт ответа». Сюда доходит только то, чего в
     * архиве ещё нет, — копию письма, отправленного из сервиса, отсекает
     * дедупликация, и второй отметки об ответе не будет.
     */
    public static function registerOutbound(int $mailMessageId): void {
        $cpId = MailArchive::linkCounterparty($mailMessageId);
        if (!$cpId) return;
        $row = Db::one("SELECT * FROM mail_messages WHERE id=?", [$mailMessageId]);
        if (!$row || !empty($row['correspondence_id'])) return;

        $corrId = Crm::logEvent((int)$cpId, 'out', (string)$row['body_text'], [
            'subject'  => $row['subject'],
            'email_to' => $row['to_emails'],
            'at'       => (string)$row['date_at'],
        ]);
        Db::update('mail_messages', ['correspondence_id' => $corrId], 'id=?', [$mailMessageId]);
    }

    /**
     * $ocr=false during «скачать весь архив» and during an mbox import: recognizing
     * thousands of old scans costs money, runs into the Vision rate limit and
     * nobody asked for it — the text layer of a PDF is still extracted.
     */
    public static function storeAttachments(int $mailMessageId, array $files, bool $ocr = true): array {
        $stored = [];
        foreach ($files as $file) {
            try {
                $stored[] = Attachments::store($file, ['mail_message_id' => $mailMessageId], ['ocr' => $ocr]);
            } catch (Throwable $e) {
                Logger::exception('attachments', $e, ['mail_message_id' => $mailMessageId]);
            }
        }
        return $stored;
    }

    /**
     * Turn archived inbound mail into requests: parse, resolve the company card,
     * create the request and notify — the pipeline module 001/002 already had.
     */
    public static function processInbound(int $mailboxId, float $budgetSec = 0.0): int {
        $rows = Db::all(
            "SELECT * FROM mail_messages WHERE mailbox_id=? AND direction='in' AND processed_at IS NULL ORDER BY id LIMIT 50",
            [$mailboxId]
        );
        $count = 0;
        $started = microtime(true);
        foreach ($rows as $row) {
            // Время вышло — остальные письма ждут следующего захода. Они уже
            // в архиве и уже видны: не разобран только их РАЗБОР (модуль 040).
            if ($budgetSec > 0 && (microtime(true) - $started) >= $budgetSec) break;
            try {
                if (self::toRequest($row)) $count++;
            } catch (Throwable $e) {
                self::postpone($row, $e);
            }
        }
        return $count;
    }

    /**
     * Разбор упал — письмо НЕ помечается разобранным (модуль 040).
     *
     * Модель могла не ответить: у провайдера таймаут, кончились ключи, фильтр
     * на пути. Раньше такое письмо получало `processed_at` и уходило в тишину
     * навсегда — запроса по нему не появлялось никогда, и никто об этом не
     * узнавал. Теперь оно ждёт следующего захода, а после нескольких неудач
     * заводится запрос БЕЗ модели: по правилам, с категорией «не определено».
     */
    private static function postpone(array $row, Throwable $e): void {
        $tries = (int)($row['triage_attempts'] ?? 0) + 1;
        Db::update('mail_messages', [
            'triage_attempts' => $tries,
            'error'           => mb_substr($e->getMessage(), 0, 500),
        ], 'id=?', [$row['id']]);
        Logger::exception('mail', $e, ['mail_message_id' => $row['id'], 'subject' => $row['subject'],
                                       'attempt' => $tries]);
        if ($tries < self::TRIAGE_TRIES) return;

        try {
            self::toRequestWithoutModel($row);
        } catch (Throwable $inner) {
            Db::update('mail_messages', ['processed_at' => date('Y-m-d H:i:s')], 'id=?', [$row['id']]);
            Logger::exception('mail', $inner, ['mail_message_id' => $row['id']]);
        }
    }

    /**
     * Письмо, которое модель так и не разобрала, — всё равно письмо клиента.
     *
     * Запрос заводится по правилам: категория «не определено», позиции ищет
     * `RequestItems::ensure()` разбором текста. Менеджер видит карточку и
     * работает с ней руками — это несравнимо лучше, чем молчание.
     */
    private static function toRequestWithoutModel(array $row): void {
        $senderEmail = (string)($row['from_email'] ?? '');
        if (Crm::isOurAddress($senderEmail)) $senderEmail = '';
        $counterpartyId = !empty($row['counterparty_id']) ? (int)$row['counterparty_id'] : Crm::resolveCounterparty([
            'email' => $senderEmail,
            'contact_person' => $row['from_name'] ?: null,
            'text'  => (string)$row['body_text'],
        ]);

        $requestId = (int)Db::insert('requests', [
            'source'           => 'email',
            'raw_text'         => $row['body_text'],
            'counterparty_id'  => $counterpartyId ?: null,
            'status'           => 'new',
            'type'             => 'kp_request',
            'type_source'      => 'rules',
            'category'         => 'other',
            'category_reason'  => 'Нейросеть не ответила — письмо разобрано правилами',
            'category_source'  => 'rules',
            'email_from'       => $senderEmail,
            'email_subject'    => $row['subject'],
            'email_message_id' => $row['message_id'],
        ]);
        $corrId = Crm::logEvent($counterpartyId, 'in', (string)$row['body_text'], [
            'request_id' => $requestId, 'subject' => $row['subject'], 'email_from' => $senderEmail,
        ]);
        Db::update('mail_messages', [
            'processed_at'      => date('Y-m-d H:i:s'),
            'category'          => 'other',
            'triage_reason'     => 'Нейросеть недоступна — разобрано правилами',
            'request_id'        => $requestId,
            'counterparty_id'   => $counterpartyId,
            'correspondence_id' => $corrId,
        ], 'id=?', [$row['id']]);
        RequestItems::ensure($requestId);

        Notifier::notify('new_request', 'Письмо без разбора: ' . ($senderEmail ?: (string)$row['from_email']),
            (string)$row['subject'], 'request', $requestId, null, '/#mail/msg/' . (int)$row['id']);
        Logger::warning('mail', 'Письмо заведено без модели: нейросеть не ответила',
                        ['mail_message_id' => (int)$row['id'], 'request_id' => $requestId]);
    }

    /**
     * ==== Подбор товара по любой переписке (модуль 039) ====
     *
     * Сервис существует ради КП, а подобрать товар можно было только там, где
     * классификатор сам завёл запрос. Письмо, которое он отнёс к «предложению
     * поставщика» или к «не определено», оставалось без таблицы подбора — и в
     * панели справа стояло «подбирать по каталогу нечего». Живой запрос на
     * бронеплиты «от бр2 до бр5» так и не доходил до каталога.
     *
     * Здесь запрос заводится РУКАМИ по уже пришедшей переписке: письмо
     * перечитывается заново — один вызов модели, и его просят, а не тратят на
     * каждое входящее, — компания подтягивается из письма, позиции разбираются
     * и подбираются по каталогу. Модель промолчала — таблица открывается
     * пустой, и позицию в неё вписывают руками: «подобрать нечего» не
     * повторяется никогда.
     *
     * @return array{request_id:int,created:bool,items:int}
     */
    public static function requestFromThread(string $threadKey, int $managerId): array {
        $threadKey = trim($threadKey);
        if ($threadKey === '') throw new InvalidArgumentException('Не указана переписка');

        // Запрос у переписки уже есть — второго не заводим: таблица подбора
        // одна на разговор, и КП собирается из неё
        $existing = (int)(Db::val(
            "SELECT MAX(request_id) FROM mail_messages WHERE thread_key=? AND request_id IS NOT NULL", [$threadKey]
        ) ?: 0);
        if ($existing > 0) {
            return ['request_id' => $existing, 'created' => false,
                    'items' => count(RequestItems::ensure($existing))];
        }

        // Отвечаем на последнее ВХОДЯЩЕЕ письмо: запрос — это то, что просил
        // клиент, а не то, что мы написали в ответ
        $row = Db::one("SELECT * FROM mail_messages WHERE thread_key=? AND direction='in'
                        ORDER BY date_at DESC, id DESC LIMIT 1", [$threadKey])
            ?: Db::one("SELECT * FROM mail_messages WHERE thread_key=? ORDER BY date_at DESC, id DESC LIMIT 1", [$threadKey]);
        if (!$row) throw new InvalidArgumentException('Переписка не найдена');

        $attachmentText = '';
        foreach (Db::all("SELECT filename, extracted_text FROM attachments WHERE mail_message_id=?", [$row['id']]) as $a) {
            if (!empty($a['extracted_text'])) {
                $attachmentText .= "--- Вложение: {$a['filename']} ---\n" . $a['extracted_text'] . "\n\n";
            }
        }

        // Разбор — лучшее, что у нас есть, но не условие. Модель не ответила,
        // ключи кончились, сеть легла — запрос всё равно заводится, а позиции
        // менеджер впишет сам.
        $parsed = [];
        try {
            $parsed = Triage::classify(
                MailText::forAnalysis((string)$row['body_text']), $attachmentText, (string)$row['subject']);
        } catch (Throwable $e) {
            Logger::exception('mail', $e, ['mail_message_id' => (int)$row['id'], 'thread_key' => $threadKey]);
        }

        // Категорию письма руками не переписываем: менеджер просил ПОДБОР, а не
        // переклассификацию. Но категория, при которой запрос не заводится,
        // в самом запросе становится обычным «запросом КП» — иначе карточка
        // сама себе противоречит.
        $category = (string)($row['category'] ?? '') ?: (string)($parsed['category'] ?? 'other');
        if (!Triage::createsRequest($category)) $category = 'kp_request';
        $type = ($parsed['request_type'] ?? 'kp_request') === 'order' ? 'order' : 'kp_request';

        $senderEmail = (string)($row['from_email'] ?? '');
        if (Crm::isOurAddress($senderEmail)) $senderEmail = '';

        $counterpartyId = !empty($row['counterparty_id']) ? (int)$row['counterparty_id'] : Crm::resolveCounterparty([
            'inn'            => $parsed['inn'] ?? '',
            'name'           => $parsed['org_name'] ?? '',
            'email'          => $senderEmail,
            'contact_person' => $parsed['contact_person'] ?? ($row['from_name'] ?: null),
            'phone'          => $parsed['contact_phone'] ?? null,
            'text'           => (string)$row['body_text'] . "\n" . $attachmentText,
        ]);

        $requestId = (int)Db::insert('requests', [
            'source'              => 'email',
            'raw_text'            => $row['body_text'],
            'parsed_json'         => json_encode($parsed, JSON_UNESCAPED_UNICODE),
            'counterparty_id'     => $counterpartyId ?: null,
            'manager_id'          => $managerId ?: null,
            'status'              => 'processing',
            'type'                => $type,
            'type_source'         => 'llm',
            'category'            => $category,
            'category_confidence' => (float)($parsed['category_confidence'] ?? 0),
            'category_reason'     => (string)($parsed['category_reason'] ?? 'Подбор заведён менеджером вручную'),
            'category_source'     => 'manager',
            'email_from'          => $senderEmail,
            'email_subject'       => $row['subject'],
            'email_message_id'    => $row['message_id'],
        ]);

        // Запрос принадлежит ВСЕЙ переписке: письма цепочки показывают одну и ту
        // же таблицу подбора, с какого бы из них её ни открыли
        Db::q("UPDATE mail_messages SET request_id=? WHERE thread_key=? AND request_id IS NULL",
              [$requestId, $threadKey]);
        if ($counterpartyId) {
            Db::q("UPDATE mail_messages SET counterparty_id=? WHERE thread_key=? AND counterparty_id IS NULL",
                  [$counterpartyId, $threadKey]);
        }

        $items = RequestItems::ensure($requestId);
        Logger::info('mail', "Подбор заведён по переписке вручную: запрос #$requestId",
                     ['thread_key' => $threadKey, 'manager_id' => $managerId, 'items' => count($items)]);

        return ['request_id' => $requestId, 'created' => true, 'items' => count($items)];
    }

    /** Returns true when the letter actually became a request. */
    private static function toRequest(array $row): bool {
        // Free verdict first: a Yandex.Direct digest or a MoySklad ticket is not a
        // request and must not cost a model call (module 006).
        $pre = Triage::prefilter($row);
        if ($pre && $pre['category'] === 'bounce') {
            // An answer of ours that never arrived. The report is archived like
            // any service letter, but the letter it is about is marked first —
            // a silent bounce is a client who thinks we ignored him (module 015).
            $report = Bounce::detect($row);
            $failed = $report ? Bounce::applyTo($report) : null;
            if ($failed) {
                $pre['reason'] = 'Не доставлено: ' . ($report['recipient'] ?: 'адрес не указан')
                               . ($report['diagnostic'] !== '' ? ' — ' . $report['diagnostic'] : '');
                Notifier::notify('mail_bounced', 'Письмо не доставлено',
                    ($report['recipient'] ?: (string)$failed['to_emails']) . ' — ' . (string)$failed['subject'],
                    'mail', (int)$failed['id'], null, '/#mail/msg/' . (int)$failed['id']);
            }
        }
        if ($pre) {
            Db::update('mail_messages', [
                'processed_at'  => date('Y-m-d H:i:s'),
                'category'      => $pre['category'],
                'triage_reason' => $pre['reason'],
                'error'         => null,
            ], 'id=?', [$row['id']]);
            return false;
        }

        $attachments = Db::all("SELECT * FROM attachments WHERE mail_message_id=?", [$row['id']]);
        $attachmentText = '';
        foreach ($attachments as $a) {
            if (!empty($a['extracted_text'])) {
                $attachmentText .= "--- Вложение: {$a['filename']} ---\n" . $a['extracted_text'] . "\n\n";
            }
        }

        // Nothing to read — a bare auto-reply, a picture-only newsletter. Archive it
        // and stop: asking the model to parse an empty letter only fills the log.
        if (mb_strlen(trim((string)$row['body_text'])) < 20 && trim($attachmentText) === '') {
            // Unless it came with files. A photo of a broken helmet or a
            // photographed ТЗ is a letter with everything in the picture — 1014
            // incoming images in the archive — and recognition can be off or
            // have failed. Dropping it as «служебное» loses a real client.
            $hasFiles = count($attachments) > 0;
            Db::update('mail_messages', [
                'processed_at'  => date('Y-m-d H:i:s'),
                'category'      => $hasFiles ? 'other' : 'service',
                'triage_reason' => $hasFiles
                    ? 'Текста нет, только вложения — прочитать глазами'
                    : 'Пустое письмо — нечего разбирать',
                'error'         => null,
            ], 'id=?', [$row['id']]);
            if ($hasFiles) {
                Notifier::notify('new_request', 'Письмо без текста, только вложения',
                    (string)$row['subject'] . ' — от ' . (string)$row['from_email'],
                    'mail', (int)$row['id'], null, '/#mail/msg/' . (int)$row['id']);
            }
            return false;
        }

        // The letter as a manager would read it: without our own quoted answer,
        // without the client's gateway banner, and with the subject, which
        // carries the order number in every reply to a shop notification.
        $clean = MailText::forAnalysis((string)$row['body_text']);
        $parsed = Triage::classify($clean, $attachmentText, (string)$row['subject']);
        $category = (string)($parsed['category'] ?? 'other');

        // A site form with a phone and no address: there is no reply to draft,
        // only a call to make. The category says so instead of the model guessing.
        if ((int)($row['needs_call'] ?? 0) === 1) {
            $category = 'callback';
            $parsed['category'] = $category;
            $parsed['category_source'] = 'prefilter';
            $parsed['category_reason'] = 'Заявка с сайта: оставлен телефон, адреса нет — нужен звонок';
        }
        $type = ($parsed['request_type'] ?? 'kp_request') === 'order' ? 'order' : 'kp_request';

        // A supplier pitch, a SEO mailing or a service notice the model recognised:
        // archive it under its category and stop — no request, no notification.
        if (!Triage::createsRequest($category)) {
            Db::update('mail_messages', [
                'processed_at'  => date('Y-m-d H:i:s'),
                'category'      => $category,
                'triage_reason' => (string)($parsed['category_reason'] ?? ''),
                'error'         => null,
            ], 'id=?', [$row['id']]);
            Logger::info('mail', "Письмо от {$row['from_email']} отнесено к «" . Triage::label($category) . "» — запрос не создаётся",
                         ['mail_message_id' => $row['id']]);
            return false;
        }

        // A form letter with no visitor address still arrives from OUR mailbox.
        // Resolving a company by that address would open a card for ourselves.
        $senderEmail = (string)($row['from_email'] ?? '');
        if (Crm::isOurAddress($senderEmail)) $senderEmail = '';
        $form = $row['form_json'] ? (json_decode((string)$row['form_json'], true) ?: []) : [];

        $counterpartyId = Crm::resolveCounterparty([
            'inn'            => $parsed['inn'] ?? '',
            'name'           => $parsed['org_name'] ?? '',
            'email'          => $senderEmail,
            'contact_person' => $parsed['contact_person'] ?? ($form['name'] ?? null) ?: ($row['from_name'] ?: null),
            'phone'          => $parsed['contact_phone'] ?? ($form['phone'] ?? null),
            // The letter itself, signatures and quoted thread included: where
            // «АО "Уралэлемент"» lives when the model did not extract it
            'text'           => (string)$row['body_text'] . "\n" . $attachmentText,
        ]);
        if ($counterpartyId) {
            Crm::upsertContact(
                $counterpartyId,
                $parsed['contact_person'] ?? ($form['name'] ?? null) ?: ($row['from_name'] ?: null),
                $senderEmail ?: null,
                $parsed['contact_phone'] ?? ($form['phone'] ?? null)
            );
            // «Мы работаем с ЭДО. Идентификатор в Диадок: 2BM-7810964292-…» —
            // 54 letters of the archive carry one, and it was retyped by hand
            // every time. The regex finds it; the model only adds the operator.
            Crm::rememberEdo($counterpartyId, (string)$row['body_text'] . "\n" . $attachmentText, $parsed['edo'] ?? null);
            Crm::fillRequisitesFromAttachments($counterpartyId, $attachments);
        }

        $requestId = Db::insert('requests', [
            'source'           => 'email',
            'raw_text'         => $row['body_text'],
            'parsed_json'      => json_encode($parsed, JSON_UNESCAPED_UNICODE),
            'counterparty_id'  => $counterpartyId,
            'status'           => 'new',
            'type'             => $type,
            'type_source'      => 'llm',
            'category'            => $category,
            'category_confidence' => (float)($parsed['category_confidence'] ?? 0),
            'category_reason'     => (string)($parsed['category_reason'] ?? ''),
            'category_source'     => (string)($parsed['category_source'] ?? 'llm'),
            'email_from'       => $senderEmail,
            'email_subject'    => $row['subject'],
            'email_message_id' => $row['message_id'],
        ]);

        $corrId = Crm::logEvent($counterpartyId, 'in', (string)$row['body_text'], [
            'request_id' => $requestId,
            'subject'    => $row['subject'],
            'email_from' => $senderEmail,
        ]);

        foreach ($attachments as $a) {
            Db::update('attachments', [
                'correspondence_id' => $corrId,
                'request_id'        => $requestId,
                'counterparty_id'   => $counterpartyId,
            ], 'id=?', [$a['id']]);
        }
        if ($attachments) Db::update('correspondence', ['has_attachment' => 1], 'id=?', [$corrId]);

        Db::update('mail_messages', [
            'processed_at'      => date('Y-m-d H:i:s'),
            'category'          => $category,
            'triage_reason'     => (string)($parsed['category_reason'] ?? ''),
            'request_id'        => $requestId,
            'counterparty_id'   => $counterpartyId,
            'correspondence_id' => $corrId,
            'error'             => null,
        ], 'id=?', [$row['id']]);

        self::autoDraft($row, $category, $counterpartyId, $attachmentText, $requestId);

        $orgName = $parsed['org_name'] ?? null;
        $title = Triage::label($category) . ($orgName ? " от $orgName" : ' от ' . ($senderEmail ?: (string)$row['from_email']));
        // A lead that left only a phone is a call, and the number belongs in the
        // notification itself — the manager should not have to open the letter.
        if ($category === 'callback' && !empty($form['phone'])) {
            $title = 'Заявка с сайта — позвонить: ' . $form['phone']
                   . (!empty($form['name']) ? ' (' . $form['name'] . ')' : '');
        }
        // The tap lands on the letter itself — that is what «пришло новое письмо»
        // means; the request it created is one link away inside.
        Notifier::notify('new_request', $title, $row['subject'], 'request', $requestId, null,
                         '/#mail/msg/' . (int)$row['id']);
        return true;
    }

    /**
     * Draft the answer right at sync time when TRIAGE_AUTO_DRAFT is on. Off by
     * default: until the manager trusts the categories, every letter should not
     * cost a second model call. A failure here must never lose the request.
     */
    private static function autoDraft(array $row, string $category, ?int $counterpartyId,
                                     string $attachmentText, ?int $requestId = null): void {
        if ((int)Settings::get('TRIAGE_AUTO_DRAFT', 0) !== 1) return;
        if (Triage::route($category)[0] === null) return;
        try {
            // The same «чего у нас нет» block the reply button sends (module 018).
            // A draft prepared here is used as-is on the first click, so leaving
            // it out would put the silence back for exactly those letters.
            $unmatched = [];
            $outOfScope = [];
            if ($requestId && RequestItems::ensure($requestId)) {
                $unmatched = RequestItems::unmatched($requestId);
                // И то, чем мы не занимаемся: черновик про эти позиции молчит
                // и ничего по ним не обещает (модуль 022)
                $outOfScope = RequestItems::outOfScope($requestId);
            }
            $text = Triage::draft($row, $category, [
                'org_name'        => $counterpartyId ? (string)Db::val("SELECT name FROM counterparties WHERE id=?", [$counterpartyId]) : '',
                'attachments'     => $attachmentText,
                'counterparty_id' => $counterpartyId,
                'email_rules'     => (string)(Db::val("SELECT content FROM email_rules ORDER BY id DESC LIMIT 1") ?: ''),
                'tov'             => Tov::read(),
                'unmatched'       => $unmatched,
                'out_of_scope'    => $outOfScope,
            ]);
            Db::update('mail_messages', ['draft_text' => $text, 'draft_at' => date('Y-m-d H:i:s')], 'id=?', [$row['id']]);
        } catch (Throwable $e) {
            Logger::exception('mail', $e, ['mail_message_id' => $row['id'], 'stage' => 'auto_draft']);
        }
    }

    // ---- Spam ----

    /**
     * The manager pressed «Спам» on a letter. Filed as spam here (so the
     * request pipeline stops treating it as one), moved into the mailbox's own
     * Spam/Junk folder (so the server itself — and every other client on the
     * account — agrees it is spam, not just our database), and the sender is
     * remembered so the next letter from them is never even offered as a request.
     */
    public static function markAsSpam(int $mailMessageId): array {
        $row = Db::one("SELECT * FROM mail_messages WHERE id=?", [$mailMessageId]);
        if (!$row) throw new RuntimeException('Письмо не найдено');

        // Спам уходит С ЭКРАНА, а не только меняет категорию. Раньше здесь
        // проставлялась одна метка: письмо оставалось в списках и на доске, и
        // менеджер жал «спам» второй и третий раз, ничего не добиваясь.
        // Архив — то же самое место, куда уходит «не наш профиль» (модуль 023).
        Db::update('mail_messages', [
            'category'        => 'spam',
            'triage_reason'   => 'Отмечено спамом вручную',
            'archived_at'     => date('Y-m-d H:i:s'),
            'archived_reason' => 'spam',
            'is_read'         => 1,
        ], 'id=?', [$mailMessageId]);
        if (!empty($row['request_id'])) {
            Db::update('requests', [
                'category'        => 'spam',
                'category_source' => 'manager',
            ], 'id=?', [(int)$row['request_id']]);
        }
        // Карточка доски, построенная вокруг письма, уходит вместе с ним;
        // карточка компании остаётся — спамом объявлено письмо, а не контрагент
        Db::q("DELETE FROM board_cards WHERE mail_message_id=? AND counterparty_id IS NULL", [$mailMessageId]);
        if (!empty($row['thread_key'])) {
            Db::q("DELETE FROM board_cards WHERE thread_key=? AND counterparty_id IS NULL", [(string)$row['thread_key']]);
        }
        // Спам унёс последнее письмо компании — карточке на доске больше нечего
        // показывать (модуль 026)
        self::pruneBoard($row['counterparty_id'] ?? null);

        $moved = false;
        $moveError = null;
        if ($row['direction'] === 'in' && (int)$row['uid'] > 0 && !empty($row['mailbox_id']) && EmailReader::available()) {
            $box = Mailboxes::get((int)$row['mailbox_id']);
            if ($box) {
                try {
                    $reader = new EmailReader(Mailboxes::cfg($box));
                    $reader->connect((string)($row['folder'] ?: 'INBOX'));
                    $junk = $reader->findJunkFolder();
                    if ($junk !== null) {
                        $moved = $reader->moveToJunk((int)$row['uid'], $junk);
                    }
                    $reader->close();
                } catch (Throwable $e) {
                    $moveError = $e->getMessage();
                    Logger::exception('mail', $e, ['mail_message_id' => $mailMessageId, 'stage' => 'mark_spam']);
                }
            }
        }

        $from = mb_strtolower(trim((string)$row['from_email']));
        if ($from !== '') {
            $list = array_values(array_filter(array_map('trim', explode(',', (string)Settings::get('TRIAGE_SPAM_SENDERS', '')))));
            $already = array_map('mb_strtolower', $list);
            if (!in_array($from, $already, true)) {
                $list[] = $from;
                Settings::set('TRIAGE_SPAM_SENDERS', implode(', ', $list));
            }
        }

        Logger::info('mail', "Письмо #$mailMessageId отмечено как спам" . ($moved ? ' и перемещено в папку спама на сервере' : ''),
            ['mail_message_id' => $mailMessageId, 'moved' => $moved, 'move_error' => $moveError]);

        return ['moved' => $moved, 'move_error' => $moveError];
    }

    // ---- Archive: письмо уходит с экрана, а не из ящика (модуль 019) ----

    /**
     * «В архив (не наш профиль)».
     *
     * Половина входящих — запросы на то, чем мы не торгуем. Спамом это назвать
     * нельзя (отправитель живой и писал по делу), удалять — тоже: письмо нужно
     * найти, если клиент вернётся с другим запросом. Поэтому оно уходит в
     * «Архив» на самом сервере и перестаёт числиться в панели: из списков, из
     * доски и из счётчика неотвеченных. Категория ставится руками — модель
     * `not_our_profile` не выдаёт.
     *
     * Ответ на такое письмо не готовится: запрос, который оно завело, закрывается
     * вместе с ним.
     */
    public static function archiveMessage(int $mailMessageId, ?int $managerId = null,
                                          string $reason = 'not_our_profile', bool $prune = true): array {
        $row = Db::one("SELECT * FROM mail_messages WHERE id=?", [$mailMessageId]);
        if (!$row) throw new RuntimeException('Письмо не найдено');

        $data = ['archived_at' => date('Y-m-d H:i:s'), 'archived_reason' => $reason, 'is_read' => 1];
        if ($reason === 'not_our_profile') {
            $data['category']      = 'not_our_profile';
            $data['triage_reason'] = 'В архив вручную: не наш профиль';
        }
        Db::update('mail_messages', $data, 'id=?', [$mailMessageId]);

        if ($reason === 'not_our_profile' && !empty($row['request_id'])) {
            Db::update('requests', ['category' => 'not_our_profile', 'category_source' => 'manager'],
                       'id=?', [(int)$row['request_id']]);
        }
        // Карточка доски, построенная вокруг этого письма, уходит с ним. Карточка
        // компании остаётся, пока у компании есть хоть одно живое письмо; когда
        // в архив ушло последнее — уходит и она, иначе на доске стоит карточка,
        // внутри которой пусто (модуль 026).
        Db::q("DELETE FROM board_cards WHERE mail_message_id=? AND counterparty_id IS NULL", [$mailMessageId]);
        if ($prune) self::pruneBoard($row['counterparty_id'] ?? null);

        [$moved, $folder, $moveError] = self::moveToArchiveFolder($row);

        Logger::info('mail', "Письмо #$mailMessageId убрано в архив" . ($moved ? " и перемещено в «{$folder}» на сервере" : ''),
            ['mail_message_id' => $mailMessageId, 'manager_id' => $managerId,
             'reason' => $reason, 'moved' => $moved, 'move_error' => $moveError]);

        return ['archived' => 1, 'moved' => $moved, 'folder' => $folder, 'move_error' => $moveError];
    }

    /** Вся переписка разом — «не наш профиль» редко бывает про одно письмо. */
    public static function archiveThread(string $threadKey, ?int $managerId = null,
                                         string $reason = 'not_our_profile'): array {
        $ids = array_column(Db::all("SELECT id FROM mail_messages WHERE thread_key=? AND archived_at IS NULL", [$threadKey]), 'id');
        if (!$ids) throw new RuntimeException('Цепочка не найдена или уже в архиве');

        $archived = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                $res = self::archiveMessage((int)$id, $managerId, $reason, false);
                $archived++;
                if (!empty($res['move_error'])) $errors[] = (string)$res['move_error'];
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                Logger::exception('mail', $e, ['mail_message_id' => $id, 'stage' => 'archive_thread']);
            }
        }
        Db::q("DELETE FROM board_cards WHERE thread_key=? AND counterparty_id IS NULL", [$threadKey]);
        self::pruneBoard(Db::val("SELECT counterparty_id FROM mail_messages
                                  WHERE thread_key=? AND counterparty_id IS NOT NULL LIMIT 1", [$threadKey]));

        return ['archived' => $archived, 'move_error' => $errors ? implode('; ', array_unique($errors)) : null];
    }

    /**
     * Снять с доски карточки, внутри которых не осталось живых писем.
     *
     * Никогда не фатально: убрать письмо с экрана важнее, чем прибраться на
     * доске, и упавшая уборка не должна отменять архивацию.
     */
    private static function pruneBoard($counterpartyId): int {
        try {
            require_once __DIR__ . '/boards.php';
            return Boards::pruneEmptyCards($counterpartyId ? (int)$counterpartyId : null);
        } catch (Throwable $e) {
            Logger::warning('mail', 'Доска не прибралась: ' . $e->getMessage());
            return 0;
        }
    }

    /** Вернуть письмо на экран. Папку на сервере не трогаем — письмо там и лежит. */
    public static function unarchiveMessage(int $mailMessageId): array {
        $row = Db::one("SELECT id, category FROM mail_messages WHERE id=?", [$mailMessageId]);
        if (!$row) throw new RuntimeException('Письмо не найдено');
        $data = ['archived_at' => null, 'archived_reason' => null];
        // Категорию возвращаем в «не определено»: прежнюю никто не помнит, а
        // «не наш профиль» на видимом письме — это уже неправда.
        if (in_array((string)($row['category'] ?? ''), ['not_our_profile', 'spam'], true)) $data['category'] = 'other';
        Db::update('mail_messages', $data, 'id=?', [$mailMessageId]);
        return ['restored' => 1];
    }

    /** Вся цепочка обратно на экран. */
    public static function unarchiveThread(string $threadKey): array {
        $ids = array_column(Db::all("SELECT id FROM mail_messages WHERE thread_key=? AND archived_at IS NOT NULL", [$threadKey]), 'id');
        foreach ($ids as $id) self::unarchiveMessage((int)$id);
        return ['restored' => count($ids)];
    }

    /**
     * Письма ящика — с экрана и обратно. Так выключенный ящик перестаёт засорять
     * доску, а включённый возвращает всё, что унёс: reason помнит, чьи это были
     * письма, поэтому «не наш профиль» обратно не всплывает.
     */
    public static function setMailboxMessagesHidden(int $mailboxId, bool $hidden): int {
        if ($hidden) {
            Db::q("UPDATE mail_messages SET archived_at=datetime('now'), archived_reason='mailbox_off'
                   WHERE mailbox_id=? AND archived_at IS NULL", [$mailboxId]);
        } else {
            Db::q("UPDATE mail_messages SET archived_at=NULL, archived_reason=NULL
                   WHERE mailbox_id=? AND archived_reason='mailbox_off'", [$mailboxId]);
        }
        $changed = (int)Db::pdo()->query("SELECT changes()")->fetchColumn();
        // Выключенный ящик уносит письма с экрана — и карточки, в которых после
        // этого пусто (модуль 026). Включённый ящик вернёт их сам: `Boards::sync()`
        // видит, что компания снова пишет.
        if ($hidden) self::pruneBoard(null);
        return $changed;
    }

    /**
     * Положить письмо в «Архив» на сервере. Best-effort целиком: недоступный
     * ящик не должен мешать убрать письмо с экрана.
     * @return array{0:bool,1:?string,2:?string} [moved, folder, error]
     */
    private static function moveToArchiveFolder(array $row): array {
        if ((string)($row['direction'] ?? '') !== 'in') return [false, null, null];
        if ((int)($row['uid'] ?? 0) <= 0 || empty($row['mailbox_id'])) return [false, null, null];
        if (!EmailReader::available()) return [false, null, 'Расширение PHP imap не установлено на сервере'];

        $box = Mailboxes::get((int)$row['mailbox_id']);
        if (!$box) return [false, null, null];

        $reader = null;
        try {
            $reader = new EmailReader(Mailboxes::cfg($box));
            $reader->connect((string)($row['folder'] ?: 'INBOX'));
            $archive = $reader->findArchiveFolder();
            if ($archive === null) return [false, null, 'На сервере нет папки «Архив» — письмо убрано только из панели'];
            if ($archive === (string)($row['folder'] ?: 'INBOX')) return [false, $archive, null];
            return [$reader->moveToFolder((int)$row['uid'], $archive), $archive, null];
        } catch (Throwable $e) {
            Logger::exception('mail', $e, ['mail_message_id' => $row['id'], 'stage' => 'archive']);
            return [false, null, $e->getMessage()];
        } finally {
            if ($reader) { try { $reader->close(); } catch (Throwable $e) { /* already gone */ } }
        }
    }

    // ---- Delete ----

    /**
     * «Удалить письмо». Deleting has to mean the same thing on both sides, or it
     * means nothing: the letter goes to the mailbox's own «Корзина» on the server
     * and its row leaves the archive here. A tombstone keeps the UID, so the next
     * sync does not cheerfully download the letter back into the panel.
     *
     * A card left with no letters at all is removed from the board too — the
     * conversation's and the company's alike (issue #93): an empty card is only
     * a dead link.
     */
    public static function deleteMessage(int $mailMessageId, ?int $managerId = null): array {
        $row = Db::one("SELECT * FROM mail_messages WHERE id=?", [$mailMessageId]);
        if (!$row) throw new RuntimeException('Письмо не найдено');

        [$serverState, $serverError] = self::removeFromServer($row);

        Db::insert('mail_deleted', [
            'mailbox_id'   => $row['mailbox_id'] !== null ? (int)$row['mailbox_id'] : null,
            'folder'       => (string)($row['folder'] ?? ''),
            'uid'          => (int)($row['uid'] ?? 0),
            'message_id'   => $row['message_id'] ?: null,
            'direction'    => $row['direction'] ?? null,
            'subject'      => $row['subject'] ?? null,
            'from_email'   => $row['from_email'] ?? null,
            'date_at'      => $row['date_at'] ?? null,
            'manager_id'   => $managerId,
            'server_state' => $serverState,
        ]);

        // Письмо уходит В КОРЗИНУ, а не в никуда (модуль 040): строка целиком
        // и список её файлов лежат в `mail_trash`, файлы с диска не стираются.
        // Пока корзину не очистили, письмо можно вернуть — любое.
        self::toTrash($row, $managerId, $serverState);
        // Строки вложений уходят вместе с письмом, а ФАЙЛЫ остаются на диске:
        // из корзины письмо возвращается со своими файлами
        Db::q("DELETE FROM attachments WHERE mail_message_id=?", [$mailMessageId]);
        // The cards go first: `mail_message_id` is ON DELETE SET NULL, so once the
        // row is gone there is nothing left to recognise the card by. A card built
        // around this one letter goes with it; a company card only loses the
        // pointer — the company itself has not been deleted.
        Db::q("DELETE FROM board_cards WHERE mail_message_id=? AND counterparty_id IS NULL", [$mailMessageId]);
        Db::q("DELETE FROM mail_messages WHERE id=?", [$mailMessageId]);

        $threadKey = (string)($row['thread_key'] ?? '');
        $threadEmpty = $threadKey !== '' && !Db::val("SELECT 1 FROM mail_messages WHERE thread_key=? LIMIT 1", [$threadKey]);
        if ($threadEmpty) Db::q("DELETE FROM board_cards WHERE thread_key=? AND counterparty_id IS NULL", [$threadKey]);
        // Последнее письмо компании — и её карточка уходит с доски (issue #93)
        $cardRemoved = !empty($row['counterparty_id']) ? self::pruneBoard((int)$row['counterparty_id']) : 0;

        Logger::info('mail', "Письмо #$mailMessageId удалено" . ($serverState === 'trashed' ? ' и перемещено в корзину на сервере' : ''),
            ['mail_message_id' => $mailMessageId, 'manager_id' => $managerId,
             'server_state' => $serverState, 'server_error' => $serverError]);

        return [
            'deleted'      => 1,
            'thread_key'   => $threadKey ?: null,
            'thread_empty' => $threadEmpty,
            'card_removed' => $cardRemoved,
            'server_state' => $serverState,
            'server_error' => $serverError,
        ];
    }

    /**
     * ==== Корзина писем (модуль 040) ====
     *
     * Удалить можно любое письмо, и любое можно вернуть. Здесь письмо
     * складывается целиком: строка архива и список её файлов. Файлы с диска
     * не стираются — иначе возвращать было бы нечего.
     */
    private static function toTrash(array $row, ?int $managerId, string $serverState): void {
        $files = Db::all("SELECT * FROM attachments WHERE mail_message_id=?", [(int)$row['id']]);
        Db::insert('mail_trash', [
            'thread_key'   => $row['thread_key'] ?? null,
            'subject'      => $row['subject'] ?? null,
            'from_email'   => $row['from_email'] ?? null,
            'to_emails'    => $row['to_emails'] ?? null,
            'direction'    => $row['direction'] ?? null,
            'date_at'      => $row['date_at'] ?? null,
            'deleted_at'   => date('Y-m-d H:i:s'),
            'deleted_by'   => $managerId,
            'server_state' => $serverState,
            'payload_json' => json_encode($row, JSON_UNESCAPED_UNICODE),
            'files_json'   => $files ? json_encode($files, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** Что лежит в корзине — с самого свежего. */
    public static function trash(int $limit = 200): array {
        return Db::all("SELECT id, thread_key, subject, from_email, to_emails, direction, date_at,
                               deleted_at, server_state,
                               (SELECT name FROM managers WHERE id = deleted_by) AS deleted_by_name
                        FROM mail_trash ORDER BY deleted_at DESC, id DESC LIMIT ?", [$limit]);
    }

    /**
     * Письмо из корзины — обратно в архив.
     *
     * Надгробие снимается: иначе следующая синхронизация увидит «это письмо
     * удалено» и не скачает его обратно, а мы его только что вернули.
     */
    public static function restoreFromTrash(int $trashId): array {
        $row = Db::one("SELECT * FROM mail_trash WHERE id=?", [$trashId]);
        if (!$row) throw new RuntimeException('В корзине такого письма нет');
        $msg = json_decode((string)$row['payload_json'], true);
        if (!is_array($msg) || !$msg) throw new RuntimeException('Письмо в корзине повреждено');

        $oldId = (int)($msg['id'] ?? 0);
        unset($msg['id']);
        // Ящик мог быть удалён вместе с письмом — тогда письмо возвращается
        // «ничьим»: читать его это не мешает, а внешний ключ не пускает
        if (!empty($msg['mailbox_id']) && !Db::val("SELECT 1 FROM mailboxes WHERE id=?", [(int)$msg['mailbox_id']])) {
            $msg['mailbox_id'] = null;
        }
        $newId = (int)Db::insert('mail_messages', $msg);

        foreach (json_decode((string)($row['files_json'] ?? '[]'), true) ?: [] as $file) {
            unset($file['id']);
            $file['mail_message_id'] = $newId;
            try { Db::insert('attachments', $file); }
            catch (Throwable $e) { Logger::exception('mail', $e, ['stage' => 'restore_attachment']); }
        }

        if (!empty($msg['mailbox_id'])) {
            Db::q("DELETE FROM mail_deleted WHERE mailbox_id=? AND folder=? AND uid=?",
                  [(int)$msg['mailbox_id'], (string)($msg['folder'] ?? ''), (int)($msg['uid'] ?? 0)]);
        }
        Db::q("DELETE FROM mail_trash WHERE id=?", [$trashId]);
        Logger::info('mail', "Письмо возвращено из корзины (было #$oldId, стало #$newId)",
                     ['mail_message_id' => $newId]);
        return ['restored' => 1, 'mail_message_id' => $newId, 'thread_key' => $msg['thread_key'] ?? null];
    }

    /** Очистить корзину — вот теперь насовсем, вместе с файлами. */
    public static function purgeTrash(?int $trashId = null): int {
        $rows = $trashId
            ? array_filter([Db::one("SELECT * FROM mail_trash WHERE id=?", [$trashId])])
            : Db::all("SELECT * FROM mail_trash");
        $purged = 0;
        foreach ($rows as $row) {
            foreach (json_decode((string)($row['files_json'] ?? '[]'), true) ?: [] as $file) {
                $path = ROOT . '/' . ltrim((string)($file['path'] ?? ''), '/');
                if (!empty($file['path']) && is_file($path)) @unlink($path);
            }
            Db::q("DELETE FROM mail_trash WHERE id=?", [(int)$row['id']]);
            $purged++;
        }
        if ($purged) Logger::info('mail', "Корзина очищена: писем $purged");
        return $purged;
    }

    /**
     * Забыть письмо, не трогая почтовый сервер. Так уходит архив вместе с
     * удаляемым ящиком: доступа к серверу уже нет (пароль удаляется в той же
     * операции), а надгробие в `mail_deleted` бессмысленно — заново это письмо
     * скачивать нечем.
     */
    public static function forgetMessage(int $mailMessageId): void {
        self::dropAttachments($mailMessageId);
        Db::q("DELETE FROM board_cards WHERE mail_message_id=? AND counterparty_id IS NULL", [$mailMessageId]);
        Db::q("DELETE FROM mail_messages WHERE id=?", [$mailMessageId]);
    }

    /** The whole conversation at once — «удалить переписку» in the thread view. */
    public static function deleteThread(string $threadKey, ?int $managerId = null): array {
        $ids = array_column(Db::all("SELECT id FROM mail_messages WHERE thread_key=? ORDER BY id", [$threadKey]), 'id');
        if (!$ids) throw new RuntimeException('Цепочка не найдена');

        $deleted = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                $res = self::deleteMessage((int)$id, $managerId);
                $deleted++;
                if (!empty($res['server_error'])) $errors[] = (string)$res['server_error'];
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                Logger::exception('mail', $e, ['mail_message_id' => $id, 'stage' => 'delete_thread']);
            }
        }
        Db::q("DELETE FROM board_cards WHERE thread_key=? AND counterparty_id IS NULL", [$threadKey]);

        return ['deleted' => $deleted, 'server_error' => $errors ? implode('; ', array_unique($errors)) : null];
    }

    /**
     * Put the letter in the server's Trash. Falls back to the IMAP \Deleted flag
     * when the account has no such folder, and stays best-effort throughout: a
     * mailbox that is unreachable right now must not block the deletion here.
     * @return array{0:string,1:?string} [state, error]
     */
    private static function removeFromServer(array $row): array {
        if ((int)($row['uid'] ?? 0) <= 0 || empty($row['mailbox_id'])) return ['local', null];
        if (!EmailReader::available()) return ['local', 'Расширение PHP imap не установлено на сервере'];

        $box = Mailboxes::get((int)$row['mailbox_id']);
        if (!$box) return ['local', null];

        $reader = null;
        try {
            $reader = new EmailReader(Mailboxes::cfg($box));
            $reader->connect((string)($row['folder'] ?: 'INBOX'));
            $trash = $reader->findTrashFolder();
            if ($trash !== null && $trash !== (string)($row['folder'] ?: 'INBOX')) {
                if ($reader->moveToFolder((int)$row['uid'], $trash)) return ['trashed', null];
            }
            // No Trash on the account (or the move was refused) — expunge it instead
            if ($reader->deleteUid((int)$row['uid'])) return ['expunged', null];
            return ['local', 'Почтовый сервер не удалил письмо — оно осталось в ящике'];
        } catch (Throwable $e) {
            Logger::exception('mail', $e, ['mail_message_id' => $row['id'], 'stage' => 'delete']);
            return ['local', $e->getMessage()];
        } finally {
            if ($reader) { try { $reader->close(); } catch (Throwable $e) { /* already gone */ } }
        }
    }

    /** Files of a deleted letter go with it — nothing else points at them. */
    private static function dropAttachments(int $mailMessageId): void {
        foreach (Db::all("SELECT id, path FROM attachments WHERE mail_message_id=?", [$mailMessageId]) as $a) {
            $path = ROOT . '/' . ltrim((string)$a['path'], '/');
            if ($a['path'] && is_file($path)) @unlink($path);
            Db::q("DELETE FROM attachments WHERE id=?", [$a['id']]);
        }
    }

    // ---- Full archive download (FR-053) ----

    /** Folders a full download walks: column prefix => [folder name, direction]. */
    private static function backfillFolders(array $box): array {
        $folders = ['in' => [(string)($box['imap_folder_in'] ?: 'INBOX'), 'in']];
        $sent = trim((string)($box['imap_folder_sent'] ?? ''));
        if ($sent !== '' && !empty($box['sync_sent'])) $folders['sent'] = [$sent, 'out'];
        return $folders;
    }

    /**
     * Pull the WHOLE history of a mailbox into the archive, not just what arrived
     * since the last check. Resumable by design: a shared host kills a long request,
     * so every batch stores its cursor and the next call carries on from there.
     * Nothing here creates requests — old mail is history, not a new КП.
     */
    public static function backfill(array $box, ?int $budgetSeconds = null, ?int $batch = null): array {
        $box = Mailboxes::get((int)$box['id']) ?: $box;
        $res = ['mailbox_id' => (int)$box['id'], 'name' => $box['name'], 'stored' => 0, 'scanned' => 0, 'error' => null];
        if (!EmailReader::available()) {
            $res['error'] = 'Расширение PHP imap не установлено на сервере';
            return $res + ['done' => false, 'percent' => 0, 'progress' => Mailboxes::backfillProgress($box)];
        }

        $deadline = microtime(true) + max(5, $budgetSeconds ?? (int)Settings::get('MAIL_BACKFILL_SECONDS', 20));
        $left     = max(1, $batch ?? (int)Settings::get('MAIL_BACKFILL_BATCH', 100));
        if (empty($box['backfill_started_at'])) {
            Db::update('mailboxes', ['backfill_started_at' => date('Y-m-d H:i:s'), 'backfill_finished_at' => null], 'id=?', [$box['id']]);
            $box['backfill_started_at'] = date('Y-m-d H:i:s');
        }

        try {
            foreach (self::backfillFolders($box) as $key => [$folder, $direction]) {
                if (!empty($box["backfill_done_$key"])) continue;
                if ($left <= 0 || microtime(true) >= $deadline) break;
                $one = self::backfillFolder($box, $key, $folder, $direction, $left, $deadline);
                $res['stored']  += $one['stored'];
                $res['scanned'] += $one['scanned'];
                $left -= $one['scanned'];
                $box = Mailboxes::get((int)$box['id']) ?: $box;   // cursors moved
            }
            Db::update('mailboxes', ['last_error' => null], 'id=?', [$box['id']]);
        } catch (Throwable $e) {
            $res['error'] = $e->getMessage();
            Db::update('mailboxes', ['last_error' => 'Архив: ' . $e->getMessage()], 'id=?', [$box['id']]);
            Logger::exception('mail', $e, ['mailbox_id' => $box['id'], 'stage' => 'backfill']);
            $box = Mailboxes::get((int)$box['id']) ?: $box;
        }

        $progress = Mailboxes::backfillProgress($box);
        if ($progress['done'] && empty($box['backfill_finished_at'])) {
            Db::update('mailboxes', ['backfill_finished_at' => date('Y-m-d H:i:s')], 'id=?', [$box['id']]);
            $progress['finished_at'] = date('Y-m-d H:i:s');
            Logger::info('mail', "Ящик «{$box['name']}»: архив писем скачан полностью", ['mailbox_id' => $box['id']]);
        }
        return $res + ['done' => $progress['done'], 'percent' => $progress['percent'], 'progress' => $progress];
    }

    /** One folder, one batch: walk UIDs upwards from the stored cursor. */
    private static function backfillFolder(array $box, string $key, string $folder, string $direction, int $limit, float $deadline): array {
        $cursorCol = "backfill_uid_$key";
        $maxCol    = "backfill_max_$key";
        $doneCol   = "backfill_done_$key";
        $uidCol    = $key === 'in' ? 'last_uid_in' : 'last_uid_sent';

        $reader = new EmailReader(Mailboxes::cfg($box));
        $reader->connect($folder);
        try {
            return self::backfillWalk($box, $key, $folder, $direction, $limit, $deadline, $reader);
        } finally {
            $reader->close();
        }
    }

    /**
     * The walk itself, split off from the connection so it can be exercised without
     * an IMAP server. $reader only has to answer maxUid / uidsInRange / fetchUid.
     */
    private static function backfillWalk(array $box, string $key, string $folder, string $direction, int $limit, float $deadline, $reader): array {
        $cursorCol = "backfill_uid_$key";
        $maxCol    = "backfill_max_$key";
        $doneCol   = "backfill_done_$key";
        $uidCol    = $key === 'in' ? 'last_uid_in' : 'last_uid_sent';

        $maxUid = $reader->maxUid();
        $cursor = (int)($box[$cursorCol] ?? 0);
        Db::update('mailboxes', [$maxCol => $maxUid], 'id=?', [$box['id']]);

        $stored = 0;
        $scanned = 0;
        // UIDs are sparse — deleted mail leaves holes, so we probe by windows
        $window = 500;
        while ($cursor < $maxUid && $scanned < $limit && microtime(true) < $deadline) {
            $to   = (int)min($maxUid, $cursor + $window);
            $uids = $reader->uidsInRange($cursor + 1, $to);
            foreach ($uids as $uid) {
                if ($scanned >= $limit || microtime(true) >= $deadline) break;
                $scanned++;
                $cursor = $uid;
                $msg = $reader->fetchUid($uid);
                if (!$msg) continue;
                $id = MailArchive::storeIncoming($box, $msg, $direction, true);
                if (!$id) continue;
                $stored++;
                self::storeAttachments($id, $msg['attachments'] ?? [], false);
                // Downloaded history is history on a company card, not a pile in
                // the archive: the same link an mbox import makes (module 021)
                MailArchive::linkCounterparty($id);
            }
            // The window held nothing (or was fully consumed) — jump past it
            if (!$uids || $cursor >= end($uids)) $cursor = max($cursor, min($to, $maxUid));
        }

        $done = $cursor >= $maxUid;
        $update = [$cursorCol => $cursor, $doneCol => $done ? 1 : 0];
        // The regular sync may now start from where the archive ends — no re-reading
        if ($done && $cursor > (int)($box[$uidCol] ?? 0)) $update[$uidCol] = $cursor;
        Db::update('mailboxes', $update, 'id=?', [$box['id']]);

        if ($stored) Logger::info('mail', "Ящик «{$box['name']}»: архив $folder — загружено $stored", ['mailbox_id' => $box['id']]);
        return ['stored' => $stored, 'scanned' => $scanned, 'done' => $done];
    }

    /** Start the download over — after «Забрать заново» or a changed folder. */
    public static function backfillReset(int $mailboxId): void {
        Db::update('mailboxes', [
            'backfill_uid_in' => 0, 'backfill_uid_sent' => 0,
            'backfill_done_in' => 0, 'backfill_done_sent' => 0,
            'backfill_max_in' => 0, 'backfill_max_sent' => 0,
            'backfill_started_at' => null, 'backfill_finished_at' => null,
        ], 'id=?', [$mailboxId]);
        Logger::info('mail', 'Скачивание архива начато заново', ['mailbox_id' => $mailboxId]);
    }

    /** Connection check for the admin panel: opens the folders and counts messages. */
    public static function testImap(array $box): array {
        $reader = new EmailReader(Mailboxes::cfg($box));
        $reader->connect((string)($box['imap_folder_in'] ?: 'INBOX'));
        $out = [
            'ok'      => true,
            'folder'  => $box['imap_folder_in'] ?: 'INBOX',
            'count'   => $reader->messageCount(),
            'total'   => $reader->maxUid(),
            'folders' => $reader->folders(),
        ];
        $reader->close();
        return $out;
    }
}
