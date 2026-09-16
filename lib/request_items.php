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
require_once __DIR__ . '/scope.php';
require_once __DIR__ . '/variants.php';
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/markup.php';

final class RequestItems {

    /**
     * Перечитать остатки строк из каталога и поправить автоматическое «под заказ».
     *
     * Остаток записывался в строку ОДИН раз — в момент подбора. Каталог потом
     * обновлялся, товар возвращался на полку, а строка так и уходила в КП с
     * красной надписью «под заказ»: цифра в ней была вчерашняя (модуль 026).
     * Примечание, написанное человеком, не трогается — только то, что поставил
     * подбор сам.
     *
     * @return int сколько строк изменилось
     */
    public static function refreshStock(int $requestId): int {
        $rows = Db::all("SELECT * FROM request_items WHERE request_id=? AND moysklad_product_id IS NOT NULL
                          AND moysklad_product_id <> ''", [$requestId]);
        if (!$rows) return 0;

        $ids = array_values(array_unique(array_column($rows, 'moysklad_product_id')));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stock = [];
        $byVariants = Variants::stockFor($ids);
        foreach (Db::all("SELECT moysklad_id, stock, reserved FROM products_cache WHERE moysklad_id IN ($ph)", $ids) as $p) {
            $id = (string)$p['moysklad_id'];
            $stock[$id] = isset($byVariants[$id]) ? $byVariants[$id]['free'] : Alternatives::freeStock($p);
        }

        $changed = 0;
        foreach ($rows as $row) {
            $id = (string)$row['moysklad_product_id'];
            // Позиции нет в кэше — молчим: это «не знаем», а не «ноль на складе»
            if (!array_key_exists($id, $stock)) continue;
            $free = $stock[$id];
            $note = Terms::stockNote($row['notes'] ?? null, $free, true);
            if ((int)($row['stock'] ?? -1) === $free && ($row['notes'] ?? null) === $note) continue;
            Db::update('request_items', ['stock' => $free, 'notes' => $note], 'id=?', [(int)$row['id']]);
            $changed++;
        }
        return $changed;
    }

    /** Rows of a request; built from the parsed letter the first time it is opened. */
    public static function ensure(int $requestId, bool $useLlm = false): array {
        $rows = self::all($requestId);
        // Карточка открывается — остатки в ней сегодняшние, а не те, что были
        // в день подбора (модуль 026)
        if ($rows) { self::refreshStock($requestId); return self::all($requestId); }

        $req = Db::one("SELECT parsed_json, raw_text, counterparty_id FROM requests WHERE id=?", [$requestId]);
        $parsed = $req && $req['parsed_json'] ? (json_decode($req['parsed_json'], true) ?: []) : [];
        $items = $parsed['items'] ?? [];
        if (!$items) {
            // Письмо разобрали, когда модель позиций не нашла, — попробуем
            // правилами: «… в количестве 5 шт» тоже строка заказа (модуль 026)
            require_once __DIR__ . '/item_lines.php';
            $items = ItemLines::extract((string)($req['raw_text'] ?? ''));
        }
        if (!$items) return [];

        $counterpartyId = !empty($req['counterparty_id']) ? (int)$req['counterparty_id'] : null;

        // Один товар в трёх размерах — это ТРИ строки, а не одна на 25 штук
        // (модуль 022). Разбор чисто текстовый и идёт до каталога: ошибка в
        // количестве — это ошибка в деньгах, и решать её моделью нельзя.
        $items = Variants::expand($items);
        $matches = ProductMatcher::matchItems($items, $useLlm, $counterpartyId);
        foreach ($matches as $i => $match) {
            $src = $items[$i] ?? [];
            if (empty($src['variant_label'])) continue;
            // Клиент просил не «шлем», а «шлем размера S» — так строка и
            // называется, хотя каталог искали по товару-родителю
            $matches[$i]['raw_name']      = (string)$src['raw_name'];
            $matches[$i]['variant_label'] = (string)$src['variant_label'];
            $matches[$i]['variant_kind']  = (string)($src['variant_kind'] ?? 'size');
        }
        self::write($requestId, self::fromMatches($matches, $counterpartyId));
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
            // Аналог тому, чем мы не занимаемся, — это предложение поставить
            // пожарный рукав вместо пожарного топора (модуль 022)
            if ((int)($row['is_out_of_scope'] ?? 0) === 1) continue;
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

        // Остатки по модификациям — одним запросом на всю карточку (модуль 026)
        $variantStock = Variants::stockFor(array_merge($ids, array_reduce($rows, function ($acc, $r) {
            $list = $r['match_variants'] ? (json_decode((string)$r['match_variants'], true) ?: []) : [];
            return array_merge($acc, array_column($list, 'moysklad_id'));
        }, [])));

        foreach ($rows as &$row) {
            $row['variants'] = $row['match_variants'] ? (json_decode($row['match_variants'], true) ?: []) : [];
            unset($row['match_variants']);
            // Кандидат с модификациями отвечает их количествами, а не нулём
            foreach ($row['variants'] as &$cand) {
                $vs = $variantStock[(string)($cand['moysklad_id'] ?? '')] ?? null;
                if (!$vs) continue;
                $cand['variant_stock'] = $vs['items'];
                $cand['stock'] = $vs['free'];
            }
            unset($cand);
            $row['variant_stock'] = $variantStock[(string)($row['moysklad_product_id'] ?? '')]['items'] ?? [];
            $row['price_options'] = $prices[$row['moysklad_product_id']] ?? [];
            // Which of the client's requirements this analogue meets — the card
            // shows it, and so does the КП
            $row['alternative'] = !empty($row['alt_specs_json'])
                ? (json_decode((string)$row['alt_specs_json'], true) ?: null) : null;
            // То же, что напечатает КП: цена после скидок и одна строка условий
            $row['effective_price'] = Terms::price($row);
            $row['wait_note']       = Terms::note($row);
            $row['is_backorder']    = ($row['stock'] !== null && (int)$row['stock'] <= 0
                                       && trim((string)($row['moysklad_product_id'] ?? '')) !== '') ? 1 : 0;
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
        $queries = array_map(function ($r) {
            $name = $r['raw_name'] !== '' ? $r['raw_name'] : (string)$r['product_name'];
            // У строки с модификацией каталог ищется по товару-родителю:
            // «(размер S)» в названии не помогает найти сам шлем (модуль 022)
            if (trim((string)($r['variant_label'] ?? '')) !== '') $name = Variants::baseName($name);
            return ['name' => $name, 'qty' => $r['quantity']];
        }, $open);
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

            // Метка модификации живёт на строке и переезжает на свежий подбор:
            // перебор ищет товар, а размер у строки остаётся тот же
            $variant = $best ? Variants::resolveRow([
                'moysklad_product_id' => $best['moysklad_id'] ?? '',
                'variant_label'       => (string)($row['variant_label'] ?? ''),
            ], $counterpartyId) : [];

            Db::update('request_items', self::keepManualPrice($row, [
                'moysklad_product_id' => $variant['moysklad_product_id'] ?? ($best['moysklad_id'] ?? null),
                'product_name'        => $variant['product_name'] ?? ($best['name'] ?? null),
                'article'             => $variant['article'] ?? ($best['article'] ?? null),
                'unit'                => $variant['unit'] ?? ($best['unit'] ?? $row['unit']),
                'price'               => (float)($variant['price'] ?? ($best['price'] ?? 0)),
                'stock'               => array_key_exists('stock', $variant)
                                            ? $variant['stock']
                                            : ($best === null ? null : Alternatives::freeStock($best)),
                'match_confidence'    => $best['score'] ?? null,
                'match_variants'      => !empty($m['variants']) ? json_encode($m['variants'], JSON_UNESCAPED_UNICODE) : null,
                'needs_choice'        => !empty($m['needs_choice']) ? 1 : 0,
                'match_source'        => $variant['match_source'] ?? ($m['match_source'] ?? null),
                'notes'               => $variant['notes'] ?? $row['notes'],
                'is_confirmed'        => !empty($m['is_confirmed']) ? 1 : 0,
                // A fresh match starts from what the client asked for again:
                // the analogue is re-decided below against today's stock
                'is_alternative'      => 0,
                'alt_of'              => null,
                'alt_specs_json'      => null,
                // Список «не наша номенклатура» мог пополниться с прошлого раза —
                // перебор подбора его перечитывает, а решение менеджера, уже
                // стоящее на строке, остаётся в силе (модуль 022)
                'is_out_of_scope'     => (int)($row['is_out_of_scope'] ?? 0) === 1
                                            || Scope::match((string)$row['raw_name']) !== null ? 1 : 0,
                'out_of_scope_reason' => (string)($row['out_of_scope_reason'] ?? '') !== ''
                                            ? $row['out_of_scope_reason'] : Scope::match((string)$row['raw_name']),
                'updated_at'          => date('Y-m-d H:i:s'),
            ]), 'id=?', [$row['id']]);
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
                // Цену и скидку менеджер ставит руками, и повторный подбор их
                // больше не перетирает: цена в КП — это его решение (модуль 023)
                'price_is_manual'     => !empty($row['price_is_manual']) ? 1 : 0,
                'discount_percent'    => max(0.0, min(100.0, (float)($row['discount_percent'] ?? 0))),
                // Развёрнутый комментарий по товару: хранится разметкой,
                // печатается ею же — теги из МойСклад не уезжают в документ
                'comment_text'        => trim((string)($row['comment_text'] ?? '')) !== ''
                                            ? Markup::toMarkdown((string)$row['comment_text']) : null,
                // «Под заказ»: срок, скидка за ожидание и предоплата
                'wait_on'             => !empty($row['wait_on']) ? 1 : 0,
                'wait_months'         => isset($row['wait_months']) && $row['wait_months'] !== '' ? max(0, (int)$row['wait_months']) : null,
                'wait_discount'       => isset($row['wait_discount']) && $row['wait_discount'] !== '' ? max(0.0, min(100.0, (float)$row['wait_discount'])) : null,
                'wait_prepay'         => isset($row['wait_prepay']) && $row['wait_prepay'] !== '' ? max(0, min(100, (int)$row['wait_prepay'])) : null,
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
                // Решение «это не к нам» принимает человек и оно живёт на строке
                'is_out_of_scope'     => !empty($row['is_out_of_scope']) ? 1 : 0,
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
            // Строка «не наша номенклатура» в КП не уходит ни в каком виде:
            // ни ценой, ни строкой «уточняем». Мы этим не занимаемся, и
            // документ не должен намекать на обратное (модуль 022).
            if ((int)($row['is_out_of_scope'] ?? 0) === 1) continue;
            $hasProduct = trim((string)($row['product_name'] ?? '')) !== '';
            $out[] = [
                // Строка запроса, из которой выросла позиция КП: по ней видно,
                // какие позиции запроса ещё не разложены по КП (модуль 027)
                'request_item_id' => isset($row['id']) ? (int)$row['id'] : null,
                'raw_name'     => (string)($row['raw_name'] ?? ''),
                'quantity'     => (float)($row['quantity'] ?? 1),
                'is_confirmed' => (int)($row['is_confirmed'] ?? 0) === 1,
                'needs_choice' => (int)($row['needs_choice'] ?? 0) === 1,
                'notes'        => $row['notes'] ?? null,
                'comment_text' => $row['comment_text'] ?? null,
                'discount_percent' => (float)($row['discount_percent'] ?? 0),
                'price_is_manual'  => (int)($row['price_is_manual'] ?? 0),
                'wait_on'      => (int)($row['wait_on'] ?? 0),
                'wait_months'  => $row['wait_months'] ?? null,
                'wait_discount'=> $row['wait_discount'] ?? null,
                'wait_prepay'  => $row['wait_prepay'] ?? null,
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

        $p = Db::one("SELECT moysklad_id, name, article, unit, price, prices_json, stock, reserved, parent_id FROM products_cache WHERE moysklad_id=?", [$productId]);
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
               AND is_confirmed=0 AND COALESCE(is_out_of_scope, 0) = 0
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
    /**
     * Инструкция модели про то, чем мы не занимаемся (модуль 022).
     *
     * Без неё нейросеть отвечала «уточним по ним наличие, сроки и цену» — по
     * топору пожарному и рукаву 5ELEM, которых у нас нет и не будет. Обещание,
     * которого никто не выполнит, дороже молчания: по умолчанию письмо про эти
     * позиции просто молчит, а `SCOPE_REPLY_MODE = decline` заставляет сказать
     * одной фразой, что мы ими не занимаемся.
     */
    public static function outOfScopeBlock(array $rows): string {
        if (!$rows) return '';
        $mode = (string)Settings::get('SCOPE_REPLY_MODE', 'silent');

        $out = "\n===== ЧЕМ МЫ НЕ ЗАНИМАЕМСЯ =====\n";
        $out .= $mode === 'decline'
            ? "Это не наша номенклатура. Скажи об этом ОДНОЙ фразой на все позиции сразу: мы их не поставляем. "
            . "Не обещай уточнить наличие, сроки или цену и не предлагай замену.\n"
            : "Это не наша номенклатура. НЕ упоминай эти позиции в ответе вообще: ни списком, ни одной строкой. "
            . "Не обещай уточнить по ним наличие, сроки или цену, не предлагай им замену и не извиняйся за них.\n";
        foreach ($rows as $r) {
            $out .= "- {$r['requested']}\n";
        }
        return $out;
    }

    /**
     * Подобранные позиции — блок для промпта ответа (модуль 023).
     *
     * «Создать ответ» писал письмо по одному тексту запроса, как будто подбора
     * не было вовсе: менеджер уже выбрал позиции, проставил цены, дописал
     * комментарии — а ответ шёл мимо всего этого и обещал «уточнить». Теперь то
     * же, что попадёт в КП, попадает и в письмо: название, количество, цена,
     * наличие, условия ожидания и комментарий менеджера.
     *
     * @param array $rows строки `request_items` — то, что вернул `all()`
     */
    public static function matchedBlock(array $rows): string {
        $lines = [];
        foreach ($rows as $row) {
            if ((int)($row['is_out_of_scope'] ?? 0) === 1) continue;
            $name = trim((string)($row['product_name'] ?? ''));
            if ($name === '') continue;

            $line = '- ' . $name;
            if (trim((string)($row['article'] ?? '')) !== '') $line .= ' (арт. ' . $row['article'] . ')';
            $line .= ' — ' . $row['quantity'] . ' ' . ($row['unit'] ?: 'шт.');

            $price = (float)($row['effective_price'] ?? $row['price'] ?? 0);
            if ($price > 0) $line .= ', ' . number_format($price, 2, ',', ' ') . ' руб. за ед.';

            $stock = $row['stock'] === null ? null : (int)$row['stock'];
            if ($stock !== null) $line .= $stock > 0 ? ", в наличии $stock" : ', под заказ';

            $wait = trim((string)($row['wait_note'] ?? ''));
            if ($wait !== '') $line .= ' (' . $wait . ')';

            // Комментарий менеджера — это то, что он сам решил сказать клиенту
            // про эту позицию. Пересказывать его своими словами нельзя.
            $comment = trim(Markup::toPlainText((string)($row['comment_text'] ?? '')));
            if ($comment !== '') $line .= "\n  комментарий менеджера (передай его смысл целиком): " . mb_substr($comment, 0, 800);

            $lines[] = $line;
        }
        if (!$lines) return '';

        return "\n===== ЧТО МЫ ПРЕДЛАГАЕМ ПО ЭТОМУ ЗАПРОСУ =====\n"
             . "Это уже подобрано и проверено менеджером. Назови в ответе ИМЕННО эти позиции, "
             . "эти количества и эти цены — не придумывай других и не меняй цифры. "
             . "Если у позиции есть комментарий менеджера, он важнее твоих формулировок.\n"
             . implode("\n", $lines) . "\n";
    }

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

    /**
     * Строки, которыми мы не занимаемся (модуль 022).
     *
     * Их видит менеджер на карточке — и только он. В КП и в ответ клиенту они
     * не уходят: `toProposalItems()` их пропускает, `unmatched()` про них не
     * знает, и в промпт ответа они не попадают ни одной буквой.
     *
     * @return array<int,array{id:int,position:int,requested:string,quantity:mixed,unit:string,reason:string}>
     */
    public static function outOfScope(int $requestId): array {
        $rows = Db::all(
            "SELECT * FROM request_items WHERE request_id=? AND COALESCE(is_out_of_scope, 0) = 1
             ORDER BY position, id", [$requestId]
        );
        $out = [];
        foreach ($rows as $row) {
            $asked = trim((string)($row['raw_name'] ?? '')) ?: trim((string)($row['product_name'] ?? ''));
            if ($asked === '') continue;
            $out[] = [
                'id'        => (int)$row['id'],
                'position'  => (int)$row['position'],
                'requested' => $asked,
                'quantity'  => $row['quantity'],
                'unit'      => (string)($row['unit'] ?: 'шт.'),
                'reason'    => (string)($row['out_of_scope_reason'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Отметить строку как не нашу — или вернуть её в работу.
     *
     * Нажатие запоминается: слова строки уходят в список `CATALOG_OUT_OF_SCOPE`,
     * и следующее такое письмо отсеется само, без модели и без сети. Возврат
     * строки правило НЕ удаляет: список правится в «Настройках», а один
     * возврат — ещё не отмена правила для всех.
     */
    public static function setScope(int $requestId, int $itemId, bool $outOfScope): array {
        $row = Db::one("SELECT * FROM request_items WHERE id=? AND request_id=?", [$itemId, $requestId]);
        if (!$row) throw new RuntimeException('Строка не найдена');

        $name = trim((string)($row['raw_name'] ?? '')) ?: trim((string)($row['product_name'] ?? ''));
        $reason = null;
        if ($outOfScope) {
            $reason = Scope::match($name) ?? Scope::remember($name) ?? 'отмечено менеджером';
        }

        Db::update('request_items', [
            'is_out_of_scope'     => $outOfScope ? 1 : 0,
            'out_of_scope_reason' => $reason,
            'updated_at'          => date('Y-m-d H:i:s'),
        ], 'id=?', [$itemId]);

        return self::all($requestId);
    }

    /** How many lines of a request are still waiting for that answer. */
    public static function openChoices(int $requestId): int {
        return (int)Db::val("SELECT COUNT(*) FROM request_items WHERE request_id=? AND needs_choice=1", [$requestId]);
    }

    /** ProductMatcher output → table rows. */
    private static function fromMatches(array $matches, ?int $counterpartyId = null): array {
        $out = [];
        foreach ($matches as $m) {
            $best = $m['match'] ?? null;
            // «Не наша номенклатура» решается по самому названию и ДО каталога:
            // рукав пожарный не становится нашей позицией оттого, что похожее
            // слово нашлось в описании чехла (модуль 022)
            $rule = Scope::match((string)($m['raw_name'] ?? ''));
            $out[] = [
                'raw_name'            => (string)($m['raw_name'] ?? ''),
                'is_out_of_scope'     => $rule !== null ? 1 : 0,
                'out_of_scope_reason' => $rule,
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
                // Остаток товара с модификациями — сумма его размеров и цветов:
                // у родителя в МойСклад своего остатка нет (модуль 026)
                'stock'               => $best === null ? null : Variants::freeStock($best),
                'match_confidence'    => $best['score'] ?? null,
                'match_variants'      => !empty($m['variants']) ? json_encode($m['variants'], JSON_UNESCAPED_UNICODE) : null,
                'needs_choice'        => !empty($m['needs_choice']) ? 1 : 0,
                'match_source'        => $m['match_source'] ?? null,
                'is_confirmed'        => !empty($m['is_confirmed']) ? 1 : 0,
                'notes'               => Terms::stockNote(null, $best ? Variants::freeStock($best) : 0, (bool)$best),
                'variant_label'       => (string)($m['variant_label'] ?? '') ?: null,
                'variant_kind'        => (string)($m['variant_kind'] ?? '') ?: null,
            ];

            // Строка просила конкретный размер или цвет — пусть и карточка
            // каталога будет его: свой артикул, своя цена, свой остаток
            $last = array_key_last($out);
            $resolved = Variants::resolveRow($out[$last], $counterpartyId);
            foreach ($resolved as $field => $value) {
                $out[$last][$field] = $value;
            }
            // Остаток теперь принадлежит модификации, значит и «под заказ» —
            // тоже её: у размера L склад свой, а не общий на товар
            if ($resolved && array_key_exists('stock', $resolved)) {
                $out[$last]['notes'] = Terms::stockNote($out[$last]['notes'], (int)$resolved['stock'], true);
            }
        }
        return $out;
    }

    /**
     * Строка, у которой цену поставили руками, переживает пересчёт: подбор
     * может уточнить название и остаток, но не цену (модуль 023).
     */
    private static function keepManualPrice(array $was, array $now): array {
        if ((int)($was['price_is_manual'] ?? 0) !== 1) return $now;
        $now['price'] = (float)($was['price'] ?? 0);
        $now['price_is_manual'] = 1;
        return $now;
    }

    private static function write(int $requestId, array $rows): void {
        Db::q("DELETE FROM request_items WHERE request_id=?", [$requestId]);
        foreach (array_values($rows) as $i => $row) {
            Db::insert('request_items', $row + ['request_id' => $requestId, 'position' => $i + 1]);
        }
    }
}
