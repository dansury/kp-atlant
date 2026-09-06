<?php
/**
 * Fuzzy product matching: Levenshtein + LLM normalization.
 * Threshold >60%, max 3 candidates, auto-pick at >90%.
 */
class ProductMatcher {

    /**
     * Match parsed items against products_cache.
     * $useLlm=false keeps it local: opening a request card must not spend a
     * model call, so the card matches by name alone and the manager asks for
     * the smarter pass with a button.
     */
    public static function matchItems(array $parsedItems, bool $useLlm = true): array {
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

        // Stage 2: match each item against cache
        $results = [];
        foreach ($parsedItems as $item) {
            $searchName = $normMap[$item['name']] ?? $item['name'];
            $candidates = self::findCandidates($searchName);

            $result = [
                'raw_name' => $item['name'],
                'normalized_name' => $searchName,
                'quantity' => $item['qty'] ?? 1,
                'raw_text' => $item['raw_text'] ?? '',
                'match' => null,
                'variants' => [],
                'is_confirmed' => false,
            ];

            if (!empty($candidates)) {
                $best = $candidates[0];
                if ($best['score'] >= 0.9 && count($candidates) === 1) {
                    // Auto-pick at >90% with single match
                    $result['match'] = $best;
                    $result['is_confirmed'] = true;
                } elseif ($best['score'] >= 0.9) {
                    // >90% but multiple — pick best, still show variants
                    $result['match'] = $best;
                    $result['is_confirmed'] = true;
                    $result['variants'] = array_slice($candidates, 1, 2);
                } else {
                    // 60-90% — show options for manager
                    $result['match'] = $best;
                    $result['variants'] = array_slice($candidates, 1, 2);
                }
            }

            $results[] = $result;
        }
        return $results;
    }

    /** The whole catalog, read once per request — a KP has many positions. */
    private static ?array $catalog = null;

    public static function forgetCatalog(): void { self::$catalog = null; }

    // Find candidates from products_cache using Levenshtein
    private static function findCandidates(string $query, int $maxResults = 3): array {
        $query = self::normalize($query);
        if (empty($query)) return [];

        self::$catalog ??= Db::all(
            "SELECT moysklad_id, name, name_normalized, article, price, stock, reserved, unit
             FROM products_cache WHERE is_archived IS NOT 1"
        );
        $products = self::$catalog;
        if (empty($products)) return [];

        $scored = [];
        foreach ($products as $p) {
            $target = $p['name_normalized'] ?: self::normalize($p['name']);
            $score = self::similarity($query, $target);

            // Boost if article matches
            if ($p['article'] && stripos($query, $p['article']) !== false) {
                $score = max($score, 0.95);
            }

            if ($score >= 0.6) {
                $scored[] = [
                    'moysklad_id' => $p['moysklad_id'],
                    'name' => $p['name'],
                    'article' => $p['article'],
                    'price' => (float)$p['price'],
                    'stock' => (int)$p['stock'],
                    'reserved' => (int)$p['reserved'],
                    'unit' => $p['unit'],
                    'score' => round($score, 3),
                ];
            }
        }

        // Sort by score desc
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $maxResults);
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
     */
    private static function normalize(string $s): string {
        $s = mb_strtolower($s);
        $s = preg_replace('/[\s\-\"\'«»()\[\]]+/u', ' ', $s);
        $s = preg_replace('/\b(шт|штук|штуки|ед|компл)\b\.?/u', '', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}
