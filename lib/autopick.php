<?php
/**
 * Нейросеть подключается сама, когда каталог не справился (модуль 065, issue #132).
 *
 * Каталог сопоставляет строки письма словами и смыслом — бесплатно и сразу. Но
 * бывает, что он ничего не нашёл, нашёл несколько равных или нашёл слабо, и
 * до сих пор это значило одно: менеджер жмёт «Подобрать нейросетью» или
 * выбирает руками. Здесь модель спрашивается САМА — один раз на письмо, одним
 * запросом на все такие строки, — и только о них.
 *
 * Модель не источник: она выбирает среди кандидатов, которых нашёл и проверил
 * код (кандидаты строки, её синонимы, полнотекстовый поиск), при температуре 0.
 * Названия, цены и остатки — из каталога; размер выбирает `Variants` по словам
 * клиента. Нет ключа, упала сеть, ответ не разобрать — строки остаются такими,
 * какими их поставил каталог.
 */
require_once __DIR__ . '/matcher.php';
require_once __DIR__ . '/synonyms.php';
require_once __DIR__ . '/variants.php';
require_once __DIR__ . '/request_items.php';
require_once __DIR__ . '/markup.php';

final class Autopick {

    /** Сколько кандидатов видит модель на одну строку. */
    private const POOL = 10;
    /** Строк письма в одном запросе — длинная спецификация не рвёт ответ по пределу. */
    private const MAX_LINES = 25;

    /** Включено ли и есть ли кого спросить. */
    public static function available(): bool {
        if ((int)Settings::get('MATCH_AUTO_LLM', 1) !== 1) return false;
        return LLM::anyReady();
    }

