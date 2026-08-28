<?php
/**
 * MoySklad → CRM projection: orders, invoices, invoice printforms, webhooks.
 * Module 002 (FR-027..FR-031). MoySklad stays the source of truth for documents.
 */
require_once __DIR__ . '/moysklad.php';
require_once __DIR__ . '/crm.php';

class MsSync {

    private static bool $inited = false;

    public static function init(): void {
        if (self::$inited) return;
        MoySklad::init($GLOBALS['cfg']['MOYSKLAD_TOKEN'] ?? '');
        self::$inited = true;
    }

    // Local company card for a MoySklad counterparty
    public static function localCounterparty(string $msAgentId): ?int {
        if (!$msAgentId) return null;
        $row = Db::one("SELECT id FROM counterparties WHERE moysklad_id=?", [$msAgentId]);
        if ($row) return Crm::rootId((int)$row['id']);

        // Not linked yet — pull the counterparty and match by INN / name
        $agent = MoySklad::getCounterparty($msAgentId);
        if (!$agent) return null;

        $id = Crm::resolveCounterparty([
            'inn'   => $agent['inn'] ?? '',
            'name'  => $agent['name'] ?? '',
            'email' => $agent['email'] ?? '',
            'phone' => $agent['phone'] ?? '',
        ]);
        if ($id) Db::update('counterparties', ['moysklad_id' => $msAgentId], 'id=?', [$id]);
        return $id;
    }

    /**
     * Pull one order from MoySklad into the local projection.
     * $links: request_id, proposal_id, counterparty_id, manager_id (used on first insert).
     */
    public static function upsertOrder(string $msOrderId, array $links = []): ?int {
        self::init();
        $o = MoySklad::getOrder($msOrderId);
        if (!$o) return null;

        $cpId = $links['counterparty_id'] ?? self::localCounterparty($o['agent_id'] ?? '');
        $existing = Db::one("SELECT * FROM orders WHERE moysklad_id=?", [$msOrderId]);
        $now = date('Y-m-d H:i:s');

        $fields = [
            'name'                => $o['name'],
            'moment'              => $o['moment'],
            'sum'                 => $o['sum'],
            'state_name'          => $o['state_name'],
            'description'         => $o['description'],
            'positions_json'      => json_encode($o['positions'], JSON_UNESCAPED_UNICODE),
            'moysklad_updated_at' => $o['updated'],
            'synced_at'           => $now,
        ];

        if ($existing) {
            if ($cpId && empty($existing['counterparty_id'])) $fields['counterparty_id'] = $cpId;
            Db::update('orders', $fields, 'id=?', [$existing['id']]);
            $localId = (int)$existing['id'];
        } else {
            $localId = Db::insert('orders', array_merge($fields, [
                'moysklad_id'     => $msOrderId,
                'counterparty_id' => $cpId,
                'request_id'      => $links['request_id'] ?? null,
                'proposal_id'     => $links['proposal_id'] ?? null,
                'manager_id'      => $links['manager_id'] ?? null,
            ]));

            Crm::logEvent($cpId, 'note', "Заказ {$o['name']} создан в МойСклад на сумму " . number_format($o['sum'], 2, ',', ' ') . ' ₽', [
                'request_id' => $links['request_id'] ?? null,
                'manager_id' => $links['manager_id'] ?? null,
                'event_type' => 'order_created',
                'subject'    => 'Заказ ' . $o['name'],
                'meta'       => ['order_id' => $localId, 'moysklad_id' => $msOrderId, 'url' => MoySklad::orderUrl($msOrderId)],
            ]);
        }

        return $localId;
    }

    // Pull invoices issued against a local order (FR-030)
    public static function syncInvoicesForOrder(int $localOrderId): int {
        self::init();
        $order = Db::one("SELECT * FROM orders WHERE id=?", [$localOrderId]);
        if (!$order) return 0;

        $invoices = MoySklad::getInvoicesByOrder($order['moysklad_id']);
        $count = 0;
        foreach ($invoices as $inv) {
            self::upsertInvoice($inv, $localOrderId, (int)$order['counterparty_id']);
            $count++;
        }
        return $count;
    }

    // Upsert one invoice; fetches the printable PDF on first sight (C-013)
    public static function upsertInvoice(array $inv, ?int $localOrderId, ?int $cpId): int {
        $existing = Db::one("SELECT * FROM invoices WHERE moysklad_id=?", [$inv['id']]);
        $now = date('Y-m-d H:i:s');

        $fields = [
            'name'                => $inv['name'],
            'moment'              => $inv['moment'],
            'sum'                 => $inv['sum'],
            'payed_sum'           => $inv['payed_sum'],
            'state_name'          => $inv['state_name'],
            'moysklad_updated_at' => $inv['updated'] ?? null,
            'synced_at'           => $now,
        ];

        if ($existing) {
            if ($localOrderId && empty($existing['order_id'])) $fields['order_id'] = $localOrderId;
            if ($cpId && empty($existing['counterparty_id'])) $fields['counterparty_id'] = $cpId;
            Db::update('invoices', $fields, 'id=?', [$existing['id']]);
            $id = (int)$existing['id'];
        } else {
            $id = Db::insert('invoices', array_merge($fields, [
                'moysklad_id'     => $inv['id'],
                'order_id'        => $localOrderId,
                'counterparty_id' => $cpId,
            ]));

            Crm::logEvent($cpId, 'note', "Счёт {$inv['name']} выставлен в МойСклад на сумму " . number_format($inv['sum'], 2, ',', ' ') . ' ₽', [
                'event_type' => 'invoice_created',
                'subject'    => 'Счёт ' . $inv['name'],
                'meta'       => ['invoice_id' => $id, 'moysklad_id' => $inv['id'], 'url' => MoySklad::invoiceUrl($inv['id'])],
            ]);
        }

        self::ensureInvoicePdf($id);
        return $id;
    }

