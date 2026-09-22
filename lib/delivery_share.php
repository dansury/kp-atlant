<?php
/**
 * Delivery folded into item prices (module 045, issue #60).
 *
 * With `KP_DELIVERY_MODE = included` the delivery amount is spread over the
 * positions in proportion to their line sums. The КП file, the КП-as-text
 * letter and the MoySklad invoice all go through here, so the three never
 * name the client different unit prices or totals.
 */
require_once __DIR__ . '/terms.php';

final class DeliveryShare {

    /** Delivery is on for this КП and is to be spread over the positions. */
    public static function included(array $proposal): bool {
        return (int)($proposal['delivery_on'] ?? 0) === 1
            && (float)($proposal['delivery_price'] ?? 0) > 0
            && (string)Settings::get('KP_DELIVERY_MODE', 'included') === 'included';
    }

    /**
     * Per-unit increments that spread $amount over the lines.
     *
     * Increments are rounded to kopecks, so the unit price stays printable.
     * The remainder goes to a line with quantity 1 when there is one (exact to
     * the kopeck), otherwise to the last line.
     *
     * @param list<array{unit:float,qty:float}> $lines
     * @return list<float> same order as $lines; all zeros when nothing to spread over
     */
    public static function perUnit(array $lines, float $amount): array {
        $out = array_fill(0, count($lines), 0.0);
        $subtotal = 0.0;
        foreach ($lines as $l) $subtotal += max(0.0, (float)$l['unit']) * max(0.0, (float)$l['qty']);
        if ($amount <= 0 || $subtotal <= 0) return $out;

        // Where the rounding remainder lands
        $absorber = null;
        foreach ($lines as $i => $l) {
            if ((float)$l['qty'] > 0 && (float)$l['unit'] > 0) $absorber = $i;
        }
        foreach ($lines as $i => $l) {
            if ((float)$l['qty'] == 1.0 && (float)$l['unit'] > 0) $absorber = $i;
        }

        $allocated = 0.0;
        foreach ($lines as $i => $l) {
            if ($i === $absorber) continue;
            $qty = (float)$l['qty'];
            $unit = (float)$l['unit'];
            if ($qty <= 0 || $unit <= 0) continue;
            $out[$i] = round($amount * ($unit * $qty / $subtotal) / $qty, 2);
            $allocated += $out[$i] * $qty;
        }
        $qty = (float)$lines[$absorber]['qty'];
        $out[$absorber] = round(($amount - $allocated) / $qty, 2);
        return $out;
    }

    /**
     * MoySklad positions of a КП — what the invoice and the order bill.
     *
     * Price and discount are the ones the КП printed: the manual discount AND
     * the wait discount, folded into one percentage (the invoice used to drop
     * the wait discount). With delivery included, each position's price grows
     * by its share so the invoice total matches the КП total. With delivery as
     * a separate line, a service position is added when `MS_DELIVERY_SERVICE_ID`
     * names one; otherwise `delivery_missing` says the invoice goes without it.
     *
     * @param array $rows proposal_items rows
     * @return array{positions:list<array>,skipped:list<string>,delivery_missing:bool}
     */
    public static function invoicePositions(array $proposal, array $rows): array {
        $positions = [];
        $skipped = [];
        $billable = [];
        foreach ($rows as $r) {
            $price = (float)$r['price'];
            $qty   = (float)$r['quantity'];
            if (empty($r['moysklad_product_id']) || $price <= 0 || $qty <= 0) {
                $skipped[] = (string)$r['product_name'];
                continue;
            }
            $billable[] = $r;
        }

        $shares = self::included($proposal)
            ? self::perUnit(array_map(fn($r) => ['unit' => Terms::price($r), 'qty' => (float)$r['quantity']], $billable),
                            (float)$proposal['delivery_price'])
            : array_fill(0, count($billable), 0.0);

        foreach ($billable as $i => $r) {
            $discount = Terms::totalDiscount($r);
            $price = (float)$r['price'];
            if ($shares[$i] > 0) {
                // Grow the pre-discount price so that after the same discount
                // the unit costs exactly effective + share
                $effective = Terms::price($r) + $shares[$i];
                $price = $discount > 0 ? round($effective / (1 - $discount / 100), 2) : $effective;
            }
            $positions[] = [
                'product_id' => $r['moysklad_product_id'],
                'quantity'   => (float)$r['quantity'],
                'price'      => $price,
                'discount'   => $discount,
                'vat'        => (int)($r['vat_rate'] ?? $proposal['vat_rate'] ?? 0),
            ];
        }

        $missing = false;
        if ((int)($proposal['delivery_on'] ?? 0) === 1 && (float)($proposal['delivery_price'] ?? 0) > 0
            && !self::included($proposal)) {
            $service = trim((string)Settings::get('MS_DELIVERY_SERVICE_ID', ''));
            if ($service !== '') {
                $positions[] = [
                    'product_id' => $service,
                    'type'       => 'service',
                    'quantity'   => 1,
                    'price'      => (float)$proposal['delivery_price'],
                    'discount'   => 0,
                    'vat'        => (int)($proposal['vat_rate'] ?? 0),
                ];
            } else {
                $missing = true;
            }
        }
        // Nothing to spread over: included delivery cannot reach the invoice
        if (self::included($proposal) && !$billable) $missing = true;

        return ['positions' => $positions, 'skipped' => $skipped, 'delivery_missing' => $missing];
    }
}