    /**
     * Строки, которые каталог не решил и о которых модель ещё не спрашивали.
     *
     * Подтверждённые (менеджером или уверенным совпадением), «не наша
     * номенклатура», аналоги и строки без слов клиента — не спрашиваются.
     *
     * @return int[] id строк `request_items`
     */
    public static function pendingIds(int $requestId): array {
        $auto = (float)Settings::get('MATCH_AUTO_CONFIRM', 0.88);
        $rows = Db::all(
            "SELECT * FROM request_items WHERE request_id=? AND llm_checked_at IS NULL
               AND COALESCE(is_confirmed, 0) = 0 AND COALESCE(is_out_of_scope, 0) = 0
               AND COALESCE(is_alternative, 0) = 0
             ORDER BY position, id", [$requestId]);
        $out = [];
        foreach ($rows as $r) {
            if (trim((string)($r['raw_name'] ?? '')) === '') continue;
            if (self::unsettled($r, $auto)) $out[] = (int)$r['id'];
        }
        return $out;
    }

    /** Не решил ли каталог эту строку: пусто, равные, по описанию или слабо. */
    public static function unsettled(array $row, float $autoConfirm): bool {
        if (trim((string)($row['moysklad_product_id'] ?? '')) === '') return true;
        if ((int)($row['needs_choice'] ?? 0) === 1) return true;
        $source = (string)($row['match_source'] ?? '');
        if ($source === 'description') return true;
        // Ссылка на товар и выбор модели — уже ответ
        if (in_array($source, ['site_url', 'нейросеть'], true)) return false;
        $score = $row['match_confidence'] ?? null;
        return $score !== null && $score !== '' && (float)$score < $autoConfirm;
    }

    /** Сводка для карточки: какие строки ждут модели и можно ли её спросить. */
    public static function status(int $requestId): array {
        $ids = self::pendingIds($requestId);
        return ['pending' => count($ids), 'ids' => $ids, 'available' => self::available()];
    }

    /**
     * Спросить модель о нерешённых строках — одним запросом.
     *
     * $ids — строки, о которых можно спрашивать (null — все нерешённые); из них
     * берутся только нерешённые. $ask — кто отвечает: fn(string $system,
     * string $user): array; по умолчанию модель при температуре 0.
     *
     * @return array{asked:int,picked:int,error?:string,items:array}
     */
    public static function run(int $requestId, ?array $ids = null, ?callable $ask = null): array {
        $pending = self::pendingIds($requestId);
        $ids = $ids === null ? $pending : array_values(array_intersect(array_map('intval', $ids), $pending));
        $ids = array_slice($ids, 0, self::MAX_LINES);
        if (!$ids) return ['asked' => 0, 'picked' => 0, 'items' => RequestItems::all($requestId)];

        $req = Db::one("SELECT raw_text, counterparty_id FROM requests WHERE id=?", [$requestId]) ?: [];
        $letter = (string)($req['raw_text'] ?? '');
        $counterpartyId = !empty($req['counterparty_id']) ? (int)$req['counterparty_id'] : null;

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::all("SELECT * FROM request_items WHERE id IN ($ph) ORDER BY position, id", $ids);

        $lines = $pools = $texts = [];
        foreach ($rows as $n => $row) {
            $asked = trim((string)$row['raw_name']);
            $texts[$n] = RequestItems::requirementText($letter, $asked);
            $pool = self::pool($row, $counterpartyId);
            if (!$pool) continue;   // выбирать не из чего — модель не придумает
            $pools[$n] = $pool;
            $label = trim((string)($row['variant_label'] ?? ''));
            $lines[] = [
                'line'       => $n,
                'asked'      => $asked,
                'text'       => self::clip($texts[$n], 500),
                'size'       => $label !== '' ? $label : null,
                'candidates' => array_map(fn($c) => [
                    'id'              => $c['moysklad_id'],
                    'name'            => $c['name'],
                    'characteristics' => self::clip((string)$c['characteristics'], 200),
                    'sizes'           => self::clip((string)$c['sizes'], 200),
                    'in_stock'        => $c['free'] > 0,
                    'description'     => self::clip((string)$c['description'], 300),
                ], $pool),
            ];
        }

        $picked = 0;
        if ($lines) {
            $query = implode("\n", array_column($lines, 'asked'));
            $system = Knowledge::augment('match_pick', [], $query);
            $user = json_encode(['lines' => $lines], JSON_UNESCAPED_UNICODE);
            try {
                $answer = $ask ? $ask($system, $user) : LLM::chatJson($system, $user, 0.0);
            } catch (Throwable $e) {
                // Строки остаются каталожными, и их спросят в следующий раз
                Logger::warning('catalog', 'Нейросеть не уточнила подбор: ' . $e->getMessage(), ['request_id' => $requestId]);
                return ['asked' => 0, 'picked' => 0, 'error' => $e->getMessage(), 'items' => RequestItems::all($requestId)];
            }

            foreach ((array)($answer['lines'] ?? []) as $a) {
                $n = $a['line'] ?? null;
                if (!is_int($n) || !isset($pools[$n])) continue;
                $id = trim((string)($a['pick'] ?? ''));
                $choice = null;
                foreach ($pools[$n] as $c) if ((string)$c['moysklad_id'] === $id) { $choice = $c; break; }
                if (!$choice) continue;   // «не нашли» или id, которого не давали
                $reason = self::clip(trim((string)($a['reason'] ?? '')), 160);
                $others = array_values(array_filter($pools[$n], fn($c) => (string)$c['moysklad_id'] !== $id));
                RequestItems::place($rows[$n], $id, [
                    'source'   => 'нейросеть',
                    'hint'     => 'выбрала нейросеть' . ($reason !== '' ? ': ' . $reason : ''),
                    'context'  => $texts[$n],
                    'score'    => $choice['score'],
                    'variants' => array_map(fn($c) => [
                        'moysklad_id' => $c['moysklad_id'], 'name' => $c['name'], 'article' => $c['article'],
                        'unit' => $c['unit'], 'price' => $c['price'], 'stock' => $c['free'],
                        'score' => $c['score'], 'source' => $c['source'],
                    ], array_slice($others, 0, 4)),
                ]);
                $picked++;
            }
        }

        // Спросили — больше не спрашиваем, пока строку не подберут заново
        Db::q("UPDATE request_items SET llm_checked_at=? WHERE id IN ($ph)", array_merge([date('Y-m-d H:i:s')], $ids));
        // Поставленное моделью, но пустое на складе — за аналогом, как любая строка
        RequestItems::fillAlternatives($requestId, false, $ids);
        if ($picked) Logger::info('catalog', "Нейросеть подобрала позиций: $picked из " . count($lines), ['request_id' => $requestId]);
        return ['asked' => count($lines), 'picked' => $picked, 'items' => RequestItems::all($requestId)];
    }

    /**
     * Кандидаты строки — то, что нашёл код, лучшие первыми.
     *
     * Текущий товар строки и её «ещё похожие», кандидаты каталога по самой
     * строке и по её торговым синонимам, полнотекстовые находки её слов.
     * Модификация стоит своим товаром: размер выберет `Variants`, а список,
     * где один шлем повторён в шести цветах, выбор только путает.
     */
    public static function pool(array $row, ?int $counterpartyId = null): array {
        $asked = trim((string)($row['raw_name'] ?? ''));
        $query = Variants::searchName($asked);
        if ($query === '') return [];

        $scores = [];   // id → оценка
        $source = [];   // id → откуда
        $add = function (string $id, $score, string $from) use (&$scores, &$source) {
            if ($id === '') return;
            $score = $score === null || $score === '' ? 0.5 : (float)$score;
            if (!isset($scores[$id]) || $score > $scores[$id]) { $scores[$id] = $score; $source[$id] = $from; }
        };

        $add((string)($row['moysklad_product_id'] ?? ''), $row['match_confidence'] ?? null, 'words');
        foreach ((array)(json_decode((string)($row['match_variants'] ?? ''), true) ?: []) as $c) {
            $add((string)($c['moysklad_id'] ?? ''), $c['score'] ?? null, (string)($c['source'] ?? 'words'));
        }
        foreach (ProductMatcher::findCandidates($query, 8, null, $counterpartyId) as $c) {
            $add((string)$c['moysklad_id'], $c['score'], (string)$c['source']);
        }
        foreach (array_slice(Synonyms::variants($query), 1, 3) as $variant) {
            foreach (ProductMatcher::findCandidates($variant, 4, null, $counterpartyId) as $c) {
                $add((string)$c['moysklad_id'], $c['score'], (string)$c['source']);
            }
        }
        // Полнотекстово: вся строка, затем её главные слова по одному
        $hits = ProductMatcher::search($query, 6);
        if (!$hits) {
            $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
                                               fn($w) => mb_strlen($w) >= 4));
            foreach (array_slice($words, 0, 3) as $w) {
                foreach (ProductMatcher::search($w, 4) as $r) $hits[] = $r;
            }
        }
        foreach ($hits as $r) $add((string)$r['moysklad_id'], 0.4, 'search');
        if (!$scores) return [];

