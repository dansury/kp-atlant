<?php
/**
 * Cron: catch-up sync with MoySklad (C-011).
 * Runs every 5 minutes and refreshes companies with recent activity,
 * so a missed webhook never leaves the CRM stale. Usage: php cron/sync_moysklad.php
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/sync.php';

$limit = (int)($argv[1] ?? 25);

// Companies with an order or invoice touched in the last 30 days, stalest first
$rows = Db::all(
    "SELECT DISTINCT c.id, c.name, c.updated_at
     FROM counterparties c
     WHERE c.merged_into_id IS NULL AND (
        EXISTS (SELECT 1 FROM orders o WHERE o.counterparty_id=c.id AND o.created_at > datetime('now','-30 days'))
        OR EXISTS (SELECT 1 FROM invoices i WHERE i.counterparty_id=c.id AND i.created_at > datetime('now','-30 days'))
     )
     ORDER BY c.updated_at ASC
     LIMIT ?",
    [$limit]
);

$totalOrders = 0;
$totalInvoices = 0;

foreach ($rows as $cp) {
    try {
        $res = MsSync::syncCompany((int)$cp['id']);
        $totalOrders += $res['orders'];
        $totalInvoices += $res['invoices'];
    } catch (Throwable $e) {
        Logger::exception('moysklad', $e, ['counterparty_id' => $cp['id']]);
    }
}

echo "Synced " . count($rows) . " companies: $totalOrders orders, $totalInvoices invoices\n";
