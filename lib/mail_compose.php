<?php
/**
 * Одно письмо, собранное менеджером, — и его отправка (issue #60).
 *
 * Код жил внутри `case 'send'` в `public/api/mail.php` и был доступен только
 * кнопке «Отправить». Отложенная отправка требует ровно того же: письмо,
 * уходящее в понедельник в девять, не должно отличаться от отправленного
 * сейчас ничем, кроме минуты отправки — ни подписью, ни цитатой, ни тем, что
 * оно попадает в переписку компании и в обучающие пары промптов.
 *
 * Поэтому тело `send` переехало сюда целиком и зовётся из двух мест: из API
 * (кнопка) и из `MailSchedule::run()` (крон). Ошибки здесь — исключения:
 * у крона нет HTTP-ответа, которым можно ответить `jsonError`.
 */
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/mailsync.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/mail_threads.php';
require_once __DIR__ . '/outbox.php';
require_once __DIR__ . '/drafts.php';
require_once __DIR__ . '/mail_signature.php';
require_once __DIR__ . '/mail_text.php';

final class MailCompose {

    /**
     * Отправить письмо так, как его собрал менеджер.
     *
     * @param array $input то же, что присылает форма ответа
     * @param int   $managerId от чьего имени письмо уходит
     * @return array ответ `Mailer::send()` плюс `warning`
     */
    public static function send(array $input, int $managerId): array {
        $to = trim((string)($input['to'] ?? ''));
        if ($to === '') throw new InvalidArgumentException('Укажите адрес получателя');
        $text = (string)($input['text'] ?? '');
        if (trim($text) === '') throw new InvalidArgumentException('Письмо пустое');

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
        $draft = MailDrafts::find($draftKeys, $managerId);
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
            : MailText::textToHtml($text);

        // Подпись менеджера — до цитаты и до отправки (модуль 039): она
        // заканчивает НАШЕ письмо, а не процитированное чужое. Второй раз
        // не приписывается: черновик нейросети уже уходит с ней.
        if (($input['signature'] ?? 1)) {
            $sign = MailSignature::forManager($managerId);
            $text = MailSignature::appendText($text, $sign);
            $html = MailSignature::appendHtml($html, $sign);
        }

        // Ответ несёт письмо, на которое отвечает (модуль 031): клиенту не
        // приходится вспоминать, о каком заказе речь, а нам — пересказывать
        // его же вопрос своими словами.
        $quoted = MailText::withQuote($text, $html, $source);
        $text = $quoted['text'];
        $html = $quoted['html'];

        // Счёт и КП среди вложений — до отправки, пока их отметки на месте (модуль 056)
        $docs = Outbox::docsOf((array)($input['files'] ?? []), $managerId);

        $res = Mailer::send([
            'to'              => $to,
            'cc'              => array_filter(array_map('trim', explode(',', (string)($input['cc'] ?? '')))),
            'subject'         => $subject,
            'text'            => $text,
            'html'            => $html,
            'mailbox_id'      => $input['mailbox_id'] ?? null,
            'manager_id'      => $managerId,
            'counterparty_id' => $counterpartyId,
            'request_id'      => $requestId,
            'in_reply_to'     => $replyTo,
            'thread_key'      => $threadKey,
            // Менеджер мог переделать документ руками и приложить свой
            'attachments'     => Outbox::resolve((array)($input['files'] ?? []), $managerId),
        ]);

        // Отправленное письмо — уже не черновик, но его карточка остаётся
        // на доске и в своей колонке (модуль 033)
        MailDrafts::sent($draftKeys, $managerId, [
            'thread_key'      => (string)(Db::val("SELECT thread_key FROM mail_messages WHERE id=?",
                                                  [(int)$res['archive_id']]) ?: $threadKey),
            'counterparty_id' => $counterpartyId,
            'mail_message_id' => (int)$res['archive_id'],
            'title'           => $counterpartyId
                ? (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$counterpartyId]) ?: $to)
                : $to,
            'manager_id'      => $managerId,
        ]);

        // Ушёл счёт — карточка ждёт оплату, ушло КП — «КП отправлено» (модуль 056)
        $stage = $docs ? self::afterDocsSent($docs, $counterpartyId, $threadKey, $to) : null;

        /**
         * Каждое отправленное письмо — образец для промптов (модуль 041).
         *
         * В паре с письмом контрагента и с тем, что предлагала модель:
         * по этим парам видно, как мы отвечаем на самом деле, и из них
         * одной кнопкой собираются правила для промпта.
         */
        if ($source) {
            require_once ROOT . '/lib/learning.php';
            Learning::recordSent([
                'subject'        => (string)($source['subject'] ?? ''),
                'question'       => (string)($source['body_text'] ?? ''),
                'auto_answer'    => (string)($source['model_draft_text'] ?? ''),
                'correct_answer' => (string)($input['text'] ?? ''),
                'manager_id'     => $managerId,
                'context'        => ['category' => $source['category'] ?? null,
                                     'mail_message_id' => (int)$source['id']],
            ]);
        }

        // The company chat shows the same message, so nothing is invisible there
        if ($counterpartyId) {
            Crm::logEvent($counterpartyId, 'out', $text, [
                'request_id' => $requestId,
                'subject'    => $subject,
                'email_to'   => $to,
                'manager_id' => $managerId,
                'event_type' => 'mail_sent',
            ]);
        }
        // «Отправлено» is not the whole truth when the copy never reached the
        // server's «Отправленные» — say so instead of letting it be found later
        return $res + ['stage' => $stage, 'warning' => $res['sent_state'] === 'failed'
            ? 'Письмо ушло, но копия не попала в «Отправленные»: ' . (string)$res['sent_error']
            : null];
    }

    /**
     * Документы письма ушли клиенту (модуль 056): счёт помечается отправленным,
     * КП — тоже, карточка встаёт в стадию. Счёт главнее КП.
     *
     * @param list<array{kind:string,doc_id:int,name:string}> $docs
     * @return ?string колонка, куда встала карточка
     */
    public static function afterDocsSent(array $docs, ?int $counterpartyId, ?string $threadKey, string $to): ?string {
        $now = date('Y-m-d H:i:s');
        $kinds = [];
        foreach ($docs as $d) {
            $kinds[$d['kind']] = true;
            if ($d['kind'] === 'invoice') {
                Db::q("UPDATE invoices SET sent_at=?, sent_to=? WHERE id=? AND sent_at IS NULL", [$now, $to, $d['doc_id']]);
            } elseif ($d['kind'] === 'kp') {
                Db::q("UPDATE proposals SET status='sent', sent_at=COALESCE(sent_at, ?), updated_at=? WHERE id=?",
                      [$now, $now, $d['doc_id']]);
            }
        }
        Outbox::forgetDocs($docs);
        $kind = isset($kinds['invoice']) ? 'payment' : (isset($kinds['kp']) ? 'kp_sent' : null);
        if (!$kind) return null;
        try {
            return Boards::advance($counterpartyId, $threadKey, $kind);
        } catch (Throwable $e) {
            // Письмо ушло — доска не повод сказать «не отправлено»
            Logger::warning('boards', 'Карточка не передвинулась: ' . $e->getMessage(),
                            ['counterparty_id' => $counterpartyId]);
            return null;
        }
    }
}
