<?php
/**
 * Incoming mail triage (module 006).
 *
 * Two thirds of the mailbox is Yandex, MoySklad and hosting notifications, and the
 * live letters are not one kind of request but thirteen. This class decides what a
 * letter is — first for free (headers and sender), then with one model call — and
 * says which prompt and which fact sources answer it.
 */
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/site_forms.php';
require_once __DIR__ . '/bounce.php';
require_once __DIR__ . '/mail_text.php';
require_once __DIR__ . '/request_items.php';

final class Triage {

    /**
     * key => [label, request type or null when no request is created,
     *         reply prompt key or null, sources: catalog|wiki|orders]
     */
    public const CATEGORIES = [
        'kp_request'       => ['Запрос КП / прайса',        'kp_request', 'reply_kp',           ['catalog', 'wiki']],
        'order'            => ['Заказ, просят счёт',        'order',      'reply_kp',           ['catalog', 'wiki']],
        'product_question' => ['Вопрос о товаре',           'kp_request', 'reply_product',      ['catalog', 'wiki']],
        'availability'     => ['Наличие и сроки',           'kp_request', 'reply_availability', ['catalog', 'wiki']],
        'order_status'     => ['Статус заказа',             'kp_request', 'reply_order_status', ['orders', 'wiki']],
        // Module 015: the second half of a deal. 561 letters of the archive are
        // about the parcel, 176 about ЭДО, 171 about the closing documents and
        // 184 about the договор — all of them used to fall into «other».
        'delivery'         => ['Доставка и отправка',       'kp_request', 'reply_delivery',     ['orders', 'wiki']],
        'edo'              => ['ЭДО и обмен документами',   'kp_request', 'reply_edo',          ['wiki']],
        'closing_docs'     => ['Закрывающие документы',     'kp_request', 'reply_closing_docs', ['orders', 'wiki']],
        'contract'         => ['Договор и спецификация',    'kp_request', 'reply_contract',     ['wiki']],
        'tender'           => ['Тендер, НМЦК, закупка',     'kp_request', 'reply_tender',       ['catalog', 'wiki']],
        'gov_order'        => ['Гособоронзаказ',            'kp_request', 'reply_gov_order',    ['wiki']],
        'return_exchange'  => ['Возврат или обмен',         'kp_request', 'reply_return',       ['wiki']],
        'docs_request'     => ['Документы и сертификаты',   'kp_request', 'reply_docs',         ['wiki']],
        'wholesale'        => ['Опт и дилерство',           'kp_request', 'reply_wholesale',    ['wiki']],
        'complaint'        => ['Претензия',                 'kp_request', 'reply_complaint',    ['wiki']],
        // A lead from the site form that left a phone and no address. There is
        // nothing to answer to, so there is no prompt: the card is a call to make.
        'callback'         => ['Заявка на звонок',          'kp_request', null,                 []],
        'supplier_offer'   => ['Нам предлагают товар',      null,         null,                 []],
        // Ставится только руками, кнопкой «В архив» на письме: модель этой
        // категории не знает и в `classify_request` она не перечислена —
        // «не наш профиль» решает менеджер, а не классификатор.
        'not_our_profile'  => ['Не наш профиль',            null,         null,                 []],
        'bounce'           => ['Письмо не доставлено',      null,         null,                 []],
        'spam'             => ['Спам и рассылки',           null,         null,                 []],
        'service'          => ['Служебное уведомление',     null,         null,                 []],
        'other'            => ['Не определено',             'kp_request', 'mail_reply',         ['wiki']],
    ];

    public static function enabled(): bool {
        return (int)Settings::get('TRIAGE_ENABLED', 1) === 1;
    }

    public static function label(string $key): string {
        return self::CATEGORIES[$key][0] ?? $key;
    }

    /**
     * Request type the existing КП pipeline works with, or null for a category
     * that must not become a request. `??` would be wrong here: a deliberate null
     * in the table means «не заводить запрос», not «ключ отсутствует».
     */
    public static function requestType(string $key): ?string {
        return array_key_exists($key, self::CATEGORIES) ? self::CATEGORIES[$key][1] : 'kp_request';
    }

    public static function createsRequest(string $key): bool {
        return self::requestType($key) !== null;
    }

    /** [prompt key, sources] for a drafted answer; prompt null = мы не отвечаем. */
    public static function route(string $key): array {
        $c = self::CATEGORIES[$key] ?? self::CATEGORIES['other'];
        return [$c[2], $c[3]];
    }

    // ---------------------------------------------------------------- prefilter

