<?php
/**
 * «Подходящие позиции» of a request (modules 008 and 009).
 *
 * Next to what the letter literally says («Распознанные позиции») there is a
 * second table: the catalog rows a manager confirmed those lines mean. The KP
 * is built from THIS table, so a wrong guess is fixed once, on the request card,
 * instead of being corrected again in every generated proposal.
 *
 * The rows appear by themselves the first time the card is opened. What does NOT
 * happen by itself is a choice between equally good candidates: such a line is
 * stored with `needs_choice = 1` and the card asks instead of guessing.
 */
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/alternatives.php';

final class RequestItems {

    /** Rows of a request; built from the parsed letter the first time it is opened. */
    public static function ensure(int $requestId, bool $useLlm = false): array {
        $rows = self::all($requestId);
        if ($rows) return $rows;

        $req = Db::one("SELECT parsed_json, counterparty_id FROM requests WHERE id=?", [$requestId]);
        $parsed = $req && $req['parsed_json'] ? (json_decode($req['parsed_json'], true) ?: []) : [];
        $items = $parsed['items'] ?? [];
        if (!$items) return [];

        $counterpartyId = !empty($req['counterparty_id']) ? (int)$req['counterparty_id'] : null;
        self::write($requestId, self::fromMatches(ProductMatcher::matchItems($items, $useLlm, $counterpartyId)));
        // Every row was written by the matcher a line ago, so every row is up
        // for an analogue — `is_confirmed` here means «уверенное совпадение по
        // названию», not «менеджер это утвердил», and an exact name match on an
        // empty shelf is exactly the line the analogue exists for.
        self::fillAlternatives($requestId, $useLlm, array_column(self::all($requestId), 'id'));
        return self::all($requestId);
    }

    /**
     * Lines we cannot ship get an analogue that we can (module 013).
     *
     * A line qualifies when it found nothing at all, or when what it found has
     * no free remainder. The analogue REPLACES the row — a КП prices one
     * position per request line, not two — but nothing is hidden: `alt_of`
     * keeps what the client asked for, `alt_specs_json` keeps the proof of
     * which of their requirements it meets, and the position we could not ship
     * goes back into `match_variants`, so «выбрать другую» offers it again with
     * one click.
     *
     * A line with no analogue in stock stays exactly as it was and the КП says
     * «под заказ» — it does not become a question.
     *
     * $onlyIds names the rows an automatic match just wrote — those are fair
     * game whatever their `is_confirmed` says, because the score set it and
     * nobody has looked at them yet. Called with no list (from the UI), it
     * leaves every confirmed row alone: that flag is then the manager's.
     *
     * @param int[]|null $onlyIds
     */
    public static function fillAlternatives(int $requestId, bool $useLlm = false, ?array $onlyIds = null): int {
        if (!Alternatives::enabled()) return 0;

        $rows = Db::all("SELECT * FROM request_items WHERE request_id=? ORDER BY position, id", [$requestId]);
        $raw = Db::val("SELECT raw_text FROM requests WHERE id=?", [$requestId]) ?: '';

        $eligible = $onlyIds === null ? null : array_map('intval', $onlyIds);

        $needy = [];
        foreach ($rows as $row) {
            if ($eligible === null) {
                if ((int)$row['is_confirmed'] === 1) continue;
            } elseif (!in_array((int)$row['id'], $eligible, true)) {
                continue;
            }
            if ((int)($row['is_alternative'] ?? 0) === 1) continue;
            // `stock` on the row is the free remainder the matcher recorded;
            // a row with no product at all is just as much «нечего отгрузить»
            $hasProduct = trim((string)($row['product_name'] ?? '')) !== '';
            $free = $row['stock'] === null ? 0 : (int)$row['stock'];
            if ($hasProduct && $free > 0) continue;
            $needy[(int)$row['id']] = $row;
        }
        if (!$needy) return 0;

        $counterpartyId = (int)(Db::val("SELECT counterparty_id FROM requests WHERE id=?", [$requestId]) ?: 0) ?: null;
        $lines = [];
        $ids = [];
        foreach ($needy as $id => $row) {
            $ids[] = $id;
            $lines[] = [
                'raw_name'   => (string)($row['raw_name'] !== '' ? $row['raw_name'] : (string)$row['product_name']),
                // The original fragment of the letter is where the requirements
                // are written — «класс Бр5», «размер L», «площадь 40 дм2»
                'raw_text'   => self::requirementText($raw, (string)$row['raw_name']),
                'exclude_id' => (string)($row['moysklad_product_id'] ?? ''),
            ];
        }

        $found = 0;
        foreach (Alternatives::suggest($lines, $counterpartyId, $useLlm) as $i => $alt) {
            if (!$alt) continue;
            $id = $ids[$i];
            $row = $needy[$id];

            // What we could not ship goes back into the candidate list, so the
            // manager can put it back without re-matching the line
            $variants = $row['match_variants'] ? (json_decode($row['match_variants'], true) ?: []) : [];
            if (!empty($row['moysklad_product_id'])) {
                array_unshift($variants, [
                    'moysklad_id' => $row['moysklad_product_id'],
                    'name'        => (string)$row['product_name'],
                    'article'     => (string)($row['article'] ?? ''),
                    'unit'        => (string)($row['unit'] ?? 'шт.'),
                    'price'       => (float)($row['price'] ?? 0),
                    'stock'       => (int)($row['stock'] ?? 0),
                    'score'       => $row['match_confidence'] ?? null,
                    'source'      => 'было подобрано, нет в наличии',
                ]);
            }

            Db::update('request_items', [
                'moysklad_product_id' => $alt['moysklad_id'],
                'product_name'        => $alt['name'],
                'article'             => $alt['article'],
                'unit'                => $alt['unit'],
                'price'               => $alt['price'],
                'stock'               => $alt['free'],
                'match_confidence'    => $alt['score'],
                'match_source'        => 'аналог',
                'match_variants'      => $variants ? json_encode($variants, JSON_UNESCAPED_UNICODE) : null,
                'is_alternative'      => 1,
                'alt_of'              => trim((string)$row['product_name']) !== ''
                                            ? (string)$row['product_name'] : (string)$row['raw_name'],
                'alt_specs_json'      => json_encode([
                    'matched' => $alt['matched'],
                    'differs' => $alt['differs'],
                    'reason'  => $alt['reason'],
                    'source'  => $alt['source'],
                ], JSON_UNESCAPED_UNICODE),
                'notes'               => $alt['reason'] !== '' ? 'аналог: ' . $alt['reason'] : 'аналог из наличия',
                // An analogue is a swap — the manager looks at it before it ships
                'needs_choice'        => 1,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], 'id=?', [$id]);
            $found++;
        }

        if ($found) Logger::info('catalog', "Подобрано аналогов: $found", ['request_id' => $requestId]);
        return $found;
    }

