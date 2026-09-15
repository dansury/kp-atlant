<?php
/**
 * Резерв под неоплаченный счёт (модуль 026).
 *
 * Кнопка «Счёт» создаёт в МойСклад заказ покупателя в статусе «Резерв» — товар
 * с этой минуты числится за клиентом и никому больше не предлагается. Клиент
 * при этом может не заплатить: счёт висит, товар лежит, а второй покупатель
 * слышит «нет в наличии». Раньше это замечали случайно, через месяц-другой.
 *
 * Поэтому у резерва есть срок (настройка «Держать резерв, дней», по умолчанию
 * две недели). Он вышел, а счёт не оплачен — менеджер получает напоминание с
 * кнопкой, которая снимает с заказа ПРОВЕДЕНИЕ: резерв уходит, заказ остаётся
 * на месте, и провести его обратно можно одним нажатием в МойСклад.
 *
 * Сам по себе сервис ничего не снимает. «Снять резерв» — это решение про
 * деньги клиента, и принимает его человек.
 */
final class Reserves {

    /** Сколько дней держим резерв, пока счёт не оплачен. 0 — не напоминать. */
    public static function days(): int {
        return max(0, (int)Settings::get('MS_RESERVE_DAYS', 14));
    }

    /**
     * Что сейчас с резервом одного заказа.
     *
     * @param array $order строка `orders` (с `reserve_*` и `applicable`)
     * @return array{held:bool,due:bool,until:?string,released:bool,paid:bool,unpaid:float}
     */
    public static function state(array $order): array {
        $id = (int)($order['id'] ?? 0);
        $released = !empty($order['reserve_released_at']);
        $held = !$released && (int)($order['applicable'] ?? 1) === 1 && !empty($order['reserve_until']);

        [$sum, $payed] = self::invoiceMoney($id);
        // Счёт выставлен и закрыт деньгами — резерв больше не про долг
        $paid = $sum > 0 && $payed + 0.01 >= $sum;

        return [
            'held'     => $held,
            'due'      => $held && !$paid && (string)$order['reserve_until'] <= date('Y-m-d H:i:s'),
            'until'    => $order['reserve_until'] ?? null,
            'released' => $released,
            'paid'     => $paid,
            'unpaid'   => round(max(0.0, $sum - $payed), 2),
        ];
    }

    /** Сколько по счетам заказа выставлено и сколько из этого оплачено. */
    private static function invoiceMoney(int $orderId): array {
        if (!$orderId) return [0.0, 0.0];
        $row = Db::one("SELECT COALESCE(SUM(sum), 0) AS s, COALESCE(SUM(payed_sum), 0) AS p
                        FROM invoices WHERE order_id=?", [$orderId]);
        return [(float)($row['s'] ?? 0), (float)($row['p'] ?? 0)];
    }

    /**
     * Заказы, по которым пора напомнить: срок вышел, счёт не оплачен, резерв
     * ещё держится и мы об этом ещё не говорили.
     *
     * @return array<int,array> строки `orders` вместе с именем компании
     */
    public static function due(): array {
        if (!self::days()) return [];

        // Время берём у PHP, а не у SQLite: `datetime('now')` считает в UTC, а
        // `reserve_until` записано по часовому поясу сервиса — на три часа
        // разницы напоминание опаздывало
        $rows = Db::all(
            "SELECT o.*, c.name AS counterparty_name
             FROM orders o
             LEFT JOIN counterparties c ON c.id = o.counterparty_id
             WHERE o.reserve_until IS NOT NULL
               AND o.reserve_until <= ?
               AND o.reserve_reminded_at IS NULL
               AND o.reserve_released_at IS NULL
               AND COALESCE(o.applicable, 1) = 1
             ORDER BY o.reserve_until", [date('Y-m-d H:i:s')]
        );

        $out = [];
        foreach ($rows as $row) {
            $state = self::state($row);
            if (!$state['due']) continue;
            $out[] = $row + ['reserve' => $state];
        }
        return $out;
    }

    /** Напомнить не повторно: отметка ставится сразу после уведомления. */
    public static function markReminded(int $orderId): void {
        Db::update('orders', ['reserve_reminded_at' => date('Y-m-d H:i:s')], 'id=?', [$orderId]);
    }
}