    /**
     * Verdict without a model call: a mailing list, an auto-reply or a known
     * service sender. Returns ['category' => …, 'reason' => …] or null when the
     * letter deserves a real look.
     */
    public static function prefilter(array $message): ?array {
        if (!self::enabled()) return null;

        $subject = (string)($message['subject'] ?? '');
        if (preg_match('/^\s*\[spam\]/i', $subject)) {
            return ['category' => 'spam', 'reason' => 'Помечено спам-фильтром сервера'];
        }

        // A form filled by a scanner (module 015). Two thirds of everything the
        // site form sends is this, and none of it may reach the model.
        $formSpam = self::formSpamReason($message);
        if ($formSpam !== null) {
            return ['category' => 'spam', 'reason' => 'Форма сайта: ' . $formSpam];
        }

        // A delivery report is not mail to answer — it is an answer of ours that
        // never arrived, and `MailSync` marks the failed letter before we archive it.
        if (Bounce::detect($message) !== null) {
            return ['category' => 'bounce', 'reason' => 'Отчёт о недоставке письма'];
        }

        $headers = mb_strtolower((string)($message['headers'] ?? ''));
        if ($headers !== '') {
            if (preg_match('/^list-(unsubscribe|id):/m', $headers)) {
                return ['category' => 'service', 'reason' => 'Заголовок List-Unsubscribe — рассылка'];
            }
            if (preg_match('/^precedence:\s*(bulk|list|junk)/m', $headers)) {
                return ['category' => 'service', 'reason' => 'Precedence: bulk — массовая рассылка'];
            }
            if (preg_match('/^auto-submitted:\s*(?!no)/m', $headers)) {
                return ['category' => 'service', 'reason' => 'Auto-Submitted — автоматическое письмо'];
            }
        }

        $from = mb_strtolower(trim((string)($message['from_email'] ?? '')));
        if ($from !== '' && self::senderMatches($from)) {
            return ['category' => 'service', 'reason' => "Отправитель $from в списке служебных"];
        }
        if ($from !== '' && self::spamSenderMatches($from)) {
            return ['category' => 'spam', 'reason' => "Отправитель $from ранее отмечен кнопкой «Спам»"];
        }
        return null;
    }

    /**
     * Why the form submission behind this letter is a bot, or null. The verdict
     * is stored on the row when the letter is archived, so a re-run costs nothing.
     */
    private static function formSpamReason(array $message): ?string {
        $stored = trim((string)($message['form_spam_reason'] ?? ''));
        if ($stored !== '') return $stored;
        $json = (string)($message['form_json'] ?? '');
        if ($json !== '') {
            $f = json_decode($json, true);
            if (is_array($f)) {
                $reason = (string)($f['spam_reason'] ?? '');
                return $reason !== '' ? $reason : null;
            }
        }
        $fields = SiteForm::parse((string)($message['body_text'] ?? $message['body'] ?? ''));
        return $fields ? SiteForm::spamReason($fields) : null;
    }

    /** Exact address against TRIAGE_SPAM_SENDERS — filled in by the «Спам» button, not edited by hand. */
    private static function spamSenderMatches(string $email): bool {
        foreach (preg_split('/[\s,]+/', (string)Settings::get('TRIAGE_SPAM_SENDERS', '')) ?: [] as $addr) {
            if (mb_strtolower(trim($addr)) === $email) return true;
        }
        return false;
    }

    /** Sender against TRIAGE_SERVICE_SENDERS: `*.yandex.ru`, `no-reply@*`, `sweb.ru`. */
    private static function senderMatches(string $email): bool {
        $domain = substr(strrchr($email, '@') ?: '', 1);
        foreach (preg_split('/[\s,]+/', (string)Settings::get('TRIAGE_SERVICE_SENDERS', '')) ?: [] as $mask) {
            $mask = mb_strtolower(trim($mask));
            if ($mask === '') continue;
            $subject = str_contains($mask, '@') ? $email : $domain;
            $re = '/^' . str_replace('\*', '.*', preg_quote($mask, '/')) . '$/u';
            if (preg_match($re, $subject)) return true;
            // A bare domain also covers its subdomains: `yandex.ru` ⊃ `pay.yandex.ru`
            if (!str_contains($mask, '*') && !str_contains($mask, '@')
                && str_ends_with($domain, '.' . $mask)) return true;
        }
        return false;
    }

    // ---------------------------------------------------------------- classify

