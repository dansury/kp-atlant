<?php
/**
 * Что клиент просил указать в самом КП (модуль 046, issue #67).
 *
 * Разбор письма (`parse_request` / `classify_request`) возвращает
 * `kp_requirements` — «страна производства», «гарантийный срок». При сборке КП
 * нейросеть пишет на них абзац документа (`post_table_text`) только по фактам:
 * условия КП, позиции, база знаний. Чего не знает — пишет «уточним». Менеджер
 * видит список требований над листом A4 и правит текст там же.
 */
require_once __DIR__ . '/kp_terms.php';

final class KpRequirements {

    /** Требования клиента к содержанию КП, как их прочитал разбор письма. */
    public static function of(int $requestId): array {
        if ($requestId <= 0) return [];
        $json = Db::val("SELECT parsed_json FROM requests WHERE id=?", [$requestId]);
        $parsed = $json ? (json_decode((string)$json, true) ?: []) : [];
        $list = $parsed['kp_requirements'] ?? [];
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $r) {
            $r = trim((string)(is_array($r) ? ($r['text'] ?? $r['name'] ?? '') : $r));
            if ($r !== '' && mb_strlen($r) <= 300) $out[mb_strtolower($r)] ??= $r;
        }
        return array_values(array_slice($out, 0, 12));
    }

    /**
     * Абзац КП на требования; пусто — требований нет или модель не ответила.
     * Сбой модели КП не ломает: документ собирается без этого абзаца.
     */
    public static function compose(int $proposalId): string {
        $p = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        if (!$p) return '';
        $asks = self::of((int)$p['request_id']);
        if (!$asks) return '';

        $items = Db::all("SELECT product_name, quantity, unit FROM proposal_items WHERE proposal_id=? ORDER BY position", [$proposalId]);
        $user = "ТРЕБОВАНИЯ КЛИЕНТА К КП:\n- " . implode("\n- ", $asks)
              . "\n\nУСЛОВИЯ КП:\n" . trim(KpTerms::forProposal($p))
              . "\n\nПОЗИЦИИ:\n" . implode("\n", array_map(
                    fn($i) => '- ' . $i['product_name'] . ', ' . (float)$i['quantity'] . ' ' . $i['unit'], $items));
        try {
            require_once __DIR__ . '/knowledge.php';
            $system = Prompts::render('kp_requirements', [
                'knowledge' => Knowledge::context(implode(' ', $asks), 'kp_requirements'),
            ]);
            $text = trim((string)(LLM::chatJson($system, $user)['text'] ?? ''));
        } catch (Throwable $e) {
            Logger::exception('kp', $e, ['proposal_id' => $proposalId, 'stage' => 'kp_requirements']);
            return '';
        }
        return mb_substr($text, 0, 3000);
    }

    /** Дописать абзац в блок под таблицей — один раз, при заведении КП. */
    public static function apply(int $proposalId): void {
        $text = self::compose($proposalId);
        if ($text === '') return;
        $was = trim((string)Db::val("SELECT post_table_text FROM proposals WHERE id=?", [$proposalId]));
        Db::update('proposals', ['post_table_text' => $was === '' ? $text : $was . "\n\n" . $text],
                   'id=?', [$proposalId]);
    }
}
