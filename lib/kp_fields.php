<?php
/**
 * Тексты КП, которые правят в редакторе и которые переходят в следующие КП
 * (модуль 051).
 *
 * Страница для редактора печатается с метками: `data-kp-field` у каждого
 * такого текста и `<span data-kp-var>` у каждой подстановки. При сохранении
 * страницы тексты читаются обратно, подстановки, которых не трогали,
 * снова становятся `{name}`, и изменённый текст ложится в КП и в заготовку.
 */
require_once __DIR__ . '/kp_terms.php';

final class KpFields {

    public const INTRO_SETTING = 'kp_intro_template';
    public const INTRO_FACTORY = '{seller} {by_request} имеет возможность поставить следующее вещевое имущество:';

    /** Пустое значение подстановки держит свой <span> живым в редакторе. */
    public const ZWSP = "\u{200B}";

    /** Поле → [название для людей, подсказка на пустом месте листа]. */
    public const FIELDS = [
        'intro_text'      => ['Вступление', 'Вступление'],
        'pre_table_text'  => ['Текст перед таблицей', 'Текст перед таблицей — щёлкните, чтобы написать'],
        'post_table_text' => ['Текст после таблицы', 'Текст после таблицы — щёлкните, чтобы написать'],
        'terms_text'      => ['Условия поставки', 'Условия поставки'],
        'unmatched_note'  => ['Пояснение к позициям без ответа', 'Пояснение к позициям без ответа'],
        'images_note'     => ['Оговорка под фотографиями', 'Оговорка под фотографиями'],
        'upsell_intro'    => ['Доукомплектование · вступление', 'Вступление к доукомплектованию'],
        'upsell_note'     => ['Доукомплектование · подпись к фото', 'Подпись к фото полной комплектации'],
    ];

    /** Поле КП → ключ настройки, из которой берётся заготовка. */
    private const SETTING_OF = [
        'images_note'  => 'kp_images_note',
        'upsell_intro' => 'kp_upsell_intro',
        'upsell_note'  => 'kp_upsell_note',
    ];

    // ------------------------------------------------------------ вступление

    public static function introTemplate(): string {
        $t = Db::val("SELECT value FROM settings WHERE key=?", [self::INTRO_SETTING]);
        return trim((string)$t) !== '' ? (string)$t : self::INTRO_FACTORY;
    }

    /** {seller} — продавец из снимка МойСклад, {by_request} — «по запросу <покупатель>». */
    public static function introVars(array $requisites, array $legal): array {
        $seller = $requisites['seller'] ?? [];
        $sellerName = trim((string)(($seller['short_name'] ?? '') ?: ($legal['short_name'] ?? '')))
            ?: trim((string)(($seller['full_name'] ?? '') ?: ($legal['full_name'] ?? '')));
        $buyer = trim((string)(($requisites['buyer']['legal_title'] ?? '') ?: ($requisites['buyer']['name'] ?? '')));
        return [
            '{seller}'     => $sellerName,
            '{by_request}' => $buyer !== '' ? 'по запросу ' . $buyer : 'по Вашему запросу',
        ];
    }

    // ---------------------------------------------------------------- печать

    /** Атрибут метки — только на странице редактора. */
    public static function attr(string $field, bool $editor): string {
        if (!$editor) return '';
        $hint = self::FIELDS[$field][1] ?? '';
        return ' data-kp-field="' . htmlspecialchars($field) . '" data-kp-hint="' . htmlspecialchars($hint) . '"';
    }

