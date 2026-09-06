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

        case 'list': {
            $boards = Boards::all();
            // An account with no boards yet gets the usual КП pipeline, so the
            // page is never an empty screen with a single button on it
            if (!$boards) {
                Boards::createBoard('Работа с письмами');
                $boards = Boards::all();
            }
            jsonData(['items' => $boards]);
        }

        case 'get': {
            $board = Boards::get((int)($_GET['id'] ?? 0));
            if (!$board) jsonError('Доска не найдена', 404);
            jsonData($board);
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
                                     (string)($input['title'] ?? ''), $input['color'] ?? null);
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
                'thread_key'      => $input['thread_key'] ?? '',
                'mail_message_id' => $input['mail_message_id'] ?? null,
                'request_id'      => $input['request_id'] ?? null,
                'title'           => $input['title'] ?? '',
                'note'            => $input['note'] ?? '',
                'manager_id'      => $manager['id'],
            ]);
            jsonOk(['id' => $id]);
        }

        case 'card_move':
            Boards::moveCard((int)($input['id'] ?? 0), (int)($input['column_id'] ?? 0), (int)($input['position'] ?? 0));
            jsonOk();

        case 'card_save':
            Boards::updateCard((int)($input['id'] ?? 0), $input);
            jsonOk();

        case 'card_delete':
            Boards::deleteCard((int)($input['id'] ?? 0));
            jsonOk();

        // Where a thread already sits — the mail page shows it, and «в доску»
        // moves the existing card instead of making a second one
        case 'placement': {
            $key = trim((string)($_GET['thread_key'] ?? ''));
            if ($key === '') jsonData(['items' => [], 'boards' => Boards::all()]);
            jsonData(['items' => Boards::threadPlacement($key), 'boards' => Boards::all()]);
        }

        // Board + column list for the «положить в доску» picker
        case 'targets': {
            $out = [];
            foreach (Boards::all() as $b) {
                $out[] = [
                    'id'      => (int)$b['id'],
                    'name'    => $b['name'],
                    'columns' => Db::all("SELECT id, title, color FROM board_columns WHERE board_id=? ORDER BY position, id", [$b['id']]),
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
