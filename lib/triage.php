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
        'return_exchange'  => ['Возврат или обмен',         'kp_request', 'reply_return',       ['wiki']],
        'docs_request'     => ['Документы и сертификаты',   'kp_request', 'reply_docs',         ['wiki']],
        'wholesale'        => ['Опт и дилерство',           'kp_request', 'reply_wholesale',    ['wiki']],
        'complaint'        => ['Претензия',                 'kp_request', 'reply_complaint',    ['wiki']],
        'supplier_offer'   => ['Нам предлагают товар',      null,         null,                 []],
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
        return null;
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
    public static function classify(string $text, string $attachmentText = ''): array {
        if (!self::enabled()) {
            $parsed = RequestParser::parse($text, $attachmentText);
            $parsed['category'] = ($parsed['request_type'] ?? '') === 'order' ? 'order' : 'kp_request';
            $parsed['category_confidence'] = 0.0;
            $parsed['category_reason'] = 'Классификация выключена';
            $parsed['category_source'] = 'llm';
            return $parsed;
        }

        $system = Prompts::render('classify_request');
        $user = $text;
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
            . self::clip((string)($message['body_text'] ?? ''), 4000) . "\n"
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
        return $user;
    }

    private static function clip(string $text, int $max): string {
        $text = trim($text);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . "\n[...]" : $text;
    }

    /** Counts for «Админ → Обзор»: what the mailbox actually consists of. */
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
