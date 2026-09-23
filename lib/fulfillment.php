<?php
/**
 * После оплаты (модуль 047): карточка — в «Сборку», менеджеру — напоминание
 * сообщить складу; склад вписал трек-номер — готов черновик письма клиенту.
 */
require_once __DIR__ . '/boards.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/notifier.php';
require_once __DIR__ . '/moysklad.php';

final class Fulfillment {

    /** Подмена чтения заказа из МойСклад в тестах: fn(string $msId): ?array */
    public static $fetchOrder = null;

    /**
     * Счёт оплачен полностью. У счёта с заказом — это оплата заказа; счёт без
     * заказа двигает карточку компании тем же путём.
     */
    public static function invoicePaid(int $invoiceId, string $source = 'bank'): bool {
        $inv = Db::one("SELECT * FROM invoices WHERE id=?", [$invoiceId]);
        if (!$inv) return false;
        if (!empty($inv['order_id'])) return self::orderPaid((int)$inv['order_id'], $source);
        if (!empty($inv['paid_at']) || empty($inv['counterparty_id'])) return false;
        Db::update('invoices', ['paid_at' => date('Y-m-d H:i:s')], 'id=?', [$invoiceId]);
        self::toAssembly((int)$inv['counterparty_id'], 'Счёт ' . $inv['name'], (float)$inv['sum'], null,
                         ['invoice_id' => $invoiceId]);
        return true;
    }

    /** Заказ оплачен — один раз на заказ. */
    public static function orderPaid(int $orderId, string $source = 'bank'): bool {
        $o = Db::one("SELECT * FROM orders WHERE id=?", [$orderId]);
        if (!$o || !empty($o['paid_at'])) return false;
        Db::update('orders', ['paid_at' => date('Y-m-d H:i:s')], 'id=?', [$orderId]);
        if (empty($o['counterparty_id'])) return true;
        self::toAssembly((int)$o['counterparty_id'], 'Заказ ' . $o['name'], (float)$o['sum'],
                         $o['manager_id'] ? (int)$o['manager_id'] : null, ['order_id' => $orderId, 'source' => $source]);
        return true;
    }

