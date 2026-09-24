<?php
/**
 * Подтверждение КП — в момент, когда его прикладывают к письму (модуль 060).
 *
 * Отдельной кнопки «Подтвердить и отправить» больше нет: КП уходит только из
 * редактора письма (issue #94). То, что делало подтверждение — вопрос о
 * позициях без цены, уроки из правок менеджера, свежие остатки, — делается
 * здесь, до сборки файла.
 */
require_once __DIR__ . '/kp_content.php';

final class KpConfirm {

    /**
     * SC-005 (модуль 018): КП без цены не уходит молча. Менеджеру задают вопрос
     * один раз, по позициям, и его ответ хранится на КП. Цена, поправленная
     * потом, снимает ответ — и вопрос возвращается.
     *
     * @return ?array полезная нагрузка `no_price`, пока вопрос не отвечен
     */
    public static function priceGate(int $proposalId, array $input, int $managerId): ?array {
        $gaps = KpContent::priceGaps($proposalId);
        if (!$gaps['items'] && !$gaps['empty']) return null;

        $stored = json_decode((string)(Db::val("SELECT no_price_ack_json FROM proposals WHERE id=?", [$proposalId]) ?: ''), true);
        $ackedIds = is_array($stored) ? array_map('intval', (array)($stored['items'] ?? [])) : [];
        $ackedEmpty = is_array($stored) && !empty($stored['empty']);

        $gapIds = array_map('intval', array_column($gaps['items'], 'id'));
        // Всё уже отвечено — и пустое КП отвечено именно как пустое
        if (!array_diff($gapIds, $ackedIds) && (!$gaps['empty'] || $ackedEmpty)) return null;

        if (empty($input['no_price_ack'])) {
            return [
                'message' => $gaps['empty']
                    ? 'В КП нет ни одной позиции — подтвердите, что отправляем его таким.'
                    : 'Без цены: ' . count($gaps['items']) . ' поз. Подтвердите, что отправляем КП без цены по ним.',
                'items' => $gaps['items'],
                'total' => $gaps['total'],
                'empty' => $gaps['empty'],
            ];
        }

        Db::update('proposals', ['no_price_ack_json' => json_encode([
            'items'      => $gapIds,
            'empty'      => $gaps['empty'],
            'manager_id' => $managerId,
            'at'         => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE)], 'id=?', [$proposalId]);
        Logger::warning('kp', 'КП уходит без цены по ' . count($gaps['items']) . ' поз.', [
            'proposal_id' => $proposalId, 'manager_id' => $managerId,
            'positions'   => array_column($gaps['items'], 'position'),
        ]);
        return null;
    }

    /**
     * Перед сборкой файла: уроки из правок (US4), сегодняшние остатки, статус.
     * Урок хранится один раз — приложить то же КП дважды не учит дважды.
     */
    public static function prepare(int $proposalId, int $managerId): void {
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        if (!$proposal) return;

        $learn = function (string $field, string $auto, string $text, array $ctx = []) use ($proposal, $managerId) {
            if (trim($text) === '' || trim($auto) === trim($text)) return;
            // «IS», а не «=»: у ручного КП запроса нет, и NULL = NULL не совпадает
            if (Db::one("SELECT id FROM corrections WHERE field=? AND auto_text=? AND manager_text=? AND request_id IS ? LIMIT 1",
                        [$field, $auto, $text, $proposal['request_id']])) return;
            Db::insert('corrections', [
                'request_id'   => $proposal['request_id'],
                'field'        => $field,
                'auto_text'    => $auto,
                'manager_text' => $text,
                'manager_id'   => $managerId,
                'context_json' => json_encode($ctx + ['counterparty_id' => $proposal['counterparty_id']], JSON_UNESCAPED_UNICODE),
            ]);
        };

        try {
            if ($proposal['cover_letter'] && $proposal['cover_letter_final']) {
                $learn('cover_letter', (string)$proposal['cover_letter'], (string)$proposal['cover_letter_final']);
            }
            // Абзацы генерировались из предыдущего КП: не изменившийся — не правка
            $prev = Db::one("SELECT pre_table_text, post_table_text FROM proposals
                             WHERE id<>? AND status<>'draft' ORDER BY id DESC LIMIT 1", [$proposalId]);
            $learn('pre_table',  (string)($prev['pre_table_text'] ?? ''),  (string)$proposal['pre_table_text']);
            $learn('post_table', (string)($prev['post_table_text'] ?? ''), (string)$proposal['post_table_text']);

            foreach (KpContent::substitutions(Db::all("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY position", [$proposalId])) as $s) {
                $learn('item_substitution', $s['requested'],
                       $s['offered'] . ($s['note'] !== '' ? ' — ' . $s['note'] : ''),
                       ['proposal_id' => $proposalId]);
            }
        } catch (Throwable $e) {
            // Урок не записался — письмо это не останавливает
            Logger::exception('kp', $e, ['proposal_id' => $proposalId, 'stage' => 'learn']);
        }

        KpContent::refreshStock($proposalId);
        Db::q("UPDATE proposals SET status='confirmed', updated_at=? WHERE id=? AND status='draft'",
              [date('Y-m-d H:i:s'), $proposalId]);
    }
}
