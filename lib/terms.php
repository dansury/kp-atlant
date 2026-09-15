<?php
/**
 * Условия поставки одной позиции КП (модуль 023).
 *
 * Товара нет на складе — это не «нет», это «через сколько-то». У такой позиции
 * появляются три величины, которые до сих пор менеджер дописывал руками в поле
 * «Примечание» и каждый раз по-своему:
 *
 *   — СРОК ОЖИДАНИЯ, месяцев (по умолчанию 3);
 *   — СКИДКА ЗА ОЖИДАНИЕ, % (по умолчанию 10) — цена, которой клиент платит за то,
 *     что ждёт;
 *   — ДОЛЯ ПРЕДОПЛАТЫ, % (по умолчанию 100) — под заказ мы возим предоплатой.
 *
 * Деньги здесь не двигаются сами. Значения проставляются в строку при сборке КП
 * ПОДГОТОВЛЕННЫМИ, но выключенными: пока `wait_on` не поднят человеком (или
 * настройкой «Включать условия ожидания самим»), цена позиции ровно та, что
 * была. Скидка, поставленная за менеджера, — это его премия, отданная без
 * спроса, и решать такое молча нельзя.
 *
 * Скидки складываются по-честному, друг на друга, а не в сумму: ручные 5% и
 * ожидание 10% — это 0.95 × 0.90, то есть 14.5%, а не 15%.
 */
final class Terms {

    /**
     * Примечание, которое подбор ставит сам, увидев пустой склад.
     *
     * Оно живёт в том же поле, что и примечание, написанное человеком, —
     * поэтому его надо УЗНАВАТЬ. Раньше не узнавали: строка получала «под
     * заказ» один раз, в момент подбора, и оставалась с ней навсегда. Остатки
     * перечитались, товар лёг на полку — а в КП по-прежнему уходила красная
     * надпись (модуль 026). Своё примечание менеджера при этом не трогается ни
     * при каких обстоятельствах.
     */
    public const AUTO_NOTE = 'под заказ';

    /**
     * Каким примечание должно стать при этом остатке.
     *
     * @param ?string $current что стоит в поле сейчас
     * @param int     $free    свободный остаток
     * @param bool    $hasProduct нашлась ли позиция в каталоге вообще
     * @return ?string новое значение поля
     */
    public static function stockNote(?string $current, int $free, bool $hasProduct): ?string {
        $current = trim((string)$current);
        // Человек написал своё — молчим
        if ($current !== '' && $current !== self::AUTO_NOTE) return $current;
        return ($hasProduct && $free <= 0) ? self::AUTO_NOTE : null;
    }

    /** Чем поля заполняются, пока их никто не трогал. */
    public static function defaults(): array {
        return [
            'months'   => max(0, (int)Settings::get('KP_WAIT_MONTHS', 3)),
            'discount' => self::clampPercent((float)Settings::get('KP_WAIT_DISCOUNT', 10)),
            'prepay'   => (int)self::clampPercent((float)Settings::get('KP_WAIT_PREPAY', 100)),
        ];
    }

    /** Поднимать ли условия ожидания самим, без менеджера. По умолчанию — нет. */
    public static function auto(): bool {
        return (int)Settings::get('KP_WAIT_AUTO', 0) === 1;
    }

    /**
     * Свободный остаток позиции: сколько можем отгрузить прямо сейчас.
     *
     * Понимает обе формы строки: у `proposal_items` остаток и резерв лежат
     * отдельно, у `request_items` в `stock` уже записан свободный.
     */
    public static function freeStock(array $item): int {
        if (array_key_exists('stock_available', $item)) {
            return max(0, (int)($item['stock_available'] ?? 0) - (int)($item['stock_reserved'] ?? 0));
        }
        return max(0, (int)($item['stock'] ?? 0));
    }

    /** Позиция, которой нет на складе, — та, ради которой всё это и есть. */
    public static function isBackorder(array $item): bool {
        $hasProduct = trim((string)($item['moysklad_product_id'] ?? '')) !== '';
        return $hasProduct && self::freeStock($item) <= 0;
    }