    private static function toAssembly(int $cpId, string $doc, float $sum, ?int $managerId, array $meta): void {
        $cpId = Crm::rootId($cpId);
        $company = (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$cpId]) ?: 'Компания');
        $col = Boards::assemblyColumn((int)Boards::singleton()['id']);
        Boards::addCard((int)$col['id'], ['counterparty_id' => $cpId]);

        $money = number_format($sum, 2, ',', ' ') . ' ₽';
        Crm::logEvent($cpId, 'note', "$doc оплачен ($money) — карточка в «Сборке»", [
            'event_type' => 'payment_received',
            'subject'    => "Оплата: $doc",
            'meta'       => $meta,
        ]);
        Notifier::notify('order_paid', 'Сообщить складу о необходимости отправки',
                         "$company: $doc оплачен на $money", 'counterparty', $cpId, $managerId,
                         '/#mail/company/' . $cpId);
    }

    // ------------------------------------------------------------- отправка

    /**
     * Карточки «Сборки»: заказы, по которым склад вписал трек-номер, получают
     * черновик письма клиенту.
     *
     * @return array{checked:int,shipped:int,errors:list<string>}
     */
    public static function checkShipments(int $limit = 30): array {
        $res = ['checked' => 0, 'shipped' => 0, 'errors' => []];
        $col = Boards::assemblyColumn((int)Boards::singleton()['id']);
        $cps = array_map('intval', array_column(Db::all(
            "SELECT DISTINCT counterparty_id FROM board_cards
             WHERE column_id=? AND counterparty_id IS NOT NULL AND dismissed_at IS NULL", [(int)$col['id']]), 'counterparty_id'));
        if (!$cps) return $res;

        $in = implode(',', array_fill(0, count($cps), '?'));
        $ids = array_merge($cps, array_map('intval', array_column(
            Db::all("SELECT id FROM counterparties WHERE merged_into_id IN ($in)", $cps), 'id')));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $orders = Db::all(
            "SELECT * FROM orders WHERE counterparty_id IN ($in) AND shipped_notified_at IS NULL
               AND COALESCE(moment, created_at) >= ? ORDER BY id DESC LIMIT " . max(1, $limit),
            [...$ids, date('Y-m-d H:i:s', time() - 120 * 86400)]);

        $serviceAttr = (string)Settings::get('MS_SHIP_SERVICE_ATTR', 'СЛУЖБА ДОСТАВКИ');
        $trackAttr   = (string)Settings::get('MS_TRACK_ATTR', 'ТРЕК-НОМЕР');
        foreach ($orders as $o) {
            $res['checked']++;
            try {
                $ms = self::$fetchOrder ? (self::$fetchOrder)((string)$o['moysklad_id'])
                                        : MoySklad::getOrder((string)$o['moysklad_id']);
            } catch (Throwable $e) {
                $res['errors'][] = "Заказ {$o['name']}: " . $e->getMessage();
                continue;
            }
            $attrs = (array)($ms['attributes'] ?? []);
            $track = self::attr($attrs, $trackAttr);
            if ($track === '') continue;
            self::shipped($o, self::attr($attrs, $serviceAttr), $track);
            $res['shipped']++;
        }
        return $res;
    }

    /** Значение доп. поля по имени, без учёта регистра и «ё». */
    public static function attr(array $attrs, string $name): string {
        $norm = fn(string $s) => str_replace('ё', 'е', mb_strtolower(trim($s)));
        foreach ($attrs as $k => $v) {
            if ($norm((string)$k) === $norm($name)) return trim((string)$v);
        }
        return '';
    }

    /** Трек есть: черновик письма, карточка с ним, уведомление. */
    public static function shipped(array $order, string $service, string $track): int {
        $cpId = Crm::rootId((int)$order['counterparty_id']);
        $company = (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$cpId]) ?: '');
        $managerId = self::managerFor($order, $cpId);
        $msg = self::shipmentText((string)$order['name'], $service, $track);

        // Ответом в переписку запроса, если там у менеджера нет своего черновика
        $letter = null;
        if (!empty($order['request_id'])) {
            $letter = Db::one("SELECT id, thread_key FROM mail_messages WHERE request_id=? AND direction='in'
                               ORDER BY date_at DESC, id DESC LIMIT 1", [(int)$order['request_id']]);
        }
        if ($letter && Db::val("SELECT 1 FROM mail_drafts WHERE manager_id=? AND (mail_message_id=? OR thread_key=?)",
                               [$managerId, (int)$letter['id'], (string)$letter['thread_key']])) {
            $letter = null;
        }

        $draftId = Db::insert('mail_drafts', [
            'mail_message_id' => $letter ? (int)$letter['id'] : null,
            'thread_key'      => $letter ? $letter['thread_key'] : null,
            'counterparty_id' => $cpId,
            'to_email'        => Crm::primaryEmail($cpId),
            'manager_id'      => $managerId,
            'subject'         => $msg['subject'],
            'body'            => $msg['html'],
            'kind'            => 'shipment',
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        Boards::draftCard($draftId, ['counterparty_id' => $cpId, 'title' => $company, 'manager_id' => $managerId]);
        Db::update('orders', ['ship_service' => $service ?: null, 'ship_track' => $track,
                              'shipped_notified_at' => date('Y-m-d H:i:s')], 'id=?', [(int)$order['id']]);

        Notifier::notify('order_shipped', "Заказ {$order['name']} отправлен — письмо клиенту готово",
                         trim("$company: " . ($service !== '' ? "$service, " : '') . "трек $track"),
                         'counterparty', $cpId, $managerId, '/#mail/company/' . $cpId);
        return $draftId;
    }

    /** Чей черновик: менеджер заказа, карточки, первый администратор. */
    private static function managerFor(array $order, int $cpId): int {
        return (int)($order['manager_id']
            ?: Db::val("SELECT manager_id FROM board_cards WHERE counterparty_id=? AND manager_id IS NOT NULL LIMIT 1", [$cpId])
            ?: Db::val("SELECT id FROM managers WHERE is_admin=1 AND COALESCE(is_active,1)=1 ORDER BY id LIMIT 1")
            ?: Db::val("SELECT id FROM managers ORDER BY id LIMIT 1"));
    }

    public static function isCdek(string $service): bool {
        $s = mb_strtolower($service);
        return str_contains($s, 'сдэк') || str_contains($s, 'сдек') || str_contains($s, 'cdek');
    }

    public static function cdekUrl(string $track): string {
        return 'https://www.cdek.ru/ru/tracking/?order_id=' . rawurlencode($track);
    }

    /** @return array{subject:string,html:string} */
    public static function shipmentText(string $orderName, string $service, string $track): array {
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<p>Здравствуйте!</p><p>Ваш заказ № ' . $e($orderName) . ' отправлен'
              . ($service !== '' ? ' службой доставки ' . $e($service) : '') . '.<br>'
              . 'Трек-номер для отслеживания: <b>' . $e($track) . '</b></p>';
        if (self::isCdek($service)) {
            $url = $e(self::cdekUrl($track));
            $html .= '<p>Отследить отправление: <a href="' . $url . '">' . $url . '</a></p>';
        }
        return ['subject' => "Заказ № $orderName отправлен", 'html' => $html];
    }
}
