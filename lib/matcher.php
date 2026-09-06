<?php
/**
 * Product matching: words (Levenshtein + Jaccard) and meaning (Yandex vectors).
 *
 * Two things the manager asked for live here:
 *   1. positions are pulled into the card BY THEMSELVES — every line of the letter
 *      gets its catalog row without anyone pressing anything;
 *   2. when the best candidates are equally good («равнозначные»), nothing is
 *      picked silently: the line is marked `needs_choice` and the card asks.
 */
require_once __DIR__ . '/embeddings.php';
require_once __DIR__ . '/catalog.php';

class ProductMatcher {

    /** Cosine of a Yandex query/doc pair: ~0.35 is noise, ~0.85 is the same thing. */
    private const VEC_FLOOR = 0.35;
    private const VEC_CEIL  = 0.85;
    /** A purely semantic hit this strong is worth showing even with no shared words. */
    private const VEC_STRONG = 0.8;

    /**
     * Match parsed items against products_cache.
     * $useLlm=false keeps it local: opening a request card must not spend a
     * model call, so the card matches by name (and by vectors, which are already
     * built) and the manager asks for the smarter pass with a button.
     */
    public static function matchItems(array $parsedItems, bool $useLlm = true, ?int $counterpartyId = null): array {
        if (empty($parsedItems)) return [];

        // Stage 1: LLM normalize names
        $normMap = [];
        if ($useLlm) {
            $rawNames = array_map(fn($i) => $i['name'], $parsedItems);
            try {
                foreach (RequestParser::normalizeNames($rawNames) as $n) {
                    $normMap[$n['original']] = $n['normalized'];
                }
            } catch (Throwable $e) {
                // A model that is unreachable must not block the match itself
                Logger::warning('catalog', 'Нормализация названий не удалась: ' . $e->getMessage());
            }
        }

        $autoConfirm = (float)Settings::get('MATCH_AUTO_CONFIRM', 0.88);
        $delta       = (float)Settings::get('MATCH_EQUAL_DELTA', 0.05);
        $maxShown    = max(2, (int)Settings::get('MATCH_CANDIDATES', 5));

        // Stage 2: one embeddings request for ALL the lines. A letter with fourteen
        // positions would otherwise open the card with fourteen sequential HTTP
        // calls — the same «одна пачка вместо очереди» that builds the index.
        $names = array_map(fn($i) => $normMap[$i['name']] ?? $i['name'], $parsedItems);
        $queryVectors = [];
        if ((float)Settings::get('MATCH_VECTOR_WEIGHT', 0.5) > 0 && Embeddings::enabled()) {
            try {
                foreach (Embeddings::embedQueries($names) as $i => $vec) {
                    if ($vec) $queryVectors[$i] = $vec;
                }
            } catch (Throwable $e) {
                Logger::warning('catalog', 'Векторы запроса не получены: ' . $e->getMessage());
            }
        }

        // Stage 3: match each item against cache
        $results = [];
        foreach ($parsedItems as $index => $item) {
            $searchName = $names[$index];
            $candidates = self::findCandidates($searchName, $maxShown, $queryVectors[$index] ?? null, $counterpartyId);

            $result = [
                'raw_name' => $item['name'],
                'normalized_name' => $searchName,
                'quantity' => $item['qty'] ?? 1,
                'raw_text' => $item['raw_text'] ?? '',
                'match' => null,
                'variants' => [],
                'is_confirmed' => false,
                'needs_choice' => false,
                'match_source' => null,
            ];

            if ($candidates) {
                $best = $candidates[0];
                // «Равнозначные» — everything within delta of the leader. Two rows
                // that score 0.91 and 0.89 are not a match and a runner-up, they
                // are a question: the same vest in two sizes, the same helmet in
                // two colours. Picking the first one is a guess the manager pays for.
                $equal = array_values(array_filter($candidates, fn($c) => $best['score'] - $c['score'] <= $delta));

                $result['match'] = $best;
                $result['match_source'] = $best['source'];
                $result['variants'] = array_slice($candidates, 1, $maxShown - 1);
                $result['needs_choice'] = count($equal) > 1;
                $result['is_confirmed'] = !$result['needs_choice'] && $best['score'] >= $autoConfirm;
            }

            $results[] = $result;
        }
        return $results;
    }

    /** The whole catalog, read once per request — a KP has many positions. */
    private static ?array $catalog = null;

    public static function forgetCatalog(): void {
        self::$catalog = null;
        Embeddings::forgetIndex();
    }