    /**
     * Условия ожидания этой строки, или null — когда их нет.
     *
     * @return array{months:int,discount:float,prepay:int}|null
     */
    public static function waiting(array $item): ?array {
        if ((int)($item['wait_on'] ?? 0) !== 1) return null;
        $d = self::defaults();
        // Незаполненное поле — это «как в настройках», а не ноль: строка,
        // написанная до модуля 023, вообще не знает этих колонок
        $months   = $item['wait_months']   ?? null;
        $discount = $item['wait_discount'] ?? null;
        $prepay   = $item['wait_prepay']   ?? null;
        return [
            'months'   => ($months   === null || $months   === '') ? $d['months']   : max(0, (int)$months),
            'discount' => ($discount === null || $discount === '') ? $d['discount'] : self::clampPercent((float)$discount),
            'prepay'   => ($prepay   === null || $prepay   === '') ? $d['prepay']   : (int)self::clampPercent((float)$prepay),
        ];
    }

    /**
     * Цена, которая печатается в КП: базовая, затем ручная скидка, затем скидка
     * за ожидание. Округление — до копейки, один раз в конце.
     */
    public static function price(array $item): float {
        $price = (float)($item['price'] ?? 0);
        $price *= 1 - self::clampPercent((float)($item['discount_percent'] ?? 0)) / 100;

        $wait = self::waiting($item);
        if ($wait) $price *= 1 - $wait['discount'] / 100;

        return round($price, 2);
    }

    /** Сколько скидки в итоге получилось, в процентах от базовой цены. */
    public static function totalDiscount(array $item): float {
        $base = (float)($item['price'] ?? 0);
        if ($base <= 0) return 0.0;
        return round((1 - self::price($item) / $base) * 100, 2);
    }

    /**
     * Что печатается под строкой: одна фраза вместо трёх полей.
     * Пусто — писать нечего, и строка остаётся как была.
     */
    public static function note(array $item): string {
        $wait = self::waiting($item);
        if (!$wait) return '';

        $parts = ['под заказ'];
        if ($wait['months'] > 0) $parts[] = 'срок ожидания ' . self::months($wait['months']);
        if ($wait['discount'] > 0) $parts[] = 'скидка за ожидание ' . self::percent($wait['discount']) . '%';
        if ($wait['prepay'] > 0) {
            $parts[] = $wait['prepay'] >= 100
                ? 'полная предоплата'
                : 'предоплата ' . self::percent((float)$wait['prepay']) . '%';
        }
        return implode(', ', $parts);
    }

    /**
     * Приготовить строку КП к ожиданию: проставить значения по умолчанию там,
     * где их ещё нет. Уже заполненное не трогается — менеджер мог поставить
     * свой срок, и пересборка КП не должна его стирать.
     *
     * @return array<string,mixed> поля для UPDATE; пусто — менять нечего
     */
    public static function prepare(array $item): array {
        if (!self::isBackorder($item)) return [];

        $d = self::defaults();
        $upd = [];
        if (($item['wait_months']   ?? null) === null) $upd['wait_months']   = $d['months'];
        if (($item['wait_discount'] ?? null) === null) $upd['wait_discount'] = $d['discount'];
        if (($item['wait_prepay']   ?? null) === null) $upd['wait_prepay']   = $d['prepay'];
        // Сам по себе выключатель поднимается только если так велели настройки
        if (self::auto() && (int)($item['wait_on'] ?? 0) !== 1) $upd['wait_on'] = 1;
        return $upd;
    }

    /** Проставить условия ожидания всем строкам КП, которых нет на складе. */
    public static function prepareProposal(int $proposalId): int {
        $done = 0;
        foreach (Db::all("SELECT * FROM proposal_items WHERE proposal_id=?", [$proposalId]) as $item) {
            $upd = self::prepare($item);
            if (!$upd) continue;
            Db::update('proposal_items', $upd, 'id=?', [$item['id']]);
            $done++;
        }
        return $done;
    }

    // ------------------------------------------------------------- частности

    /** «3 мес.», «1 месяц», «2 месяца» — документ читает человек. */
    private static function months(int $n): string {
        $mod100 = $n % 100;
        $mod10 = $n % 10;
        if ($mod100 >= 11 && $mod100 <= 14) return "$n месяцев";
        if ($mod10 === 1) return "$n месяц";
        if ($mod10 >= 2 && $mod10 <= 4) return "$n месяца";
        return "$n месяцев";
    }

    /** 10.0 → «10», 12.5 → «12,5»: в документе не нужен хвост из нулей. */
    private static function percent(float $v): string {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }

    private static function clampPercent(float $v): float {
        return max(0.0, min(100.0, $v));
    }
}