    /**
     * Parse + classify in one model call. Falls back to the pre-006 behaviour
     * (plain `parse_request`, category `other`) when triage is switched off.
     */
    public static function classify(string $text, string $attachmentText = '', string $subject = ''): array {
        if (!self::enabled()) {
            $parsed = RequestParser::parse(($subject !== '' ? "Тема письма: $subject\n\n" : '') . $text, $attachmentText);
            $parsed['category'] = ($parsed['request_type'] ?? '') === 'order' ? 'order' : 'kp_request';
            $parsed['category_confidence'] = 0.0;
            $parsed['category_reason'] = 'Классификация выключена';
            $parsed['category_source'] = 'llm';
            return $parsed;
        }

        $system = Prompts::render('classify_request');
        // The subject is half the letter here: «Атлант Армор: Новый заказ N6764»
        // and «Счёт на оплату» are answers to a notification whose body says only
        // «почему не отправляете» — 141 such letters in the archive (module 015).
        $user = ($subject !== '' ? "Тема письма: $subject\n\n" : '') . $text;
        if (trim($attachmentText) !== '') {
            $user .= "\n\n===== ТЕКСТ ВЛОЖЕНИЙ =====\n" . $attachmentText;
        }
        $parsed = LLM::chatJson($system, $user);

        $category = (string)($parsed['category'] ?? '');
        if (!isset(self::CATEGORIES[$category])) $category = 'other';
        $conf = (float)($parsed['category_confidence'] ?? 0);
        // A guess the model itself does not believe is worse than «разберись сам»
        $min = (float)Settings::get('TRIAGE_MIN_CONFIDENCE', 0.5);
        if ($conf > 0 && $conf < $min && !in_array($category, ['spam', 'service'], true)) {
            $parsed['category_reason'] = 'Низкая уверенность (' . round($conf, 2) . '): ' . ($parsed['category_reason'] ?? '');
            $category = 'other';
        }

        $parsed['category'] = $category;
        $parsed['category_confidence'] = $conf;
        $parsed['category_source'] = 'llm';
        // The КП pipeline still asks for request_type — derive it, don't ask twice
        $parsed['request_type'] = self::requestType($category) ?? 'kp_request';
        $parsed['items'] = is_array($parsed['items'] ?? null) ? $parsed['items'] : [];
        return $parsed;
    }

    // ---------------------------------------------------------------- drafting

    /**
     * Draft an answer with the prompt and the sources this category calls for.
     * $ctx: org_name, attachments, thread, email_rules, tov, counterparty_id.
     */
    public static function draft(array $message, string $category, array $ctx = []): string {
        [$promptKey, $sources] = self::route($category);
        // Spam and service mail have no reply prompt; a manager who insists on
        // answering one gets the generic letter prompt rather than an error.
        if ($promptKey === null) [$promptKey, $sources] = self::route('other');

        $query = trim((string)($message['subject'] ?? '') . "\n"
            . self::clip(MailText::forAnalysis((string)($message['body_text'] ?? '')), 4000) . "\n"
            . self::clip((string)($ctx['attachments'] ?? ''), 1500));

        $vars = [
            'email_rules' => $ctx['email_rules'] ?? '',
            'tov'         => $ctx['tov'] ?? '',
            'catalog'     => '',
            'orders'      => '',
        ];
        if (in_array('catalog', $sources, true)) {
            $vars['catalog'] = Catalog::block($query);
        }
        if (in_array('orders', $sources, true)) {
            $vars['orders'] = Catalog::ordersBlock(
                $query,
                (string)($message['from_email'] ?? ''),
                isset($ctx['counterparty_id']) ? (int)$ctx['counterparty_id'] : null
            );
        }

        $system = in_array('wiki', $sources, true)
            ? Knowledge::augment($promptKey, $vars, $query)
            : Prompts::render($promptKey, $vars);

        return LLM::chatText($system, self::userMessage($message, $ctx), 0.4);
    }

    /** The letter itself, its thread and its attachments — what a manager would read. */
    private static function userMessage(array $message, array $ctx): string {
        $user = '';
        if (!empty($ctx['org_name']))       $user .= "Компания: {$ctx['org_name']}\n";
        if (!empty($message['from_name']))  $user .= "Контакт: {$message['from_name']}\n";
        if (!empty($ctx['thread'])) {
            $user .= "\n===== ПРЕДЫДУЩАЯ ПЕРЕПИСКА =====\n";
            foreach ($ctx['thread'] as $t) {
                $who = ($t['direction'] ?? 'in') === 'in' ? 'Клиент' : 'Мы';
                $user .= "[$who, {$t['date_at']}] " . self::clip((string)($t['body_text'] ?? ''), 800) . "\n\n";
            }
        }
        $user .= "\n===== ПИСЬМО, НА КОТОРОЕ ОТВЕЧАЕМ =====\n";
        $user .= 'Тема: ' . (string)($message['subject'] ?? '') . "\n";
        $user .= self::clip((string)($message['body_text'] ?? ''), 6000) . "\n";
        if (trim((string)($ctx['attachments'] ?? '')) !== '') {
            $user .= "\n===== ТЕКСТ ВЛОЖЕНИЙ =====\n" . self::clip((string)$ctx['attachments'], 6000) . "\n";
        }
        // Positions the catalog never answered — named, not quietly dropped
        $user .= RequestItems::unmatchedBlock((array)($ctx['unmatched'] ?? []));
        return $user;
    }

    private static function clip(string $text, int $max): string {
        $text = trim($text);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . "\n[...]" : $text;
    }

    /** Counts for «Настройки → Обзор»: what the mailbox actually consists of. */
    public static function stats(int $days = 30): array {
        $rows = Db::all(
            "SELECT category, COUNT(*) AS n FROM mail_messages
             WHERE direction='in' AND category IS NOT NULL AND date_at >= datetime('now', ?)
             GROUP BY category ORDER BY n DESC", ["-$days days"]
        );
        return array_map(fn($r) => [
            'key'   => $r['category'],
            'label' => self::label((string)$r['category']),
            'count' => (int)$r['n'],
        ], $rows);
    }
}