    /**
     * Candidates for one phrase, best first. Public because «Настройки → Каталог
     * → Проверить поиск» shows exactly what the request card will see.
     *
     * Each row carries `score` (0..1), `lexical`, `vector` and `source`
     * (`words` / `meaning` / `both`), so the card can say why a row is there.
     *
     * $queryVector lets a caller that already embedded the phrase (a whole letter
     * embedded in one batch) skip the per-phrase request.
     */
    public static function findCandidates(string $query, int $maxResults = 3, ?array $queryVector = null, ?int $counterpartyId = null): array {
        $normQuery = self::normalize($query);
        if ($normQuery === '') return [];

        $products = self::catalog();
        if (empty($products)) return [];

        $minScore = (float)Settings::get('MATCH_MIN_SCORE', 0.6);
        $weight   = min(1.0, max(0.0, (float)Settings::get('MATCH_VECTOR_WEIGHT', 0.5)));

        // Meaning: the vector index answers «чем это по смыслу», which is how
        // «броник скрытого ношения» finds «Бронежилет скрытого ношения Страж»
        // without sharing a single whole word with it.
        $vector = [];
        if ($weight > 0 && Embeddings::enabled()) {
            try {
                $hits = $queryVector
                    ? Embeddings::searchByVector($queryVector, max(20, $maxResults * 4))
                    : Embeddings::search($query, max(20, $maxResults * 4));
                foreach ($hits as $hit) {
                    $vector[$hit['moysklad_id']] = self::vectorConfidence((float)$hit['score']);
                }
            } catch (Throwable $e) {
                // A dead embeddings API degrades the match, it never breaks it
                Logger::warning('catalog', 'Векторный поиск недоступен: ' . $e->getMessage());
            }
        }

        $scored = [];
        foreach ($products as $p) {
            $lexical = self::similarity($normQuery, $p['match_text']);

            // Article typed straight into the letter is an exact answer
            if ($p['article'] && stripos($normQuery, mb_strtolower((string)$p['article'])) !== false) {
                $lexical = max($lexical, 0.95);
            }

            $vec = $vector[$p['moysklad_id']] ?? null;
            // A product with no vector must not be punished for it — the lexical
            // score stands in, so a half-indexed catalog still ranks sensibly
            $combined = $vec === null ? $lexical : (1 - $weight) * $lexical + $weight * $vec;

            $qualifies = $combined >= $minScore || ($vec !== null && $vec >= self::VEC_STRONG);
            if (!$qualifies) continue;

            $prices = Catalog::decodePrices($p['prices_json'] ?? null);
            $scored[] = [
                'moysklad_id' => $p['moysklad_id'],
                'name'        => $p['name'],
                'article'     => $p['article'],
                'price'       => Catalog::priceFor($p, $counterpartyId),
                'prices'      => $prices,
                'stock'       => (int)$p['stock'],
                'reserved'    => (int)$p['reserved'],
                'unit'        => $p['unit'],
                'characteristics' => $p['characteristics'] ?? '',
                'score'       => round($combined, 3),
                'lexical'     => round($lexical, 3),
                'vector'      => $vec === null ? null : round($vec, 3),
                'source'      => self::sourceOf($lexical, $vec, $minScore),
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $maxResults);
    }

    /**
     * The catalog as the matcher needs it, read and normalized once per request.
     *
     * The comparison text is built HERE and not taken from `name_normalized`:
     * that column was written by whichever importer filled the row, and a
     * catalog half from the API and half from an Excel file would otherwise be
     * matched by two different rules.
     */
    private static function catalog(): array {
        if (self::$catalog !== null) return self::$catalog;
        $rows = Db::all(
            "SELECT moysklad_id, name, name_normalized, article, price, prices_json, stock, reserved, unit,
                    characteristics, product_type
             FROM products_cache WHERE is_archived IS NOT 1"
        );
        foreach ($rows as &$row) $row['match_text'] = self::normalize((string)$row['name']);
        unset($row);
        return self::$catalog = $rows;
    }

    /** Why this row is in the list — the card prints it next to the score. */
    private static function sourceOf(float $lexical, ?float $vec, float $minScore): string {
        $byWords   = $lexical >= $minScore;
        $byMeaning = $vec !== null && $vec >= self::VEC_STRONG;
        if ($byWords && $byMeaning) return 'both';
        if ($byMeaning) return 'meaning';
        return 'words';
    }

    /**
     * Cosine → confidence. Unrelated Yandex text-search pairs sit around 0.3,
     * the same product phrased differently around 0.8, so the raw number is not
     * comparable with a Levenshtein ratio until it is stretched over that range.
     */
    private static function vectorConfidence(float $cosine): float {
        $v = ($cosine - self::VEC_FLOOR) / (self::VEC_CEIL - self::VEC_FLOOR);
        return max(0.0, min(1.0, $v));
    }

    // Compute similarity 0..1
    private static function similarity(string $a, string $b): float {
        if ($a === $b) return 1.0;

        // Word-level Jaccard for multi-word names
        $wordsA = array_filter(explode(' ', $a));
        $wordsB = array_filter(explode(' ', $b));

        if (count($wordsA) > 1 || count($wordsB) > 1) {
            $intersection = count(array_intersect($wordsA, $wordsB));
            $union = count(array_unique(array_merge($wordsA, $wordsB)));
            $jaccard = $union > 0 ? $intersection / $union : 0;

            // Weighted average with the character distance
            return $jaccard * 0.6 + self::editSimilarity($a, $b) * 0.4;
        }

        // Single word: Levenshtein
        return self::editSimilarity($a, $b);
    }

    /**
     * Levenshtein normalized to 0..1. PHP's levenshtein() counts BYTES, so the
     * distance was divided by a length counted in CHARACTERS — on Cyrillic that
     * made the ratio roughly twice too big and could go negative, and «аптечка
     * большая полевая» scored below the threshold against «Большая полевая
     * аптечка», a rename of the very same product.
     */
    private static function editSimilarity(string $a, string $b): float {
        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) return 0.0;
        return max(0.0, 1 - (levenshtein($a, $b) / $maxLen));
    }

    /**
     * Normalize a name for matching.
     *
     * The /u flags are load-bearing: «»  are two bytes each, and the second byte
     * of «»» (0xBB) is also the second byte of «л». Without /u the character
     * class ate that byte out of every «л» in the catalog, so «аптечка большая
     * полевая» never found «Большая полевая аптечка».
     *
     * Punctuation is separated as well: the catalog writes «Бронежилет Страж,
     * размер L» and the client writes «бронежилет страж», so «страж,» has to
     * become the word «страж» or the two never share it.
     */
    private static function normalize(string $s): string {
        $s = mb_strtolower($s);
        $s = preg_replace('/[\s\-\"\'«»(),;:.\/\\\[\]]+/u', ' ', $s);
        $s = preg_replace('/\b(шт|штук|штуки|ед|компл)\b\.?/u', '', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}
