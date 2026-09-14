<?php
/**
 * Текст товара между МойСклад и КП (модуль 020).
 *
 * Описания в МойСклад пишут в визуальном редакторе, и в базу они попадают
 * размеченными: `<ul><li>Класс защиты Бр1.</li><li>Материал — СВМПЭ.</li></ul>`.
 * Карточка товара в редакторе КП — это простая `<textarea>`, поэтому менеджер
 * видел там не список, а теги, и они же уходили в PDF ровно так, как выглядят.
 *
 * Здесь один и тот же текст живёт в двух видах и переводится туда и обратно:
 *
 *   HTML из МойСклад → Markdown  — то, что правит менеджер;
 *   Markdown         → HTML      — то, что печатают mPDF и Word.
 *
 * Markdown выбран потому, что он читается без разметки: список остаётся
 * списком, даже если никто его не «отрендерил». Набор намеренно узкий —
 * списки, абзацы, жирный, курсив, заголовки и ссылки, — потому что это всё,
 * что бывает в описании товара, а гадать про остальное хуже, чем не трогать.
 */
final class Markup {

    /** Блочные теги: после них нужен перенос строки, а не пробел. */
    private const BLOCK = ['p', 'div', 'br', 'ul', 'ol', 'li', 'table', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                           'blockquote', 'section', 'article', 'header', 'footer', 'hr'];

    /**
     * Размечен ли текст HTML-ом. Одинокий `<` в «ширина < 40 мм» разметкой не
     * считается: ищем именно тег с именем, иначе обычный текст уедет в парсер.
     */
    public static function looksLikeHtml(string $text): bool {
        return (bool)preg_match('#</?(?:' . implode('|', self::BLOCK) . '|b|strong|i|em|u|a|span|img|td|th)\b[^>]*>#iu', $text)
            || (bool)preg_match('/&(?:nbsp|amp|lt|gt|quot|#\d{1,5});/iu', $text);
    }

    /**
     * Привести текст к тому виду, в котором он хранится и правится, — к Markdown.
     * Уже неразмеченный текст возвращается как есть: функция идемпотентна, и её
     * безопасно звать на поле, которое менеджер уже правил руками.
     */
    public static function toMarkdown(string $text): string {
        $text = utf8Text($text);
        return self::looksLikeHtml($text) ? self::htmlToMarkdown($text) : self::tidy($text);
    }

    // ------------------------------------------------------------ HTML → MD

    public static function htmlToMarkdown(string $html): string {
        $html = utf8Text($html);
        if (trim($html) === '') return '';

        // Скрипты и стили — не текст товара ни в каком виде
        $html = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1>#isu', '', $html);

        // На хостинге может не быть ext-dom, и тогда `new DOMDocument()` — это
        // фатальная ошибка посреди генерации КП. Разбираем регулярками: хуже,
        // чем деревом, но лучше, чем `<ul><li>` в подписанном документе.
        if (!class_exists('DOMDocument')) return self::htmlToMarkdownFallback($html);

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // МойСклад отдаёт фрагмент, а не документ, и нередко с незакрытыми тегами
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>',
                                 LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) return self::tidy(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) return '';