    /**
     * Текст как он печатается. Для PDF и Word — ровно как до модуля 051;
     * для редактора — подстановки в своих <span>.
     */
    public static function html(string $raw, array $vars, bool $editor, bool $br = true): string {
        $fmt = fn(string $s): string => $br ? nl2br(htmlspecialchars($s)) : htmlspecialchars($s);
        if (!$editor) return $fmt(strtr($raw, $vars));
        $vars = array_filter($vars, fn($k) => str_contains($raw, (string)$k), ARRAY_FILTER_USE_KEY);
        if (!$vars) return $fmt($raw);
        $pattern = '/(' . implode('|', array_map(fn($k) => preg_quote((string)$k, '/'), array_keys($vars))) . ')/u';
        $out = '';
        foreach (preg_split($pattern, $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if (!array_key_exists($part, $vars)) { $out .= $fmt($part); continue; }
            $value = (string)$vars[$part];
            $out .= '<span data-kp-var="' . htmlspecialchars(trim($part, '{}')) . '" data-kp-val="'
                  . htmlspecialchars($value) . '">' . ($value === '' ? self::ZWSP : $fmt($value)) . '</span>';
        }
        return $out;
    }

    // ------------------------------------------------------ чтение со страницы

    /**
     * Тексты размеченных полей страницы. Одно поле может стоять несколько раз
     * (оговорка под каждой фотографией) — поэтому список.
     * @return array<string,list<string>>
     */
    public static function extract(string $html): array {
        if (!str_contains($html, 'data-kp-field')) return [];
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $out = [];
        foreach ((new DOMXPath($dom))->query('//*[@data-kp-field]') ?: [] as $el) {
            if (!$el instanceof DOMElement) continue;
            $field = $el->getAttribute('data-kp-field');
            if (!isset(self::FIELDS[$field])) continue;
            $buf = '';
            self::textOf($el, $buf);
            $out[$field][] = self::normalize($buf);
        }
        return $out;
    }

    private const BLOCKS = ['div', 'p', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'tr', 'table', 'blockquote'];

    private static function textOf(DOMNode $node, string &$buf): void {
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMText) {
                $buf .= (string)preg_replace('/[ \t\r\n]+/u', ' ', str_replace("\xC2\xA0", ' ', (string)$c->nodeValue));
                continue;
            }
            if (!$c instanceof DOMElement) continue;
            $tag = strtolower($c->tagName);
            if ($tag === 'br') { $buf .= "\n"; continue; }
            if ($c->hasAttribute('data-kp-editor-ui')) continue;
            if ($c->hasAttribute('data-kp-var')) {
                $inner = '';
                self::textOf($c, $inner);
                $same = self::normalize($inner) === self::normalize($c->getAttribute('data-kp-val'));
                $buf .= $same ? '{' . $c->getAttribute('data-kp-var') . '}' : $inner;
                continue;
            }
            $block = in_array($tag, self::BLOCKS, true);
            if ($block && $buf !== '' && !str_ends_with($buf, "\n")) $buf .= "\n";
            self::textOf($c, $buf);
            if ($block && $buf !== '' && !str_ends_with($buf, "\n")) $buf .= "\n";
        }
    }

    /** Один и тот же текст одинаково: пробелы, края строк, переводы строк. */
    public static function normalize(string $s): string {
        $s = str_replace([self::ZWSP, "\r\n", "\r", "\xC2\xA0"], ['', "\n", "\n", ' '], $s);
        $s = (string)preg_replace('/[ \t]+/u', ' ', $s);
        $s = (string)preg_replace('/ *\n */u', "\n", $s);
        return trim($s);
    }

    // ------------------------------------------------ в КП и в заготовку

    /**
     * Что напечатано сейчас: своё значение КП или заготовка.
     * @return list<string> варианты, которые считаются «не трогали»
     */
    private static function printed(string $field, array $p): array {
        return match ($field) {
            'intro_text'   => [trim((string)($p['intro_text'] ?? '')) !== '' ? (string)$p['intro_text'] : self::introTemplate()],
            'terms_text'   => [KpTerms::rawForProposal($p), KpTerms::legacyTerm(KpTerms::rawForProposal($p), $p)],
            'unmatched_note' => [(string)Settings::get('KP_UNMATCHED_NOTE', '')],
            'images_note', 'upsell_intro', 'upsell_note'
                           => [trim((string)($p[$field] ?? '')) !== '' ? (string)$p[$field]
                               : (string)(Db::val("SELECT value FROM settings WHERE key=?", [self::SETTING_OF[$field]]) ?? '')],
            default        => [(string)($p[$field] ?? '')],
        };
    }

    /**
     * Изменённые тексты — в КП и в заготовку следующих КП.
     * @param array<string,list<string>> $extracted из extract()
     * @return list<string> названия полей, ставших заготовкой
     */
    public static function apply(int $proposalId, array $extracted, int $managerId = 0): array {
        $p = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        if (!$p) return [];
        $learned = [];
        $own = [];
        foreach ($extracted as $field => $values) {
            if (!isset(self::FIELDS[$field])) continue;
            $was = array_map([self::class, 'normalize'], self::printed($field, $p));
            $new = null;
            foreach ($values as $v) {
                if (!in_array(self::normalize($v), $was, true)) { $new = self::normalize($v); break; }
            }
            if ($new === null) continue;

            if ($field !== 'unmatched_note') $own[$field] = $new;
            match ($field) {
                'terms_text'   => KpTerms::remember($new),
                'intro_text'   => Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)", [self::INTRO_SETTING, $new]),
                'unmatched_note' => Settings::set('KP_UNMATCHED_NOTE', $new),
                'images_note', 'upsell_intro', 'upsell_note'
                               => Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)", [self::SETTING_OF[$field], $new]),
                // KpSet::create() берёт последнюю такую правку (модуль 011)
                'pre_table_text', 'post_table_text' => Db::insert('corrections', [
                    'request_id'   => $p['request_id'],
                    'field'        => $field === 'pre_table_text' ? 'pre_table' : 'post_table',
                    'auto_text'    => (string)($p[$field] ?? ''),
                    'manager_text' => $new,
                    'manager_id'   => $managerId ?: null,
                    'context_json' => json_encode(['proposal_id' => $proposalId, 'source' => 'kp_editor'], JSON_UNESCAPED_UNICODE),
                ]),
            };
            $learned[] = self::FIELDS[$field][0];
        }
        if ($own) Db::update('proposals', $own + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$proposalId]);
        return $learned;
    }
}
