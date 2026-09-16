<?php
/**
 * Черновик письма и карточка, которая из него вырастает (модуль 029).
 *
 * A letter being written is work in progress, and until now it was invisible:
 * the draft of a NEW letter could not even be saved (the API wanted a message
 * id or a thread key, and a first letter has neither), and nothing on the board
 * said that somebody is writing to this company right now.
 *
 * Here a draft is a first-class object: it is stored from the first keystroke,
 * it knows whom it is addressed to, and it puts a card into «В работе» — filled
 * in from the draft itself, so the card is a company with an ИНН and a subject,
 * not an empty rectangle. Sending the letter does not lose the card: the card
 * stops being a draft card and keeps the column the manager left it in.
 */
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/boards.php';
require_once __DIR__ . '/mail_text.php';

final class MailDrafts {

    /**
     * Save (or clear) the draft and keep its board card in step.
     *
     * @param array $o draft_id, mail_message_id, thread_key, counterparty_id, to, subject, body
     * @return array{saved:bool,draft_id:?int,card_id:?int,column:?string,counterparty_id:?int,facts:array}
     */
    public static function save(array $o, int $managerId): array {
        $body = (string)($o['body'] ?? '');
        $row  = self::find($o, $managerId);

        // Пустой черновик — это не черновик, а стёртое поле
        if (trim(strip_tags($body)) === '') {
            if ($row) self::drop((int)$row['id']);
            return ['saved' => false, 'draft_id' => null, 'card_id' => null,
                    'column' => null, 'counterparty_id' => null, 'facts' => []];
        }

        $to      = trim((string)($o['to'] ?? ($row['to_email'] ?? '')));
        $subject = (string)($o['subject'] ?? ($row['subject'] ?? ''));
        $facts   = self::facts($body, $to);

        $cpId = !empty($o['counterparty_id']) ? Crm::rootId((int)$o['counterparty_id'])
              : (!empty($row['counterparty_id']) ? Crm::rootId((int)$row['counterparty_id']) : null);
        if (!$cpId) $cpId = self::companyFor($facts, $to);
        if ($cpId) self::enrich($cpId, $facts);

        $data = [
            'mail_message_id' => !empty($o['mail_message_id']) ? (int)$o['mail_message_id'] : ($row['mail_message_id'] ?? null),
            'thread_key'      => trim((string)($o['thread_key'] ?? '')) ?: ($row['thread_key'] ?? null),
            'counterparty_id' => $cpId,
            'to_email'        => $to ?: null,
            'manager_id'      => $managerId,
            'body'            => $body,
            'subject'         => $subject,
            'updated_at'      => date('Y-m-d H:i:s'),
        ];
        if ($row) { Db::update('mail_drafts', $data, 'id=?', [(int)$row['id']]); $id = (int)$row['id']; }
        else      { $id = Db::insert('mail_drafts', $data); }

        $card = Boards::draftCard($id, [
            'counterparty_id' => $cpId,
            'thread_key'      => $data['thread_key'],
            'title'           => $facts['company'] ?: ($to ?: 'Новое письмо'),
            'manager_id'      => $managerId,
        ]);

        // Текст письма назад не отдаём — он и так в поле у менеджера
        unset($facts['text']);
        return ['saved' => true, 'draft_id' => $id, 'card_id' => $card['id'] ?? null,
                'column' => $card['column'] ?? null, 'counterparty_id' => $cpId, 'facts' => $facts];
    }

