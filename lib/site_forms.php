<?php
/**
 * Letters a web form sends us (module 015).
 *
 * 923 letters of the archive are Bitrix form notifications from atlant-armour.ru
 * — «Задать вопрос» and «Задать вопрос по заказу». Their `From:` is our OWN
 * address, so every one of them was the same party, the same thread and the same
 * company card; the client — name, phone, email, order number — was only ever
 * inside the body, and an answer could not leave without a manager copying the
 * address out by hand.
 *
 * This class turns such a letter back into the letter the client meant to send:
 * the sender is the visitor, the subject is what they asked about, and the body
 * is their message with the form's own context above it. Two thirds of those
 * letters are bots filling the form with `1`, `555` and SQL payloads, and they
 * are recognised here — for free, before a model is ever called.
 */
final class SiteForm {

    /** Letters that ARE a form notification, whatever mailbox they arrived in. */
    private const SIGNATURE = '/Заполнена\s+форма\s+"([^"]{1,80})"/u';

    /** Field labels Bitrix prints, in the order it prints them. */
    private const FIELDS = [
        'name'    => 'Имя посетителя',
        'phone'   => 'Телефон',
        'email'   => 'Email',
        'product' => 'Интересующий товар/услуга',
        'order'   => 'Интересующий заказ',
        'message' => 'Сообщение',
        'sent_at' => 'Запрос отправлен',
    ];

    public static function enabled(): bool {
        return (int)Settings::get('SITE_FORM_UNWRAP', 1) === 1;
    }

