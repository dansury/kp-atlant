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
 * три рабочих дня — issue #150), и клиент слышит о нём в письме со счётом. Он вышел, а счёт не оплачен — менеджер получает напоминание с
 * кнопкой, которая снимает с заказа ПРОВЕДЕНИЕ: резерв уходит, заказ остаётся
 * на месте, и провести его обратно можно одним нажатием в МойСклад.
 *
 * Сам по себе сервис ничего не снимает. «Снять резерв» — это решение про
 * деньги клиента, и принимает его человек.
 */
final class Reserves {

    /** Сколько дней держим резерв, пока счёт не оплачен. 0 — не напоминать. */
    public static function days(): int {
        return max(0, (int)Settings::get('MS_RESERVE_DAYS', 3));
    }

    /** Дни резерва — рабочие: суббота и воскресенье не считаются (issue #150). */
    public static function businessDays(): bool {
        return (string)Settings::get('MS_RESERVE_BUSINESS', '1') === '1';
    }

    /** До какого момента держим резерв, выставленный в $from. null — не держим. */
    public static function until(?int $from = null): ?string {
        $days = self::days();
        if (!$days) return null;
        $t = $from ?? time();
        if (!self::businessDays()) return date('Y-m-d H:i:s', $t + $days * 86400);
        // Счёт в пятницу вечером — резерв до среды, а не до понедельника
        for ($left = $days; $left > 0;) {
            $t += 86400;
            if ((int)date('N', $t) <= 5) $left--;
        }
        return date('Y-m-d H:i:s', $t);
    }

    /** «3 рабочих дня», «5 рабочих дней», «14 дней». */
    public static function daysLabel(?int $n = null): string {
        $n = $n ?? self::days();
        $m10 = $n % 10; $m100 = $n % 100;
        $form = ($m10 === 1 && $m100 !== 11) ? 0 : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? 1 : 2);
        $word = ['день', 'дня', 'дней'][$form];
        if (!self::businessDays()) return "$n $word";
        return "$n " . ['рабочий', 'рабочих', 'рабочих'][$form] . " $word";
    }

    /** Абзац письма со счётом: на сколько держим резерв и что будет дальше. '' — не писать. */
    public static function letterNote(): string {
        if (!self::days()) return '';
        $tpl = trim((string)Settings::get('MS_RESERVE_LETTER', ''));
        return $tpl === '' ? '' : str_replace('{days}', self::daysLabel(), $tpl);
    }

    /** Дописать абзац в письмо один раз: второй раз он там уже есть. */
    public static function appendToLetter(string $text, string $note): string {
        $note = trim($note);
        if ($note === '' || self::contains($text, $note)) return $text;
        return rtrim($text) . "\n\n" . $note;
    }

    /** Абзац уже в тексте — сравниваем без пробелов и регистра: письмо могли переформатировать. */
    public static function contains(string $text, string $note): bool {
        $norm = fn(string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
        $head = mb_substr($norm($note), 0, 60);
        return $head !== '' && str_contains($norm($text), $head);
    }

    /** Абзац для ответа по запросу: у запроса есть заказ с резервом и неоплаченным счётом. */
    public static function noteForRequest(int $requestId): string {
        if (!$requestId) return '';
        foreach (Db::all("SELECT * FROM orders WHERE request_id=? OR proposal_id IN
                              (SELECT id FROM proposals WHERE request_id=?)", [$requestId, $requestId]) as $o) {
            if (self::holdsUnpaid($o)) return self::letterNote();
        }
        return '';
    }

    /** Абзац к счёту, который прикладывают к письму. */
    public static function noteForInvoice(int $invoiceId): string {
        $o = Db::one("SELECT o.* FROM invoices i JOIN orders o ON o.id = i.order_id WHERE i.id=?", [$invoiceId]);
        return $o && self::holdsUnpaid($o) ? self::letterNote() : '';
    }

    private static function holdsUnpaid(array $order): bool {
        $s = self::state($order);
        return $s['held'] && !$s['paid'];
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
