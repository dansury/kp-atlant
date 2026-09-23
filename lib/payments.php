<?php
/**
 * Входящие оплаты из Т-Банка (модуль 047): платёж → счёт → «Входящий платёж»
 * в МойСклад → карточка в «Сборку».
 */
require_once __DIR__ . '/tbank.php';
require_once __DIR__ . '/moysklad.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/fulfillment.php';
require_once __DIR__ . '/notifier.php';

final class Payments {

    /** Подмена МойСклад в тестах: fn(string $invoiceMsId, float $sum, array $o): array{id} */
    public static $payIn = null;
    /** fn(array $invoiceRow): ?array — счёт из МойСклад в форме getInvoice() */
    public static $readInvoice = null;
    /** fn(string $account, string $from, string $to): list<array> */
    public static $statement = null;

    /** @return array{read:int,new:int,matched:int,unmatched:int,errors:list<string>,skipped?:bool} */
    public static function check(): array {
        $res = ['read' => 0, 'new' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => []];
        if (!self::$statement && !TBank::configured()) return $res + ['skipped' => true];

        $days = max(1, (int)Settings::get('TBANK_LOOKBACK_DAYS', 3));
        $from = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
        $to   = gmdate('Y-m-d\TH:i:s\Z');
        $accounts = self::$statement ? (TBank::accounts() ?: ['test']) : TBank::accounts();
        foreach ($accounts as $account) {
            try {
                $ops = self::$statement ? (self::$statement)($account, $from, $to) : TBank::statement($account, $from, $to);
            } catch (Throwable $e) {
                $res['errors'][] = "Счёт $account: " . $e->getMessage();
                Logger::warning('bank', 'Выписка Т-Банка не прочиталась: ' . $e->getMessage(), ['account' => $account]);
                continue;
            }
            foreach ($ops as $op) {
                $res['read']++;
                if (Db::val("SELECT 1 FROM bank_payments WHERE operation_id=?", [$op['id']])) continue;
                $res['new']++;
                $status = self::record($op);
                if ($status === 'unmatched') $res['unmatched']++; else $res['matched']++;
            }
        }
        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('tbank_last_check', ?)",
              [json_encode($res + ['at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)]);
        return $res;
    }

    /** Номера счетов из назначения платежа, без ведущих нулей. */
    public static function invoiceNumbers(string $purpose): array {
        $out = [];
        $re = '/(?<![\p{L}])сч(?:[её]т(?:а|у|ом|ам|ов)?|\.)?\s*(?:на\s+оплату\s*)?(?:№|N\s|No\.?|#)?\s*(\d[\d\-\/]*)/iu';
        if (preg_match_all($re, $purpose, $m)) {
            foreach ($m[1] as $n) {
                $n = ltrim(rtrim($n, '-/'), '0');
                if ($n !== '') $out[$n] = true;
            }
        }
        // Числовые ключи PHP превращает в int — номер счёта остаётся строкой
        return array_map('strval', array_keys($out));
    }

    /** Счёт, который оплачен этой операцией, или null. */
    public static function match(array $op): ?array {
        $amount = (float)$op['amount'];
        $inn = (string)($op['payer_inn'] ?? '');

        $nums = self::invoiceNumbers((string)($op['purpose'] ?? ''));
        if ($nums) {
            $in = implode(',', array_fill(0, count($nums), '?'));
            $rows = Db::all(
                "SELECT i.*, c.inn AS cp_inn FROM invoices i LEFT JOIN counterparties c ON c.id = i.counterparty_id
                 WHERE ltrim(i.name, '0') IN ($in) AND i.sum >= ?
                 ORDER BY (COALESCE(i.payed_sum, 0) < i.sum) DESC, i.id DESC", [...$nums, $amount - 1]);
            foreach ($rows as $r) {
                $cpInn = preg_replace('/\D/', '', (string)($r['cp_inn'] ?? ''));
                if ($inn !== '' && $cpInn !== '' && $cpInn !== $inn) continue;
                return $r;
            }
        }

        if ($inn === '') return null;
        $cps = array_map('intval', array_column(Db::all("SELECT id FROM counterparties WHERE inn=?", [$inn]), 'id'));
        if (!$cps) return null;
        $in = implode(',', array_fill(0, count($cps), '?'));
        $cps = array_merge($cps, array_map('intval', array_column(
            Db::all("SELECT id FROM counterparties WHERE merged_into_id IN ($in)", $cps), 'id')));
        $in = implode(',', array_fill(0, count($cps), '?'));
        $rows = Db::all(
            "SELECT * FROM invoices WHERE counterparty_id IN ($in)
               AND ABS(sum - ?) < 0.01 AND COALESCE(payed_sum, 0) < sum - 0.01", [...$cps, $amount]);
        return count($rows) === 1 ? $rows[0] : null;
    }

    /** Записать операцию и провести её дальше. @return string matched|unmatched|error */
    public static function record(array $op): string {
        $inv = self::match($op);
        $rowId = Db::insert('bank_payments', [
            'operation_id'    => $op['id'],
            'account'         => $op['account'] ?? null,
            'operation_date'  => $op['date'] ?? null,
            'amount'          => (float)$op['amount'],
            'payer_inn'       => ($op['payer_inn'] ?? '') ?: null,
            'payer_name'      => ($op['payer_name'] ?? '') ?: null,
            'purpose'         => ($op['purpose'] ?? '') ?: null,
            'doc_number'      => ($op['number'] ?? '') ?: null,
            'invoice_id'      => $inv ? (int)$inv['id'] : null,
            'order_id'        => $inv && $inv['order_id'] ? (int)$inv['order_id'] : null,
            'counterparty_id' => $inv && $inv['counterparty_id'] ? (int)$inv['counterparty_id'] : null,
            'status'          => $inv ? 'matched' : 'unmatched',
        ]);
        $money = number_format((float)$op['amount'], 2, ',', ' ') . ' ₽';

        if (!$inv) {
            Notifier::notify('payment_unmatched', 'Поступила оплата — счёт не найден',
                             trim(($op['payer_name'] ?? '') . ", $money. " . ($op['purpose'] ?? '')));
            return 'unmatched';
        }

        $status = 'matched';
        $before = (float)($inv['payed_sum'] ?? 0);
        // Платёж в МойСклад — только пока счёт там не оплачен: второй платёж
        // на те же деньги испортит взаиморасчёты
        if ((int)Settings::get('TBANK_CREATE_PAYMENTIN', 1) === 1 && $before < (float)$inv['sum'] - 0.01) {
            try {
                $opts = ['purpose' => $op['purpose'] ?? '', 'number' => $op['number'] ?? '', 'date' => $op['date'] ?? ''];
                $pay = self::$payIn ? (self::$payIn)((string)$inv['moysklad_id'], (float)$op['amount'], $opts)
                                    : self::msPayIn((string)$inv['moysklad_id'], (float)$op['amount'], $opts);
                Db::update('bank_payments', ['moysklad_payment_id' => $pay['id'] ?? null], 'id=?', [$rowId]);
            } catch (Throwable $e) {
                $status = 'error';
                Db::update('bank_payments', ['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500)], 'id=?', [$rowId]);
                Logger::warning('bank', 'Входящий платёж в МойСклад не создан: ' . $e->getMessage(), ['invoice_id' => $inv['id']]);
                Notifier::notify('payment_error', "Оплата счёта {$inv['name']}: платёж в МойСклад не создан",
                                 $e->getMessage(), 'counterparty', $inv['counterparty_id'] ? (int)$inv['counterparty_id'] : null);
            }
        }

        // Сумма оплаты — из МойСклад, а не догадкой; не ответил — считаем сами
        $paid = min((float)$inv['sum'], $before + (float)$op['amount']);
        try {
            $fresh = self::$readInvoice ? (self::$readInvoice)($inv) : self::msInvoice((string)$inv['moysklad_id']);
            if ($fresh) $paid = max($paid, (float)$fresh['payed_sum']);
        } catch (Throwable $e) {
            Logger::warning('bank', 'Счёт не перечитался из МойСклад: ' . $e->getMessage(), ['invoice_id' => $inv['id']]);
        }
        Db::update('invoices', ['payed_sum' => $paid], 'id=?', [(int)$inv['id']]);

        if ($paid >= (float)$inv['sum'] - 0.01) Fulfillment::invoicePaid((int)$inv['id'], 'bank');
        return $status;
    }

    private static function msPayIn(string $invoiceMsId, float $sum, array $o): array {
        MsSync::init();
        return MoySklad::createPaymentIn($invoiceMsId, $sum, $o);
    }

    private static function msInvoice(string $invoiceMsId): ?array {
        MsSync::init();
        return MoySklad::getInvoice($invoiceMsId);
    }
}
