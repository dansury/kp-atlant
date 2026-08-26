<?php
/**
 * Fuzzy product matching: Levenshtein + LLM normalization.
 * Threshold >60%, max 3 candidates, auto-pick at >90%.
 */
class ProductMatcher {

    // Match parsed items against products_cache
    public static function matchItems(array $parsedItems): array {
        if (empty($parsedItems)) return [];

        // Stage 1: LLM normalize names
        $rawNames = array_map(fn($i) => $i['name'], $parsedItems);
        $normalized = RequestParser::normalizeNames($rawNames);
        $normMap = [];
        foreach ($normalized as $n) {
            $normMap[$n['original']] = $n['normalized'];
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

    // Find candidates from products_cache using Levenshtein
    private static function findCandidates(string $query, int $maxResults = 3): array {
        $query = self::normalize($query);
        if (empty($query)) return [];

        // Get all products from cache
        $products = Db::all("SELECT moysklad_id, name, name_normalized, article, price, stock, reserved, unit FROM products_cache");
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

            // Also compute char-level Levenshtein
            $maxLen = max(mb_strlen($a), mb_strlen($b));
            $lev = $maxLen > 0 ? 1 - (levenshtein($a, $b) / $maxLen) : 0;

            // Weighted average
            return $jaccard * 0.6 + $lev * 0.4;
        }

        // Single word: Levenshtein
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        return $maxLen > 0 ? 1 - (levenshtein($a, $b) / $maxLen) : 0;
    }

    // Normalize string for matching
    private static function normalize(string $s): string {
        $s = mb_strtolower($s);
        $s = preg_replace('/[\s\-\"\'«»()\[\]]+/', ' ', $s);
        $s = preg_replace('/\b(шт|штук|штуки|ед|компл)\b\.?/', '', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
