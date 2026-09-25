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
     * Карточки доски во всех колонках, кроме «Закрыто» (issue #88): заказы, по
     * которым склад вписал трек-номер, получают черновик письма клиенту.
     *
     * Отгрузки (issue #112): у заказа их может быть несколько, и трек склад
     * вписывает то в заказ, то в саму отгрузку. Каждая отгрузка запоминается
     * один раз (`order_demands`): новая — уведомление, которое помечает
     * карточку; с треком — ещё и трек в черновике ответа.
     *
     * $onlyCp — только эта компания: «Обновить из МойСклад» в её карточке.
     *
     * @return array{checked:int,shipped:int,demands:int,errors:list<string>}
     */
    public static function checkShipments(int $limit = 30, ?int $onlyCp = null): array {
        $res = ['checked' => 0, 'shipped' => 0, 'demands' => 0, 'errors' => []];
        if ($onlyCp) {
            $cps = [Crm::rootId($onlyCp)];
        } else {
            $boardId = (int)Boards::singleton()['id'];
            $cps = array_map('intval', array_column(Db::all(
                "SELECT DISTINCT bc.counterparty_id FROM board_cards bc
                   JOIN board_columns col ON col.id = bc.column_id
                 WHERE col.board_id=? AND COALESCE(col.kind, '') <> 'closed'
                   AND bc.counterparty_id IS NOT NULL AND bc.dismissed_at IS NULL", [$boardId]), 'counterparty_id'));
        }
        if (!$cps) return $res;

        $in = implode(',', array_fill(0, count($cps), '?'));
        $ids = array_merge($cps, array_map('intval', array_column(
            Db::all("SELECT id FROM counterparties WHERE merged_into_id IN ($in)", $cps), 'id')));
        $in = implode(',', array_fill(0, count($ids), '?'));
        // Ещё не отправленные — первыми: у них отгрузка вот-вот появится.
        // Отправленный заказ смотрим ещё 30 дней — вторая отгрузка тоже новость
        $orders = Db::all(
            "SELECT * FROM orders WHERE counterparty_id IN ($in)
               AND COALESCE(moment, created_at) >= ?
               AND (shipped_notified_at IS NULL OR shipped_notified_at >= ?)
             ORDER BY (shipped_notified_at IS NULL) DESC, id DESC LIMIT " . max(1, $limit),
            [...$ids, date('Y-m-d H:i:s', time() - 120 * 86400), date('Y-m-d H:i:s', time() - 30 * 86400)]);

        $serviceAttr = (string)Settings::get('MS_SHIP_SERVICE_ATTR', 'СЛУЖБА ДОСТАВКИ');
        $trackAttr   = (string)Settings::get('MS_TRACK_ATTR', 'ТРЕК-НОМЕР');
        $hasDemands  = Db::hasTable('order_demands');
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
            $orderTrack   = self::attr($attrs, $trackAttr);
            $orderService = self::attr($attrs, $serviceAttr);

            $demands = [];
            if ($hasDemands) {
                try {
                    $demands = self::$fetchDemands ? (array)(self::$fetchDemands)((string)$o['moysklad_id'])
                                                   : MoySklad::getDemandsByOrder((string)$o['moysklad_id']);
                } catch (Throwable $e) {
                    $res['errors'][] = "Отгрузки заказа {$o['name']}: " . $e->getMessage();
                }
            }
            $notified = [];   // треки, о которых по этому заказу уже сказано
            if (!empty($o['shipped_notified_at']) && (string)$o['ship_track'] !== '') $notified[] = (string)$o['ship_track'];
            foreach ($demands as $d) {
                $r = self::demand($o, $d, count($demands) === 1 ? $orderTrack : '', $orderService,
                                  $trackAttr, $serviceAttr, $notified);
                $res['demands'] += $r['new'];
                $res['shipped'] += $r['shipped'];
            }
            // Трек в самом заказе, отгрузки ещё нет (или её не прочитать) — как раньше
            if ($orderTrack !== '' && empty($o['shipped_notified_at']) && !in_array($orderTrack, $notified, true)) {
                self::shipped($o, $orderService, $orderTrack);
                $res['shipped']++;
            }
        }
        return $res;
    }

    /** Подмена чтения отгрузок в тестах: fn(string $msOrderId): list<array> */
    public static $fetchDemands = null;

    /**
     * Одна отгрузка заказа: запомнить, сказать о новой, отдать трек в письмо.
     * @param list<string> $notified треки заказа, о которых уже сказано (дополняется)
     * @return array{new:int,shipped:int}
     */
    private static function demand(array $o, array $d, string $orderTrack, string $orderService,
                                   string $trackAttr, string $serviceAttr, array &$notified): array {
        $out = ['new' => 0, 'shipped' => 0];
        $msId = (string)($d['id'] ?? '');
        if ($msId === '') return $out;
        $attrs = (array)($d['attributes'] ?? []);
        $track = self::attr($attrs, $trackAttr) ?: $orderTrack;
        $service = self::attr($attrs, $serviceAttr) ?: $orderService;
        $name = (string)($d['name'] ?? '');

        $row = Db::one("SELECT * FROM order_demands WHERE moysklad_id=?", [$msId]);
        if (!$row) {
            $id = Db::insert('order_demands', [
                'order_id' => (int)$o['id'], 'moysklad_id' => $msId, 'name' => $name ?: null,
                'moment' => (string)($d['moment'] ?? '') ?: null,
                'ship_service' => $service ?: null, 'ship_track' => $track ?: null,
                'seen_at' => date('Y-m-d H:i:s'),
            ]);
            $row = Db::one("SELECT * FROM order_demands WHERE id=?", [$id]);
            $out['new'] = 1;
        } elseif ($track !== '' && (string)$row['ship_track'] !== $track) {
            Db::update('order_demands', ['ship_track' => $track, 'ship_service' => $service ?: null], 'id=?', [(int)$row['id']]);
        }

        if ($track !== '' && empty($row['notified_at'])) {
            if (!in_array($track, $notified, true)) {
                self::shipped($o, $service, $track, $name);
                $out['shipped'] = 1;
                $notified[] = $track;
            }
            Db::update('order_demands', ['notified_at' => date('Y-m-d H:i:s')], 'id=?', [(int)$row['id']]);
        } elseif ($out['new'] && $track === '') {
            // Отгрузка есть, трека ещё нет — карточка помечается, письмо подождёт трек
            $cpId = Crm::rootId((int)$o['counterparty_id']);
            $company = (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$cpId]) ?: '');
            Notifier::notify('order_shipped', "Отгрузка" . ($name !== '' ? " $name" : '') . " по заказу {$o['name']}",
                             trim(($company !== '' ? "$company: " : '') . 'трек-номер ещё не вписан — письмо появится, когда склад его внесёт'),
                             'counterparty', $cpId, self::managerFor($o, $cpId), '/#mail/company/' . $cpId);
        }
        return $out;
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
    public static function shipped(array $order, string $service, string $track, string $demandName = ''): int {
        $cpId = Crm::rootId((int)$order['counterparty_id']);
        $company = (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$cpId]) ?: '');
        $managerId = self::managerFor($order, $cpId);
        $msg = self::shipmentText((string)$order['name'], $service, $track);

        // Ответом в переписку запроса
        $letter = null;
        if (!empty($order['request_id'])) {
            $letter = Db::one("SELECT id, thread_key FROM mail_messages WHERE request_id=? AND direction='in'
                               ORDER BY date_at DESC, id DESC LIMIT 1", [(int)$order['request_id']]);
        }
        // У менеджера уже пишется ответ в эту переписку (или компании) — трек
        // встаёт в ЕГО черновик, а не во второй рядом (issue #112)
        $own = $letter
            ? Db::one("SELECT id, body FROM mail_drafts WHERE manager_id=? AND (mail_message_id=? OR thread_key=?)
                       ORDER BY id DESC LIMIT 1", [$managerId, (int)$letter['id'], (string)$letter['thread_key']])
            : Db::one("SELECT id, body FROM mail_drafts WHERE manager_id=? AND counterparty_id=?
                       ORDER BY id DESC LIMIT 1", [$managerId, $cpId]);
        if ($own) {
            $body = (string)$own['body'];
            if (!str_contains($body, $track)) {
                Db::update('mail_drafts', ['body' => $body . self::trackParagraph((string)$order['name'], $service, $track),
                                           'updated_at' => date('Y-m-d H:i:s')], 'id=?', [(int)$own['id']]);
            }
            $draftId = (int)$own['id'];
            Boards::draftCard($draftId, ['counterparty_id' => $cpId, 'title' => $company, 'manager_id' => $managerId]);
            self::markShipped($order, $service, $track);
            self::notifyShipped($order, $company, $service, $track, $demandName, $cpId, $managerId);
            return $draftId;
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
        self::markShipped($order, $service, $track);
        self::notifyShipped($order, $company, $service, $track, $demandName, $cpId, $managerId);
        return $draftId;
    }

    private static function markShipped(array $order, string $service, string $track): void {
        Db::update('orders', ['ship_service' => $service ?: null, 'ship_track' => $track,
                              'shipped_notified_at' => date('Y-m-d H:i:s')], 'id=?', [(int)$order['id']]);
    }

    private static function notifyShipped(array $order, string $company, string $service, string $track,
                                          string $demandName, int $cpId, int $managerId): void {
        Notifier::notify('order_shipped', "Заказ {$order['name']} отправлен — письмо клиенту готово",
                         trim("$company: " . ($demandName !== '' ? "отгрузка $demandName, " : '')
                              . ($service !== '' ? "$service, " : '') . "трек $track"),
                         'counterparty', $cpId, $managerId, '/#mail/company/' . $cpId);
    }

    /** Абзац с треком — дописывается в черновик, который менеджер уже пишет. */
    public static function trackParagraph(string $orderName, string $service, string $track): string {
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<p>Заказ № ' . $e($orderName) . ' отправлен' . ($service !== '' ? ' службой ' . $e($service) : '')
              . '. Трек-номер: <b>' . $e($track) . '</b>';
        if (self::isCdek($service)) {
            $url = $e(self::cdekUrl($track));
            $html .= '<br>Отследить: <a href="' . $url . '">' . $url . '</a>';
        }
        return $html . '</p>';
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
