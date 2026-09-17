<?php
/**
 * The board (modules 010 + 011) — one kanban where the whole office works.
 *
 * A card is a COMPANY, not a letter and not a request: opening it shows every
 * conversation that company ever sent, the requests those letters became and
 * the КП that answered them. Companies, requests and mail were three lists a
 * manager had to cross-check by hand; here they are one object that moves
 * between columns.
 *
 * Nothing is put on the board by hand any more: `sync()` drops every company
 * that wrote to us into «Входящие», and a sender we do not know yet lands there
 * as a conversation card until a company card is resolved for it.
 */
require_once __DIR__ . '/mail_threads.php';
require_once __DIR__ . '/mail_text.php';
require_once __DIR__ . '/crm.php';

final class Boards {

    /** A first board that already makes sense for a КП pipeline. */
    private const DEFAULT_COLUMNS = [
        ['Входящие',      '#6b7fd7', 'inbox'],
        ['В работе',      '#e0a53c', 'work'],
        ['КП отправлено', '#4f9e57', null],
        ['Ждём оплату',   '#b45cc0', null],
        ['Закрыто',       '#8a8f98', null],
    ];

    /** Letters of these categories never make a card of their own. */
    private const IGNORED_CATEGORIES = ['spam', 'service', 'not_our_profile'];

    /** Never flood the board on the first run over a large archive. */
    private const SYNC_LIMIT = 400;

    public static function all(): array {
        $rows = Db::all("SELECT b.*, (SELECT COUNT(*) FROM board_columns c
                                      JOIN board_cards d ON d.column_id = c.id
                                      WHERE c.board_id = b.id AND d.dismissed_at IS NULL) AS cards
                         FROM boards b ORDER BY b.position, b.id");
        // «Письма» (item 2) treats the board as always there — one letter
        // program, not a list you might find empty. An account with none yet
        // gets the usual КП pipeline columns instead of a dead end.
        if (!$rows) {
            self::createBoard('Письма');
            return self::all();
        }
        foreach ($rows as &$r) $r['cards'] = (int)$r['cards'];
        return $rows;
    }

    /** The single board this office works on — always exists (see all()). */
    public static function singleton(): array {
        return self::all()[0];
    }

    /**
     * The column new mail falls into. Named, not «the leftmost one», so
     * reordering the columns cannot silently redirect the intake.
     */
    public static function inboxColumn(int $boardId): ?array {
        return Db::one("SELECT * FROM board_columns WHERE board_id=? AND kind='inbox' ORDER BY position, id LIMIT 1", [$boardId])
            ?: Db::one("SELECT * FROM board_columns WHERE board_id=? ORDER BY position, id LIMIT 1", [$boardId]);
    }

    /**
     * The column a letter being written falls into. Named like the intake one,
     * so renaming «В работе» does not send the drafts back to «Входящие».
     */
    public static function workColumn(int $boardId): ?array {
        return Db::one("SELECT * FROM board_columns WHERE board_id=? AND kind='work' ORDER BY position, id LIMIT 1", [$boardId])
            ?: Db::one("SELECT * FROM board_columns WHERE board_id=? AND title='В работе' ORDER BY position, id LIMIT 1", [$boardId])
            ?: Db::one("SELECT * FROM board_columns WHERE board_id=? AND (kind IS NULL OR kind<>'inbox') ORDER BY position, id LIMIT 1", [$boardId])
            ?: self::inboxColumn($boardId);
    }

    /** The board with its columns and cards — one request paints the whole page. */
    public static function get(int $id): ?array {
        $board = Db::one("SELECT * FROM boards WHERE id=?", [$id]);
        if (!$board) return null;

        $columns = Db::all("SELECT * FROM board_columns WHERE board_id=? ORDER BY position, id", [$id]);
        // Every card of the board is decorated in one batch — the counters of a
        // company are two queries for the whole board, not two per card.
        // Снятая с доски карточка на экране не появляется, но строка её живёт
        // дальше: `sync()` не заводит её заново, а колонка, в которой она
        // стояла, не теряется (модуль 031).
        $cards = Db::all(
            "SELECT d.*, g.name AS manager_name FROM board_cards d
             JOIN board_columns c ON c.id = d.column_id
             LEFT JOIN managers g ON g.id = d.manager_id
             WHERE c.board_id=? AND d.dismissed_at IS NULL ORDER BY d.position, d.id", [$id]
        );
        self::decorateAll($cards);

        $byColumn = [];
        foreach ($cards as $card) $byColumn[(int)$card['column_id']][] = $card;
        foreach ($columns as &$col) {
            $col['id'] = (int)$col['id'];
            $col['cards'] = self::sortCards($byColumn[$col['id']] ?? []);
        }
        unset($col);

        return $board + ['columns' => $columns];
    }

    /**
     * A card with fresh mail belongs at the top of its column, whatever column
     * the manager dragged it into — «пришло новое письмо» is the one thing that
     * must never be scrolled to. Everything else keeps the order it was dragged
     * into, so the board a manager arranged still looks arranged.
     */
    private static function sortCards(array $cards): array {
        usort($cards, function ($a, $b) {
            $ha = !empty($a['hot']) ? 1 : 0;
            $hb = !empty($b['hot']) ? 1 : 0;
            if ($ha !== $hb) return $hb <=> $ha;
            if ($ha) return strcmp((string)($b['last_at'] ?? ''), (string)($a['last_at'] ?? ''));
            return [(int)$a['position'], (int)$a['id']] <=> [(int)$b['position'], (int)$b['id']];
        });
        return array_values($cards);
    }

