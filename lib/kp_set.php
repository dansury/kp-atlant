<?php
/**
 * КП запроса (модуль 048: одно рабочее КП на запрос — самое новое).
 *
 * Здесь заводится КП, его позиции переводятся из таблицы подбора, КП
 * пересобирается по подбору и убирается. Счетов у одного КП может быть
 * несколько.
 */
require_once __DIR__ . '/request_items.php';
require_once __DIR__ . '/kp_content.php';
require_once __DIR__ . '/kp_terms.php';
require_once __DIR__ . '/requisites.php';
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/variants.php';
require_once __DIR__ . '/request_shape.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/moysklad.php';

final class KpSet {

    /**
     * Завести КП запроса — пустое, с шапкой и условиями по умолчанию.
     *
     * Реквизиты замораживаются сразу: документ, собранный сегодня, печатается с
     * сегодняшними ИНН и банком, даже если открыть его через полгода
     * (модуль 013).
     */
    public static function create(int $requestId, ?int $managerId = null): int {
        $req = Db::one("SELECT * FROM requests WHERE id=?", [$requestId]);
        if (!$req) throw new RuntimeException('Запрос не найден');

        $proposalId = Db::insert('proposals', [
            'request_id'      => $requestId,
            'counterparty_id' => $req['counterparty_id'],
            'manager_id'      => $managerId,
            'vat_rate'        => (int)(Db::val("SELECT value FROM settings WHERE key='default_vat_rate'") ?: 5),
            'execution_days'  => (int)(Db::val("SELECT value FROM settings WHERE key='default_execution_days'") ?: 30),
            'validity_days'   => (int)(Db::val("SELECT value FROM settings WHERE key='default_validity_days'") ?: 14),
            'conditions_text' => Db::val("SELECT value FROM settings WHERE key='default_conditions_text'") ?: '',
            'terms_text'      => KpTerms::defaultText(),
            // Дописанное вокруг таблицы в прошлый раз — уже здесь (модуль 011)
            'pre_table_text'  => (string)(Db::val("SELECT manager_text FROM corrections WHERE field='pre_table' ORDER BY id DESC LIMIT 1") ?: ''),
            'post_table_text' => (string)(Db::val("SELECT manager_text FROM corrections WHERE field='post_table' ORDER BY id DESC LIMIT 1") ?: ''),
            'show_match_table' => (RequestShape::of($requestId) === RequestShape::TABLE) ? 1 : 0,
        ] + self::deliveryFields($requestId));

        Requisites::freeze($proposalId);
        return $proposalId;
    }

    /**
     * Доставка из таблицы подбора — в поля КП (модуль 034).
     *
     * Она правится строкой под позициями, а печатается строкой таблицы или
     * раскладывается по ценам позиций.
     */
    private static function deliveryFields(int $requestId): array {
        $d = RequestItems::delivery($requestId);
        return [
            'delivery_on'    => $d['on'],
            'delivery_name'  => $d['name'],
            'delivery_price' => $d['price'],
            'delivery_mode'  => $d['mode_set'],
        ];
    }