    // Download and cache the invoice printform. Returns absolute path or null.
    public static function ensureInvoicePdf(int $invoiceId): ?string {
        self::init();
        $inv = Db::one("SELECT * FROM invoices WHERE id=?", [$invoiceId]);
        if (!$inv) return null;

        $abs = $inv['pdf_path'] ? ROOT . '/' . $inv['pdf_path'] : null;
        if ($abs && is_file($abs) && filesize($abs) > 0) return $abs;

        $pdf = MoySklad::exportInvoicePdf($inv['moysklad_id']);
        if ($pdf === null) return null;

        $dir = ROOT . '/storage/invoices';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $rel = 'storage/invoices/' . $inv['moysklad_id'] . '.pdf';
        file_put_contents(ROOT . '/' . $rel, $pdf);
        Db::update('invoices', ['pdf_path' => $rel], 'id=?', [$invoiceId]);
        return ROOT . '/' . $rel;
    }

    /**
     * Refresh everything MoySklad knows about one company (FR-030).
     * Called when the card is opened and when the tab regains focus.
     */
    public static function syncCompany(int $counterpartyId): array {
        self::init();
        $cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$counterpartyId]);
        if (!$cp) return ['orders' => 0, 'invoices' => 0];

        $orders = 0;
        $invoices = 0;

        // Known local orders first — cheap and always relevant
        foreach (Db::all("SELECT moysklad_id FROM orders WHERE counterparty_id=?", [$counterpartyId]) as $row) {
            if (self::upsertOrder($row['moysklad_id'], ['counterparty_id' => $counterpartyId])) $orders++;
        }

        // Then anything created directly in MoySklad for this company
        if (!empty($cp['moysklad_id'])) {
            foreach (MoySklad::getOrdersByCounterparty($cp['moysklad_id'], 180) as $o) {
                if (!Db::one("SELECT id FROM orders WHERE moysklad_id=?", [$o['id']])) {
                    if (self::upsertOrder($o['id'], ['counterparty_id' => $counterpartyId])) $orders++;
                }
            }
            foreach (MoySklad::getInvoicesByCounterparty($cp['moysklad_id']) as $inv) {
                $localOrder = $inv['order_id']
                    ? Db::one("SELECT id FROM orders WHERE moysklad_id=?", [$inv['order_id']])
                    : null;
                self::upsertInvoice($inv, $localOrder ? (int)$localOrder['id'] : null, $counterpartyId);
                $invoices++;
            }
        }

        // Invoices for orders we created from CRM (company may not be linked yet)
        foreach (Db::all("SELECT id FROM orders WHERE counterparty_id=?", [$counterpartyId]) as $row) {
            $invoices += self::syncInvoicesForOrder((int)$row['id']);
        }

        Db::update('counterparties', ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$counterpartyId]);
        return ['orders' => $orders, 'invoices' => $invoices];
    }

    // Register the webhooks this module needs (FR-029). Idempotent.
    public static function ensureWebhooks(): array {
        self::init();
        $appUrl = rtrim($GLOBALS['cfg']['APP_URL'] ?? '', '/');
        if (!$appUrl || !str_starts_with($appUrl, 'https://')) {
            return ['ok' => false, 'error' => 'APP_URL должен быть публичным https-адресом'];
        }

        $secret = (string)Db::val("SELECT value FROM settings WHERE key='moysklad_webhook_secret'");
        $url = "$appUrl/api/moysklad_hook.php?secret=" . urlencode($secret);

        $wanted = [
            ['customerorder', 'CREATE'],
            ['customerorder', 'UPDATE'],
            ['invoiceout', 'CREATE'],
            ['invoiceout', 'UPDATE'],
        ];

        try {
            $existing = MoySklad::listWebhooks();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Нет прав на вебхуки: ' . $e->getMessage()];
        }

        $created = 0;
        foreach ($wanted as [$entity, $action]) {
            $found = false;
            foreach ($existing as $w) {
                if ($w['entityType'] === $entity && $w['action'] === $action && $w['url'] === $url) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                try {
                    MoySklad::createWebhook($url, $entity, $action);
                    $created++;
                } catch (Throwable $e) {
                    return ['ok' => false, 'error' => "Не удалось создать вебхук $entity/$action: " . $e->getMessage(), 'created' => $created];
                }
            }
        }

        return ['ok' => true, 'created' => $created, 'url' => $url];
    }

    // Remove this installation's webhooks
    public static function removeWebhooks(): int {
        self::init();
        $secret = (string)Db::val("SELECT value FROM settings WHERE key='moysklad_webhook_secret'");
        $removed = 0;
        foreach (MoySklad::listWebhooks() as $w) {
            if (str_contains($w['url'], $secret)) {
                if (MoySklad::deleteWebhook($w['id'])) $removed++;
            }
        }
        return $removed;
    }
}
