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
            // Проведён ли заказ — он же «держим ли резерв» (модуль 026)
            'applicable'          => !empty($o['applicable']) ? 1 : 0,
        ];

        if ($existing) {
            if ($cpId && empty($existing['counterparty_id'])) $fields['counterparty_id'] = $cpId;
            Db::update('orders', $fields, 'id=?', [$existing['id']]);
            $localId = (int)$existing['id'];
        } else {
            try {
                $localId = Db::insert('orders', array_merge($fields, [
                    'moysklad_id'     => $msOrderId,
                    'counterparty_id' => $cpId,
                    'request_id'      => $links['request_id'] ?? null,
                    'proposal_id'     => $links['proposal_id'] ?? null,
                    'manager_id'      => $links['manager_id'] ?? null,
                    // На какую организацию карточки заказ, если их несколько (модуль 029)
                    'org_id'          => $links['org_id'] ?? null,
                ]));
            } catch (PDOException $e) {
                // МойСклад шлёт вебхук дважды на один и тот же заказ, и второй
                // приходит, пока первый ещё пишет строку: оба видят «заказа
                // нет» и оба вставляют. Гонка кончалась падением «UNIQUE
                // constraint failed: orders.moysklad_id» в журнале (модуль 034).
                // Заказ уже есть — значит, работа сделана, и это не ошибка.
                if (!self::isDuplicate($e)) throw $e;
                $row = Db::one("SELECT id FROM orders WHERE moysklad_id=?", [$msOrderId]);
                if (!$row) throw $e;
                Db::update('orders', $fields, 'id=?', [(int)$row['id']]);
                return (int)$row['id'];
            }

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

    /** Нарушение уникальности — не ошибка синхронизации, а «уже записано». */
    private static function isDuplicate(PDOException $e): bool {
        return str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || ($e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate entry'));
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
    /** Документ моложе стольких дней двигает карточку по стадиям (issue #119). */
    public const FRESH_DAYS = 14;

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
            // Оплату внесли в МойСклад руками — карточка едет в «Сборку» так же,
            // как по выписке банка. Только переход «не оплачен → оплачен»: старые
            // оплаченные счета при первой синхронизации никуда не двигают (модуль 047)
            $sum = (float)$inv['sum'];
            if ($sum > 0 && (float)$existing['payed_sum'] < $sum - 0.01 && (float)$inv['payed_sum'] >= $sum - 0.01) {
                require_once __DIR__ . '/fulfillment.php';
                Fulfillment::invoicePaid($id, 'moysklad');
            }
        } else {
            try {
                $id = Db::insert('invoices', array_merge($fields, [
                    'moysklad_id'     => $inv['id'],
                    'order_id'        => $localOrderId,
                    'counterparty_id' => $cpId,
                ]));
            } catch (PDOException $e) {
                // Тот же двойной вебхук, что и у заказов (модуль 034)
                if (!self::isDuplicate($e)) throw $e;
                $row = Db::one("SELECT id FROM invoices WHERE moysklad_id=?", [$inv['id']]);
                if (!$row) throw $e;
                Db::update('invoices', $fields, 'id=?', [(int)$row['id']]);
                self::ensureInvoicePdf((int)$row['id']);
                return (int)$row['id'];
            }

            Crm::logEvent($cpId, 'note', "Счёт {$inv['name']} выставлен в МойСклад на сумму " . number_format($inv['sum'], 2, ',', ' ') . ' ₽', [
                'event_type' => 'invoice_created',
                'subject'    => 'Счёт ' . $inv['name'],
                'meta'       => ['invoice_id' => $id, 'moysklad_id' => $inv['id'], 'url' => MoySklad::invoiceUrl($inv['id'])],
            ]);
            // Счёт выставлен — карточка ждёт оплату (issue #119). Старые счета,
            // которые первая синхронизация поднимает из истории, её не двигают
            $moment = strtotime((string)($inv['moment'] ?? '')) ?: time();
            if ($cpId && $moment >= time() - self::FRESH_DAYS * 86400) {
                require_once __DIR__ . '/boards.php';
                Boards::advance($cpId, null, 'payment');
            }
        }

        self::ensureInvoicePdf($id);
        return $id;
    }

    /** Документы, у которых есть печатная форма: вид → [сущность МойСклад, таблица, как назвать]. */
    public const PRINTABLE = [
        'invoice' => ['invoiceout',    'invoices',      'Счёт'],
        'order'   => ['customerorder', 'orders',        'Заказ'],
        'demand'  => ['demand',        'order_demands', 'Отгрузка'],
    ];

    /**
     * Печатная форма документа сделки (issue #119): 👁 в ленте и «В письмо».
     * Счёт — как раньше; заказ и отгрузка печатаются первым шаблоном МойСклад
     * и кладутся в `storage/docs/`, чтобы второй показ не ждал МойСклад.
     *
     * @return array{path:?string, name:string, error:string}
     */
    public static function docPdf(string $doc, int $id): array {
        if (!isset(self::PRINTABLE[$doc])) return ['path' => null, 'name' => '', 'error' => 'У этого документа нет печатной формы'];
        [$entity, $table, $label] = self::PRINTABLE[$doc];
        $row = Db::one("SELECT * FROM $table WHERE id=?", [$id]);
        if (!$row) return ['path' => null, 'name' => '', 'error' => 'Документ не найден'];
        $name = trim($label . ' ' . (string)($row['name'] ?? '')) . '.pdf';
        if ($doc === 'invoice') {
            $path = self::ensureInvoicePdf($id);
            return ['path' => $path, 'name' => $name, 'error' => $path ? '' : MoySklad::lastExportError()];
        }
        $rel = 'storage/docs/' . $doc . '-' . preg_replace('/[^\w-]/', '', (string)$row['moysklad_id']) . '.pdf';
        if (is_file(ROOT . '/' . $rel) && filesize(ROOT . '/' . $rel) > 0) return ['path' => ROOT . '/' . $rel, 'name' => $name, 'error' => ''];
        self::init();
        $pdf = self::$fetchPdf ? (self::$fetchPdf)((string)$row['moysklad_id'])
                               : MoySklad::exportPdf($entity, (string)$row['moysklad_id']);
        if ($pdf === null) return ['path' => null, 'name' => $name, 'error' => MoySklad::lastExportError()];
        if (!is_dir(ROOT . '/storage/docs')) mkdir(ROOT . '/storage/docs', 0755, true);
        file_put_contents(ROOT . '/' . $rel, $pdf);
        return ['path' => ROOT . '/' . $rel, 'name' => $name, 'error' => ''];
    }

    /** Подмена печати счёта в тестах: fn(string $msInvoiceId): ?string */
    public static $fetchPdf = null;

    // Download and cache the invoice printform. Returns absolute path or null.
    public static function ensureInvoicePdf(int $invoiceId): ?string {
        self::init();
        $inv = Db::one("SELECT * FROM invoices WHERE id=?", [$invoiceId]);
        if (!$inv) return null;

        $abs = $inv['pdf_path'] ? ROOT . '/' . $inv['pdf_path'] : null;
        if ($abs && is_file($abs) && filesize($abs) > 0) return $abs;

        $pdf = self::$fetchPdf ? (self::$fetchPdf)((string)$inv['moysklad_id'])
                               : MoySklad::exportInvoicePdf($inv['moysklad_id']);
        if ($pdf === null) {
            Logger::warning('moysklad', 'Печатная форма счёта не получена: ' . MoySklad::lastExportError(),
                            ['invoice_id' => $invoiceId]);
            return null;
        }

        $dir = ROOT . '/storage/invoices';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $rel = 'storage/invoices/' . $inv['moysklad_id'] . '.pdf';
        file_put_contents(ROOT . '/' . $rel, $pdf);
        Db::update('invoices', ['pdf_path' => $rel], 'id=?', [$invoiceId]);
        return ROOT . '/' . $rel;
    }

    /**
     * Привязать к карточке заказ и/или счёт МойСклад по номеру (issue #111).
     *
     * Документ заведён в МойСклад на другого контрагента, на дубль или без
     * письма — сервис сам его к компании не отнесёт. Привязка ручная и потому
     * сильнее автоматической: компания документа меняется на эту.
     *
     * @return array{order:?array, invoice:?array, errors:list<string>}
     */
    public static function linkDocuments(int $cpId, string $orderNo, string $invoiceNo, ?int $requestId = null, ?int $managerId = null): array {
        self::init();
        $cpId = Crm::rootId($cpId);
        $res = ['order' => null, 'invoice' => null, 'errors' => []];
        $links = ['counterparty_id' => $cpId, 'request_id' => $requestId, 'manager_id' => $managerId];

        if (trim($orderNo) !== '') {
            $msId = MoySklad::findByName('customerorder', $orderNo);
            if (!$msId) {
                $res['errors'][] = "Заказ «{$orderNo}» в МойСклад не найден";
            } elseif ($localId = self::upsertOrder($msId, $links)) {
                $upd = ['counterparty_id' => $cpId];
                if ($requestId && !Db::val("SELECT request_id FROM orders WHERE id=?", [$localId])) $upd['request_id'] = $requestId;
                Db::update('orders', $upd, 'id=?', [$localId]);
                self::syncInvoicesForOrder($localId);
                Db::q("UPDATE invoices SET counterparty_id=? WHERE order_id=?", [$cpId, $localId]);
                $o = Db::one("SELECT id, name, sum FROM orders WHERE id=?", [$localId]);
                $res['order'] = $o;
                Crm::logEvent($cpId, 'note', "Заказ {$o['name']} привязан к карточке вручную", [
                    'event_type' => 'order_linked', 'subject' => 'Заказ ' . $o['name'], 'manager_id' => $managerId,
                    'meta' => ['order_id' => $localId, 'moysklad_id' => $msId, 'url' => MoySklad::orderUrl($msId)],
                ]);
            }
        }

        if (trim($invoiceNo) !== '') {
            $msId = MoySklad::findByName('invoiceout', $invoiceNo);
            $inv = $msId ? MoySklad::getInvoice($msId) : null;
            if (!$inv) {
                $res['errors'][] = "Счёт «{$invoiceNo}» в МойСклад не найден";
            } else {
                $localOrder = null;
                if (!empty($inv['order_id'])) {
                    $localOrder = self::upsertOrder((string)$inv['order_id'], $links);
                    if ($localOrder) Db::update('orders', ['counterparty_id' => $cpId], 'id=?', [$localOrder]);
                }
                $id = self::upsertInvoice($inv, $localOrder, $cpId);
                Db::update('invoices', ['counterparty_id' => $cpId], 'id=?', [$id]);
                if ($localOrder) Db::q("UPDATE invoices SET order_id=? WHERE id=? AND order_id IS NULL", [$localOrder, $id]);
                $row = Db::one("SELECT id, name, sum FROM invoices WHERE id=?", [$id]);
                $res['invoice'] = $row;
                Crm::logEvent($cpId, 'note', "Счёт {$row['name']} привязан к карточке вручную", [
                    'event_type' => 'invoice_linked', 'subject' => 'Счёт ' . $row['name'], 'manager_id' => $managerId,
                    'meta' => ['invoice_id' => $id, 'moysklad_id' => $msId, 'url' => MoySklad::invoiceUrl($msId)],
                ]);
            }
        }
        return $res;
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

    // Webhooks this module needs (FR-029)
    private const WANTED_HOOKS = [
        ['customerorder', 'CREATE'],
        ['customerorder', 'UPDATE'],
        ['invoiceout', 'CREATE'],
        ['invoiceout', 'UPDATE'],
    ];

    // Endpoint path used to recognise our own hooks regardless of the secret
    public const HOOK_PATH = '/api/moysklad_hook.php';

    public static function webhookUrl(): string {
        $appUrl = rtrim($GLOBALS['cfg']['APP_URL'] ?? '', '/');
        $secret = (string)Db::val("SELECT value FROM settings WHERE key='moysklad_webhook_secret'");
        return $appUrl . self::HOOK_PATH . '?secret=' . urlencode($secret);
    }

    // Our hooks in MoySklad — matched by endpoint, so hooks left over from an
    // older secret are found instead of being silently duplicated.
    public static function ourWebhooks(): array {
        $wanted = self::webhookUrl();
        $out = [];
        foreach (MoySklad::listWebhooks() as $w) {
            if (!str_contains((string)$w['url'], self::HOOK_PATH)) continue;
            $w['current'] = ($w['url'] === $wanted) && !empty($w['enabled']);
            $out[] = $w;
        }
        return $out;
    }

    // Register the webhooks this module needs (FR-029). Idempotent.
    public static function ensureWebhooks(): array {
        self::init();
        $appUrl = rtrim($GLOBALS['cfg']['APP_URL'] ?? '', '/');
        if (!$appUrl || !str_starts_with($appUrl, 'https://')) {
            return ['ok' => false, 'error' => 'APP_URL должен быть публичным https-адресом'];
        }

        $url = self::webhookUrl();

        try {
            $existing = self::ourWebhooks();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Нет доступа к вебхукам: ' . $e->getMessage()];
        }

        $created = 0; $updated = 0; $kept = 0; $errors = [];

        foreach (self::WANTED_HOOKS as [$entity, $action]) {
            // Prefer an exact match, otherwise reuse a stale hook on the same endpoint
            $exact = null; $stale = null;
            foreach ($existing as $w) {
                if ($w['entityType'] !== $entity || $w['action'] !== $action) continue;
                if (!empty($w['current'])) { $exact = $w; break; }
                $stale = $stale ?? $w;
            }

            if ($exact) { $kept++; continue; }

            try {
                if ($stale) {
                    MoySklad::updateWebhook($stale['id'], $url, true);
                    $updated++;
                } else {
                    MoySklad::createWebhook($url, $entity, $action);
                    $created++;
                }
            } catch (Throwable $e) {
                // MoySklad refusing a duplicate means the hook is already in place
                $msg = MoySklad::lastErrorMessage();
                $dup = MoySklad::lastErrorCode() === 409
                    || str_contains(mb_strtolower($msg), 'уже существует')
                    || str_contains(mb_strtolower($msg), 'already exists');
                if ($dup) { $kept++; continue; }
                $errors[] = "$entity/$action: " . $e->getMessage();
            }
        }

        if ($errors) {
            return [
                'ok' => false,
                'error' => 'Не удалось зарегистрировать вебхуки — ' . implode(' | ', $errors),
                'created' => $created, 'updated' => $updated, 'kept' => $kept,
            ];
        }

        return ['ok' => true, 'created' => $created, 'updated' => $updated, 'kept' => $kept, 'url' => $url];
    }

    // ---------------------------------------------------------- webhook events

    /** Attempts one event gets before it is given up on, and how long it is kept. */
    private const HOOK_RETRIES = 5;
    private const HOOK_KEEP_DAYS = 3;

    /**
     * One webhook event — the work the receiver used to do inline.
     *
     * It lives here so the cron can repeat exactly the same work for an event
     * that failed: MoySklad delivers a webhook once, and an order lost to a rate
     * limit was synced only if its company already had documents (that is all
     * `cron/sync_moysklad.php` looks at). The first order of a new company was
     * lost until someone opened the card by hand.
     *
     * Returns the line written into `webhook_log.result`; throws when the event
     * is worth another attempt.
     */
    public static function handleWebhookEvent(array $event): string {
        $href = $event['meta']['href'] ?? '';
        $entityType = (string)($event['meta']['type'] ?? '');
        $msId = $href ? basename(parse_url($href, PHP_URL_PATH) ?: '') : '';

        if (!$msId) return 'no id';

        if ($entityType === 'customerorder') {
            $localId = self::upsertOrder($msId);
            if (!$localId) return 'order not linked to any company';
            self::syncInvoicesForOrder($localId);
            return "order #$localId synced";
        }

        if ($entityType === 'invoiceout') {
            self::init();
            $inv = MoySklad::getInvoice($msId);
            if (!$inv) return 'invoice not found';
            $localOrder = !empty($inv['order_id'])
                ? Db::one("SELECT id, counterparty_id FROM orders WHERE moysklad_id=?", [$inv['order_id']])
                : null;
            $cpId = $localOrder['counterparty_id'] ?? self::localCounterparty($inv['agent_id'] ?? '');
            if ($localOrder && empty($localOrder['counterparty_id'])) {
                // Order arrived before the company link — refresh it
                self::upsertOrder($inv['order_id']);
            }
            $id = self::upsertInvoice($inv, $localOrder ? (int)$localOrder['id'] : null, $cpId ? (int)$cpId : null);
            return "invoice #$id synced";
        }

        return "unsupported entity: $entityType";
    }

    /**
     * Run one event and write down what came of it. `$logId` — the row to update
     * when this is a repeat rather than a first delivery.
     */
    public static function runWebhookEvent(array $event, string $raw, ?int $logId = null): string {
        $attempt = $logId === null
            ? 1
            : (int)(Db::val("SELECT attempts FROM webhook_log WHERE id=?", [$logId]) ?: 0) + 1;

        try {
            $result = self::handleWebhookEvent($event);
            $status = 'ok';
        } catch (Throwable $e) {
            $result = 'error: ' . $e->getMessage();
            $status = 'error';
            $ctx = [
                'entity'      => $event['meta']['type'] ?? '',
                'action'      => $event['action'] ?? '',
                'moysklad_id' => $event['meta']['href'] ?? '',
                'attempt'     => $attempt,
            ];
            // The first failure and the last one are errors — the admin hears
            // about them. The repeats in between are warnings: one event must
            // not ring the same bell five times.
            if ($attempt === 1 || $attempt >= self::HOOK_RETRIES) {
                Logger::exception('moysklad', $e, $ctx);
            } else {
                Logger::warning('moysklad', "Вебхук МойСклад не прошёл, попытка $attempt: " . $e->getMessage(), $ctx);
            }
        }

        $fields = ['result' => $result, 'status' => $status, 'attempts' => $attempt];
        if ($logId !== null) {
            Db::update('webhook_log', $fields, 'id=?', [$logId]);
            return $result;
        }

        $href = $event['meta']['href'] ?? '';
        Db::insert('webhook_log', $fields + [
            'entity_type' => $event['meta']['type'] ?? '',
            'action'      => $event['action'] ?? '',
            'moysklad_id' => $href ? basename(parse_url($href, PHP_URL_PATH) ?: '') : '',
            'payload'     => mb_substr($raw, 0, 4000),
            'event_json'  => json_encode($event, JSON_UNESCAPED_UNICODE),
        ]);
        return $result;
    }

    /**
     * Events that failed, run again — the cron calls this every five minutes.
     * A rate limit passes in seconds, so the second attempt usually succeeds.
     */
    public static function retryFailedWebhooks(int $limit = 50): array {
        $rows = Db::all(
            "SELECT id, event_json FROM webhook_log
             WHERE status='error' AND attempts < ? AND event_json IS NOT NULL
               AND created_at > datetime('now', ?)
             ORDER BY id LIMIT ?",
            [self::HOOK_RETRIES, '-' . self::HOOK_KEEP_DAYS . ' days', $limit]
        );

        $done = 0; $failed = 0;
        foreach ($rows as $row) {
            $event = json_decode((string)$row['event_json'], true);
            if (!is_array($event)) {
                Db::update('webhook_log', ['status' => 'skipped', 'result' => 'event json broken'], 'id=?', [$row['id']]);
                continue;
            }
            $result = self::runWebhookEvent($event, '', (int)$row['id']);
            str_starts_with($result, 'error:') ? $failed++ : $done++;
        }
        if ($done || $failed) {
            Logger::info('moysklad', "Повторная обработка вебхуков: удалось $done, снова не вышло $failed",
                         ['done' => $done, 'failed' => $failed]);
        }
        return ['done' => $done, 'failed' => $failed];
    }

    // Remove this installation's webhooks
    public static function removeWebhooks(): int {
        self::init();
        $removed = 0;
        foreach (self::ourWebhooks() as $w) {
            if (MoySklad::deleteWebhook($w['id'])) $removed++;
        }
        return $removed;
    }
}