        // Модификация — своим товаром, с лучшей из оценок семьи
        $ids = array_keys($scores);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $parents = array_column(Db::all("SELECT moysklad_id, parent_id FROM products_cache WHERE moysklad_id IN ($ph)", $ids),
                                'parent_id', 'moysklad_id');
        $roots = [];
        foreach ($scores as $id => $score) {
            $root = trim((string)($parents[$id] ?? '')) ?: (string)$id;
            if (!isset($roots[$root]) || $score > $roots[$root]['score']) {
                $roots[$root] = ['score' => $score, 'source' => $source[$id], 'fallback' => (string)$id];
            }
        }
        uasort($roots, fn($a, $b) => $b['score'] <=> $a['score']);

        $rootIds = array_map('strval', array_keys($roots));
        $ph = implode(',', array_fill(0, count($rootIds), '?'));
        $byId = [];
        foreach (Db::all("SELECT * FROM products_cache WHERE moysklad_id IN ($ph) AND COALESCE(is_archived, 0) = 0", $rootIds) as $p) {
            $byId[(string)$p['moysklad_id']] = $p;
        }
        $family = Variants::stockFor($rootIds);

        $out = [];
        foreach ($roots as $root => $meta) {
            $p = $byId[(string)$root] ?? null;
            if (!$p) continue;   // товар в архиве или родителя в каталоге нет
            $sizes = array_map(fn($v) => $v['label'] . ($v['free'] > 0 ? '' : ' (нет)'), $family[(string)$root]['items'] ?? []);
            $out[] = [
                'moysklad_id'     => (string)$p['moysklad_id'],
                'name'            => (string)$p['name'],
                'article'         => (string)($p['article'] ?? ''),
                'unit'            => (string)($p['unit'] ?? '') ?: 'шт.',
                'price'           => Catalog::priceFor($p, $counterpartyId),
                'characteristics' => (string)($p['characteristics'] ?? ''),
                'sizes'           => implode(', ', array_slice($sizes, 0, 12)),
                'free'            => Variants::freeStock($p),
                'description'     => Markup::toPlainText((string)($p['description'] ?? '')),
                'score'           => round((float)$meta['score'], 3),
                'source'          => $meta['source'],
            ];
            if (count($out) >= self::POOL) break;
        }
        return $out;
    }

    private static function clip(string $text, int $max): string {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
