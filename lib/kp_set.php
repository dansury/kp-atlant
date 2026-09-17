<?php
/**
 * Несколько КП на один запрос (модуль 027).
 *
 * Клиент прислал письмо на восемь позиций, а платить за них собирается двумя
 * разными заявками: шлемы по одной, бронежилеты по другой. До сих пор у запроса
 * было ровно одно КП со всеми восемью строками — и менеджер собирал второй
 * документ руками, в Word, теряя связь с каталогом, ценами и счётом.
 *
 * Здесь запрос — это НАБОР КП. Их можно добавлять («+ Ещё одно КП»), называть
 * своими словами, а позиции — перетаскивать: из запроса в КП, из КП в КП,
 * из КП обратно в запрос. Счёт выставляется по конкретному КП, и счетов у
 * одного КП может быть несколько.
 *
 * Позиция живёт в одном месте: перетаскивание — это `UPDATE proposal_id`, а не
 * копия. Строка, оказавшаяся в двух документах сразу, — это счёт, выставленный
 * дважды за один товар.
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
    public static function create(int $requestId, ?int $managerId = null, string $label = ''): int {
        $req = Db::one("SELECT * FROM requests WHERE id=?", [$requestId]);
        if (!$req) throw new RuntimeException('Запрос не найден');

        $proposalId = Db::insert('proposals', [
            'request_id'      => $requestId,
            'counterparty_id' => $req['counterparty_id'],
            'manager_id'      => $managerId,
            'label'           => trim($label) ?: null,
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
     * Она правится строкой под позициями, а печатается строкой таблицы: у
     * запроса КП бывает несколько, и доставка у них одна и та же.
     */
    private static function deliveryFields(int $requestId): array {
        $d = RequestItems::delivery($requestId);
        return [
            'delivery_on'    => $d['on'],
            'delivery_name'  => $d['name'],
            'delivery_price' => $d['price'],
        ];
    }

    /**
     * Позиция КП из строки «Подходящих позиций».
     *
     * Один и тот же перевод строки подбора в строку документа для обоих путей:
     * и для «Сформировать КП» целиком, и для одной перетащенной позиции.
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
            'is_alternative'      => !empty($m['is_alternative']) ? 1 : 0,
            'alt_reason'          => $m['alt_specs']['reason'] ?? null,
            'alt_specs_json'      => !empty($m['alt_specs'])
                ? json_encode($m['alt_specs'], JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /**
     * Положить строку запроса в КП. Уже лежащую там не задваиваем.
     *
     * @return int id созданной позиции КП
     */
    public static function addFromRequest(int $proposalId, int $requestItemId, ?int $position = null): int {
        $proposal = Db::one("SELECT id, request_id FROM proposals WHERE id=?", [$proposalId]);
        if (!$proposal) throw new RuntimeException('КП не найдено');

        $exists = Db::val("SELECT id FROM proposal_items WHERE proposal_id=? AND request_item_id=?",
                          [$proposalId, $requestItemId]);
        if ($exists) return (int)$exists;

        $row = null;
        foreach (RequestItems::all((int)$proposal['request_id']) as $r) {
            if ((int)$r['id'] === $requestItemId) { $row = $r; break; }
        }
        if (!$row) throw new RuntimeException('Строка запроса не найдена');
        // «Не наша номенклатура» в документ не идёт ни в каком виде (модуль 022)
        if ((int)($row['is_out_of_scope'] ?? 0) === 1) {
            throw new RuntimeException('Эта позиция отмечена как не наша номенклатура');
        }

        $mapped = RequestItems::toProposalItems([$row]);
        if (!$mapped) throw new RuntimeException('Строку запроса не удалось перевести в позицию КП');

        $id = Db::insert('proposal_items',
            self::itemRow($mapped[0], self::nextPosition($proposalId)) + ['proposal_id' => $proposalId]);
        if ($position !== null) self::place($id, $proposalId, $position);
        self::rebuild($proposalId);
        return $id;
    }

    /**
     * Перетащить позицию в другое КП (или на другое место в своём).
     *
     * Пересобираются ОБА документа: и тот, из которого ушла строка, и тот, в
     * который она пришла, — иначе предпросмотр показывает вчерашний файл.
     */
    public static function moveItem(int $itemId, int $toProposalId, ?int $position = null): void {
        $item = Db::one("SELECT * FROM proposal_items WHERE id=?", [$itemId]);
        if (!$item) throw new RuntimeException('Позиция не найдена');
        $from = (int)$item['proposal_id'];

        $to = Db::one("SELECT id, request_id FROM proposals WHERE id=?", [$toProposalId]);
        if (!$to) throw new RuntimeException('КП не найдено');
        $fromRequest = (int)Db::val("SELECT request_id FROM proposals WHERE id=?", [$from]);
        if ((int)$to['request_id'] !== $fromRequest) {
            throw new RuntimeException('Позицию можно двигать только между КП одного запроса');
        }

        self::place($itemId, $toProposalId, $position ?? PHP_INT_MAX);
        if ($from !== $toProposalId) {
            // Строка ушла — в покинутом КП остаётся дыра в нумерации, и КП
            // печатается с позициями «1, 3»
            self::resequence($from);
            self::rebuild($from);
        }
        self::rebuild($toProposalId);
    }

    /** Убрать позицию из КП — она возвращается в список позиций запроса. */
    public static function removeItem(int $itemId): void {
        $proposalId = (int)(Db::val("SELECT proposal_id FROM proposal_items WHERE id=?", [$itemId]) ?: 0);
        if (!$proposalId) throw new RuntimeException('Позиция не найдена');
        Db::q("DELETE FROM proposal_items WHERE id=?", [$itemId]);
        self::resequence($proposalId);
        self::rebuild($proposalId);
    }

    /** Имя КП, которое пишет менеджер. Пусто — КП зовётся своим номером. */
    public static function rename(int $proposalId, string $label): void {
        Db::update('proposals', ['label' => trim($label) ?: null, 'updated_at' => date('Y-m-d H:i:s')],
                   'id=?', [$proposalId]);
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
     * Раскладка запроса по КП — то, что рисует доска позиций.
     *
     * @return array{proposals:array<int,array>,pool:array<int,array>}
     */
    public static function board(int $requestId): array {
        $proposals = Db::all("SELECT * FROM proposals WHERE request_id=? ORDER BY id", [$requestId]);

        $used = [];
        $out = [];
        foreach ($proposals as $n => $p) {
            $items = Db::all(
                "SELECT id, position, product_name, requested_name, unit, quantity, price,
                        notes, request_item_id, is_excluded, discount_percent, wait_on,
                        wait_months, wait_discount, moysklad_product_id
                 FROM proposal_items WHERE proposal_id=? ORDER BY position, id", [(int)$p['id']]
            );
            $total = 0.0;
            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['effective_price'] = Terms::price($it);
                $it['sum'] = $it['effective_price'] * (float)$it['quantity'];
                if ((int)($it['is_excluded'] ?? 0) !== 1) $total += $it['sum'];
                if ($it['request_item_id']) $used[(int)$it['request_item_id']] = true;
            }
            unset($it);

            if ((int)($p['delivery_on'] ?? 0) === 1) $total += (float)($p['delivery_price'] ?? 0);

            // На доске стоит тот же итог, что и в документе: при «цене + НДС»
            // сумма строк — это ещё не то, что заплатит клиент (модуль 030)
            $vat = Requisites::forProposal((int)$p['id'])['vat'] ?? [];
            if (!array_key_exists('rate', $vat)) $vat['rate'] = (int)($p['vat_rate'] ?? 5);
            $vatTotals = Requisites::vatTotals($total, $vat, Requisites::vatMode($p));

            $out[] = [
                'id'        => (int)$p['id'],
                'title'     => self::title($p, $n + 1),
                'label'     => $p['label'],
                'number'    => $p['number'],
                'status'    => $p['status'],
                'sent_at'   => $p['sent_at'],
                'items'     => $items,
                'total'     => $vatTotals['total'],
                // Что за налог сидит в этом итоге — теми же словами, что в документе
                'vat'       => ['note'   => $vatTotals['note'],
                                'amount' => $vatTotals['amount'],
                                'mode'   => $vatTotals['mode']],
                'delivery'  => (int)($p['delivery_on'] ?? 0) === 1 ? [
                    'name'  => trim((string)($p['delivery_name'] ?? '')) ?: 'Доставка',
                    'price' => (float)($p['delivery_price'] ?? 0),
                ] : null,
                'invoices'  => self::invoices((int)$p['id']),
                'can_delete' => !in_array((string)$p['status'], ['sent', 'order_created'], true)
                             && !Db::val("SELECT COUNT(*) FROM invoices WHERE proposal_id=?", [(int)$p['id']]),
            ];
        }

        // Пул: строки запроса, которых нет ни в одном КП. «Не наша
        // номенклатура» сюда не попадает — её и перетаскивать некуда.
        $pool = [];
        foreach (RequestItems::all($requestId) as $row) {
            if ((int)($row['is_out_of_scope'] ?? 0) === 1) continue;
            if (isset($used[(int)$row['id']])) continue;
            $pool[] = [
                'id'           => (int)$row['id'],
                'raw_name'     => (string)($row['raw_name'] ?? ''),
                'product_name' => (string)($row['product_name'] ?? ''),
                'unit'         => (string)($row['unit'] ?: 'шт.'),
                'quantity'     => (float)$row['quantity'],
                'price'        => (float)($row['price'] ?? 0),
                'notes'        => $row['notes'],
            ];
        }

        return ['proposals' => $out, 'pool' => $pool];
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

    /** Как КП называется на доске: имя менеджера, иначе номер, иначе «КП N». */
    public static function title(array $proposal, int $ordinal): string {
        $label = trim((string)($proposal['label'] ?? ''));
        if ($label !== '') return $label;
        $number = trim((string)($proposal['number'] ?? ''));
        return $number !== '' ? 'КП ' . $number : 'КП ' . $ordinal;
    }

    // ------------------------------------------------------------- частности

    /** Положить позицию в КП на заданное место и перенумеровать соседей. */
    private static function place(int $itemId, int $proposalId, int $position): void {
        $siblings = array_map('intval', array_column(Db::all(
            "SELECT id FROM proposal_items WHERE proposal_id=? AND id<>? ORDER BY position, id",
            [$proposalId, $itemId]), 'id'));
        $position = max(0, min(count($siblings), $position));
        array_splice($siblings, $position, 0, [$itemId]);

        foreach ($siblings as $i => $id) {
            $data = ['position' => $i + 1];
            if ($id === $itemId) $data['proposal_id'] = $proposalId;
            Db::update('proposal_items', $data, 'id=?', [$id]);
        }
    }

    private static function nextPosition(int $proposalId): int {
        return (int)Db::val("SELECT COALESCE(MAX(position), 0) + 1 FROM proposal_items WHERE proposal_id=?",
                            [$proposalId]);
    }

    /**
     * Условия ожидания из таблицы подбора — в КП этого запроса (модуль 035).
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
        $source = [];
        foreach (Db::all("SELECT id, wait_on, wait_months, wait_discount, wait_prepay
                          FROM request_items WHERE request_id=?", [$requestId]) as $row) {
            $source[(int)$row['id']] = $row;
        }
        if (!$source) return 0;

        $items = Db::all(
            "SELECT i.id, i.proposal_id, i.request_item_id,
                    i.wait_on, i.wait_months, i.wait_discount, i.wait_prepay
             FROM proposal_items i
             JOIN proposals p ON p.id = i.proposal_id
             WHERE p.request_id=? AND p.status NOT IN ('sent', 'order_created')
               AND i.request_item_id IS NOT NULL", [$requestId]);

        $touched = [];
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

    /** Убрать дыры в нумерации после удаления строки. */
    public static function resequence(int $proposalId): void {
        foreach (Db::all("SELECT id FROM proposal_items WHERE proposal_id=? ORDER BY position, id",
                         [$proposalId]) as $i => $row) {
            Db::update('proposal_items', ['position' => $i + 1], 'id=?', [(int)$row['id']]);
        }
    }

    /**
     * Пересобрать документ после правки позиций.
     *
     * Best-effort: не собравшийся PDF не должен отменять перетаскивание —
     * строка уже там, где её положил человек.
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
