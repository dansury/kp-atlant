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
            // Same for the card text: a variant inherits the product's description
            if (trim((string)($product['description'] ?? '')) === '' && !empty($product['parent_id'])) {
                $parent = Db::one("SELECT description, specs_text, included_text FROM products_cache WHERE moysklad_id=?",
                                  [$product['parent_id']]);
                if ($parent) $product = array_merge($product, array_filter($parent, fn($v) => (string)$v !== ''));
            }

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
    /**
     * Positions where we offer something other than what was asked for.
     *
     * A client asks for one manufacturer's plate carrier and we have an
     * equivalent of our own — the КП has to say so in the manager's own words,
     * and every such pair is remembered so the next letter says it the same way
     * (module 011). Comparison is deliberately crude: normalized text, no model
     * call, because this runs on every КП.
     *
     * @param array $items rows of proposal_items
     * @return array<int,array{requested:string,offered:string,note:string}>
     */
    public static function substitutions(array $items): array {
        $out = [];
        foreach ($items as $it) {
            $asked  = trim((string)($it['requested_name'] ?? ''));
            $given  = trim((string)($it['product_name'] ?? ''));
            if ($asked === '' || $given === '') continue;
            if (self::sameProduct($asked, $given)) continue;
            $out[] = [
                'requested' => $asked,
                'offered'   => $given,
                'note'      => trim((string)($it['notes'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * «БЖ Кираса-5» and «Бронежилет Кираса 5» are the same position; «Шлем 6Б47»
     * answered with «Шлем Атлант АШ-1» is a swap. The model code decides: a
     * client who names one is asking for that exact thing, and offering another
     * has to be explained. Everything else falls back to word overlap.
     */
    private static function sameProduct(string $a, string $b): bool {
        $tokens = function (string $v): array {
            $v = mb_strtolower(str_replace(['«', '»', '"'], ' ', $v));
            $v = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $v);
            return array_values(array_filter(explode(' ', trim($v)), fn($t) => $t !== ''));
        };
        $asked = $tokens($a);
        $given = $tokens($b);
        if (!$asked || !$given) return true;

        // A model code — «6б47», «ач-2м», «5а» — must survive the swap
        $isCode = fn(string $t) => mb_strlen($t) >= 2 && preg_match('/\d/u', $t);
        foreach ($asked as $t) {
            if ($isCode($t) && !in_array($t, $given, true)) return false;
        }

        $common = count(array_intersect($asked, $given));
        return $common / count($asked) >= 0.5;
    }

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

    // ==== Photos of a product: what exists, and which of them go into this KP ====

    /**
     * Every photo we know of for a product, from both sources: files already
     * downloaded through the API (images_json) and the public CDN links the
     * Excel export carries (image_urls). A key — «local:0», «url:2» — identifies
     * one photo across a redeploy, so a manager's selection survives it.
     * @return array<int,array{key:string,source:string,ref:string}>
     */
    public static function productImageList(string $moyskladId, int $max = 12): array {
        $row = Db::one("SELECT images_json, image_urls, parent_id FROM products_cache WHERE moysklad_id=?", [$moyskladId]);
        if (!$row) return [];

        // A variant («Бронежилет (Размер: L)») carries no photos of its own —
        // in МойСклад they hang on the product, so borrow them from the parent
        $empty = fn($v) => $v === null || $v === '' || $v === '[]';
        if ($empty($row['images_json']) && $empty($row['image_urls']) && !empty($row['parent_id'])) {
            $parent = Db::one("SELECT images_json, image_urls FROM products_cache WHERE moysklad_id=?", [$row['parent_id']]);
            if ($parent) $row = $parent + $row;
        }

        $out = [];
        foreach ((array)(json_decode((string)($row['images_json'] ?? ''), true) ?: []) as $i => $path) {
            if (is_string($path) && file_exists($path)) $out[] = ['key' => "local:$i", 'source' => 'file', 'ref' => $path];
        }
        foreach ((array)(json_decode((string)($row['image_urls'] ?? ''), true) ?: []) as $i => $url) {
            if (is_string($url) && str_starts_with($url, 'http')) $out[] = ['key' => "url:$i", 'source' => 'url', 'ref' => $url];
        }
        return array_slice($out, 0, $max);
    }

    /** One photo by its key — raw bytes plus a mime type, ready to stream. */
    public static function imageBytes(string $moyskladId, string $key): ?array {
        foreach (self::productImageList($moyskladId, 100) as $img) {
            if ($img['key'] !== $key) continue;
            $binary = $img['source'] === 'file' ? @file_get_contents($img['ref']) : self::fetchUrl($img['ref']);
            if ($binary === false || $binary === null || $binary === '') return null;
            return ['bytes' => $binary, 'mime' => self::mimeOf($img['ref'], $binary)];
        }
        return null;
    }

    /**
     * Photos of one KP position as data URIs. `selected_images` holds the keys
     * the manager ticked; an empty selection means «все, что нашлись» — the
     * behaviour КП had before the picker existed.
     */
    public static function itemGallery(array $item, int $max = 5): array {
        $selected = json_decode((string)($item['selected_images'] ?? ''), true);
        $msId = (string)($item['moysklad_product_id'] ?? '');

        // No catalog link (a hand-typed position) — only what is already on the item
        if ($msId === '') return self::imagesForPdf($item['images_json'] ?? null, $max);

        $list = self::productImageList($msId);
        if (is_array($selected) && $selected !== []) {
            $list = array_values(array_filter($list, fn($i) => in_array($i['key'], $selected, true)));
        } elseif (is_array($selected)) {
            return [];   // an explicitly empty selection means «без фото»
        }

        $out = [];
        foreach (array_slice($list, 0, $max) as $img) {
            $binary = $img['source'] === 'file' ? @file_get_contents($img['ref']) : self::fetchUrl($img['ref']);
            if (!$binary) continue;
            $out[] = 'data:' . self::mimeOf($img['ref'], $binary) . ';base64,' . base64_encode($binary);
        }
        // Nothing resolved (offline, links rotted) — fall back to the cached paths
        return $out ?: self::imagesForPdf($item['images_json'] ?? null, $max);
    }

    /** Public CDN image, cached on disk so a PDF rebuild does not re-download it. */
    private static function fetchUrl(string $url): ?string {
        $dir = ROOT . '/storage/product_images';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $cache = $dir . '/cdn-' . md5($url) . '.img';
        if (is_file($cache) && filesize($cache) > 0) return (string)file_get_contents($cache);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'AtlantArmourKP/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !$body) return null;

        @file_put_contents($cache, $body);
        return (string)$body;
    }

    private static function mimeOf(string $ref, string $binary): string {
        $ext = strtolower(pathinfo(parse_url($ref, PHP_URL_PATH) ?: $ref, PATHINFO_EXTENSION));
        return match (true) {
            $ext === 'png'  => 'image/png',
            $ext === 'webp' => 'image/webp',
            $ext === 'gif'  => 'image/gif',
            in_array($ext, ['jpg', 'jpeg'], true) => 'image/jpeg',
            // The CDN hides the extension behind a query string — sniff the header
            str_starts_with($binary, "\x89PNG") => 'image/png',
            default => 'image/jpeg',
        };
    }
}
