<?php
/**
 * Analogues for a position we cannot ship (module 013).
 *
 * The rule the manager asked for, in order: look at the NAME (through the
 * synonyms of the trade, because a client writes «броник» and we sell a
 * «бронежилет»), then look at the DESCRIPTION and compare it against what the
 * client actually asked for, and then say OUT LOUD which of those requirements
 * our position meets. A КП that silently swaps a brand for ours reads as a
 * mistake; a КП that says «просили класс Бр5 — у нашего Бр5, площадь та же,
 * вес на 300 г меньше» reads as an answer.
 *
 * Everything here degrades instead of failing, the way the rest of the catalog
 * does: with no model key the analogue is still found (names, synonyms and
 * vectors), still checked against the requirements (locally, by text) and still
 * explained — only in flatter words. A dead API never turns into «аналог не
 * найден» when one is sitting in the catalog.
 *
 * Nothing here ever asks a question. A line with no analogue in stock returns
 * null and the КП says «уточним срок поставки» — it does not stop to ask.
 */
require_once __DIR__ . '/matcher.php';
require_once __DIR__ . '/synonyms.php';

final class Alternatives {

    /** How many catalog rows one line is allowed to consider. */
    private const POOL = 8;

    /** Words that carry no requirement on their own. */
    private const STOP = ['для', 'или', 'при', 'под', 'над', 'без', 'шт', 'штук', 'комплект',
                          'нужно', 'нужен', 'нужна', 'требуется', 'просим', 'прошу'];

    /** Attribute words that make a fragment a requirement even with no digit in it. */
    private const ATTRIBUTES = ['класс', 'защит', 'вес', 'размер', 'рост', 'цвет', 'материал',
                                'ткань', 'площад', 'стандарт', 'гост', 'объ', 'литр', 'ростов',
                                'исполнен', 'крепл', 'посадк', 'камуфляж', 'расцветк', 'плотност'];

    public static function enabled(): bool {
        return (int)Settings::get('ALT_ENABLED', 1) === 1;
    }

    /**
     * Is this catalog row actually shippable? «Есть на складе» in a КП means
     * the free remainder, not the number МойСклад prints on the card: stock
     * that is already reserved for someone else is not ours to promise.
     */
    public static function freeStock(?array $product): int {
        if (!$product) return 0;
        return max(0, (int)($product['stock'] ?? 0) - (int)($product['reserved'] ?? 0));
    }

