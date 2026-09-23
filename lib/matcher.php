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
require_once __DIR__ . '/markup.php';

class ProductMatcher {

    /** Cosine of a Yandex query/doc pair: ~0.35 is noise, ~0.85 is the same thing. */
    private const VEC_FLOOR = 0.35;
    private const VEC_CEIL  = 0.85;
    /** A purely semantic hit this strong is worth showing even with no shared words. */
    private const VEC_STRONG = 0.8;
    /** Нижний край оценки за полное вхождение слов запроса в название. */
    private const NAME_CONTAIN_BASE = 0.7;
    /** Потолок оценки для товара с ЧУЖОЙ меткой («бр2» против «бр3») — ниже порога. */
    private const MARKER_CONFLICT_CAP = 0.45;

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
            $candidates = self::rankedCandidates($searchName, $maxShown, $queryVectors[$index] ?? null, $counterpartyId);

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

            // A link to the product's own page on the shop is not a guess at
            // all: 47 letters of the archive quote one, and `products_cache`
            // knows which row it belongs to (module 015). A named row beats
            // every score, so the search never runs for that line.
            $byLink = self::byShopLink((string)($item['raw_text'] ?? '') . ' ' . (string)($item['name'] ?? ''), $counterpartyId);
            if ($byLink) {
                $result['match'] = $byLink;
                $result['match_source'] = 'site_url';
                $result['variants'] = [];
                $result['needs_choice'] = false;
                $result['is_confirmed'] = true;
                $results[] = $result;
                continue;
            }

            if ($candidates) {
                $best = $candidates[0];
                // «Равнозначные» — everything within delta of the leader. Two rows
                // that score 0.91 and 0.89 are not a match and a runner-up, they
                // are a question: the same vest in two sizes, the same helmet in
                // two colours. Picking the first one is a guess the manager pays for.
                // Только из ряда лидера: бронежилет с плитой в описании — не
                // «равнозначный вариант» самой плите.
                $fromDesc = fn($c) => ($c['source'] ?? '') === 'description';
                $equal = array_values(array_filter($candidates, fn($c) => $best['score'] - $c['score'] <= $delta
                    && $c['rank'] === $best['rank']));
                $candidates = self::stripRank($candidates);
                $best = $candidates[0];

                $result['match'] = $best;
                $result['match_source'] = $best['source'];
                $result['variants'] = array_slice($candidates, 1, $maxShown - 1);
                $result['needs_choice'] = count($equal) > 1;
                // Описание подставляется, но «ок» ему не ставится: слово из
                // чужого списка комплектации — не повод решать за менеджера
                $result['is_confirmed'] = !$result['needs_choice'] && $best['score'] >= $autoConfirm
                    && !$fromDesc($best);
            }

