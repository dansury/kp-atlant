<?php
/**
 * Что мы продали на эти слова (issue #142, модуль 067).
 *
 * «Тактические наушники с активным шумоподавлением» менеджер однажды закрыл
 * «Earmor M31 MOD3», и КП ушло. Следующее письмо с теми же словами подбор
 * встречал с нуля — и находил «Переходники для наушников». Здесь живёт память:
 * формулировка клиента → товар, который на неё ответили.
 *
 * Пишут в неё только решения человека — отправленное КП и товар, который
 * менеджер поставил в строку сам. Собственная догадка подбора сюда не попадает:
 * иначе ошибка закрепляла бы саму себя.
 */
require_once __DIR__ . '/matcher.php';
require_once __DIR__ . '/variants.php';

final class MatchMemory {

    /** Оценка товара из памяти: выше автоподтверждения, ниже ссылки на товар. */
    public const SCORE = 0.97;

    /** Формулировка → ключ: без размера, шума, порядка слов и окончаний. */
    public static function key(string $phrase): string {
        return ProductMatcher::phraseKey($phrase);
    }

    /**
     * Запомнить: на эти слова ответили этим товаром. Хранится товар-родитель —
     * размер следующего письма свой, его выберет `Variants`.
     */
    public static function remember(string $phrase, string $productId, string $source, ?int $managerId = null): bool {
        $key = self::key($phrase);
        $productId = trim($productId);
        if ($key === '' || $productId === '') return false;
        $root = self::root($productId);
        if ($root === null) return false;

        $now = date('Y-m-d H:i:s');
        $name = (string)(Db::val("SELECT name FROM products_cache WHERE moysklad_id=?", [$root]) ?: '');
        Db::q("INSERT INTO match_memory (phrase_key, phrase, moysklad_id, product_name, source, hits, manager_id, first_at, last_at)
               VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
               ON CONFLICT(phrase_key, moysklad_id) DO UPDATE SET
                   hits = match_memory.hits + 1, phrase = excluded.phrase, product_name = excluded.product_name,
                   source = excluded.source, manager_id = COALESCE(excluded.manager_id, match_memory.manager_id),
                   last_at = excluded.last_at",
              [$key, mb_substr(trim($phrase), 0, 500), $root, $name, $source, $managerId, $now, $now]);
        return true;
    }

    /**
     * Товар, которым в последний раз ответили на эти слова, — если он ещё в
     * каталоге. Свежая пара побеждает: менеджер поправил память, выбрав другой.
     *
     * @return ?array{moysklad_id:string,product_name:string,hits:int,source:string,last_at:string,phrase:string}
     */
    public static function recall(string $phrase): ?array {
        $key = self::key($phrase);
        if ($key === '') return null;
        $row = Db::one(
            "SELECT m.moysklad_id, p.name AS product_name, m.hits, m.source, m.last_at, m.phrase
             FROM match_memory m
             JOIN products_cache p ON p.moysklad_id = m.moysklad_id
             WHERE m.phrase_key = ? AND COALESCE(p.is_archived, 0) = 0
             ORDER BY m.last_at DESC, m.hits DESC, m.id DESC LIMIT 1", [$key]);
        if (!$row || ProductMatcher::modelConflict($phrase, $row['product_name'])) return null;
        $row['hits'] = (int)$row['hits'];
        return $row;
    }

    /**
     * КП ушло клиенту: каждая его строка — ответ на слова клиента. Аналог,
     * снятая позиция и «не наша номенклатура» — не ответ на эти слова.
     *
     * @return int сколько пар записано
     */
    public static function fromProposal(int $proposalId, string $source = 'kp_sent', ?int $managerId = null): int {
        $rows = Db::all(
            "SELECT pi.moysklad_product_id, pi.requested_name, pi.is_alternative, pi.is_excluded,
                    ri.raw_name, ri.is_out_of_scope, ri.is_alternative AS row_alternative
             FROM proposal_items pi
             LEFT JOIN request_items ri ON ri.id = pi.request_item_id
             WHERE pi.proposal_id = ?", [$proposalId]);
        $n = 0;
        foreach ($rows as $r) {
            if ((int)($r['is_alternative'] ?? 0) === 1 || (int)($r['row_alternative'] ?? 0) === 1) continue;
            if ((int)($r['is_excluded'] ?? 0) === 1 || (int)($r['is_out_of_scope'] ?? 0) === 1) continue;
            $phrase = trim((string)($r['raw_name'] ?? '')) ?: trim((string)($r['requested_name'] ?? ''));
            if ($phrase === '') continue;
            if (self::remember($phrase, (string)($r['moysklad_product_id'] ?? ''), $source, $managerId)) $n++;
        }
        return $n;
    }

    /** Все отправленные КП — одним заходом (миграция v57). */
    public static function backfill(): int {
        $n = 0;
        foreach (Db::all("SELECT id FROM proposals WHERE status IN ('sent','order_created') OR sent_at IS NOT NULL
                          ORDER BY COALESCE(sent_at, created_at), id") as $p) {
            $n += self::fromProposal((int)$p['id']);
        }
        return $n;
    }

    /** Корень семьи: модификация → её товар; товара нет в каталоге — null. */
    private static function root(string $productId): ?string {
        $row = Db::one("SELECT moysklad_id, parent_id FROM products_cache WHERE moysklad_id=?", [$productId]);
        if (!$row) return null;
        $parent = trim((string)($row['parent_id'] ?? ''));
        if ($parent !== '' && Db::val("SELECT 1 FROM products_cache WHERE moysklad_id=?", [$parent])) return $parent;
        return (string)$row['moysklad_id'];
    }
}