    /**
     * The piece of the letter this line came from — where its requirements are.
     * The parser keeps a `raw_text` per item, but a line edited by hand has
     * none, so the letter is searched for the sentence that names it.
     */
    private static function requirementText(string $letter, string $name): string {
        $name = trim($name);
        if ($name === '' || $letter === '') return $name;
        foreach (preg_split('/\R|(?<=[.;])\s+/u', $letter) ?: [] as $line) {
            if (mb_stripos($line, mb_substr($name, 0, 12)) !== false) return trim($line);
        }
        return $name;
    }

    public static function all(int $requestId): array {
        $rows = Db::all("SELECT * FROM request_items WHERE request_id=? ORDER BY position, id", [$requestId]);

        // Every price type the matched product has — the card offers it as a
        // pick next to the price field («как руками, так и выбором»)
        $ids = array_values(array_unique(array_filter(array_column($rows, 'moysklad_product_id'))));
        $prices = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT moysklad_id, prices_json FROM products_cache WHERE moysklad_id IN ($placeholders)", $ids) as $p) {
                $prices[$p['moysklad_id']] = Catalog::decodePrices($p['prices_json']);
            }
        }

        foreach ($rows as &$row) {
            $row['variants'] = $row['match_variants'] ? (json_decode($row['match_variants'], true) ?: []) : [];
            unset($row['match_variants']);
            $row['price_options'] = $prices[$row['moysklad_product_id']] ?? [];
            // Which of the client's requirements this analogue meets — the card
            // shows it, and so does the КП
            $row['alternative'] = !empty($row['alt_specs_json'])
                ? (json_decode((string)$row['alt_specs_json'], true) ?: null) : null;
        }
        return $rows;
    }

    /** Re-run the catalog match; the items afterwards. */
    public static function rematch(int $requestId, bool $useLlm = false): array {
        return self::rematchReport($requestId, $useLlm)['items'];
    }

    /**
     * Re-run the catalog match and say what it did (modules 008 §5, 018).
     *
     * A row the manager confirmed is left exactly as it is — spec 008 §5,
     * «confirmed line is never re-picked by the automatic match». It is not
     * merely skipped when the answers come back: it never goes to the model at
     * all, so «Подобрать нейросетью» normalizes and prices only the lines that
     * are still open, and a confirmed line cannot be renamed by a normalization
     * it was never part of.
     *
     * The counts are what the card reports back: a run that found nothing for
     * the remaining lines used to look exactly like a run that found everything.
     *
     * @return array{items:array,repicked:int,found:int,empty:int,kept:int,alternatives:int}
     */
    public static function rematchReport(int $requestId, bool $useLlm = false): array {
        $existing = self::all($requestId);
        if (!$existing) {
            $items = self::ensure($requestId, $useLlm);
            $found = count(array_filter($items, fn($r) => trim((string)($r['product_name'] ?? '')) !== ''));
            return ['items' => $items, 'repicked' => count($items), 'found' => $found,
                    'empty' => count($items) - $found, 'kept' => 0, 'alternatives' => 0];
        }

        // Only the open lines are asked about — a confirmed row is not a query
        $open = [];
        foreach ($existing as $i => $row) {
            if ((int)$row['is_confirmed'] !== 1) $open[$i] = $row;
        }
        $kept = count($existing) - count($open);
        if (!$open) {
            return ['items' => $existing, 'repicked' => 0, 'found' => 0,
                    'empty' => 0, 'kept' => $kept, 'alternatives' => 0];
        }

        $counterpartyId = (int)(Db::val("SELECT counterparty_id FROM requests WHERE id=?", [$requestId]) ?: 0) ?: null;
        $queries = array_map(fn($r) => [
            'name' => ($r['raw_name'] !== '' ? $r['raw_name'] : (string)$r['product_name']),
            'qty'  => $r['quantity'],
        ], $open);
        // matchItems answers positionally, so the query list is re-keyed and the
        // original row index is kept alongside it
        $rowIndex = array_keys($open);
        $matches = ProductMatcher::matchItems(array_values($queries), $useLlm, $counterpartyId);

        $repicked = [];
        $found = 0;
        foreach ($rowIndex as $n => $i) {
            $row = $existing[$i];
            $repicked[] = (int)$row['id'];
            $m = $matches[$n] ?? null;
            $best = $m['match'] ?? null;
            if ($best) $found++;
            Db::update('request_items', [
                'moysklad_product_id' => $best['moysklad_id'] ?? null,
                'product_name'        => $best['name'] ?? null,
                'article'             => $best['article'] ?? null,
                'unit'                => $best['unit'] ?? $row['unit'],
                'price'               => (float)($best['price'] ?? 0),
                'stock'               => $best === null ? null : Alternatives::freeStock($best),
                'match_confidence'    => $best['score'] ?? null,
                'match_variants'      => !empty($m['variants']) ? json_encode($m['variants'], JSON_UNESCAPED_UNICODE) : null,
                'needs_choice'        => !empty($m['needs_choice']) ? 1 : 0,
                'match_source'        => $m['match_source'] ?? null,
                'is_confirmed'        => !empty($m['is_confirmed']) ? 1 : 0,
                // A fresh match starts from what the client asked for again:
                // the analogue is re-decided below against today's stock
                'is_alternative'      => 0,
                'alt_of'              => null,
                'alt_specs_json'      => null,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], 'id=?', [$row['id']]);
        }
        // Only the rows rematch() actually re-picked are up for an analogue —
        // it left the manager's confirmed lines alone and so does this
        $alternatives = self::fillAlternatives($requestId, $useLlm, $repicked);
        return [
            'items'        => self::all($requestId),
            'repicked'     => count($repicked),
            'found'        => $found,
            'empty'        => count($repicked) - $found,
            'kept'         => $kept,
            'alternatives' => $alternatives,
        ];
    }

    /** Replace the table with what the editor sent. */
    public static function save(int $requestId, array $rows): array {
        $keep = [];
        foreach (array_values($rows) as $i => $row) {
            $rawName  = trim((string)($row['raw_name'] ?? ''));
            $prodName = trim((string)($row['product_name'] ?? ''));
            if ($rawName === '' && $prodName === '') continue;   // an empty line the manager left behind

            $data = [
                'request_id'          => $requestId,
                'position'            => $i + 1,
                'raw_name'            => $rawName,
                'quantity'            => max(0, (float)($row['quantity'] ?? 1)),
                'moysklad_product_id' => trim((string)($row['moysklad_product_id'] ?? '')) ?: null,
                'product_name'        => $prodName ?: null,
                'article'             => trim((string)($row['article'] ?? '')) ?: null,
                'unit'                => trim((string)($row['unit'] ?? '')) ?: 'шт.',
                'price'               => (float)($row['price'] ?? 0),
                'stock'               => isset($row['stock']) && $row['stock'] !== '' ? (int)$row['stock'] : null,
                'is_confirmed'        => !empty($row['is_confirmed']) ? 1 : 0,
                // A row the manager saved is answered: the choice prompt goes away
                'needs_choice'        => (!empty($row['is_confirmed']) || $prodName !== '') ? 0 : (int)($row['needs_choice'] ?? 0),
                'notes'               => trim((string)($row['notes'] ?? '')) ?: null,
                // The manager put a different product on the line — it is no
                // longer our analogue but their choice, and the КП stops
                // explaining it as a swap
                'is_alternative'      => !empty($row['is_alternative']) ? 1 : 0,
                'alt_of'              => trim((string)($row['alt_of'] ?? '')) ?: null,
                'updated_at'          => date('Y-m-d H:i:s'),
            ];

            $id = (int)($row['id'] ?? 0);
            if ($id && Db::one("SELECT id FROM request_items WHERE id=? AND request_id=?", [$id, $requestId])) {
                unset($data['request_id']);
                Db::update('request_items', $data, 'id=?', [$id]);
            } else {
                $id = Db::insert('request_items', $data);
            }
            $keep[] = $id;
        }

        // Rows the editor no longer sends were deleted in the browser
        $all = Db::all("SELECT id FROM request_items WHERE request_id=?", [$requestId]);
        foreach ($all as $r) {
            if (!in_array((int)$r['id'], $keep, true)) {
                Db::q("DELETE FROM request_items WHERE id=?", [$r['id']]);
            }
        }
        return self::all($requestId);
    }

    /**
     * Table rows → the shape proposals.php already inserts, so a KP built from
     * the confirmed table and one built from a fresh match share the same code.
     */
    public static function toProposalItems(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $hasProduct = trim((string)($row['product_name'] ?? '')) !== '';
            $out[] = [
                'raw_name'     => (string)($row['raw_name'] ?? ''),
                'quantity'     => (float)($row['quantity'] ?? 1),
                'is_confirmed' => (int)($row['is_confirmed'] ?? 0) === 1,
                'needs_choice' => (int)($row['needs_choice'] ?? 0) === 1,
                'notes'        => $row['notes'] ?? null,
                'variants'     => $row['variants'] ?? [],
                'is_alternative' => (int)($row['is_alternative'] ?? 0) === 1,
                'alt_of'         => $row['alt_of'] ?? null,
                'alt_specs'      => $row['alternative'] ?? null,
                'match'        => $hasProduct ? [
                    'moysklad_id' => $row['moysklad_product_id'] ?? null,
                    'name'        => (string)$row['product_name'],
                    'article'     => $row['article'] ?? '',
                    'unit'        => $row['unit'] ?: 'шт.',
                    'price'       => (float)($row['price'] ?? 0),
                    'stock'       => $row['stock'],
                    'reserved'    => null,
                    'score'       => $row['match_confidence'] ?? null,
                ] : null,
            ];
        }
        return $out;
    }

    /**
     * The manager answered the «равнозначные позиции» question: one of the
     * candidates becomes the row and the question is closed.
     */
    public static function choose(int $requestId, int $itemId, string $productId): array {
        $row = Db::one("SELECT * FROM request_items WHERE id=? AND request_id=?", [$itemId, $requestId]);
        if (!$row) throw new RuntimeException('Строка не найдена');

        $p = Db::one("SELECT moysklad_id, name, article, unit, price, prices_json, stock, reserved FROM products_cache WHERE moysklad_id=?", [$productId]);
        if (!$p) throw new RuntimeException('Позиция каталога не найдена');

        $counterpartyId = (int)(Db::val("SELECT counterparty_id FROM requests WHERE id=?", [$requestId]) ?: 0) ?: null;
        Db::update('request_items', [
            'moysklad_product_id' => $p['moysklad_id'],
            'product_name'        => $p['name'],
            'article'             => $p['article'],
            'unit'                => $p['unit'] ?: 'шт.',
            'price'               => Catalog::priceFor($p, $counterpartyId),
            'stock'               => Alternatives::freeStock($p),
            'is_confirmed'        => 1,
            'needs_choice'        => 0,
            'updated_at'          => date('Y-m-d H:i:s'),
        ], 'id=?', [$itemId]);

        return self::all($requestId);
    }

    /**
     * Lines the catalog never answered (module 018).
     *
     * A row with no product and no manager behind it is the client's own
     * sentence and nothing else. Until now it was visible only as «без цены: N»
     * in the manager's totals — the client was told nothing at all. The reply
     * draft and the КП both say these out loud, in the client's own words.
     *
     * @return array<int,array{position:int,requested:string,quantity:mixed,unit:string}>
     */
    public static function unmatched(int $requestId): array {
        $rows = Db::all(
            "SELECT * FROM request_items
             WHERE request_id=? AND (moysklad_product_id IS NULL OR moysklad_product_id='')
               AND is_confirmed=0
             ORDER BY position, id", [$requestId]
        );
        $out = [];
        foreach ($rows as $row) {
            $asked = trim((string)($row['raw_name'] ?? ''));
            if ($asked === '') $asked = trim((string)($row['product_name'] ?? ''));
            if ($asked === '') continue;
            $out[] = [
                'position'  => (int)$row['position'],
                'requested' => $asked,
                'quantity'  => $row['quantity'],
                'unit'      => (string)($row['unit'] ?: 'шт.'),
            ];
        }
        return $out;
    }

    /**
     * The unmatched lines as a block for a model prompt — one wording, used by
     * every place that drafts a letter, so the reply and the КП say the same.
     */
    public static function unmatchedBlock(array $unmatched): string {
        if (!$unmatched) return '';
        $out = "\n===== ЧЕГО НЕТ В НАШЕМ КАТАЛОГЕ =====\n"
             . "По этим позициям запроса совпадения не нашлось. Назови их в ответе ДОСЛОВНО "
             . "словами клиента и напиши, что уточняем по ним наличие, сроки и цену. "
             . "Не молчи о них, не придумывай им замену и не выдавай за них другой товар.\n";
        foreach ($unmatched as $u) {
            $out .= "- {$u['requested']} ({$u['quantity']} {$u['unit']})\n";
        }
        return $out;
    }

    /** How many lines of a request are still waiting for that answer. */
    public static function openChoices(int $requestId): int {
        return (int)Db::val("SELECT COUNT(*) FROM request_items WHERE request_id=? AND needs_choice=1", [$requestId]);
    }

    /** ProductMatcher output → table rows. */
    private static function fromMatches(array $matches): array {
        $out = [];
        foreach ($matches as $m) {
            $best = $m['match'] ?? null;
            $out[] = [
                'raw_name'            => (string)($m['raw_name'] ?? ''),
                'quantity'            => (float)($m['quantity'] ?? 1),
                'moysklad_product_id' => $best['moysklad_id'] ?? null,
                'product_name'        => $best['name'] ?? null,
                'article'             => $best['article'] ?? null,
                'unit'                => $best['unit'] ?? 'шт.',
                'price'               => (float)($best['price'] ?? 0),
                // The FREE remainder, not the number on the МойСклад card: stock
                // already reserved for someone else is not ours to promise, and
                // this column is what decides «в наличии» / «под заказ» all the
                // way through to the КП and to the analogue search (module 013)
                'stock'               => $best === null ? null : Alternatives::freeStock($best),
                'match_confidence'    => $best['score'] ?? null,
                'match_variants'      => !empty($m['variants']) ? json_encode($m['variants'], JSON_UNESCAPED_UNICODE) : null,
                'needs_choice'        => !empty($m['needs_choice']) ? 1 : 0,
                'match_source'        => $m['match_source'] ?? null,
                'is_confirmed'        => !empty($m['is_confirmed']) ? 1 : 0,
                'notes'               => ($best && Alternatives::freeStock($best) === 0) ? 'под заказ' : null,
            ];
        }
        return $out;
    }

    private static function write(int $requestId, array $rows): void {
        Db::q("DELETE FROM request_items WHERE request_id=?", [$requestId]);
        foreach (array_values($rows) as $i => $row) {
            Db::insert('request_items', $row + ['request_id' => $requestId, 'position' => $i + 1]);
        }
    }
}