            $results[] = $result;
        }
        return $results;
    }

    /**
     * The catalog row a link in the client's own line points at.
     *
     * Clients paste the product page — «…/ballisticheskiy-shlem-termit-aramid/?oid=6018».
     * `products_cache.site_url` is the same address (module 013 caches it after
     * checking it), so the line needs no matching at all. The `oid` is Bitrix's
     * offer id; the slug is what stays stable when it is absent.
     */
    public static function byShopLink(string $text, ?int $counterpartyId = null): ?array {
        if (!preg_match('~https?://[\w.-]*atlant-armour\.ru/[^\s<>"\)\]]+~iu', $text, $m)) return null;
        $url = rtrim($m[0], '.,;');

        $row = Db::one("SELECT * FROM products_cache WHERE site_url=? LIMIT 1", [$url]);
        if (!$row) {
            $path = (string)parse_url($url, PHP_URL_PATH);
            $slug = trim((string)preg_replace('~.*/([^/]+)/?$~', '$1', $path));
            if ($slug !== '' && mb_strlen($slug) > 6) {
                $row = Db::one("SELECT * FROM products_cache WHERE site_url LIKE ? ORDER BY id LIMIT 1", ['%/' . $slug . '%']);
            }
        }
        if (!$row) return null;

        // The same shape `findCandidates()` returns — the card, the КП and the
        // analogue pass all read these keys and must not learn a second one.
        return [
            'moysklad_id' => $row['moysklad_id'],
            'name'        => $row['name'],
            'article'     => $row['article'],
            'price'       => Catalog::priceFor($row, $counterpartyId),
            'prices'      => Catalog::decodePrices($row['prices_json'] ?? null),
            'stock'       => (int)$row['stock'],
            'reserved'    => (int)$row['reserved'],
            'unit'        => $row['unit'],
            'characteristics' => $row['characteristics'] ?? '',
            'score'       => 1.0,
            'lexical'     => 1.0,
            'vector'      => null,
            'source'      => 'site_url',
        ];
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
        return self::stripRank(self::rankedCandidates($query, $maxResults, $queryVector, $counterpartyId));
    }

    /** Служебный ряд наружу не уходит. */
    private static function stripRank(array $rows): array {
        foreach ($rows as &$row) unset($row['rank']);
        unset($row);
        return $rows;
    }

    /** Кандидаты с рядом (`rank`) — `matchItems()` сравнивает ряды. */
    private static function rankedCandidates(string $query, int $maxResults, ?array $queryVector, ?int $counterpartyId): array {
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

        // «Монокуляр» стоит не в названии, а в описании — и это всё равно тот
        // самый товар. Описание ищется вторым заходом и стоит ДЕШЕВЛЕ названия:
        // совпадение в нём предлагается, но не выигрывает у совпадения в имени
        // (модуль 023).
        $descWeight = min(1.0, max(0.0, (float)Settings::get('MATCH_DESC_WEIGHT', 0.75)));
        $queryWords = self::contentWords($normQuery);
        // Потолок оценки за вхождение: на волос ниже автоподтверждения
        $nameCap = max(self::NAME_CONTAIN_BASE,
                       (float)Settings::get('MATCH_AUTO_CONFIRM', 0.88) - 0.01);

        // «Бр2» и «Бр3» — это РАЗНЫЕ товары (модуль 040). Отличаются они ровно
        // одним знаком, слово короче четырёх букв в отбор не попадает, и
        // «Боковая плита для бронежилета Бр2» уверенно находила плиту Бр3:
        // совпадение 84% и ни одного намёка, что класс защиты другой.
        $queryMarkers = self::markers($normQuery);

        // Название ищется ключевыми словами прежде описания: главное слово
        // (вид товара) и доля слов запроса в имени, метки — тоже слова
        $head = self::headWord($normQuery);
        $nameWords = $queryWords;
        foreach ($queryMarkers as $prefix => $values) {
            foreach (array_keys($values) as $v) $nameWords[] = $prefix . $v;
        }

        $scored = [];
        foreach ($products as $p) {
            $byName = self::similarity($normQuery, $p['match_text']);

            // Все слова запроса стоят в названии — это тот самый товар, хотя
            // Жаккар делит на объединение и топит «монокуляр аксион» в
            // «Монокуляр тепловизионный Пульсар Аксион XM30F». Множитель на
            // схожесть разводит товар, его модификацию и родителя.
            if ($queryWords) {
                $inName = self::containment($queryWords, $p['match_text']);
                $byName = max($byName, $inName * (self::NAME_CONTAIN_BASE
                    + ($nameCap - self::NAME_CONTAIN_BASE) * $byName));
            }
            $lexical = $byName;
            $headHit = $head === null || self::headInName($head, $p['match_text']);
            $inNameKeys = $nameWords ? self::containment($nameWords, $p['match_text']) : 0.0;

            // Article typed straight into the letter is an exact answer
            if ($p['article'] && stripos($normQuery, mb_strtolower((string)$p['article'])) !== false) {
                $lexical = max($lexical, 0.95);
            }

            $byDesc = 0.0;
            if ($descWeight > 0 && $queryWords && $p['desc_text'] !== '') {
                $byDesc = self::containment($queryWords, $p['desc_text']) * $descWeight;
                $lexical = max($lexical, $byDesc);
            }

            $vec = $vector[$p['moysklad_id']] ?? null;
            // A product with no vector must not be punished for it — the lexical
            // score stands in, so a half-indexed catalog still ranks sensibly
            $combined = $vec === null ? $lexical : (1 - $weight) * $lexical + $weight * $vec;

            // Класс защиты, номер модели, ГОСТ-индекс: запрос назвал один, у
            // товара стоит другой — это не «почти то же самое», это не тот
            // товар. Смысловая близость такую пару тоже не спасает.
            $conflict = $queryMarkers && self::markerConflict($queryMarkers, $p['match_text']);
            if ($conflict) $combined = min($combined, self::MARKER_CONFLICT_CAP);

            // Тот же вид товара и половина ключевых слов в имени — уже находка
            // по названию, даже когда «30x25 см» топят оценку ниже порога
            $byKeywords = !$conflict && $head !== null && $headHit && $inNameKeys >= 0.5;

            $qualifies = $combined >= $minScore || $byKeywords
                || (!$conflict && $vec !== null && $vec >= self::VEC_STRONG);
            if (!$qualifies) continue;

            $prices = Catalog::decodePrices($p['prices_json'] ?? null);
            $source = self::sourceOf($byKeywords ? max($byName, $minScore) : $byName, $byDesc, $vec, $minScore);
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
                'source'      => $source,
                'rank'        => self::rankOf($source, (string)($p['product_type'] ?? ''), $headHit),
            ];
        }

        // Ряд важнее оценки: 0.75, набранные описанием, не обгоняют 0.7,
        // набранные названием
        usort($scored, fn($a, $b) => [$a['rank'], -$a['score']] <=> [$b['rank'], -$b['score']]);
        return array_slice($scored, 0, $maxResults);
    }

    /**
     * Ряд выдачи: 0 — названием или смыслом и тот же вид товара, 1 — названием
     * или смыслом, но вид другой, 2 — описанием, 3 — описанием комплекта.
     * Описание комплекта — список ЧУЖИХ товаров: «монокуляр» в нём значит
     * «лежит внутри», а не «это он и есть».
     */
    private static function rankOf(string $source, string $productType, bool $headHit): int {
        if ($source !== 'description') return $headHit ? 0 : 1;
        return $productType === 'bundle' ? 3 : 2;
    }

    /** Главное слово запроса: первое буквенное от четырёх букв, вид товара. */
    private static function headWord(string $normalized): ?string {
        foreach (explode(' ', $normalized) as $w) {
            if (mb_strlen($w) >= 4 && preg_match('/^\p{L}+$/u', $w)) return $w;
        }
        return null;
    }

    /**
     * Главное слово стоит в первых трёх словах имени: вид товара пишется в
     * начале, а «(с бронеплитами)» в хвосте бронежилет плитой не делает.
     */
    private static function headInName(string $head, string $nameText): bool {
        foreach (array_slice(explode(' ', $nameText), 0, 3) as $w) {
            if (self::sameStem($head, $w)) return true;
        }
        return false;
    }

    /** «бронеплита» = «бронеплиты», но не «бронежилет». */
    private static function sameStem(string $a, string $b): bool {
        $min = min(mb_strlen($a), mb_strlen($b));
        $need = max(4, $min - 2);
        if ($min < $need) return false;
        return mb_substr($a, 0, $need) === mb_substr($b, 0, $need);
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
                    characteristics, description, specs_text, product_type, parent_id
             FROM products_cache WHERE is_archived IS NOT 1"
        );
        foreach ($rows as &$row) {
            $row['match_text'] = self::normalize((string)$row['name']);
            // Описание, характеристики и характеристики модификации — один
            // мешок слов: клиент не знает, в какое из полей мы это положили
            $row['desc_text'] = self::normalize(trim(
                Markup::toPlainText((string)($row['description'] ?? '')) . ' '
                . Markup::toPlainText((string)($row['specs_text'] ?? '')) . ' '
                . (string)($row['characteristics'] ?? '')
            ));
        }
        unset($row);
        return self::$catalog = $rows;
    }

    /** Why this row is in the list — the card prints it next to the score. */
    private static function sourceOf(float $byName, float $byDesc, ?float $vec, float $minScore): string {
        $byWords   = $byName >= $minScore;
        $byMeaning = $vec !== null && $vec >= self::VEC_STRONG;
        if ($byWords && $byMeaning) return 'both';
        if ($byMeaning) return 'meaning';
        // Имя не дотянуло, а описание дотянуло — так и сказать: менеджер иначе
        // не поймёт, почему в списке строка, не похожая на запрос ни словом
        if (!$byWords && $byDesc >= $minScore) return 'description';
        return 'words';
    }

    /**
     * Доля слов запроса, которые ВСТРЕЧАЮТСЯ в тексте. Для описания считается
     * именно вхождение, а не Жаккар: описание в десять раз длиннее запроса, и
     * любая мера, делящая на объединение, у него всегда около нуля.
     */
    private static function containment(array $queryWords, string $haystack): float {
        if (!$queryWords) return 0.0;
        $words = array_flip(array_filter(explode(' ', $haystack)));
        $hits = 0;
        foreach ($queryWords as $w) {
            if (isset($words[$w])) { $hits++; continue; }
            // «монокуляры» в запросе и «монокуляр» в описании — одно слово
            $stem = mb_substr($w, 0, max(4, mb_strlen($w) - 2));
            if ($stem !== $w && str_contains($haystack, $stem)) $hits++;
        }
        return $hits / count($queryWords);
    }

    /**
     * Метки-различители названия: «бр2», «бр5а», «6б45», «xm30f» (модуль 040).
     *
     * Это те куски названия, ради которых товар и выбирают: класс защиты,
     * номер модели, индекс ГОСТ. Голое число меткой не считается — это
     * количество, размер упаковки или год.
     *
     * @return array<string, array<string, true>> приставка => её значения
     */
    private static function markers(string $normalized): array {
        $out = [];
        foreach (explode(' ', $normalized) as $w) {
            if ($w === '' || !preg_match('/^(\p{L}+)(\d[\p{L}\d+]*)$/u', $w, $m)) continue;
            $out[$m[1]][$m[2]] = true;
        }
        return $out;
    }

    /**
     * Метка запроса и метка товара с той же приставкой, но другая — конфликт.
     *
     * Метки у товара НЕТ вовсе — не конфликт: так выглядит товар-родитель,
     * у которого класс стоит на модификациях.
     */
    private static function markerConflict(array $queryMarkers, string $candidateText): bool {
        $theirs = self::markers($candidateText);
        foreach ($queryMarkers as $prefix => $values) {
            if (empty($theirs[$prefix])) continue;
            if (!array_intersect_key($values, $theirs[$prefix])) return true;
        }
        return false;
    }

    /** Слова запроса, по которым вообще имеет смысл искать. */
    private static function contentWords(string $normalized): array {
        $out = [];
        foreach (explode(' ', $normalized) as $w) {
            if (mb_strlen($w) < 4) continue;   // «для», «шт», «под» ничего не отбирают
            $out[$w] = true;
        }
        return array_keys($out);
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
        // «БР-3» — та же метка, что «Бр3» (модуль 040)
        $s = preg_replace('/(?<=\p{L})-(?=\d)/u', '', $s);
        $s = preg_replace('/[\s\-\"\'«»(),;:.\/\\\[\]]+/u', ' ', $s);
        $s = preg_replace('/\b(шт|штук|штуки|ед|компл)\b\.?/u', '', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}
