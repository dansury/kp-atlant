<?php
/**
 * Plain text of a letter (module 015).
 *
 * `strip_tags()` alone keeps whatever stands between `<style>` and `</style>`,
 * so an HTML-only letter — an Avito notice, a Bitrix mailing, any modern
 * newsletter — reached the classifier as a page of CSS. That text went into the
 * prompt, into the request card and into the wiki query; the letter itself never
 * did. Everything that turns HTML into the text we store goes through here.
 */
final class MailText {

    /** Readable text of an HTML body: no CSS, no scripts, no head. */
    public static function fromHtml(string $html): string {
        if (trim($html) === '') return '';
        // Order matters: kill the elements whose CONTENT is not text first,
        // then turn block boundaries into newlines, only then drop the tags.
        $s = preg_replace('#<(style|script|head|title|noscript)\b[^>]*>.*?</\1>#isu', ' ', $html) ?? $html;
        $s = preg_replace('#<!--.*?-->#su', ' ', $s) ?? $s;
        $s = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#iu', "\n", $s) ?? $s;
        $s = preg_replace('#</(td|th)>#iu', "\t", $s) ?? $s;
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace(["\xC2\xA0", "\r\n", "\r"], [' ', "\n", "\n"], $s);
        // A mail template leaves dozens of blank lines between real sentences
        $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? $s;
        return trim($s);
    }

    /**
     * What a manager would read before answering: our own quoted letter, the
     * client's corporate footer and the security banner their mail gateway
     * stamps on every external message are noise in the prompt and noise in the
     * catalog query. The FULL body stays in the archive — this is only what we
     * hand to the model.
     */
    public static function forAnalysis(string $text, int $max = 8000): string {
        $text = self::stripQuoted($text);
        $text = self::stripBanners($text);
        $text = trim((string)preg_replace('/\n{3,}/u', "\n\n", $text));
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . "\n[...]" : $text;
    }

    /**
     * Cut the quoted history. The client's own words come first in every client
     * this mailbox sees, so the first quote marker ends the new text — but only
     * when something readable stands above it, or a bare «Re: …» forward would
     * lose its whole content.
     */
    public static function stripQuoted(string $text): string {
        $cuts = [
            // Gmail / Mail.ru / Yandex / Outlook, Russian and English
            '/\n\s*-{2,}\s*Original Message\s*-{2,}/iu',
            '/\n\s*-----Original Message-----/iu',
            '/\n\s*_{5,}\s*\n/u',
            '/\n\s*From:\s.*\n\s*Sent:\s/iu',
            '/\n\s*(?:пн|вт|ср|чт|пт|сб|вс),\s*\d{1,2}\s+\S+\.?\s+\d{4}\s*г?\.?\s*в\s*\d{1,2}:\d{2}/u',
            '/\n\s*(?:понедельник|вторник|среда|четверг|пятница|суббота|воскресенье),\s*\d{1,2}\s+\S+\s+\d{4}/iu',
            '/\n\s*\d{1,2}\.\d{2}\.\d{4},?\s+\d{1,2}:\d{2},?\s+.{0,80}(?:писал|wrote|<)/u',
            '/\n\s*On\s.{0,80}\swrote:/iu',
            '/\n\s*Кому:\s.*\n\s*Тема:/u',
        ];
        $cutAt = mb_strlen($text);
        foreach ($cuts as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $at = mb_strlen(substr($text, 0, $m[0][1]));
                if ($at < $cutAt) $cutAt = $at;
            }
        }
        $head = mb_substr($text, 0, $cutAt);
        // A letter that is nothing BUT a quote (a forward) keeps its quote
        if (trim(preg_replace('/^\s*(?:>|&gt;).*$/mu', '', $head) ?? '') === '') return trim($text);
        $head = (string)preg_replace('/^\s*(?:>|&gt;).*$/mu', '', $head);
        return trim($head) !== '' ? trim($head) : trim($text);
    }

    /**
     * Gateway banners and signatures. These repeat in every letter from a big
     * client and would otherwise be the most «relevant» text in the mailbox.
     */
    public static function stripBanners(string $text): string {
        $banners = [
            '/ВНЕШНЯЯ ПОЧТА[:!].*$/mu',
            '/^\s*CAUTION:.*$/mu',
            '/Данное сообщение.{0,200}конфиденциальн.*$/imu',
            '/This (?:e-?mail|message).{0,200}confidential.*$/imu',
            '/Отправлено из Mail\s*\(?\s*https?:\/\/[^\s)]+\s*\)?\s*для \S+/iu',
            '/Sent from my \w+/iu',
            '/^--\s*$/mu',
        ];
        foreach ($banners as $re) {
            $text = (string)preg_replace($re, '', $text);
        }
        return $text;
    }
}