    /**
     * Analogues for the lines that need one, all in a single model call.
     *
     * $useLlm = false keeps it free: opening a request card must not spend a
     * model call (module 008), so the card gets the local answer; a line the
     * catalog left unsettled goes to the model by itself (`Autopick`, module 065).
     *
     * @param array $lines [['raw_name' => string, 'raw_text' => string, 'exclude_id' => ?string]]
     * @return array<int, ?array> same keys as $lines; null = nothing in stock fits
     */
    public static function suggest(array $lines, ?int $counterpartyId = null, bool $useLlm = true): array {
        $out = array_fill_keys(array_keys($lines), null);
        if (!$lines || !self::enabled()) return $out;

        // 1. Candidates by name and meaning, narrowed to what we can actually ship
        $pools = [];
        foreach ($lines as $i => $line) {
            $pool = self::candidates((string)($line['raw_name'] ?? ''), (string)($line['exclude_id'] ?? ''), $counterpartyId);
            if ($pool) $pools[$i] = $pool;
        }
        if (!$pools) return $out;

        // 2. What the client asked for, read off their own words — free, and the
        //    only version of the requirements that is guaranteed to exist
        $wanted = [];
        foreach ($pools as $i => $_) {
            $wanted[$i] = self::requirements(
                trim((string)($lines[$i]['raw_name'] ?? '') . "\n" . (string)($lines[$i]['raw_text'] ?? ''))
            );
        }

        // 3. Local verdict first, so the model has something to fall back to
        foreach ($pools as $i => $pool) {
            $out[$i] = self::localPick($pool, $wanted[$i]);
        }

        // 4. One model call for every line at once, then merge what it chose.
        //    It may only pick among the ids we handed it — the price, the stock
        //    and the name still come from the catalog, never from the answer.
        if ($useLlm && (int)Settings::get('ALT_USE_LLM', 1) === 1) {
            try {
                foreach (self::askModel($lines, $pools, $wanted) as $i => $picked) {
                    if ($picked) $out[$i] = $picked;
                }
            } catch (Throwable $e) {
                Logger::warning('catalog', 'Подбор аналогов нейросетью не удался: ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Catalog rows that could stand in for this name, best first.
     * Only rows with a free remainder: an analogue that is itself out of stock
     * answers nothing.
     */
    public static function candidates(string $name, string $excludeId = '', ?int $counterpartyId = null): array {
        $name = trim($name);
        if ($name === '') return [];

        // Семья товара, вместо которого ищем, — не аналог (issue #132): другой
        // размер или цвет того же шлема — это тот же шлем, а не замена ему
        $family = $excludeId === '' ? '' : ((string)(Db::val("SELECT parent_id FROM products_cache WHERE moysklad_id=?",
                                                             [$excludeId]) ?: '') ?: $excludeId);
        $merged = [];
        foreach (Synonyms::variants($name) as $variant) {
            foreach (ProductMatcher::findCandidates($variant, self::POOL, null, $counterpartyId) as $row) {
                $id = (string)$row['moysklad_id'];
                if ($id === $excludeId) continue;
                if ($family !== '' && ($id === $family || (string)($row['parent_id'] ?? '') === $family)) continue;
                if (self::freeStock($row) <= 0) continue;
                // The same row found by two phrasings keeps its better score
                if (!isset($merged[$id]) || $row['score'] > $merged[$id]['score']) $merged[$id] = $row;
            }
        }
        if (!$merged) return [];

        // Description and characteristics — what «посмотреть описание» means.
        // A variant with none of its own borrows its product's, exactly as the
        // КП card does.
        $ids = array_keys($merged);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach (Db::all(
            "SELECT p.moysklad_id, p.description, p.characteristics, p.specs_text, p.parent_id,
                    par.description AS parent_description, par.specs_text AS parent_specs
             FROM products_cache p
             LEFT JOIN products_cache par ON par.moysklad_id = p.parent_id
             WHERE p.moysklad_id IN ($ph)", $ids
        ) as $row) {
            $id = $row['moysklad_id'];
            $merged[$id]['description'] = trim((string)$row['description']) !== ''
                ? (string)$row['description'] : (string)$row['parent_description'];
            $merged[$id]['specs_text'] = trim((string)$row['specs_text']) !== ''
                ? (string)$row['specs_text'] : (string)$row['parent_specs'];
            $merged[$id]['characteristics'] = (string)($row['characteristics'] ?? '');
        }

        $pool = array_values($merged);
        usort($pool, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($pool, 0, self::POOL);
    }

    /**
     * The requirements hiding in what the client wrote.
     *
     * Split on the punctuation a specification line uses and keep the fragments
     * that say something measurable — a number, or one of the attribute words.
     * «Бронежилет 5 класса защиты, площадь 40 дм2, цвет мультикам, 10 шт» gives
     * three requirements and drops the quantity.
     */
    public static function requirements(string $text): array {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') return [];

        $out = [];
        foreach (preg_split('/[,;\n·•\/]+|\s-\s/u', $text) ?: [] as $fragment) {
            $fragment = trim($fragment, " \t.-–—:");
            if ($fragment === '' || mb_strlen($fragment) < 3) continue;
            // A bare quantity is not a requirement about the product
            if (preg_match('/^\d+\s*(шт|штук|компл|ед)\.?$/iu', $fragment)) continue;

            $hasNumber = (bool)preg_match('/\d/u', $fragment);
            $hasAttribute = false;
            $lower = mb_strtolower($fragment);
            foreach (self::ATTRIBUTES as $a) {
                if (str_contains($lower, $a)) { $hasAttribute = true; break; }
            }
            if (!$hasNumber && !$hasAttribute) continue;

            $out[] = $fragment;
            if (count($out) >= 10) break;
        }
        return $out;
    }

    /**
     * The answer with no model in it: the best-scoring row in stock, with every
     * requirement checked against its own text. This is what a КП says when the
     * key is missing, the proxy is down or the model refuses — never «не нашли».
     */
    private static function localPick(array $pool, array $wanted): ?array {
        $best = null;
        foreach ($pool as $row) {
            $verdict = self::compare($row, $wanted);
            // More requirements met beats a better name score; a tie goes to the name
            $rank = [count($verdict['matched']), $row['score']];
            if ($best === null || $rank > $best['rank']) {
                $best = ['rank' => $rank, 'row' => $row, 'verdict' => $verdict];
            }
        }
        if (!$best) return null;

        return self::shape($best['row'], $best['verdict']['matched'], $best['verdict']['differs'],
                           self::localReason($best['row'], $best['verdict']), 'words');
    }

    /** Every requirement checked against one row's own text. */
    private static function compare(array $row, array $wanted): array {
        $haystack = mb_strtolower(trim(
            ($row['name'] ?? '') . ' ' . ($row['characteristics'] ?? '') . ' '
            . ($row['specs_text'] ?? '') . ' ' . ($row['description'] ?? '')
        ));
        $matched = [];
        $differs = [];
        foreach ($wanted as $requirement) {
            $proof = self::proofOf($requirement, $haystack);
            if ($proof !== null) {
                $matched[] = ['requirement' => $requirement, 'ours' => $proof];
            } else {
                $differs[] = $requirement;
            }
        }
        return ['matched' => $matched, 'differs' => $differs];
    }

    /**
     * Does our text actually say this? Returns the sentence that proves it, so
     * the КП can quote OUR description rather than repeat the client's wording
     * back at them as if it were a fact about our product.
     */
    private static function proofOf(string $requirement, string $haystack): ?string {
        $tokens = self::tokens($requirement);
        if (!$tokens) return null;

        $hits = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) $hits++;
        }
        // Two thirds of the meaningful words, and every number, must be there:
        // «класс защиты 5» must not be proven by a row that only says «класс»
        if ($hits / count($tokens) < 0.67) return null;
        foreach ($tokens as $token) {
            if (preg_match('/^\d/u', $token) && !str_contains($haystack, $token)) return null;
        }

        // The sentence around the first hit — evidence a manager can read
        foreach (preg_split('/[.;\n]+/u', $haystack) ?: [] as $sentence) {
            foreach ($tokens as $token) {
                if (str_contains($sentence, $token)) {
                    $sentence = trim($sentence);
                    return mb_strlen($sentence) > 160 ? mb_substr($sentence, 0, 160) . '…' : $sentence;
                }
            }
        }
        return trim($requirement);
    }

    /** Words of a requirement worth looking for: no stop-words, nothing tiny. */
    private static function tokens(string $text): array {
        $text = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
        $out = [];
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($word === '' || in_array($word, self::STOP, true)) continue;
            if (mb_strlen($word) < 2) continue;
            // Cut the Russian ending, the same way the catalog search does
            $out[mb_strlen($word) > 6 ? mb_substr($word, 0, 6) : $word] = true;
        }
        return array_keys($out);
    }

    private static function localReason(array $row, array $verdict): string {
        if ($verdict['matched']) {
            return 'соответствует запросу по ' . count($verdict['matched'])
                 . ' из ' . (count($verdict['matched']) + count($verdict['differs'])) . ' указанных требований, есть на складе';
        }
        return 'ближайшая позиция нашего производства из наличия';
    }

    /**
     * One model call for every line at once.
     * The model chooses among ids we already verified and explains the choice —
     * it never supplies a name, a price or a stock level.
     */
    private static function askModel(array $lines, array $pools, array $wanted): array {
        $payload = [];
        foreach ($pools as $i => $pool) {
            $payload[] = [
                'index'        => $i,
                'requested'    => trim((string)($lines[$i]['raw_name'] ?? '')),
                'request_text' => self::clip((string)($lines[$i]['raw_text'] ?? ''), 600),
                'requirements' => $wanted[$i],
                'candidates'   => array_map(fn($c) => [
                    'id'              => $c['moysklad_id'],
                    'name'            => $c['name'],
                    'characteristics' => self::clip((string)($c['characteristics'] ?? ''), 400),
                    'description'     => self::clip((string)($c['description'] ?? '') . ' ' . (string)($c['specs_text'] ?? ''), 1200),
                ], $pool),
            ];
        }

        $system = Prompts::render('kp_alternatives');
        // Temperature 0: the same letter must produce the same КП twice running
        $answer = LLM::chatJson($system, json_encode(['lines' => $payload], JSON_UNESCAPED_UNICODE), 0.0);

        $out = [];
        foreach ($answer['lines'] ?? [] as $picked) {
            $i = $picked['index'] ?? null;
            if (!is_int($i) || !isset($pools[$i])) continue;
            $id = (string)($picked['pick'] ?? '');
            $row = null;
            foreach ($pools[$i] as $candidate) {
                if ((string)$candidate['moysklad_id'] === $id) { $row = $candidate; break; }
            }
            if (!$row) continue;   // «ничего не подходит», or an id it invented

            // Keep only the claims our own text supports — a model that decides
            // our vest is «класс Бр6» because the client wanted Бр6 would put a
            // lie in a document we sign
            $verdict = self::compare($row, array_column((array)($picked['matched'] ?? []), 'requirement'));
            $matched = $verdict['matched'] ?: self::compare($row, $wanted[$i])['matched'];
            $differs = array_values(array_unique(array_merge(
                array_filter((array)($picked['differs'] ?? []), 'is_string'),
                $verdict['differs']
            )));

            $out[$i] = self::shape($row, $matched, $differs,
                                   trim((string)($picked['reason'] ?? '')) ?: self::localReason($row, $verdict),
                                   'model');
        }
        return $out;
    }

    /** The shape every caller gets, whoever picked the row. */
    private static function shape(array $row, array $matched, array $differs, string $reason, string $source): array {
        return [
            'moysklad_id' => $row['moysklad_id'],
            'name'        => $row['name'],
            'article'     => $row['article'] ?? '',
            'unit'        => $row['unit'] ?: 'шт.',
            'price'       => (float)($row['price'] ?? 0),
            'stock'       => (int)($row['stock'] ?? 0),
            'free'        => self::freeStock($row),
            'score'       => $row['score'] ?? null,
            'matched'     => array_values($matched),
            'differs'     => array_values($differs),
            'reason'      => $reason,
            'source'      => $source,
        ];
    }

    /** A КП-ready sentence: «Просили X — предлагаем Y: …». */
    public static function explain(string $requested, array $alt): string {
        $text = 'Запрошено «' . $requested . '» — предлагаем «' . $alt['name'] . '»';
        if ($alt['reason'] !== '') $text .= ': ' . $alt['reason'];
        return $text . '.';
    }

    private static function clip(string $text, int $max): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
