<?php
/**
 * KP content builder: product cards (description, specs, kit, photos) and
 * upsell modules. Everything here is best-effort — a missing description or
 * an unreachable image never blocks PDF generation.
 */
class KpContent {

    // Fill description / specs / kit / photos for every item of a proposal.
    // Existing manager-edited text is never overwritten.
    public static function enrichItems(int $proposalId): void {
        $items = Db::all("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY position", [$proposalId]);
        $maxImages = (int)(Db::val("SELECT value FROM settings WHERE key='kp_max_images_per_item'") ?: 5);

        foreach ($items as $item) {
            $msId = $item['moysklad_product_id'] ?? '';
            if (!$msId) continue;

            $product = Db::one("SELECT * FROM products_cache WHERE moysklad_id=?", [$msId]);
            if (!$product) continue;

            $upd = [];

            // MoySklad description is the source of truth for the card text
            if (empty($item['description_text']) && !empty($product['description'])) {
                $upd['description_text'] = self::splitDescription($product['description'])['description'];
            }
            if (empty($item['specs_text'])) {
                $specs = $product['specs_text'] ?: self::splitDescription($product['description'] ?? '')['specs'];
                if ($specs) $upd['specs_text'] = $specs;
            }
            if (empty($item['included_text'])) {
                $included = $product['included_text'] ?: self::splitDescription($product['description'] ?? '')['included'];
                if ($included) $upd['included_text'] = $included;
            }

            // Photos — cached on disk, refreshed at most monthly
            if (empty($item['images_json'])) {
                $images = array_slice(MoySklad::productImages($msId), 0, $maxImages);
                if ($images) $upd['images_json'] = json_encode($images, JSON_UNESCAPED_UNICODE);
            }

            if ($upd) Db::update('proposal_items', $upd, 'id=?', [$item['id']]);
        }
    }

    // MoySklad descriptions are free text. Managers write them with the same
    // headings the sample KP uses, so split on those and fall back to
    // "everything is description" when the headings are absent.
    public static function splitDescription(string $text): array {
        $out = ['description' => trim($text), 'specs' => '', 'included' => ''];
        if (trim($text) === '') return $out;

        $pattern = '/^\s*(характеристики|технические характеристики|комплектация|состав комплекта)\s*:?\s*$/miu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) < 3) return $out;

        $out['description'] = trim($parts[0]);
        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            $heading = mb_strtolower(trim($parts[$i]));
            $body = trim($parts[$i + 1] ?? '');
            if (str_contains($heading, 'комплект') || str_contains($heading, 'состав')) {
                $out['included'] = $body;
            } else {
                $out['specs'] = $body;
            }
        }
        return $out;
    }

    // Suggest upsell modules for a proposal: products flagged as addons in the
    // MoySklad addon folder, cheapest-first, excluding what is already in the KP.
    public static function suggestAddons(int $proposalId, int $limit = 12): array {
        $inKp = Db::all("SELECT moysklad_product_id FROM proposal_items WHERE proposal_id=? AND moysklad_product_id IS NOT NULL", [$proposalId]);
        $exclude = array_column($inKp, 'moysklad_product_id');

        $sql = "SELECT moysklad_id, name, unit, price, stock FROM products_cache WHERE is_addon=1";
        $params = [];
        if ($exclude) {
            $sql .= ' AND moysklad_id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
            $params = $exclude;
        }
        $sql .= ' ORDER BY price ASC, name ASC LIMIT ' . (int)$limit;

        return Db::all($sql, $params);
    }

    // Replace the upsell rows of a proposal with the given selection.
    // $rows: [{moysklad_product_id?, product_name, unit?, price?, notes?}, ...]
    public static function setAddons(int $proposalId, array $rows): void {
        Db::q("DELETE FROM proposal_addons WHERE proposal_id=?", [$proposalId]);
        foreach (array_values($rows) as $i => $row) {
            $name = trim((string)($row['product_name'] ?? ''));
            if ($name === '') continue;
            Db::insert('proposal_addons', [
                'proposal_id'         => $proposalId,
                'position'            => $i + 1,
                'product_name'        => $name,
                'moysklad_product_id' => $row['moysklad_product_id'] ?? null,
                'unit'                => $row['unit'] ?? 'шт.',
                'price'               => (float)($row['price'] ?? 0),
                'notes'               => $row['notes'] ?? null,
                'is_selected'         => array_key_exists('is_selected', $row) ? (int)(bool)$row['is_selected'] : 1,
            ]);
        }
    }

    // Seed the upsell block on a fresh proposal so the manager starts from a
    // filled table rather than an empty one.
    public static function seedAddons(int $proposalId): void {
        if (Db::val("SELECT COUNT(*) FROM proposal_addons WHERE proposal_id=?", [$proposalId])) return;
        $suggested = self::suggestAddons($proposalId);
        if (!$suggested) return;
        self::setAddons($proposalId, array_map(fn($p) => [
            'product_name'        => $p['name'],
            'moysklad_product_id' => $p['moysklad_id'],
            'unit'                => $p['unit'] ?: 'шт.',
            'price'               => $p['price'],
            'notes'               => ((int)($p['stock'] ?? 0) === 0) ? 'под заказ' : null,
        ], $suggested));
    }

    // Read images for a PDF: absolute paths converted to base64 data URIs,
    // because mPDF cannot reach files outside the document root reliably.
    public static function imagesForPdf(?string $imagesJson, int $max = 5): array {
        $paths = $imagesJson ? (json_decode($imagesJson, true) ?: []) : [];
        $out = [];
        foreach (array_slice($paths, 0, $max) as $path) {
            if (!is_string($path) || !file_exists($path)) continue;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';
            if ($ext === 'jpg') $ext = 'jpeg';
            $out[] = 'data:image/' . $ext . ';base64,' . base64_encode(file_get_contents($path));
        }
        return $out;
    }
}