    /**
     * Позиция КП из строки «Подходящих позиций».
     *
     * Один и тот же перевод строки подбора в строку документа для «Сформировать
     * КП» и «🔄 Пересобрать».
     *
     * @param array $m строка в форме `RequestItems::toProposalItems()`
     */
    public static function itemRow(array $m, int $position): array {
        $match = $m['match'] ?? null;

        // «Сколько можем отгрузить» — свободный остаток. У товара с
        // модификациями своего остатка в МойСклад нет: считаем по ним.
        $free = $match ? max(0, (int)($match['stock'] ?? 0) - (int)($match['reserved'] ?? 0)) : 0;
        if ($match && !empty($match['moysklad_id'])) {
            $byVariants = Variants::stockOf((string)$match['moysklad_id']);
            if ($byVariants !== null) {
                $free = $byVariants['free'];
                $match['stock'] = $free;
                $match['reserved'] = 0;   // резерв модификаций уже вычтен
            }
        }

        return [
            'position'            => $position,
            'request_item_id'     => $m['request_item_id'] ?? null,
            'product_name'        => $match ? $match['name'] : $m['raw_name'],
            // Что клиент написал сам: аналог виден только против этой строки
            'requested_name'      => $m['raw_name'] ?? null,
            'moysklad_product_id' => $match['moysklad_id'] ?? null,
            'unit'                => $match['unit'] ?? 'шт.',
            'quantity'            => $m['quantity'],
            'price'               => $match['price'] ?? 0,
            // Вилка цен: цена стоит только на модификациях и они стоят по-разному
            // (модуль 036). Равен цене или ноль — вилки нет, печатается одна цена.
            'price_max'           => (float)($match['price_max'] ?? 0),
            'stock_available'     => $match['stock'] ?? null,
            'stock_reserved'      => $match['reserved'] ?? null,
            'match_confidence'    => $match['score'] ?? null,
            'match_variants'      => !empty($m['variants'])
                ? json_encode($m['variants'], JSON_UNESCAPED_UNICODE) : null,
            'is_confirmed'        => !empty($m['is_confirmed']) ? 1 : 0,
            // Своё примечание менеджера сильнее автоматического «под заказ»
            'notes'               => Terms::stockNote($m['notes'] ?? null, $free, (bool)$match),
            'comment_text'        => $m['comment_text'] ?? null,
            'discount_percent'    => (float)($m['discount_percent'] ?? 0),
            'price_is_manual'     => (int)($m['price_is_manual'] ?? 0),
            'wait_on'             => (int)($m['wait_on'] ?? 0),
            'wait_months'         => $m['wait_months'] ?? null,
            'wait_discount'       => $m['wait_discount'] ?? null,
            'wait_prepay'         => $m['wait_prepay'] ?? null,
            // Фото выбраны в таблице подбора — документ печатает их же
            'selected_images'     => $m['selected_images'] ?? null,
            'is_alternative'      => !empty($m['is_alternative']) ? 1 : 0,
            // Слова КЛИЕНТА про то, вместо чего стоит наша позиция: КП печатает
            // их над её названием. Менеджер их правит, поэтому это не
            // `requested_name`, а своё поле (модуль 036)
            'alt_of'              => trim((string)($m['alt_of'] ?? '')) ?: ($m['raw_name'] ?? null),
            'alt_reason'          => $m['alt_specs']['reason'] ?? null,
            'alt_specs_json'      => !empty($m['alt_specs'])
                ? json_encode($m['alt_specs'], JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /**
     * «🔄 Пересобрать» (модуль 048): позиции КП — заново из таблицы подбора,
     * доставка — оттуда же. Правка листа A4 сбрасывается: документ снова
     * собирается из данных. Отправленное КП сюда не попадает — по нему
     * собирается новое (`proposals.php?action=rebuild`).
     */
    public static function rebuildItems(int $proposalId): void {
        $p = Db::one("SELECT id, request_id, status FROM proposals WHERE id=?", [$proposalId]);
        if (!$p) throw new RuntimeException('КП не найдено');
        if (in_array((string)$p['status'], ['sent', 'order_created'], true)) {
            throw new RuntimeException('КП уже ушло клиенту — его не переписывают');
        }
        $requestId = (int)$p['request_id'];
        $matched = RequestItems::toProposalItems(RequestItems::ensure($requestId));

        Db::q("DELETE FROM proposal_items WHERE proposal_id=?", [$proposalId]);
        foreach ($matched as $i => $m) {
            Db::insert('proposal_items', self::itemRow($m, $i + 1) + ['proposal_id' => $proposalId]);
        }
        Db::update('proposals', self::deliveryFields($requestId) + [
            'html_override'    => null,
            'html_override_at' => null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], 'id=?', [$proposalId]);
        self::rebuild($proposalId);
    }

    /**
     * Убрать КП целиком.
     *
     * Отправленное клиенту КП не удаляется: документ, который клиент держит в
     * руках, из базы не исчезает. Позиции такого КП переносятся руками.
     */
    public static function delete(int $proposalId): void {
        $p = Db::one("SELECT id, status FROM proposals WHERE id=?", [$proposalId]);
        if (!$p) throw new RuntimeException('КП не найдено');
        if (in_array((string)$p['status'], ['sent', 'order_created'], true)) {
            throw new RuntimeException('КП уже ушло клиенту — его нельзя убрать');
        }
        if (Db::val("SELECT COUNT(*) FROM invoices WHERE proposal_id=?", [$proposalId])) {
            throw new RuntimeException('По этому КП уже выставлен счёт — сначала отмените счёт в МойСклад');
        }
        Db::q("DELETE FROM proposal_items WHERE proposal_id=?", [$proposalId]);
        Db::q("DELETE FROM proposal_addons WHERE proposal_id=?", [$proposalId]);
        Db::q("DELETE FROM proposals WHERE id=?", [$proposalId]);
    }

    /**
     * Что нужно строке кнопок под таблицей подбора (модуль 048): статус, счета,
     * правлен ли лист руками и можно ли убрать КП.
     */
    public static function summary(int $proposalId): array {
        $p = Db::one("SELECT id, number, status, html_override FROM proposals WHERE id=?", [$proposalId]);
        if (!$p) throw new RuntimeException('КП не найдено');
        $invoices = self::invoices($proposalId);
        return [
            'id'         => (int)$p['id'],
            'number'     => $p['number'],
            'status'     => $p['status'],
            // Лист A4 правили руками — «Пересобрать» эту правку сбросит
            'edited'     => trim((string)($p['html_override'] ?? '')) !== '',
            'invoices'   => $invoices,
            'can_delete' => !in_array((string)$p['status'], ['sent', 'order_created'], true) && !$invoices,
        ];
    }

    /** Счета, выставленные по этому КП. Их может быть несколько. */
    public static function invoices(int $proposalId): array {
        $rows = Db::all(
            "SELECT i.id, i.name, i.sum, i.payed_sum, i.state_name, i.moment, i.sent_at, i.moysklad_id
             FROM invoices i WHERE i.proposal_id=? ORDER BY i.id", [$proposalId]
        );
        foreach ($rows as &$r) {
            $r['id']  = (int)$r['id'];
            $r['url'] = MoySklad::invoiceUrl((string)$r['moysklad_id']);
            $r['pdf_url'] = '/api/invoices.php?action=pdf&id=' . (int)$r['id'];
        }
        unset($r);
        return $rows;
    }

    /**
     * Условия ожидания из таблицы подбора — в КП этого запроса (модуль 037).
     *
     * Срок ожидания ставится в подборе, а печатается в КП дважды: строкой «под
     * заказ, срок ожидания 6 месяцев» и сроком исполнения в условиях. Пока их
     * никто не сводил, КП, собранное ДО правки, печатало прежние три месяца —
     * те, что проставились по умолчанию в день сборки, — и менеджер выставлял
     * срок второй раз, уже в документе.
     *
     * Переписывается только то, что в подборе ЗАПОЛНЕНО: пустое поле означает
     * «как в настройках», а не «ноль», и своё значение КП за ним не теряет.
     * Отправленное клиенту КП не трогается вовсе — документ, который он держит
     * в руках, печатается так, как его подписали.
     *
     * @return int сколько КП пересобралось
     */
    public static function syncWaitFromRequest(int $requestId): int {
        // Доставка строки подбора — сразу в неотправленное КП (модуль 049)
        $touched = [];
        $delivery = self::deliveryFields($requestId);
        foreach (Db::all("SELECT id, delivery_on, delivery_name, delivery_price, delivery_mode FROM proposals
                          WHERE request_id=? AND status NOT IN ('sent', 'order_created')", [$requestId]) as $p) {
            $same = (int)$p['delivery_on'] === (int)$delivery['delivery_on']
                && (string)$p['delivery_name'] === (string)$delivery['delivery_name']
                && abs((float)$p['delivery_price'] - (float)$delivery['delivery_price']) < 0.005
                && (string)$p['delivery_mode'] === (string)$delivery['delivery_mode'];
            if ($same) continue;
            Db::update('proposals', $delivery, 'id=?', [(int)$p['id']]);
            $touched[(int)$p['id']] = true;
        }

        $source = [];
        foreach (Db::all("SELECT id, wait_on, wait_months, wait_discount, wait_prepay
                          FROM request_items WHERE request_id=?", [$requestId]) as $row) {
            $source[(int)$row['id']] = $row;
        }
        if (!$source) {
            foreach (array_keys($touched) as $proposalId) self::rebuild($proposalId);
            return count($touched);
        }

        $items = Db::all(
            "SELECT i.id, i.proposal_id, i.request_item_id,
                    i.wait_on, i.wait_months, i.wait_discount, i.wait_prepay
             FROM proposal_items i
             JOIN proposals p ON p.id = i.proposal_id
             WHERE p.request_id=? AND p.status NOT IN ('sent', 'order_created')
               AND i.request_item_id IS NOT NULL", [$requestId]);

        foreach ($items as $item) {
            $src = $source[(int)$item['request_item_id']] ?? null;
            if (!$src) continue;

            $upd = [];
            foreach (['wait_on', 'wait_months', 'wait_discount', 'wait_prepay'] as $field) {
                $value = $src[$field] ?? null;
                // Выключатель «под заказ» пустым не бывает: подбор пишет 0 или 1
                if ($field !== 'wait_on' && ($value === null || $value === '')) continue;
                if ((string)$value === (string)($item[$field] ?? '')) continue;
                $upd[$field] = $value;
            }
            if (!$upd) continue;

            Db::update('proposal_items', $upd, 'id=?', [(int)$item['id']]);
            $touched[(int)$item['proposal_id']] = true;
        }

        foreach (array_keys($touched) as $proposalId) self::rebuild($proposalId);
        return count($touched);
    }

    /**
     * Пересобрать документ после правки позиций.
     *
     * Best-effort: не собравшийся PDF не отменяет правку позиций — она уже
     * сохранена.
     */
    public static function rebuild(int $proposalId): void {
        try {
            KpContent::enrichItems($proposalId);
            Terms::prepareProposal($proposalId);
            PdfGenerator::generate($proposalId);
        } catch (Throwable $e) {
            Logger::warning('kp', 'КП не пересобралось после правки позиций: ' . $e->getMessage(),
                            ['proposal_id' => $proposalId]);
        }
    }
}