    /**
     * Live state of what a card carries — for a company card that is its whole
     * mail history, not one thread: how many letters, how many unread, whether
     * the last word was theirs (an unanswered card is what a board is for).
     *
     * Written as batched queries on purpose: a board of two hundred companies
     * must not cost two hundred round trips per column.
     *
     * @param array<int,array> $cards passed by reference, decorated in place
     */
    private static function decorateAll(array &$cards): void {
        $cpIds = [];
        foreach ($cards as $c) if (!empty($c['counterparty_id'])) $cpIds[] = (int)$c['counterparty_id'];
        $cpIds = array_values(array_unique($cpIds));

        $stats = $cpIds ? self::companyStats($cpIds) : [];
        $drafts = self::draftsOf($cards);

        foreach ($cards as &$card) {
            $card['id'] = (int)$card['id'];
            $card['position'] = (int)$card['position'];
            $card['counterparty_id'] = $card['counterparty_id'] ? (int)$card['counterparty_id'] : null;
            $card['kind'] = $card['counterparty_id'] ? 'company' : ($card['thread_key'] ? 'thread' : 'note');
            $card['company'] = null;
            $card['thread'] = null;
            $card['unread'] = 0;
            $card['unanswered'] = false;
            $card['last_at'] = null;

            if ($card['counterparty_id'] && isset($stats[$card['counterparty_id']])) {
                $s = $stats[$card['counterparty_id']];
                $card['company'] = $s;
                $card['unread'] = $s['unread'];
                $card['unanswered'] = $s['unanswered'];
                $card['last_at'] = $s['last_at'];
                if (trim((string)$card['title']) === '' || $card['title'] === 'Карточка') $card['title'] = $s['name'];
            } elseif (!empty($card['thread_key'])) {
                $t = MailThreads::summary((string)$card['thread_key']);
                if ($t) {
                    $card['thread'] = [
                        'subject'   => $t['subject'],
                        'count'     => $t['count'],
                        'unread'    => $t['unread'],
                        'last_at'   => $t['last_at'],
                        'last_direction'    => $t['last_direction'],
                        'last_from'         => $t['last_from'],
                        'counterparty_id'   => $t['counterparty_id'],
                        'counterparty_name' => $t['counterparty_name'],
                        'mailboxes' => array_map(fn($b) => $b['name'], $t['mailboxes']),
                    ];
                    $card['unread'] = $t['unread'];
                    $card['unanswered'] = $t['last_direction'] === 'in';
                    $card['last_at'] = $t['last_at'];
                    if (trim((string)$card['title']) === '') $card['title'] = $t['subject'];
                }
            }
            // Письмо, которое пишут прямо сейчас: карточка говорит, кому и о чём,
            // ещё до отправки (модуль 033)
            $card['draft'] = $drafts[(int)($card['draft_id'] ?? 0)] ?? null;
            if (!$card['draft']) $card['draft_id'] = null;
            elseif ($card['kind'] === 'note') $card['kind'] = 'draft';

            // «Прочитано», нажатое на карточке, гасит и жирный шрифт (модуль 026).
            // Жирность даёт «ждёт ответа», а оно считается по датам писем —
            // отметить карточку разобранной было нечем, и групповое «Прочитано»
            // выглядело как кнопка, которая ничего не делает.
            $card['seen_at'] = $card['seen_at'] ?? null;
            if ($card['seen_at'] && $card['last_at'] && (string)$card['seen_at'] >= (string)$card['last_at']) {
                $card['unanswered'] = false;
            }
            // Bright and on top: a letter nobody has read, or one nobody has answered
            $card['hot'] = $card['unread'] > 0 || $card['unanswered'];
        }
        unset($card);
    }

