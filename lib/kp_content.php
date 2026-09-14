<?php
/**
 * KP content builder: product cards (description, specs, kit, photos) and
 * upsell modules. Everything here is best-effort — a missing description or
 * an unreachable image never blocks PDF generation.
 */
require_once __DIR__ . '/bitrix.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/request_shape.php';
require_once __DIR__ . '/markup.php';

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

            // MoySklad description is the source of truth for the card text.
            // Его пишут в визуальном редакторе, и приходит он размеченным —
            // в карточку КП кладётся Markdown, а не `<ul><li>` (модуль 020).
            if (empty($item['description_text']) && !empty($product['description'])) {
                $upd['description_text'] = self::splitDescription($product['description'])['description'];
            }
            if (empty($item['specs_text'])) {
                $specs = Markup::toMarkdown((string)($product['specs_text'] ?? ''))
                    ?: self::splitDescription($product['description'] ?? '')['specs'];
                if ($specs) $upd['specs_text'] = $specs;
            }
            if (empty($item['included_text'])) {
                $included = Markup::toMarkdown((string)($product['included_text'] ?? ''))
                    ?: self::splitDescription($product['description'] ?? '')['included'];
                if ($included) $upd['included_text'] = $included;
            }

            // Photos — cached on disk, refreshed at most monthly
            if (empty($item['images_json'])) {
                $images = array_slice(MoySklad::productImages($msId), 0, $maxImages);
                if ($images) $upd['images_json'] = json_encode($images, JSON_UNESCAPED_UNICODE);
            }

            // The product's page on atlant-armour.ru (module 013). Resolved and
            // verified once, here, so the PDF never waits on the site — and
            // frozen on the item, so a reprint carries the link it was sent with.
            if (empty($item['site_url']) && (int)Settings::get('KP_SHOW_SITE_LINK', 1) === 1) {
                $url = Bitrix::productUrl($msId);
                if ($url) $upd['site_url'] = $url;
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

            // A position the matcher deliberately replaced because we could not
            // ship the original already knows it is a swap, and knows why — the
            // word-overlap guess below is only for the ones nobody labelled
            $deliberate = (int)($it['is_alternative'] ?? 0) === 1;
            if (!$deliberate && self::sameProduct($asked, $given)) continue;

            $out[] = [
                'requested' => $asked,
                'offered'   => $given,
                'note'      => trim((string)($it['alt_reason'] ?? '')) ?: trim((string)($it['notes'] ?? '')),
                'matched'   => self::matchedSpecs($it),
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

    /**
     * The client's requirements this position was proved to meet.
     * Stored by `Alternatives` when the swap was made and never recomputed —
     * a КП must say the same thing on its second printing as on its first.
     *
     * @return array<int,array{requirement:string,ours:string}>
     */
    public static function matchedSpecs(array $item): array {
        $decoded = json_decode((string)($item['alt_specs_json'] ?? ''), true);
        if (!is_array($decoded)) return [];
        $out = [];
        foreach ((array)($decoded['matched'] ?? []) as $row) {
            $requirement = trim((string)($row['requirement'] ?? ''));
            if ($requirement === '') continue;
            $out[] = ['requirement' => $requirement, 'ours' => trim((string)($row['ours'] ?? ''))];
        }
        return $out;
    }

    /** Requirements this position does NOT meet — named, not hidden. */
    public static function unmatchedSpecs(array $item): array {
        $decoded = json_decode((string)($item['alt_specs_json'] ?? ''), true);
        if (!is_array($decoded)) return [];
        return array_values(array_filter(
            array_map('strval', (array)($decoded['differs'] ?? [])),
            fn($v) => trim($v) !== ''
        ));
    }

    /**
     * Positions the catalog never answered (module 018) — и те, которые
     * менеджер свернул руками (модуль 020).
     *
     * A line with no `moysklad_product_id` that nobody confirmed by hand is not
     * a position — it is the client's own sentence, carried through parsing and
     * matching without ever meeting a product. The КП prints these separately,
     * in the client's own words, instead of pricing them at nothing and letting
     * the reader discover the hole.
     *
     * Свёрнутая позиция — та же дыра, только замеченная: «этого у нас нет».
     * Она уходит из таблицы и из карточек, но НЕ из документа: клиент читает
     * её здесь, своими словами, а не выясняет пропажу сам.
     *
     * @return array<int,array{n:int,requested:string,quantity:mixed,unit:string,excluded:bool}>
     */
    public static function unmatchedRows(int $proposalId): array {
        $items = Db::all(
            "SELECT * FROM proposal_items
             WHERE proposal_id=?
               AND (COALESCE(is_excluded, 0) = 1
                    OR ((moysklad_product_id IS NULL OR moysklad_product_id='') AND is_confirmed=0))
             ORDER BY position", [$proposalId]
        );
        $rows = [];
        foreach ($items as $item) {
            // Кто что просил — дословно: `requested_name` is what the letter
            // said, and `product_name` only repeats it when nothing was found
            $asked = trim((string)($item['requested_name'] ?? ''));
            if ($asked === '') $asked = trim((string)($item['product_name'] ?? ''));
            if ($asked === '') continue;
            $rows[] = [
                'n'         => (int)$item['position'],
                'requested' => $asked,
                'quantity'  => $item['quantity'],
                'unit'      => (string)($item['unit'] ?: 'шт.'),
                'excluded'  => (int)($item['is_excluded'] ?? 0) === 1,
            ];
        }
        return $rows;
    }

    /**
     * Positions of a КП that carry no money (module 018).
     *
     * SC-005 of spec 001 — «ни одно КП не уходит клиенту без подтверждения
     * менеджером» — was a click, not a statement about the document: a КП whose
     * «Итого» was 0,00 руб. confirmed and sent exactly like a priced one. This
     * is what `confirm` and `send` weigh that click against.
     *
     * @return array{items:array<int,array<string,mixed>>,total:float,empty:bool}
     */
    public static function priceGaps(int $proposalId): array {
        // Свёрнутая позиция в документ не попадает, значит и цены у неё нет по
        // определению — спрашивать про неё «отправляем без цены?» нечего
        $items = self::printedItems($proposalId);
        $total = 0.0;
        $gaps = [];
        foreach ($items as $item) {
            $price = (float)($item['price'] ?? 0);
            $total += $price * (float)($item['quantity'] ?? 0);
            if ($price > 0) continue;
            $name = trim((string)($item['product_name'] ?? ''));
            if ($name === '') $name = trim((string)($item['requested_name'] ?? ''));
            $gaps[] = [
                'id'       => (int)$item['id'],
                'position' => (int)$item['position'],
                'name'     => $name !== '' ? $name : 'позиция без названия',
                'quantity' => $item['quantity'],
                'unit'     => (string)($item['unit'] ?: 'шт.'),
            ];
        }
        // A КП with no positions at all is the same hole seen from the other
        // side, and the manager is asked about it the same way
        return ['items' => $gaps, 'total' => $total, 'empty' => !$items];
    }

    /**
     * Позиции, которые печатаются в КП: всё, кроме свёрнутых (модуль 020).
     *
     * Одно место, где это решается, — иначе «Итого», карточки товаров и
     * проверка «КП без цены» разошлись бы в том, что считают позицией.
     */
    public static function printedItems(int $proposalId): array {
        return Db::all(
            "SELECT * FROM proposal_items
             WHERE proposal_id=? AND COALESCE(is_excluded, 0) = 0
             ORDER BY position", [$proposalId]
        );
    }

    /**
     * «Таблица соответствия» — the block a КП opens with when the request
     * arrived as a table (module 013).
     *
     * One row per line of the client's own specification, in their order, and
     * next to it what we answer with: our position, its артикул, whether it is
     * in stock, and — when it is not the thing they named — which of their
     * requirements it meets. The client reads the answer the same way they
     * wrote the question.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function matchTableRows(int $proposalId): array {
        // The артикул lives on the catalog row, not on the КП line — a
        // спецификация is read by it, so the table must carry it
        $items = Db::all(
            "SELECT i.*, p.article
             FROM proposal_items i
             LEFT JOIN products_cache p ON p.moysklad_id = i.moysklad_product_id
             WHERE i.proposal_id=? ORDER BY i.position", [$proposalId]
        );
        $rows = [];
        foreach ($items as $i => $item) {
            $requested = trim((string)($item['requested_name'] ?? ''));
            $offered   = trim((string)($item['product_name'] ?? ''));
            $free      = (int)($item['stock_available'] ?? 0) - (int)($item['stock_reserved'] ?? 0);
            $isAlt     = (int)($item['is_alternative'] ?? 0) === 1;
            $excluded  = (int)($item['is_excluded'] ?? 0) === 1;

            $rows[] = [
                'n'          => $i + 1,
                'requested'  => $requested !== '' ? $requested : $offered,
                'quantity'   => $item['quantity'],
                'unit'       => $item['unit'] ?: 'шт.',
                // Свёрнутую позицию таблица не выбрасывает: строка запроса
                // остаётся, а в ответе честно стоит «уточняем» (модуль 020)
                'offered'    => $excluded ? '' : $offered,
                'article'    => $excluded ? '' : (string)($item['article'] ?? ''),
                'is_alternative' => $isAlt && !$excluded,
                'excluded'   => $excluded,
                // «в наличии» / «под заказ» — the honest two-value answer a
                // спецификация expects, taken from the free remainder
                'availability' => $excluded ? 'уточняем' : ($free > 0 ? 'в наличии' : 'под заказ'),
                'free'       => max(0, $free),
                'matched'    => ($isAlt && !$excluded) ? self::matchedSpecs($item) : [],
                'differs'    => ($isAlt && !$excluded) ? self::unmatchedSpecs($item) : [],
                'note'       => trim((string)($item['alt_reason'] ?? '')) ?: trim((string)($item['notes'] ?? '')),
            ];
        }
        return $rows;
    }

    /**
     * Does this КП carry the correspondence table?
     *
     * The manager's own switch wins; with none, the setting decides, and «auto»
     * means «the request arrived as a table». Never a question, never a model
     * call — the same letter produces the same document twice running.
     */
    public static function showMatchTable(array $proposal): bool {
        if ($proposal['show_match_table'] !== null && $proposal['show_match_table'] !== '') {
            return (int)$proposal['show_match_table'] === 1;
        }
        $mode = (string)Settings::get('KP_MATCH_TABLE', 'auto');
        if ($mode === 'always') return true;
        if ($mode === 'never')  return false;

        $requestId = (int)($proposal['request_id'] ?? 0);
        return $requestId > 0 && RequestShape::of($requestId) === RequestShape::TABLE;
    }

    public static function splitDescription(string $text): array {
        // Разметка из МойСклад снимается ДО разбора: заголовок «Характеристики:»,
        // завёрнутый в `<p>`, не стоял в начале строки и раздел не находился —
        // всё описание уезжало одним куском, вместе с тегами (модуль 020)
        $text = Markup::toMarkdown($text);
        $out = ['description' => trim($text), 'specs' => '', 'included' => ''];
        if (trim($text) === '') return $out;

        $pattern = '/^\s*#{0,6}\s*\**(характеристики|технические характеристики|комплектация|состав комплекта)\**\s*:?\s*$/miu';
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
     * The link printed as a QR, so it survives paper (module 017).
     *
     * A КП leaves as Word and is read as often on a printed sheet as on a
     * screen; there the address under the card is something a client would
     * have to retype. The picture encodes exactly `site_url` — the same link
     * `Bitrix::productUrl()` verified and froze on the item, never a shortener
     * and never a tracking wrapper, because what the client scans has to be
     * the address he can also read.
     *
     * Empty string when there is no link or the setting is off; the caller
     * prints the link alone and says nothing about it.
     */
    public static function itemQr(array $item): string {
        if ((int)Settings::get('KP_QR_CODE', 1) !== 1) return '';

        $url = trim((string)($item['site_url'] ?? ''));
        if ($url === '') return '';

        return Qr::dataUri($url, 4, 2);
    }

    /**
     * Photos of one KP position as data URIs. `selected_images` holds the keys
     * the manager ticked; an empty selection means «все, что нашлись» — the
     * behaviour КП had before the picker existed.
     */
    public static function itemGallery(array $item, int $max = 5): array {
        $selected = json_decode((string)($item['selected_images'] ?? ''), true);
        $msId = (string)($item['moysklad_product_id'] ?? '');

        // With no explicit pick the card carries the product's FIRST photo, as
        // the manager asked (module 013) — `KP_CARD_PHOTOS`. A manager who
        // ticked photos by hand meant those, and that choice is not capped here.
        if (!is_array($selected)) {
            $max = max(1, min($max, (int)Settings::get('KP_CARD_PHOTOS', 1)));
        }

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