        return self::tidy(self::walk($body, ''));
    }

    /**
     * То же самое без ext-dom. Понимает ровно то, чем бывает описание товара из
     * МойСклад: списки, абзацы, переносы и выделение. Всё остальное теряет
     * теги, но сохраняет текст — а текст здесь и есть ценность.
     */
    private static function htmlToMarkdownFallback(string $html): string {
        $out = $html;
        $out = (string)preg_replace('#<br\s*/?>#iu', "\n", $out);
        $out = (string)preg_replace('#</?(?:p|div|section|article|header|footer|blockquote|h[1-6]|table)\b[^>]*>#iu', "\n\n", $out);
        $out = (string)preg_replace('#</li\s*>#iu', "\n", $out);
        $out = (string)preg_replace('#<li\b[^>]*>#iu', '- ', $out);
        $out = (string)preg_replace('#</?(?:ul|ol)\b[^>]*>#iu', "\n", $out);
        $out = (string)preg_replace('#</?(?:b|strong)\b[^>]*>#iu', '**', $out);
        $out = (string)preg_replace('#</?(?:i|em)\b[^>]*>#iu', '*', $out);
        $out = (string)preg_replace('#</?(?:td|th)\b[^>]*>#iu', ' · ', $out);
        $out = (string)preg_replace('#</tr\s*>#iu', "\n", $out);
        $out = strip_tags($out);
        $out = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // «** **» вокруг пустоты и осиротевшие маркеры списка — мусор обхода
        $out = (string)preg_replace('/\*\*\s*\*\*/u', '', $out);
        $out = (string)preg_replace('/^[ \t]*-[ \t]*$/mu', '', $out);
        return self::tidy($out);
    }

    /** Обход дерева: каждый узел отдаёт свой кусок Markdown. */
    private static function walk(DOMNode $node, string $listPrefix): string {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                // Внутри HTML перенос строки — это пробел; абзацы делают теги
                $out .= (string)preg_replace('/\s+/u', ' ', $child->nodeValue ?? '');
                continue;
            }
            if (!$child instanceof DOMElement) continue;

            $tag = strtolower($child->tagName);
            $out .= match ($tag) {
                'br'  => "\n",
                'hr'  => "\n\n---\n\n",
                'img' => self::image($child),
                'b', 'strong' => self::emphasis($child, '**'),
                'i', 'em'     => self::emphasis($child, '*'),
                'a'   => self::link($child),
                'ul', 'ol' => "\n" . self::list($child, $tag === 'ol') . "\n",
                'li'  => $listPrefix . trim(self::walk($child, '')) . "\n",
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6' =>
                    "\n\n" . str_repeat('#', (int)substr($tag, 1)) . ' ' . trim(self::walk($child, '')) . "\n\n",
                'tr'  => "\n" . trim(self::cells($child)) . "\n",
                'td', 'th' => trim(self::walk($child, '')),
                'p', 'div', 'section', 'article', 'header', 'footer', 'blockquote' =>
                    "\n\n" . trim(self::walk($child, '')) . "\n\n",
                default => self::walk($child, $listPrefix),
            };
        }
        return $out;
    }

    /** `<ul>` и `<ol>`: маркер у каждого пункта, нумерация — по порядку. */
    private static function list(DOMElement $list, bool $ordered): string {
        $out = '';
        $n = 1;
        foreach ($list->childNodes as $li) {
            if (!$li instanceof DOMElement || strtolower($li->tagName) !== 'li') continue;
            $text = trim(self::walk($li, ''));
            if ($text === '') continue;
            // Вложенный список уже пришёл со своими переносами — сдвигаем его целиком
            $text = (string)preg_replace('/\n+/u', "\n  ", $text);
            $out .= ($ordered ? ($n++) . '. ' : '- ') . $text . "\n";
        }
        return $out;
    }

    /** Ячейки строки таблицы — через « · »: таблица в описании всегда узкая. */
    private static function cells(DOMElement $tr): string {
        $cells = [];
        foreach ($tr->childNodes as $td) {
            if (!$td instanceof DOMElement || !in_array(strtolower($td->tagName), ['td', 'th'], true)) continue;
            $text = trim((string)preg_replace('/\s+/u', ' ', self::walk($td, '')));
            if ($text !== '') $cells[] = $text;
        }
        return implode(' · ', $cells);
    }

    private static function emphasis(DOMElement $el, string $marker): string {
        $text = trim(self::walk($el, ''));
        // `**` вокруг пустоты — это `****` в тексте, а не выделение
        return $text === '' ? '' : $marker . $text . $marker;
    }

    private static function link(DOMElement $el): string {
        $text = trim(self::walk($el, ''));
        $href = trim((string)$el->getAttribute('href'));
        if ($text === '') return $href;
        if ($href === '' || $href === $text) return $text;
        return '[' . $text . '](' . $href . ')';
    }

    private static function image(DOMElement $el): string {
        $alt = trim((string)$el->getAttribute('alt'));
        $src = trim((string)$el->getAttribute('src'));
        // Картинки карточки приходят отдельным списком фото; base64 в описании
        // не нужен никому — от неё остаётся только подпись, если она была
        if ($alt === '') return '';
        return str_starts_with($src, 'data:') ? $alt : '![' . $alt . '](' . $src . ')';
    }

    /** Убрать хвосты обхода: тройные пустые строки, пробелы на концах строк. */
    private static function tidy(string $text): string {
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = (string)preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string)preg_replace('/ *\n */u', "\n", $text);
        $text = (string)preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }

    // ------------------------------------------------------------ MD → HTML

    /**
     * Markdown → HTML для документа. Всё, что не разметка, экранируется: текст
     * товара приходит из МойСклад и из рук менеджера, и ни то ни другое не
     * должно превращаться в теги само по себе.
     */
    public static function markdownToHtml(string $md): string {
        $md = utf8Text($md);

        // В поле может лежать HTML — из МойСклад, из письма, из старого КП,
        // который не застал миграцию. Экранировать его значит напечатать
        // `<ul><li>Класс защиты Бр1.</li>` буквой в подписанном документе; это
        // и случилось. Разметку приводим к Markdown прямо здесь, на печати:
        // печать — последняя дверь, и дальше проверять уже негде (модуль 022).
        if (self::looksLikeHtml($md)) $md = self::toMarkdown($md);

        $md = self::tidy($md);
        if ($md === '') return '';

        $html = '';
        $list = null;        // 'ul' | 'ol' | null — открытый сейчас список
        $para = [];          // строки текущего абзаца

        $closeList = function () use (&$html, &$list) {
            if ($list !== null) { $html .= "</$list>"; $list = null; }
        };
        $closePara = function () use (&$html, &$para) {
            if ($para) { $html .= '<p>' . implode('<br>', $para) . '</p>'; $para = []; }
        };

        foreach (explode("\n", $md) as $line) {
            $line = rtrim($line);

            if (trim($line) === '') { $closePara(); $closeList(); continue; }

            if (preg_match('/^ *(?:---+|\*\*\*+)$/u', $line)) {
                $closePara(); $closeList();
                $html .= '<hr>';
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/u', $line, $m)) {
                $closePara(); $closeList();
                $level = min(6, strlen($m[1]));
                $html .= "<h$level>" . self::inlineToHtml($m[2]) . "</h$level>";
                continue;
            }

            // Пункт списка: «- », «* », «• » или «1. »
            if (preg_match('/^\s*(?:([-*•])|(\d+)[.)])\s+(.*)$/u', $line, $m)) {
                $closePara();
                $want = $m[1] !== '' ? 'ul' : 'ol';
                if ($list !== $want) { $closeList(); $html .= "<$want>"; $list = $want; }
                $html .= '<li>' . self::inlineToHtml($m[3]) . '</li>';
                continue;
            }

            $closeList();
            $para[] = self::inlineToHtml($line);
        }
        $closePara();
        $closeList();
        return $html;
    }

    /**
     * Разметка внутри строки. Порядок важен: сначала экранируем весь текст,
     * потом вставляем теги — иначе `<` из «ширина < 40 мм» станет тегом.
     */
    private static function inlineToHtml(string $text): string {
        $out = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // [текст](ссылка) — адрес проверяем: javascript: в КП не попадает
        $out = (string)preg_replace_callback(
            '/\[([^\]]+)\]\(\s*([^)\s]+)\s*\)/u',
            function ($m) {
                $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!preg_match('#^(?:https?://|mailto:|tel:)#iu', $href)) return $m[1];
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $m[1] . '</a>';
            },
            $out
        );

        $out = (string)preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<b>$1</b>', $out);
        $out = (string)preg_replace('/(?<![\w*])\*(?=\S)([^*]+?)(?<=\S)\*(?![\w*])/u', '<i>$1</i>', $out);
        return $out;
    }
}