    /**
     * Черновики всех карточек доски одним запросом — тема, адресат и начало
     * текста того, что пишут.
     *
     * @param array<int,array> $cards
     * @return array<int,array>
     */
    private static function draftsOf(array $cards): array {
        $ids = [];
        foreach ($cards as $c) if (!empty($c['draft_id'])) $ids[] = (int)$c['draft_id'];
        $ids = array_values(array_unique($ids));
        if (!$ids) return [];

        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Db::all("SELECT id, subject, to_email, body, updated_at FROM mail_drafts WHERE id IN ($in)", $ids) as $d) {
            $out[(int)$d['id']] = [
                'subject'    => (string)($d['subject'] ?? ''),
                'to'         => (string)($d['to_email'] ?? ''),
                'preview'    => MailText::preview(MailText::fromHtml((string)$d['body']), 140),
                'updated_at' => $d['updated_at'],
            ];
        }
        return $out;
    }

    /**
     * Everything the board needs to know about a company, for many companies at
     * once: mail counters, who spoke last, open requests, the last КП.
     *
     * @param int[] $ids
     * @return array<int,array>
     */
    private static function companyStats(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));

        $out = [];
        foreach (Db::all("SELECT id, name, inn, contact_email, default_price_type FROM counterparties WHERE id IN ($in)", $ids) as $c) {
            $out[(int)$c['id']] = [
                'id'            => (int)$c['id'],
                'name'          => (string)$c['name'],
                'inn'           => $c['inn'],
                'contact_email' => $c['contact_email'],
                'letters'       => 0, 'unread' => 0, 'threads' => 0,
                'last_at'       => null, 'last_in_at' => null, 'last_out_at' => null,
                'unanswered'    => false, 'has_attachment' => false,
                'subject'       => null, 'preview' => null,
                'requests_open' => 0, 'proposal_status' => null, 'proposal_id' => null,
                // Ящики, в которые писала компания, — по ним фильтруется доска
                'mailbox_ids'   => [],
            ];
        }
        if (!$out) return [];

        // A merged card's letters belong to the company it was merged into
        $map = [];
        foreach ($ids as $id) $map[$id] = $id;
        foreach (Db::all("SELECT id, merged_into_id FROM counterparties WHERE merged_into_id IN ($in)", $ids) as $m) {
            $map[(int)$m['id']] = (int)$m['merged_into_id'];
        }
        $mailIds = array_keys($map);
        $mIn = implode(',', array_fill(0, count($mailIds), '?'));

        $rows = Db::all(
            "SELECT counterparty_id,
                    COUNT(*) AS letters,
                    COUNT(DISTINCT thread_key) AS threads,
                    SUM(CASE WHEN direction='in' AND is_read=0 THEN 1 ELSE 0 END) AS unread,
                    SUM(has_attachment) AS files,
                    GROUP_CONCAT(DISTINCT mailbox_id) AS mailbox_ids,
                    MAX(date_at) AS last_at,
                    MAX(CASE WHEN direction='in'  THEN date_at END) AS last_in_at,
                    MAX(CASE WHEN direction='out' THEN date_at END) AS last_out_at
             FROM mail_messages WHERE counterparty_id IN ($mIn) AND archived_at IS NULL
             GROUP BY counterparty_id", $mailIds
        );
        foreach ($rows as $r) {
            $id = $map[(int)$r['counterparty_id']] ?? null;
            if ($id === null || !isset($out[$id])) continue;
            $c = &$out[$id];
            $c['letters'] += (int)$r['letters'];
            $c['threads'] += (int)$r['threads'];
            $c['unread']  += (int)$r['unread'];
            $c['has_attachment'] = $c['has_attachment'] || (int)$r['files'] > 0;
            foreach (explode(',', (string)($r['mailbox_ids'] ?? '')) as $mb) {
                if ($mb !== '' && !in_array((int)$mb, $c['mailbox_ids'], true)) $c['mailbox_ids'][] = (int)$mb;
            }
            foreach (['last_at', 'last_in_at', 'last_out_at'] as $f) {
                if ($r[$f] && (string)$r[$f] > (string)$c[$f]) $c[$f] = $r[$f];
            }
            unset($c);
        }

        // The subject and the first line of the newest letter — a card should
        // say what the company wants without being opened
        foreach ($mailIds as $mid) {
            $root = $map[$mid];
            if (!isset($out[$root])) continue;
            $last = Db::one("SELECT thread_subject, subject, body_text, date_at FROM mail_messages
                             WHERE counterparty_id=? AND archived_at IS NULL
                             ORDER BY date_at DESC, id DESC LIMIT 1", [$mid]);
            if (!$last) continue;
            if ($out[$root]['subject'] === null || (string)$last['date_at'] >= (string)$out[$root]['last_at']) {
                $out[$root]['subject'] = $last['thread_subject'] ?: $last['subject'];
                $out[$root]['preview'] = mb_substr(trim((string)$last['body_text']), 0, 140);
            }
        }

        foreach (Db::all("SELECT counterparty_id, COUNT(*) AS n FROM requests
                          WHERE counterparty_id IN ($mIn) AND status NOT IN ('closed','ordered')
                          GROUP BY counterparty_id", $mailIds) as $r) {
            $id = $map[(int)$r['counterparty_id']] ?? null;
            if ($id !== null && isset($out[$id])) $out[$id]['requests_open'] += (int)$r['n'];
        }

        foreach (Db::all("SELECT p.counterparty_id, p.id, p.status, p.created_at FROM proposals p
                          WHERE p.counterparty_id IN ($mIn) ORDER BY p.created_at", $mailIds) as $p) {
            $id = $map[(int)$p['counterparty_id']] ?? null;
            if ($id !== null && isset($out[$id])) {
                $out[$id]['proposal_id'] = (int)$p['id'];
                $out[$id]['proposal_status'] = $p['status'];
            }
        }

        foreach ($out as &$c) {
            // They wrote last → the ball is ours. That is the whole «жирным
            // шрифтом / блёклым шрифтом» rule of the board, decided here once.
            $c['unanswered'] = $c['last_in_at'] !== null
                && ((string)$c['last_in_at'] > (string)$c['last_out_at']);
        }
        unset($c);
        return $out;
    }

    /**
     * Put on the board everything that has written to us and is not there yet.
     * Runs on every board open: «Входящие» is not a list somebody fills in, it
     * is the mail itself.
     *
     * A company gets ONE card however many conversations it has — that is the
     * merger of companies, requests and letters into a single object. A sender
     * we cannot resolve to a company keeps a conversation card until we can,
     * and that card is upgraded in place (staying in whatever column it was
     * dragged to) the moment the company is known.
     */
    public static function sync(?int $boardId = null, bool $force = false): array {
        if ((int)Settings::get('BOARD_AUTOLOAD', 1) !== 1) return ['created' => 0, 'upgraded' => 0];

        $boardId = $boardId ?: (int)self::singleton()['id'];
        $inbox = self::inboxColumn($boardId);
        if (!$inbox) return ['created' => 0, 'upgraded' => 0];

        // The board is opened all day and the archive only changes when mail
        // arrives or triage resolves a sender. Four counters say whether
        // anything did — cheaper than grouping the whole archive every time.
        $sig = fn() => implode(':', [
            $boardId,
            (string)Db::val("SELECT COALESCE(MAX(id), 0) FROM mail_messages"),
            (string)Db::val("SELECT COUNT(*) FROM mail_messages WHERE counterparty_id IS NOT NULL"),
            // Письма, вернувшиеся из архива, — тоже изменение доски: без этого
            // счётчика «Вернуть в работу» возвращало письма, а карточка, снятая
            // вместе с ними, не возвращалась (модуль 026)
            (string)Db::val("SELECT COUNT(*) FROM mail_messages WHERE archived_at IS NULL"),
            (string)Db::val("SELECT COALESCE(MAX(id), 0) FROM counterparties"),
            (string)Db::val("SELECT COUNT(*) FROM board_cards WHERE dismissed_at IS NULL"),
        ]);
        $before = $sig();
        if (!$force && (string)Db::val("SELECT value FROM settings WHERE key='board_sync_sig'") === $before) {
            return ['created' => 0, 'upgraded' => 0, 'skipped' => true];
        }

        $days  = max(1, (int)Settings::get('BOARD_INBOX_DAYS', 180));
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $ignored = "'" . implode("','", self::IGNORED_CATEGORIES) . "'";
        $created = $upgraded = 0;

        // Снятые карточки считаются стоящими на доске: иначе интейк заводил бы
        // их заново каждым открытием доски, и «убрать с доски» не значило бы
        // ничего (модуль 031). Возвращает такую карточку новое письмо — ниже.
        //
        // Карточка числится за той компанией, в которую её компанию слили:
        // интейк группирует письма по КОРНЮ семьи, и карточка, оставшаяся на
        // слитом id, выглядела для него отсутствующей — рядом с разложенной
        // заводилась вторая, во «Входящих» (модуль 031).
        //
        // Карточка ищется по ВСЕМ доскам, а не только по этой (модуль 036):
        // перенесённая на другую доску переставала быть видимой интейку, и
        // рядом с ней заводилась вторая, во «Входящих», — перенос выглядел
        // задваиванием. Доска у сервиса одна по замыслу, но заводить их никто
        // не мешает, и одна компания — это одна карточка, а не одна на доску.
        $onBoard = Db::all("SELECT d.id, COALESCE(cp.merged_into_id, d.counterparty_id) AS root_id,
                                   d.counterparty_id, d.thread_key, c.board_id
                            FROM board_cards d
                            JOIN board_columns c ON c.id = d.column_id
                            LEFT JOIN counterparties cp ON cp.id = d.counterparty_id");
        $haveCp = $haveThread = [];
        foreach ($onBoard as $r) {
            if ($r['root_id']) {
                $root = (int)$r['root_id'];
                $haveCp[$root] = true;
                // Строку двигаем на корень: дальше она живёт как карточка той
                // компании, под которой её теперь ищут и письма, и поиск.
                // Правится только своя доска: чужую эта синхронизация не ведёт.
                if ((int)$r['board_id'] === $boardId && (int)$r['counterparty_id'] !== $root) {
                    $name = (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$root]) ?: '');
                    Db::update('board_cards',
                               ['counterparty_id' => $root] + ($name !== '' ? ['title' => $name] : []),
                               'id=?', [(int)$r['id']]);
                    $upgraded++;
                }
            }
            if ($r['thread_key']) $haveThread[(string)$r['thread_key']] = true;
        }

        $revived = self::reviveDismissed($boardId);

        $pos = (int)Db::val("SELECT COALESCE(MAX(position), -1) + 1 FROM board_cards WHERE column_id=?", [$inbox['id']]);

        // 1. Companies that wrote (or were written to) inside the window
        $companies = Db::all(
            "SELECT COALESCE(c.merged_into_id, c.id) AS cp_id, MAX(m.date_at) AS last_at
             FROM mail_messages m JOIN counterparties c ON c.id = m.counterparty_id
             WHERE m.date_at >= ? AND m.archived_at IS NULL
               AND (m.category IS NULL OR m.category NOT IN ($ignored))
             GROUP BY cp_id ORDER BY last_at DESC LIMIT ?", [$since, self::SYNC_LIMIT]
        );
        foreach ($companies as $row) {
            $cpId = (int)$row['cp_id'];
            if (isset($haveCp[$cpId])) continue;

            // Already on the board as an unresolved conversation? Keep the card
            // (and its column) and let it grow up into the company card.
            $existing = Db::one(
                "SELECT d.id FROM board_cards d JOIN board_columns col ON col.id = d.column_id
                 WHERE col.board_id=? AND d.counterparty_id IS NULL AND d.thread_key IS NOT NULL
                   AND d.thread_key IN (SELECT DISTINCT thread_key FROM mail_messages
                                        WHERE counterparty_id IN (SELECT id FROM counterparties WHERE id=? OR merged_into_id=?))
                 ORDER BY d.id LIMIT 1", [$boardId, $cpId, $cpId]
            );
            $name = (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$cpId]) ?: 'Компания');
            if ($existing) {
                Db::update('board_cards', ['counterparty_id' => $cpId, 'thread_key' => null, 'title' => $name],
                           'id=?', [$existing['id']]);
                $upgraded++;
            } else {
                Db::insert('board_cards', [
                    'column_id'       => (int)$inbox['id'],
                    'position'        => $pos++,
                    'counterparty_id' => $cpId,
                    'title'           => $name,
                    'moved_at'        => date('Y-m-d H:i:s'),
                ]);
                $created++;
            }
            $haveCp[$cpId] = true;
        }

        // 2. Letters from a sender we have not resolved to a company yet — they
        //    are on the board all the same, because «все письма подгружаются»
        $orphans = Db::all(
            "SELECT m.thread_key, MAX(m.date_at) AS last_at, MAX(COALESCE(m.counterparty_id, 0)) AS cp
             FROM mail_messages m
             WHERE m.thread_key IS NOT NULL AND m.direction='in' AND m.date_at >= ?
               AND m.archived_at IS NULL
               AND (m.category IS NULL OR m.category NOT IN ($ignored))
             GROUP BY m.thread_key HAVING cp = 0
             ORDER BY last_at DESC LIMIT ?", [$since, self::SYNC_LIMIT]
        );
        foreach ($orphans as $row) {
            $key = (string)$row['thread_key'];
            if (isset($haveThread[$key])) continue;
            $t = MailThreads::summary($key);
            Db::insert('board_cards', [
                'column_id'  => (int)$inbox['id'],
                'position'   => $pos++,
                'thread_key' => $key,
                'title'      => $t['subject'] ?? 'Без темы',
                'moved_at'   => date('Y-m-d H:i:s'),
            ]);
            $haveThread[$key] = true;
            $created++;
        }

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('board_sync_sig', ?)", [$sig()]);
        return ['created' => $created + $revived, 'upgraded' => $upgraded];
    }

    /**
     * Снятая карточка возвращается на доску, когда компания написала снова.
     *
     * «Убрать с доски» — это «здесь разобрано», а не «не показывать никогда»:
     * новое письмо после снятия снова требует человека. Возвращается карточка
     * во «Входящие» — её прежняя колонка была этапом разобранной работы, а
     * пришедшее письмо начинает работу заново.
     *
     * @return int сколько карточек вернулось
     */
    private static function reviveDismissed(int $boardId): int {
        $rows = Db::all(
            "SELECT d.id, d.counterparty_id, d.thread_key, d.dismissed_at FROM board_cards d
             JOIN board_columns c ON c.id = d.column_id
             WHERE c.board_id=? AND d.dismissed_at IS NOT NULL", [$boardId]
        );
        if (!$rows) return 0;

        $inbox = self::inboxColumn($boardId);
        $n = 0;
        foreach ($rows as $row) {
            // Считаем по дате самого письма, а не по времени его попадания в
            // базу: импорт старой переписки из mbox — это не «компания написала
            // снова», и поднимать разобранные карточки он не должен
            $since = (string)$row['dismissed_at'];
            $fresh = !empty($row['counterparty_id'])
                ? (int)Db::val(
                    "SELECT COUNT(*) FROM mail_messages
                     WHERE archived_at IS NULL AND date_at > ?
                       AND counterparty_id IN (SELECT id FROM counterparties WHERE id=? OR merged_into_id=?)",
                    [$since, (int)$row['counterparty_id'], (int)$row['counterparty_id']])
                : (int)Db::val(
                    "SELECT COUNT(*) FROM mail_messages
                     WHERE archived_at IS NULL AND date_at > ? AND thread_key=?",
                    [$since, (string)$row['thread_key']]);
            if ($fresh <= 0) continue;
            Db::update('board_cards', ['dismissed_at' => null, 'moved_at' => date('Y-m-d H:i:s')],
                       'id=?', [(int)$row['id']]);
            if ($inbox) self::moveCard((int)$row['id'], (int)$inbox['id'], 0);
            $n++;
        }
        return $n;
    }

    /** Create a board; a brand new one comes with the usual columns filled in. */
    public static function createBoard(string $name, bool $withColumns = true): int {
        $pos = (int)Db::val("SELECT COALESCE(MAX(position), 0) + 1 FROM boards");
        $id = Db::insert('boards', ['name' => trim($name) ?: 'Новая доска', 'position' => $pos]);
        if ($withColumns) {
            foreach (self::DEFAULT_COLUMNS as $i => [$title, $color, $kind]) {
                Db::insert('board_columns', ['board_id' => $id, 'title' => $title, 'color' => $color,
                                             'kind' => $kind, 'position' => $i]);
            }
        }
        return $id;
    }

    public static function renameBoard(int $id, string $name): void {
        Db::update('boards', ['name' => trim($name) ?: 'Доска'], 'id=?', [$id]);
    }

    public static function deleteBoard(int $id): void {
        // SQLite enforces ON DELETE CASCADE only with foreign_keys=ON, which
        // bootstrap sets — but a board is cheap to clean up explicitly
        Db::q("DELETE FROM board_cards WHERE column_id IN (SELECT id FROM board_columns WHERE board_id=?)", [$id]);
        Db::q("DELETE FROM board_columns WHERE board_id=?", [$id]);
        Db::q("DELETE FROM boards WHERE id=?", [$id]);
    }

    public static function saveColumn(int $boardId, ?int $columnId, string $title, ?string $color, ?string $kind = null): int {
        if ($columnId) {
            $data = array_filter(['title' => trim($title) ?: 'Колонка', 'color' => $color], fn($v) => $v !== null);
            // Exactly one intake column per board, or new mail would double up;
            // the same for «В работе», where the drafts land
            if (in_array($kind, ['inbox', 'work'], true)) {
                Db::q("UPDATE board_columns SET kind=NULL WHERE board_id=? AND id<>? AND kind=?", [$boardId, $columnId, $kind]);
                $data['kind'] = $kind;
            }
            Db::update('board_columns', $data, 'id=? AND board_id=?', [$columnId, $boardId]);
            return $columnId;
        }
        $pos = (int)Db::val("SELECT COALESCE(MAX(position), -1) + 1 FROM board_columns WHERE board_id=?", [$boardId]);
        if (in_array($kind, ['inbox', 'work'], true)) {
            Db::q("UPDATE board_columns SET kind=NULL WHERE board_id=? AND kind=?", [$boardId, $kind]);
        }
        return Db::insert('board_columns', [
            'board_id' => $boardId,
            'title'    => trim($title) ?: 'Колонка',
            'color'    => $color ?: '#8a8f98',
            'kind'     => in_array($kind, ['inbox', 'work'], true) ? $kind : null,
            'position' => $pos,
        ]);
    }

    public static function deleteColumn(int $columnId): void {
        $col = Db::one("SELECT * FROM board_columns WHERE id=?", [$columnId]);
        if (!$col) return;
        // Cards are never destroyed with the column: they go back to «Входящие»,
        // because a deleted column must not delete a client's correspondence
        $inbox = self::inboxColumn((int)$col['board_id']);
        if ($inbox && (int)$inbox['id'] !== $columnId) {
            Db::q("UPDATE board_cards SET column_id=? WHERE column_id=?", [$inbox['id'], $columnId]);
        } else {
            Db::q("DELETE FROM board_cards WHERE column_id=?", [$columnId]);
        }
        Db::q("DELETE FROM board_columns WHERE id=?", [$columnId]);
    }

    /** Column order after a drag of the column headers. */
    public static function reorderColumns(int $boardId, array $columnIds): void {
        foreach (array_values($columnIds) as $i => $cid) {
            Db::update('board_columns', ['position' => $i], 'id=? AND board_id=?', [(int)$cid, $boardId]);
        }
    }

    /**
     * Put a company (or a still-unresolved conversation) on a board. The same
     * company is never added twice to one board — it is moved to the asked-for
     * column instead, because a company that exists in two columns is exactly
     * the confusion a board is supposed to remove.
     */
    public static function addCard(int $columnId, array $o): int {
        $col = Db::one("SELECT * FROM board_columns WHERE id=?", [$columnId]);
        if (!$col) throw new RuntimeException('Колонка не найдена');

        $cpId      = !empty($o['counterparty_id']) ? (int)$o['counterparty_id'] : null;
        $threadKey = trim((string)($o['thread_key'] ?? ''));

        // A conversation whose company is known belongs to the company card
        if (!$cpId && $threadKey !== '') {
            $known = Db::val("SELECT counterparty_id FROM mail_messages
                              WHERE thread_key=? AND counterparty_id IS NOT NULL ORDER BY date_at DESC LIMIT 1", [$threadKey]);
            if ($known) { $cpId = (int)Crm::rootId((int)$known); $threadKey = ''; }
        }

        $existing = null;
        if ($cpId) {
            $existing = Db::one("SELECT d.id FROM board_cards d JOIN board_columns c ON c.id = d.column_id
                                 WHERE c.board_id=? AND d.counterparty_id=?", [(int)$col['board_id'], $cpId]);
        } elseif ($threadKey !== '') {
            $existing = Db::one("SELECT d.id FROM board_cards d JOIN board_columns c ON c.id = d.column_id
                                 WHERE c.board_id=? AND d.thread_key=?", [(int)$col['board_id'], $threadKey]);
        }
        if ($existing) {
            // Карточку могли снять с доски — «положить в колонку» её возвращает
            Db::update('board_cards', ['dismissed_at' => null], 'id=?', [(int)$existing['id']]);
            self::moveCard((int)$existing['id'], $columnId, 0);
            return (int)$existing['id'];
        }

        $title = trim((string)($o['title'] ?? ''));
        if ($title === '' && $cpId) {
            $title = (string)(Db::val("SELECT name FROM counterparties WHERE id=?", [$cpId]) ?: '');
        }
        if ($title === '' && $threadKey !== '') {
            $t = MailThreads::summary($threadKey);
            $title = $t['subject'] ?? '';
        }

        // New cards land on top: what was just added is what is being worked on
        Db::q("UPDATE board_cards SET position = position + 1 WHERE column_id=?", [$columnId]);
        return Db::insert('board_cards', [
            'column_id'       => $columnId,
            'position'        => 0,
            'counterparty_id' => $cpId,
            'thread_key'      => $threadKey ?: null,
            'mail_message_id' => !empty($o['mail_message_id']) ? (int)$o['mail_message_id'] : null,
            'request_id'      => !empty($o['request_id']) ? (int)$o['request_id'] : null,
            'title'           => $title ?: 'Карточка',
            'note'            => trim((string)($o['note'] ?? '')) ?: null,
            'manager_id'      => !empty($o['manager_id']) ? (int)$o['manager_id'] : null,
            'moved_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    /** Drop a card at a position inside a column — the whole point of the board. */
    public static function moveCard(int $cardId, int $columnId, int $position): void {
        $card = Db::one("SELECT * FROM board_cards WHERE id=?", [$cardId]);
        if (!$card) throw new RuntimeException('Карточка не найдена');
        if (!Db::one("SELECT id FROM board_columns WHERE id=?", [$columnId])) {
            throw new RuntimeException('Колонка не найдена');
        }

        $siblings = Db::all("SELECT id FROM board_cards WHERE column_id=? AND id<>? ORDER BY position, id",
                            [$columnId, $cardId]);
        $ids = array_map(fn($r) => (int)$r['id'], $siblings);
        $position = max(0, min(count($ids), $position));
        array_splice($ids, $position, 0, [$cardId]);

        foreach ($ids as $i => $id) {
            $data = ['position' => $i];
            // Only the dragged card changes column and «когда двигали» — the ones
            // it pushed aside keep their own history
            if ($id === $cardId) $data += ['column_id' => $columnId, 'moved_at' => date('Y-m-d H:i:s')];
            Db::update('board_cards', $data, 'id=?', [$id]);
        }
    }

    /**
     * ==== Групповой перенос карточек (модуль 036) ====
     *
     * Отмеченные галочками карточки переезжают ВМЕСТЕ и в том порядке, в
     * котором стояли. До этого перетаскивание знало ровно одну карточку —
     * ту, за которую тянули: экран показывал переехавшую группу, база хранила
     * одну строку, и обновление страницы возвращало остальные на место.
     *
     * Порядок считается один раз на всю группу: `moveCard()` на каждую
     * карточку по очереди перенумеровывает колонку между вставками, и группа
     * приезжала перевёрнутой.
     *
     * @param int[] $cardIds в том порядке, в каком они должны лечь
     * @return int сколько карточек переехало
     */
    public static function moveCards(array $cardIds, int $columnId, int $position): int {
        $ids = array_values(array_unique(array_filter(array_map('intval', $cardIds))));
        if (!$ids) return 0;
        if (!Db::one("SELECT id FROM board_columns WHERE id=?", [$columnId])) {
            throw new RuntimeException('Колонка не найдена');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $known = array_map(fn($r) => (int)$r['id'], Db::all("SELECT id FROM board_cards WHERE id IN ($in)", $ids));
        $moving = array_values(array_filter($ids, fn($id) => in_array($id, $known, true)));
        if (!$moving) throw new RuntimeException('Карточка не найдена');

        $siblings = array_map(fn($r) => (int)$r['id'], Db::all(
            "SELECT id FROM board_cards WHERE column_id=? ORDER BY position, id", [$columnId]
        ));
        $siblings = array_values(array_filter($siblings, fn($id) => !in_array($id, $moving, true)));
        $position = max(0, min(count($siblings), $position));
        array_splice($siblings, $position, 0, $moving);

        $now = date('Y-m-d H:i:s');
        foreach ($siblings as $i => $id) {
            $data = ['position' => $i];
            // Своя история только у тех, кто ехал: карточки, которых группа
            // подвинула, не «двигали»
            if (in_array($id, $moving, true)) $data += ['column_id' => $columnId, 'moved_at' => $now];
            Db::update('board_cards', $data, 'id=?', [$id]);
        }
        return count($moving);
    }

    public static function updateCard(int $cardId, array $o): void {
        $data = [];
        if (array_key_exists('title', $o)) $data['title'] = trim((string)$o['title']) ?: 'Карточка';
        if (array_key_exists('note', $o))  $data['note']  = trim((string)$o['note']) ?: null;
        if (array_key_exists('manager_id', $o)) $data['manager_id'] = $o['manager_id'] ? (int)$o['manager_id'] : null;
        if ($data) Db::update('board_cards', $data, 'id=?', [$cardId]);
    }

    /**
     * Карточка уходит С ДОСКИ, а не из базы (модуль 031).
     *
     * Удалённую строку `sync()` заводил заново при следующем же открытии доски —
     * во «Входящих», — так что «убрать с доски» не убирало ничего, а разложенная
     * по колонкам доска сваливалась обратно. Отметка `dismissed_at` держит и
     * снятие, и колонку, в которой карточка стояла.
     */
    public static function dismissCard(int $cardId): void {
        Db::update('board_cards', ['dismissed_at' => date('Y-m-d H:i:s')], 'id=?', [$cardId]);
    }

    /**
     * Строку карточки убираем насовсем — только когда за ней не осталось писем
     * в работе (архив, спам, `pruneEmptyCards`). Снятие руками — `dismissCard()`.
     */
    public static function deleteCard(int $cardId): void {
        Db::q("DELETE FROM board_cards WHERE id=?", [$cardId]);
    }

    // ---- Письмо, которое пишут прямо сейчас (модуль 033) ----

    /**
     * Карточка черновика — в «В работе».
     *
     * Письмо, которое менеджер СЕЙЧАС пишет, — это работа, и на доске её до сих
     * пор не было видно вовсе: карточки заводила только входящая почта. Черновик
     * заводит свою карточку сам, с компанией, темой и началом текста, вытянутыми
     * из тела письма.
     *
     * Карточка компании, уже стоящая в колонке, второй раз не заводится: она
     * переезжает из «Входящие» в работу (письмо ей уже пишут) и остаётся там,
     * куда её поставил менеджер, если это не «Входящие».
     *
     * @return array{id:int,column:string,created:bool}|array{}
     */
    public static function draftCard(int $draftId, array $o): array {
        $boardId = (int)self::singleton()['id'];
        $work    = self::workColumn($boardId);
        if (!$work) return [];

        $cpId = !empty($o['counterparty_id']) ? (int)$o['counterparty_id'] : null;
        $key  = trim((string)($o['thread_key'] ?? ''));
        $card = self::findCard($boardId, $draftId, $cpId, $key);
        $title = trim((string)($o['title'] ?? ''));

        if (!$card) {
            Db::q("UPDATE board_cards SET position = position + 1 WHERE column_id=?", [(int)$work['id']]);
            $id = Db::insert('board_cards', [
                'column_id'       => (int)$work['id'],
                'position'        => 0,
                'draft_id'        => $draftId,
                'counterparty_id' => $cpId,
                'thread_key'      => $cpId ? null : ($key ?: null),
                'title'           => $title ?: 'Новое письмо',
                'manager_id'      => !empty($o['manager_id']) ? (int)$o['manager_id'] : null,
                'moved_at'        => date('Y-m-d H:i:s'),
            ]);
            return ['id' => $id, 'column' => (string)$work['title'], 'created' => true];
        }

        $upd = ['draft_id' => $draftId];
        if ($cpId && empty($card['counterparty_id'])) {
            $upd['counterparty_id'] = $cpId;
            $upd['thread_key'] = null;
        }
        if ($title !== '' && in_array(trim((string)$card['title']), ['', 'Карточка', 'Новое письмо'], true)) {
            $upd['title'] = $title;
        }
        // Снятая с доски карточка возвращается: «разобрано» кончилось на том,
        // что этой компании снова пишут (модуль 031 + 033)
        $wasDismissed = !empty($card['dismissed_at']);
        if ($wasDismissed) $upd['dismissed_at'] = null;
        Db::update('board_cards', $upd, 'id=?', [(int)$card['id']]);

        $column = (string)Db::val("SELECT title FROM board_columns WHERE id=?", [(int)$card['column_id']]);
        if ($wasDismissed || self::isInbox((int)$card['column_id'])) {
            self::moveCard((int)$card['id'], (int)$work['id'], 0);
            $column = (string)$work['title'];
        }
        return ['id' => (int)$card['id'], 'column' => $column, 'created' => false];
    }

    /**
     * Письмо ушло. Карточка остаётся на доске и в своей колонке — она больше
     * не черновик, а компания со своей перепиской.
     */
    public static function adoptDraftCard(int $draftId, array $letter): void {
        $boardId = (int)self::singleton()['id'];
        $cpId = !empty($letter['counterparty_id']) ? (int)$letter['counterparty_id'] : null;
        $key  = trim((string)($letter['thread_key'] ?? ''));
        if (!$cpId && $key === '') return;

        $card = self::findCard($boardId, $draftId, $cpId, $key);
        if (!$card) {
            // Отправили, ничего не сохранив черновиком, — карточка всё равно нужна
            $work = self::workColumn($boardId);
            if (!$work) return;
            self::addCard((int)$work['id'], [
                'counterparty_id' => $cpId,
                'thread_key'      => $key,
                'title'           => (string)($letter['title'] ?? ''),
                'manager_id'      => $letter['manager_id'] ?? null,
            ]);
            return;
        }

        $upd = ['draft_id' => null];
        if ($cpId) { $upd['counterparty_id'] = $cpId; $upd['thread_key'] = null; }
        elseif ($key !== '' && empty($card['thread_key'])) { $upd['thread_key'] = $key; }
        if (!empty($letter['mail_message_id']) && empty($card['mail_message_id'])) {
            $upd['mail_message_id'] = (int)$letter['mail_message_id'];
        }
        $wasDismissed = !empty($card['dismissed_at']);
        if ($wasDismissed) $upd['dismissed_at'] = null;
        Db::update('board_cards', $upd, 'id=?', [(int)$card['id']]);

        if ($wasDismissed || self::isInbox((int)$card['column_id'])) {
            $work = self::workColumn($boardId);
            if ($work) self::moveCard((int)$card['id'], (int)$work['id'], 0);
        }
    }

    /**
     * Черновик стёрли. Карточка, которая жила только им, уходит с доски —
     * доска не держит пустых карточек (модуль 026); карточка с перепиской,
     * запросом или заметкой остаётся и просто перестаёт быть черновиком.
     */
    public static function dropDraftCard(int $draftId): void {
        $card = Db::one("SELECT * FROM board_cards WHERE draft_id=?", [$draftId]);
        if (!$card) return;

        $cpId = !empty($card['counterparty_id']) ? (int)$card['counterparty_id'] : 0;
        $hasMail = $cpId
            ? (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE counterparty_id=? AND archived_at IS NULL", [$cpId]) > 0
            : !empty($card['thread_key']);
        if (!$hasMail && empty($card['request_id']) && trim((string)($card['note'] ?? '')) === '') {
            self::deleteCard((int)$card['id']);
            return;
        }
        Db::update('board_cards', ['draft_id' => null], 'id=?', [(int)$card['id']]);
    }

    /** Карточка этой доски: по черновику, по компании или по переписке. */
    private static function findCard(int $boardId, int $draftId, ?int $cpId, string $threadKey): ?array {
        $find = fn(string $where, array $params) => Db::one(
            "SELECT d.* FROM board_cards d JOIN board_columns c ON c.id = d.column_id
             WHERE c.board_id=? AND $where ORDER BY d.id LIMIT 1", [$boardId, ...$params]);

        if ($draftId && ($row = $find('d.draft_id=?', [$draftId]))) return $row;
        if ($cpId && ($row = $find('d.counterparty_id=?', [$cpId]))) return $row;
        if ($threadKey !== '' && ($row = $find('d.thread_key=?', [$threadKey]))) return $row;
        return null;
    }

    private static function isInbox(int $columnId): bool {
        return (string)Db::val("SELECT kind FROM board_columns WHERE id=?", [$columnId]) === 'inbox';
    }

    /**
     * Групповая операция над отмеченными карточками.
     *
     * Доска — это место, где разбирают почту, а разбор почты на сорока
     * карточках по одной карточке за раз не разбор, а работа руками. Поэтому
     * отмеченным карточкам можно сказать одно и то же: прочитано, в архив,
     * спам, переехать в колонку, уйти с доски.
     *
     * Карточка компании — это ВСЕ её переписки, поэтому операция идёт по
     * цепочкам, а не по письмам: «в архив» на карточке значит «вся переписка
     * этой компании — не наш профиль», ровно как и в самой карточке.
     *
     * @param array<int,int> $cardIds
     * @return array{done:int,failed:int,errors:array<int,string>}
     */
    public static function bulk(array $cardIds, string $op, array $opts = [], ?int $managerId = null): array {
        require_once __DIR__ . '/mailsync.php';

        $ids = array_values(array_unique(array_filter(array_map('intval', $cardIds))));
        if (!$ids) return ['done' => 0, 'failed' => 0, 'errors' => []];

        // Перенос — операция над ГРУППОЙ, а не над каждой карточкой по очереди:
        // порядок внутри группы считается один раз, иначе она приезжает
        // перевёрнутой (модуль 036). Место назначения не указано — в конец.
        if ($op === 'move') {
            $columnId = (int)($opts['column_id'] ?? 0);
            if (!$columnId) return ['done' => 0, 'failed' => count($ids), 'errors' => ['Не выбрана колонка']];
            $position = array_key_exists('position', $opts) && $opts['position'] !== ''
                ? (int)$opts['position'] : PHP_INT_MAX;
            try {
                return ['done' => self::moveCards($ids, $columnId, $position), 'failed' => 0, 'errors' => []];
            } catch (Throwable $e) {
                return ['done' => 0, 'failed' => count($ids), 'errors' => [$e->getMessage()]];
            }
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $cards = Db::all("SELECT * FROM board_cards WHERE id IN ($in)", $ids);

        $done = 0; $failed = 0; $errors = [];
        foreach ($cards as $card) {
            try {
                switch ($op) {
                    case 'remove':
                        self::dismissCard((int)$card['id']);
                        break;

                    case 'read':
                        foreach (self::cardThreadKeys($card) as $key) MailThreads::markRead($key);
                        // Письма прочитаны — и карточка разобрана: иначе она
                        // остаётся жирной, потому что клиент всё ещё «писал
                        // последним». Новое письмо придёт позже этой отметки и
                        // поднимет карточку обратно.
                        Db::update('board_cards', ['seen_at' => date('Y-m-d H:i:s')], 'id=?', [(int)$card['id']]);
                        break;

                    case 'archive':
                        foreach (self::cardThreadKeys($card) as $key) MailSync::archiveThread($key, $managerId);
                        // Убранное «не наш профиль» с доски уходит вместе с письмами
                        self::deleteCard((int)$card['id']);
                        break;

                    case 'unarchive':
                        foreach (self::cardThreadKeys($card) as $key) MailSync::unarchiveThread($key);
                        break;

                    case 'spam':
                        // Спам — про входящие письма: наши собственные ответы
                        // спамом не бывают
                        foreach (self::cardMessageIds($card, 'in') as $mid) MailSync::markAsSpam($mid);
                        self::deleteCard((int)$card['id']);
                        break;

                    default:
                        throw new RuntimeException('Неизвестная операция: ' . $op);
                }
                $done++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = trim((string)$card['title']) . ': ' . $e->getMessage();
            }
        }
        return ['done' => $done, 'failed' => $failed, 'errors' => array_slice($errors, 0, 5)];
    }

    /**
     * Карточка компании, у которой не осталось ни одного живого письма, уходит
     * с доски (модуль 026).
     *
     * «Не наш профиль» на последней переписке убирал письма, а карточка стояла
     * дальше — пустая, без единого письма внутри, и разбирать её приходилось
     * второй раз руками. Вернули письма из архива — `sync()` поставит карточку
     * обратно сам, потому что компания снова пишет.
     *
     * @param int|null $counterpartyId проверить одну компанию; null — все на доске
     * @return int сколько карточек убрано
     */
    public static function pruneEmptyCards(?int $counterpartyId = null): int {
        // Карточка доски стоит на КОРНЕВОЙ компании, а письмо может числиться за
        // слитой в неё — иначе слитая карточка никогда бы не убиралась
        $rootId = $counterpartyId ? Crm::rootId($counterpartyId) : null;
        $cards = $rootId
            ? Db::all("SELECT id, counterparty_id, draft_id FROM board_cards WHERE counterparty_id=?", [$rootId])
            : Db::all("SELECT id, counterparty_id, draft_id FROM board_cards WHERE counterparty_id IS NOT NULL");

        $removed = 0;
        foreach ($cards as $card) {
            // Письмо, которое ей пишут прямо сейчас, — это живая работа, даже
            // когда писем в архиве ещё ноль (модуль 033)
            if (!empty($card['draft_id'])) continue;
            $cpId = (int)$card['counterparty_id'];
            // Слитая карточка держит письма под своим прежним id — считаем семью
            $live = (int)Db::val(
                "SELECT COUNT(*) FROM mail_messages
                 WHERE archived_at IS NULL
                   AND counterparty_id IN (SELECT id FROM counterparties WHERE id=? OR merged_into_id=?)",
                [$cpId, $cpId]
            );
            if ($live > 0) continue;
            self::deleteCard((int)$card['id']);
            $removed++;
        }
        return $removed;
    }

    /** Все цепочки, которые несёт карточка: у компании — её переписка целиком. */
    private static function cardThreadKeys(array $card): array {
        if (!empty($card['counterparty_id'])) {
            // Слитая карточка держит письма под своим прежним id — считаем семью,
            // иначе групповая операция молча проходила мимо половины переписки
            $cpId = (int)$card['counterparty_id'];
            return array_column(Db::all(
                "SELECT DISTINCT thread_key FROM mail_messages
                 WHERE thread_key IS NOT NULL AND archived_at IS NULL
                   AND counterparty_id IN (SELECT id FROM counterparties WHERE id=? OR merged_into_id=?)",
                [$cpId, $cpId]), 'thread_key');
        }
        return !empty($card['thread_key']) ? [(string)$card['thread_key']] : [];
    }

    /** Письма карточки — по направлению, когда операция касается только входящих. */
    private static function cardMessageIds(array $card, ?string $direction = null): array {
        $keys = self::cardThreadKeys($card);
        if (!$keys) return [];
        $in = implode(',', array_fill(0, count($keys), '?'));
        $sql = "SELECT id FROM mail_messages WHERE thread_key IN ($in) AND archived_at IS NULL";
        $params = $keys;
        if ($direction !== null) { $sql .= " AND direction=?"; $params[] = $direction; }
        return array_map('intval', array_column(Db::all($sql, $params), 'id'));
    }

    /**
     * A card, wherever it lives: title/note of the card itself, plus the company,
     * the mail behind it and the request it came from — a manager searching for
     * «Глори Эйр» should find the card even when only the letter mentions it.
     */
    public static function search(string $q, int $limit = 50): array {
        require_once __DIR__ . '/mail.php';

        // Слова ищутся ВСЕ: «уралэлемент счёт» — это карточка, где есть и то, и
        // другое. И каждое слово ищется по всему, что за карточкой стоит —
        // письма целиком, вложения, запрос (модуль 023).
        $terms = MailArchive::searchTerms($q);
        if (!$terms) return [];

        $where = ['d.dismissed_at IS NULL'];
        $params = [];
        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $where[] = "(d.title LIKE ? OR d.note LIKE ?
                OR cp.name LIKE ? OR cp.inn LIKE ?
                OR r.email_subject LIKE ? OR r.raw_text LIKE ? OR r.email_from LIKE ?
                OR EXISTS (SELECT 1 FROM mail_messages m
                           LEFT JOIN attachments a ON a.mail_message_id = m.id
                           WHERE (m.thread_key = d.thread_key
                                  OR (d.counterparty_id IS NOT NULL AND m.counterparty_id = d.counterparty_id))
                             AND (m.subject LIKE ? OR m.body_text LIKE ? OR m.body_html LIKE ?
                                  OR m.from_email LIKE ? OR m.from_name LIKE ?
                                  OR m.to_emails LIKE ? OR m.cc_emails LIKE ?
                                  OR a.filename LIKE ? OR a.extracted_text LIKE ?)))";
            array_push($params, ...array_fill(0, 16, $like));
        }

        return Db::all(
            "SELECT d.id AS card_id, d.title, d.note, d.thread_key, d.counterparty_id, d.request_id, d.moved_at,
                    col.id AS column_id, col.title AS column_title, col.color,
                    b.id AS board_id, b.name AS board_name
             FROM board_cards d
             JOIN board_columns col ON col.id = d.column_id
             JOIN boards b ON b.id = col.board_id
             LEFT JOIN counterparties cp ON cp.id = d.counterparty_id
             LEFT JOIN requests r ON r.id = d.request_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY d.id
             ORDER BY d.moved_at DESC
             LIMIT ?",
            [...$params, $limit]
        );
    }

    /** Which board a thread is already on — the mail page shows it as a chip. */
    public static function threadPlacement(string $threadKey): array {
        $cpId = Db::val("SELECT counterparty_id FROM mail_messages
                         WHERE thread_key=? AND counterparty_id IS NOT NULL ORDER BY date_at DESC LIMIT 1", [$threadKey]);
        return Db::all(
            "SELECT d.id AS card_id, c.id AS column_id, c.title AS column_title, c.color,
                    b.id AS board_id, b.name AS board_name
             FROM board_cards d
             JOIN board_columns c ON c.id = d.column_id
             JOIN boards b ON b.id = c.board_id
             WHERE d.dismissed_at IS NULL AND (d.thread_key=? OR (? IS NOT NULL AND d.counterparty_id=?))",
            [$threadKey, $cpId, $cpId]
        );
    }

    /** Where a company card sits — shown on the company card itself (module 011). */
    public static function companyPlacement(int $counterpartyId): array {
        return Db::all(
            "SELECT d.id AS card_id, d.note, c.id AS column_id, c.title AS column_title, c.color,
                    b.id AS board_id, b.name AS board_name
             FROM board_cards d
             JOIN board_columns c ON c.id = d.column_id
             JOIN boards b ON b.id = c.board_id
             WHERE d.dismissed_at IS NULL
               AND d.counterparty_id IN (SELECT id FROM counterparties WHERE id=? OR merged_into_id=?)",
            [$counterpartyId, $counterpartyId]
        );
    }
}