    /**
     * The draft this composer is showing: an answer lives on its letter or its
     * thread, a first letter lives on the company it is addressed to.
     */
    public static function find(array $o, int $managerId): ?array {
        // Ключи перебираются по очереди, а не «первый заполненный решает»:
        // ответ, сохранённый по цепочке, ищут потом по письму — и наоборот
        $draftId = (int)($o['draft_id'] ?? 0);
        if ($draftId) {
            $row = Db::one("SELECT * FROM mail_drafts WHERE id=? AND manager_id=?", [$draftId, $managerId]);
            if ($row) return $row;
        }
        $msgId = (int)($o['mail_message_id'] ?? 0);
        if ($msgId) {
            $row = Db::one("SELECT * FROM mail_drafts WHERE mail_message_id=? AND manager_id=?", [$msgId, $managerId]);
            if ($row) return $row;
        }
        $key = trim((string)($o['thread_key'] ?? ''));
        if ($key !== '') {
            $row = Db::one("SELECT * FROM mail_drafts WHERE thread_key=? AND manager_id=? ORDER BY id DESC LIMIT 1",
                           [$key, $managerId]);
            if ($row) return $row;
        }
        $cpId = !empty($o['counterparty_id']) ? Crm::rootId((int)$o['counterparty_id']) : 0;
        if ($cpId) {
            return Db::one("SELECT * FROM mail_drafts
                            WHERE counterparty_id=? AND manager_id=? AND mail_message_id IS NULL AND thread_key IS NULL
                            ORDER BY id DESC LIMIT 1", [$cpId, $managerId]);
        }
        return null;
    }

    /** Письмо ушло: карточка остаётся, черновик — нет. */
    public static function sent(array $o, int $managerId, array $letter): void {
        $row = self::find($o, $managerId);
        // Черновика могло и не быть — ответ написали и отправили одним
        // движением. Карточка нужна всё равно: письмо написано, значит работа
        Boards::adoptDraftCard($row ? (int)$row['id'] : 0, $letter);
        if ($row) Db::q("DELETE FROM mail_drafts WHERE id=?", [(int)$row['id']]);

        // Того же письма черновиков больше не остаётся: ни по письму, ни по цепочке
        $msgId = (int)($o['mail_message_id'] ?? 0);
        if ($msgId) Db::q("DELETE FROM mail_drafts WHERE mail_message_id=? AND manager_id=?", [$msgId, $managerId]);
        $key = trim((string)($letter['thread_key'] ?? $o['thread_key'] ?? ''));
        if ($key !== '') Db::q("DELETE FROM mail_drafts WHERE thread_key=? AND manager_id=?", [$key, $managerId]);
    }

    /** Убрать черновик руками — вместе с карточкой, если она только им и жила. */
    public static function clear(array $o, int $managerId): void {
        $row = self::find($o, $managerId);
        if ($row) self::drop((int)$row['id']);
    }

    private static function drop(int $draftId): void {
        Boards::dropDraftCard($draftId);
        Db::q("DELETE FROM mail_drafts WHERE id=?", [$draftId]);
    }

    /**
     * Что письмо говорит о себе само: компания, ИНН, телефон, о чём оно.
     *
     * Это и есть «полноценная карточка»: менеджер пишет письмо, а карточка на
     * доске уже знает, кому и о чём, — без второго ввода тех же данных руками.
     *
     * @return array{text:string,company:string,inn:string,phone:string,preview:string}
     */
    public static function facts(string $body, string $to): array {
        $text = trim(MailText::fromHtml($body));
        $req  = Crm::requisitesFromText($text);
        $company = Crm::companyFromText($text);

        $phone = '';
        if (preg_match('/(?:\+7|8)[\s\-()]*\d{3}[\s\-()]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}/u', $text, $m)) {
            $phone = trim($m[0]);
        }
        return [
            'text'    => $text,
            'company' => $company,
            'inn'     => (string)($req['inn'] ?? ''),
            'phone'   => $phone,
            'preview' => MailText::preview($text, 140),
        ];
    }

    /**
     * Компания, которой пишут. Найденная — та же карточка, что и у входящих
     * писем (ИНН → домен → название); ненайденная заводится, иначе черновик
     * повиснет на доске карточкой-сиротой без истории и реквизитов.
     */
    private static function companyFor(array $facts, string $to): ?int {
        if ($to === '' && $facts['company'] === '') return null;
        if ($to !== '' && Crm::isOurAddress($to)) return null;

        $hints = [
            'inn'   => $facts['inn'],
            'name'  => $facts['company'],
            'email' => $to,
            'phone' => $facts['phone'] ?: null,
            'text'  => $facts['text'],
        ];
        $found = Crm::findCounterparty($hints);
        if ($found) return $found;
        // Писать в пустоту нельзя: либо есть адрес, либо есть название
        return Crm::resolveCounterparty($hints);
    }

    /** ИНН и телефон из тела письма — на карточку компании, если там пусто. */
    private static function enrich(int $cpId, array $facts): void {
        $cp = Db::one("SELECT inn, contact_phone FROM counterparties WHERE id=?", [$cpId]);
        if (!$cp) return;
        $upd = [];
        if ($facts['inn'] !== '' && trim((string)($cp['inn'] ?? '')) === '') {
            $upd['inn'] = Crm::cleanInn($facts['inn']);
        }
        if ($facts['phone'] !== '' && trim((string)($cp['contact_phone'] ?? '')) === '') {
            $upd['contact_phone'] = $facts['phone'];
        }
        if ($upd) Db::update('counterparties', array_filter($upd) + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$cpId]);
    }
}
