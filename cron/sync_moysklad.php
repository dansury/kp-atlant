<?php
/**
 * Cron: catch-up sync with MoySklad (C-011).
 * Runs every 5 minutes and refreshes companies with recent activity,
 * so a missed webhook never leaves the CRM stale. Usage: php cron/sync_moysklad.php
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/sync.php';
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/bitrix.php';

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
        // Реквизиты покупателя и его договор — то, чем КП его адресует
        if ((int)Settings::get('REQUISITES_AUTOSYNC', 1) === 1) {
            Requisites::syncCounterparty((int)$cp['id']);
        }
    } catch (Throwable $e) {
        Logger::exception('moysklad', $e, ['counterparty_id' => $cp['id']]);
    }
}

// Наши собственные реквизиты, НДС и банк — из организации в МойСклад
// (module 013). КП фиксирует их у себя, но обновляться они должны сами.
if ((int)Settings::get('REQUISITES_AUTOSYNC', 1) === 1) {
    try {
        MoySklad::init((string)Settings::get('MOYSKLAD_TOKEN', ''));
        Requisites::syncOrganization();
    } catch (Throwable $e) {
        Logger::exception('moysklad', $e, ['stage' => 'requisites']);
    }
}

// Ссылки на товары на сайте греются здесь, а не при генерации КП: документ
// не должен ждать ответа Битрикса.
$urls = 0;
try {
    $urls = Bitrix::refreshUrls(50);
} catch (Throwable $e) {
    Logger::exception('bitrix', $e, ['stage' => 'urls']);
}

echo "Synced " . count($rows) . " companies: $totalOrders orders, $totalInvoices invoices, $urls site links\n";
