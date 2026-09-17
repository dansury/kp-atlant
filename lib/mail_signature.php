<?php
/**
 * Подпись под письмом (модуль 038).
 *
 * Промпты ответа с самого начала заканчивались строкой «без темы и без
 * подписи — их подставит система», а система не подставляла ничего: письмо
 * уходило клиенту обрывом на полуслове, и менеджер дописывал «С уважением,
 * Яна, менеджер по оптовым заказам, +7…» руками — в каждом письме.
 *
 * Подпись принадлежит МЕНЕДЖЕРУ, как и подпись под КП (модуль 022): пишет
 * Яна — уходит подпись Яны. Пусто — берётся общая подпись компании, а если и
 * её не завели, письмо всё равно не обрывается: подпись собирается из карточки
 * менеджера, его имени и телефона.
 *
 * Текст хранится как текст, с переносами строк. В HTML он превращается при
 * отправке — там же, где письмо получает своё оформление.
 */
final class MailSignature {

    /** Общая подпись компании: ею подписываются те, кто своей не завёл. */
    public const SETTING = 'MAIL_SIGNATURE';

    /** Своя подпись менеджера — ровно то, что он вписал, без подстановок. */
    public static function own(int $managerId): string {
        if ($managerId <= 0) return '';
        return self::clean((string)(Db::val("SELECT email_signature FROM managers WHERE id=?", [$managerId]) ?: ''));
    }

    /**
     * Чем на самом деле подпишется письмо этого менеджера: своя подпись,
     * общая подпись компании, а в последнюю очередь — собранная из карточки.
     */
    public static function forManager(int $managerId): string {
        $own = self::own($managerId);
        if ($own !== '') return $own;

        $common = self::clean((string)Settings::get(self::SETTING, ''));
        if ($common !== '') return $common;

        return self::fromCard($managerId);
    }

    /** Откуда подпись взялась — это видно в панели, чтобы не гадать. */
    public static function describe(int $managerId): array {
        $own = self::own($managerId);
        $common = self::clean((string)Settings::get(self::SETTING, ''));
        return [
            'signature'  => $own,
            'effective'  => self::forManager($managerId),
            'common'     => $common,
            'fallback'   => self::fromCard($managerId),
            'source'     => $own !== '' ? 'manager' : ($common !== '' ? 'company' : 'card'),
        ];
    }

    public static function save(int $managerId, string $text): string {
        if ($managerId <= 0) throw new InvalidArgumentException('Не указан менеджер');
        $text = self::clean($text);
        Db::update('managers', [
            'email_signature' => $text !== '' ? $text : null,
            'updated_at'      => date('Y-m-d H:i:s'),
        ], 'id=?', [$managerId]);
        Logger::info('managers', 'Подпись в письмах изменена', ['manager_id' => $managerId]);
        return $text;
    }

    /**
     * Дописать подпись к тексту письма.
     *
     * Второй раз она не приписывается: черновик нейросети уже уходит с
     * подписью, и отправка не должна ставить её следом ещё раз.
     */
    public static function appendText(string $body, string $signature): string {
        $signature = self::clean($signature);
        if ($signature === '' || self::has($body, $signature)) return $body;
        return rtrim($body) . "\n\n" . $signature;
    }

    /** То же для HTML-части: подпись — обычный абзац с переносами строк. */
    public static function appendHtml(string $html, string $signature): string {
        $signature = self::clean($signature);
        if ($signature === '' || self::has($html, $signature)) return $html;
        $safe = htmlspecialchars($signature, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return rtrim($html) . '<p>' . nl2br($safe) . '</p>';
    }

    /**
     * Подпись в письме уже стоит?
     *
     * Сравнение построчное и приблизительное: менеджер мог поправить одну
     * строку руками, и это не повод приписать подпись во второй раз. Половина
     * строк на месте — считаем, что подпись стоит.
     */
    public static function has(string $body, string $signature): bool {
        $lines = array_values(array_filter(array_map(
            fn($l) => self::normalize($l), explode("\n", self::clean($signature))
        ), fn($l) => mb_strlen($l) >= 3));
        if (!$lines) return false;

        $haystack = self::normalize(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $body)));
        $hits = 0;
        foreach ($lines as $line) if (mb_strpos($haystack, $line) !== false) $hits++;
        return $hits * 2 >= count($lines);
    }

    /**
     * Подпись из карточки менеджера — последняя линия обороны.
     *
     * Она не заменяет настоящую подпись, но письмо с ней заканчивается именем
     * и телефоном, а не пустотой.
     */
    private static function fromCard(int $managerId): string {
        $m = $managerId > 0 ? Db::one("SELECT name, phone FROM managers WHERE id=?", [$managerId]) : null;
        $name = trim((string)($m['name'] ?? ''));
        if ($name === '') return '';
        $phone = trim((string)($m['phone'] ?? ''));
        return "С уважением,\n" . $name . ($phone !== '' ? "\n" . $phone : '');
    }

    /** Лишние пробелы и пустые строки по краям — но не внутри подписи. */
    private static function clean(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/u', "\n", $text);
        return trim((string)$text);
    }

    private static function normalize(string $text): string {
        $text = mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        // Телефон в подписи и телефон в письме — один номер, записанный по-разному
        $text = str_replace(["\u{00a0}", '(', ')', '-', '—', '–'], ' ', $text);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }
}
