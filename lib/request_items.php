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
final class RequestItems {

    /** Rows of a request; built from the parsed letter the first time it is opened. */
    public static function ensure(int $requestId, bool $useLlm = false): array {
        $rows = self::all($requestId);
        if ($rows) return $rows;

        $req = Db::one("SELECT parsed_json FROM requests WHERE id=?", [$requestId]);
        $parsed = $req && $req['parsed_json'] ? (json_decode($req['parsed_json'], true) ?: []) : [];
        $items = $parsed['items'] ?? [];
        if (!$items) return [];

        self::write($requestId, self::fromMatches(ProductMatcher::matchItems($items, $useLlm)));
        return self::all($requestId);
    }

    public static function all(int $requestId): array {
        $rows = Db::all("SELECT * FROM request_items WHERE request_id=? ORDER BY position, id", [$requestId]);
        foreach ($rows as &$row) {
            $row['variants'] = $row['match_variants'] ? (json_decode($row['match_variants'], true) ?: []) : [];
            unset($row['match_variants']);
        }
        return $rows;
    }

    /**
     * Re-run the catalog match. A row the manager confirmed by hand is left
     * exactly as it is — the button re-picks only what is still unconfirmed.
     */
    public static function rematch(int $requestId, bool $useLlm = false): array {
        $existing = self::all($requestId);
        if (!$existing) return self::ensure($requestId, $useLlm);

        $queries = array_map(fn($r) => [
            'name' => ($r['raw_name'] !== '' ? $r['raw_name'] : (string)$r['product_name']),
            'qty'  => $r['quantity'],
        ], $existing);
        $matches = ProductMatcher::matchItems($queries, $useLlm);

        foreach ($existing as $i => $row) {
            if ((int)$row['is_confirmed'] === 1) continue;
            $m = $matches[$i] ?? null;
            $best = $m['match'] ?? null;
            Db::update('request_items', [
                'moysklad_product_id' => $best['moysklad_id'] ?? null,
                'product_name'        => $best['name'] ?? null,
                'article'             => $best['article'] ?? null,
                'unit'                => $best['unit'] ?? $row['unit'],
                'price'               => (float)($best['price'] ?? 0),
                'stock'               => $best['stock'] ?? null,
                'match_confidence'    => $best['score'] ?? null,
                'match_variants'      => !empty($m['variants']) ? json_encode($m['variants'], JSON_UNESCAPED_UNICODE) : null,
                'needs_choice'        => !empty($m['needs_choice']) ? 1 : 0,
                'match_source'        => $m['match_source'] ?? null,
                'is_confirmed'        => !empty($m['is_confirmed']) ? 1 : 0,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], 'id=?', [$row['id']]);
        }
        return self::all($requestId);
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

        $p = Db::one("SELECT moysklad_id, name, article, unit, price, stock FROM products_cache WHERE moysklad_id=?", [$productId]);
        if (!$p) throw new RuntimeException('Позиция каталога не найдена');

        Db::update('request_items', [
            'moysklad_product_id' => $p['moysklad_id'],
            'product_name'        => $p['name'],
            'article'             => $p['article'],
            'unit'                => $p['unit'] ?: 'шт.',
            'price'               => (float)$p['price'],
            'stock'               => $p['stock'],
            'is_confirmed'        => 1,
            'needs_choice'        => 0,
            'updated_at'          => date('Y-m-d H:i:s'),
        ], 'id=?', [$itemId]);

        return self::all($requestId);
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
                'stock'               => $best['stock'] ?? null,
                'match_confidence'    => $best['score'] ?? null,
                'match_variants'      => !empty($m['variants']) ? json_encode($m['variants'], JSON_UNESCAPED_UNICODE) : null,
                'needs_choice'        => !empty($m['needs_choice']) ? 1 : 0,
                'match_source'        => $m['match_source'] ?? null,
                'is_confirmed'        => !empty($m['is_confirmed']) ? 1 : 0,
                'notes'               => ($best && (int)($best['stock'] ?? 0) === 0) ? 'под заказ' : null,
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
