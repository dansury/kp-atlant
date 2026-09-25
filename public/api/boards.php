<?php
/**
 * API: kanban boards (module 010). Boards, columns, and cards that carry a mail
 * thread from one column to the next.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/boards.php';

$manager = requireAuth();
$action  = $_GET['action'] ?? '';
$input   = in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true) ? getInput() : [];

try {
    switch ($action) {

        case 'list':
            // Boards::all() creates the one board itself when there is none yet
            // (item 2 — «Письма» always has exactly one board to open)
            jsonData(['items' => Boards::all()]);

        case 'get': {
            $id = (int)($_GET['id'] ?? 0) ?: (int)Boards::singleton()['id'];
            // Opening the board IS the intake: every company that wrote to us is
            // already in «Входящие» by the time the page paints (module 011)
            $sync = isset($_GET['sync']) && !$_GET['sync'] ? ['created' => 0, 'upgraded' => 0] : Boards::sync($id);
            $board = Boards::get($id);
            if (!$board) jsonError('Доска не найдена', 404);
            // Ящики едут вместе с доской: фильтр по ящику не стоит второго запроса
            $board['mailboxes'] = array_map(
                fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']],
                Mailboxes::forManager($manager)
            );
            jsonData($board + ['sync' => $sync]);
        }

        // Pull new mail onto the board without repainting everything else
        case 'sync': {
            $id = (int)($input['board_id'] ?? 0) ?: (int)Boards::singleton()['id'];
            jsonOk(Boards::sync($id, true));
        }

        case 'board_save': {
            $id = (int)($input['id'] ?? 0);
            $name = (string)($input['name'] ?? '');
            if ($id) { Boards::renameBoard($id, $name); }
            else     { $id = Boards::createBoard($name, !isset($input['with_columns']) || !empty($input['with_columns'])); }
            jsonOk(['id' => $id]);
        }

        case 'board_delete':
            Boards::deleteBoard((int)($input['id'] ?? 0));
            jsonOk();

        case 'column_save': {
            $boardId = (int)($input['board_id'] ?? 0);
            if (!$boardId) jsonError('Не указана доска');
            $id = Boards::saveColumn($boardId, (int)($input['id'] ?? 0) ?: null,
                                     (string)($input['title'] ?? ''), $input['color'] ?? null,
                                     !empty($input['kind']) ? (string)$input['kind'] : null,
                                     array_key_exists('card_limit', $input) ? (int)$input['card_limit'] : null);
            jsonOk(['id' => $id]);
        }

        case 'column_delete':
            Boards::deleteColumn((int)($input['id'] ?? 0));
            jsonOk();

        case 'columns_reorder':
            Boards::reorderColumns((int)($input['board_id'] ?? 0), (array)($input['ids'] ?? []));
            jsonOk();

        case 'card_add': {
            $columnId = (int)($input['column_id'] ?? 0);
            if (!$columnId) jsonError('Не указана колонка');
            $id = Boards::addCard($columnId, [
                'counterparty_id' => $input['counterparty_id'] ?? null,
                'thread_key'      => $input['thread_key'] ?? '',
                'mail_message_id' => $input['mail_message_id'] ?? null,
                'request_id'      => $input['request_id'] ?? null,
                'title'           => $input['title'] ?? '',
                'note'            => $input['note'] ?? '',
                'manager_id'      => $manager['id'],
            ]);
            jsonOk(['id' => $id]);
        }

        /**
         * Перенос карточек. `ids` — группа, отмеченная галочками: она едет
         * целиком и в своём порядке (модуль 036). Ответ несёт ДОСКУ: экран
         * перерисовывается тем, что лежит в базе, а не тем, что нарисовало
         * перетаскивание, — иначе переезд виден до обновления страницы и
         * пропадает после него.
         */
        case 'card_move': {
            $ids = !empty($input['ids']) ? (array)$input['ids'] : [(int)($input['id'] ?? 0)];
            $columnId = (int)($input['column_id'] ?? 0);
            Boards::moveCards($ids, $columnId, (int)($input['position'] ?? 0));
            // Возвращается ТА доска, на которую человек смотрит, а не та, куда
            // уехали карточки: групповое «в колонку» умеет целить и на чужую
            jsonOk(['board' => Boards::get((int)Boards::singleton()['id'])]);
        }

        case 'card_save':
            Boards::updateCard((int)($input['id'] ?? 0), $input);
            jsonOk();

        // «Убрать с доски» — снятие, а не удаление: письма остаются в почте, а
        // карточка не возвращается сама следующим открытием доски (модуль 031)
        case 'card_delete':
            // `purge` — удалить строку карточки насовсем (модуль 040). Снятие
            // помнит, где карточка стояла, и возвращает её, когда компания
            // напишет снова; удаление не возвращает ничего. Карточку, за
            // которой не осталось ни одного письма, иначе было не убрать.
            $cardId = (int)($input['id'] ?? 0);
            if (!empty($input['purge'])) { Boards::deleteCard($cardId); jsonOk(['purged' => true]); }
            jsonOk(['purged' => Boards::dismissCard($cardId)]);

        /**
         * Групповая операция над отмеченными карточками (прочитано, в архив,
         * спам, переместить, убрать). Ответ всегда говорит, сколько сделано и
         * что не получилось: молча наполовину выполненная операция над сорока
         * письмами — худшее, что может случиться на этом экране.
         */
        case 'bulk': {
            $ids = (array)($input['ids'] ?? []);
            $op  = (string)($input['op'] ?? '');
            if (!$ids) jsonError('Не отмечено ни одной карточки');
            if ($op === '') jsonError('Не указана операция');
            $res = Boards::bulk($ids, $op, $input, (int)$manager['id']);
            Logger::info('boards', "Групповая операция «{$op}»: {$res['done']} карточек",
                         ['manager_id' => (int)$manager['id'], 'failed' => $res['failed']]);
            // Доска в ответе — чтобы экран показал то, что получилось, а не то,
            // на что надеялся браузер (модуль 036)
            jsonOk($res + ['board' => Boards::get((int)Boards::singleton()['id'])]);
        }

        // Where a thread already sits — the mail page shows it, and «в доску»
        // moves the existing card instead of making a second one
        case 'placement': {
            $cp  = (int)($_GET['counterparty_id'] ?? 0);
            if ($cp) jsonData(['items' => Boards::companyPlacement($cp), 'boards' => Boards::all()]);
            $key = trim((string)($_GET['thread_key'] ?? ''));
            if ($key === '') jsonData(['items' => [], 'boards' => Boards::all()]);
            jsonData(['items' => Boards::threadPlacement($key), 'boards' => Boards::all()]);
        }

        // Search across every board at once — card title/note, the mail thread
        // behind it and the request it came from
        // Стрелка «ещё» под колонкой с лимитом (issue #110)
        case 'column_cards': {
            $colId = (int)($_GET['id'] ?? 0);
            if (!$colId) jsonError('Не указана колонка');
            jsonData(Boards::columnCards($colId, (int)($_GET['offset'] ?? 0), (int)($_GET['limit'] ?? 0)));
        }

        // Найденные поиском карточки целиком — и те, что срезал лимит колонки
        case 'search_cards': {
            $q = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) jsonData(['items' => []]);
            jsonData(['items' => Boards::searchCards($q)]);
        }

        case 'search': {
            $q = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) jsonError('Слишком короткий запрос');
            jsonData(['items' => Boards::search($q)]);
        }

        // Board + column list for the «положить в доску» picker
        case 'targets': {
            $out = [];
            foreach (Boards::all() as $b) {
                $out[] = [
                    'id'      => (int)$b['id'],
                    'name'    => $b['name'],
                    'columns' => Db::all("SELECT id, title, color, kind FROM board_columns WHERE board_id=? ORDER BY position, id", [$b['id']]),
                ];
            }
            jsonData(['items' => $out]);
        }

        default:
            jsonError('Unknown action', 400);
    }
} catch (Throwable $e) {
    Logger::exception('boards', $e, ['action' => $action]);
    jsonError($e->getMessage(), 500);
}