    /**
     * Fields of a form letter, or null when the body is not one.
     * Works on the quoted copy too: a manager's reply carries the form inside.
     */
    public static function parse(string $body): ?array {
        if ($body === '' || !preg_match(self::SIGNATURE, $body, $m)) return null;

        // Quote markers turn «> Телефон: +7…» into a field we would not match
        $clean = (string)preg_replace('/^\s*(?:>|&gt;)+\s?/mu', '', $body);
        $labels = array_values(self::FIELDS);
        $stop = implode('|', array_map(fn($l) => preg_quote($l, '/'), $labels))
              . '|Просмотр результата на сайте';

        $out = ['form' => trim($m[1]), 'result_id' => null];
        if (preg_match('/Заполнена\s+форма\s+"[^"]{1,80}"[^\(\n]{0,60}\((\d{1,8})\)/u', $clean, $r)) {
            $out['result_id'] = (int)$r[1];
        }
        foreach (self::FIELDS as $key => $label) {
            $re = '/' . preg_quote($label, '/') . '\s*:\s*(.*?)\s*(?=(?:' . $stop . ')\s*:|$)/su';
            $out[$key] = preg_match($re, $clean, $f) ? self::clean($f[1]) : '';
        }
        // Bitrix prints a bare address as «mail@x.ru: mailto:mail@x.ru»
        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $out['email'], $e)) $out['email'] = mb_strtolower($e[0]);
        else $out['email'] = '';
        $out['phone'] = trim((string)preg_replace('/\s*>+\s*$/u', '', $out['phone']));
        $out['order'] = trim((string)preg_replace('/\D+/u', '', $out['order'])) ?: '';
        return $out;
    }

    private static function clean(string $v): string {
        $v = (string)preg_replace('/\s*(?:https?:\/\/\S*form_result_edit[^\s]*)/u', '', $v);
        $v = (string)preg_replace('/\s+/u', ' ', $v);
        return trim($v, " \t\n\r\0\x0B>");
    }

    /**
     * The letter as if the visitor had written it: sender, subject and body
     * replaced, the original kept in `form_json`. A form with no email keeps our
     * own sender — there is nothing to answer to — and is marked `needs_call`,
     * which is a task for a manager, not a draft for a model.
     */
    public static function unwrap(array $msg): array {
        if (!self::enabled()) return $msg;
        $body = (string)($msg['body'] ?? '');
        $fields = self::parse($body);
        if (!$fields) return $msg;

        $spam = self::spamReason($fields);
        $msg['form_json'] = json_encode($fields + ['spam_reason' => $spam], JSON_UNESCAPED_UNICODE);
        $msg['source_channel'] = 'site_form';
        $msg['form_spam_reason'] = $spam;
        $msg['needs_call'] = ($fields['email'] === '' && $fields['phone'] !== '' && !$spam) ? 1 : 0;
        // A bot's «letter» is archived as it arrived: rewriting its sender would
        // put a fake company on the board for the sake of a payload.
        if ($spam) return $msg;

        if ($fields['email'] !== '') {
            $msg['from']      = $fields['email'];
            $msg['from_name'] = $fields['name'] !== '' ? $fields['name'] : $fields['email'];
        }
        $msg['subject'] = self::subject($fields, (string)($msg['subject'] ?? ''));
        $msg['body']    = self::body($fields);
        $msg['body_html'] = '';
        return $msg;
    }

    /**
     * The subject a manager reads on the board. Every form letter arrives under
     * the same «Новый вопрос с сайта», so the subject has to carry what this one
     * is about — it is also the thread key for a visitor who left no address.
     */
    public static function subject(array $f, string $fallback = ''): string {
        $topic = self::clip($f['message'] ?? '', 60);
        // With no address of the visitor's own, the sender stays OUR mailbox and
        // the subject is the only thing separating one lead from another — so
        // the form's own result number goes into it.
        $tail = ($f['email'] ?? '') === '' && !empty($f['result_id']) ? ' №' . $f['result_id'] : '';
        if (($f['order'] ?? '') !== '') {
            return 'Вопрос по заказу ' . $f['order'] . ($topic !== '' ? ': ' . $topic : '') . $tail;
        }
        if ($topic !== '') return 'Вопрос с сайта: ' . $topic . $tail;
        $fallback = trim($fallback) !== '' ? trim($fallback) : 'Вопрос с сайта';
        return $fallback . (!empty($f['result_id']) ? ' №' . $f['result_id'] : '');
    }

    /** The visitor's own words first; what the form knows about them, after. */
    public static function body(array $f): string {
        $out = trim((string)($f['message'] ?? ''));
        if ($out === '') $out = '(в форме не было текста сообщения)';
        $ctx = [];
        if (($f['name'] ?? '') !== '')    $ctx[] = 'Имя: ' . $f['name'];
        if (($f['phone'] ?? '') !== '')   $ctx[] = 'Телефон: ' . $f['phone'];
        if (($f['email'] ?? '') !== '')   $ctx[] = 'Email: ' . $f['email'];
        if (($f['order'] ?? '') !== '')   $ctx[] = 'Заказ: ' . $f['order'];
        if (($f['product'] ?? '') !== '') $ctx[] = 'Интересует: ' . $f['product'];
        if (($f['sent_at'] ?? '') !== '') $ctx[] = 'Отправлено: ' . $f['sent_at'];
        $ctx[] = 'Источник: форма «' . ($f['form'] ?? 'сайт') . '» на atlant-armour.ru';
        return $out . "\n\n--- Данные формы ---\n" . implode("\n", $ctx);
    }

    // ------------------------------------------------------------------ spam

    /** Payloads a scanner leaves in a form field — never a client's text. */
    private const PAYLOAD = '/(?:
          \bPG_SLEEP\s*\(|\bWAITFOR\s+DELAY\b|\bDBMS_PIPE\.|\bBENCHMARK\s*\(
        | \bUNION\b[\s\/*]+\bSELECT\b | \bSEL\s?ECT\b[\s\S]{0,40}\bFR\s?OM\b
        | <\s*script | javascript\s*: | \$\{\s*\w+\s*\} | \{\{\s*\d+\s*[*+]
        | \bonerror\s*= | \.\.\/\.\.\/ | \bcurl\s+-|\bwget\s+http
        )/ixu';

    /** Throwaway values a bot types when the form demands something. */
    private const JUNK = ['1', '-1', '555', '777', '0', 'test', 'тест', 'asdf', 'qwe', 'qwerty', 'aaa'];

    /**
     * Why this submission is a bot, or null when a human filled the form.
     * Deterministic on purpose: 668 of 923 form letters are bots, and none of
     * them may cost a model call or open a company card.
     */
    public static function spamReason(array $f): ?string {
        if ((int)Settings::get('SITE_FORM_SPAM_FILTER', 1) !== 1) return null;

        $all = implode("\n", [$f['name'] ?? '', $f['phone'] ?? '', $f['email'] ?? '',
                              $f['product'] ?? '', $f['message'] ?? '']);
        if (preg_match(self::PAYLOAD, $all)) return 'В полях формы payload сканера (SQL/скрипт)';

        $junk = fn(string $v) => $v === '' || in_array(mb_strtolower(trim($v)), self::JUNK, true);
        $name = (string)($f['name'] ?? '');
        $phone = (string)($f['phone'] ?? '');
        $message = trim((string)($f['message'] ?? ''));

        if ($junk($name) && $junk($phone)) return 'Имя и телефон в форме не заполнены по-человечески';
        if ($junk($message) && mb_strlen($message) < 12 && $junk($name)) return 'Пустое сообщение и мусорное имя';

        $email = (string)($f['email'] ?? '');
        foreach (['example.com', 'example.org', 'test.com', 'mail.com.ua'] as $bad) {
            if ($email !== '' && str_ends_with($email, '@' . $bad)) return "Почта с тестового домена $bad";
        }
        // A phone that is not a phone at all: a bot types letters or three digits
        if ($phone !== '' && !$junk($phone) && preg_match_all('/\d/u', $phone) < 7 && $name !== '' && $junk($name)) {
            return 'Телефон не похож на номер, имя мусорное';
        }
        // A link with a random 20-letter host is a link-building probe
        if (preg_match('/https?:\/\/[a-z]{18,}\./iu', $all)) return 'Ссылка на случайный домен — спам-бот';
        return null;
    }

    private static function clip(string $s, int $max): string {
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) . '…' : $s;
    }
}
