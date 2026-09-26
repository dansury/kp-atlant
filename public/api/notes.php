<?php
/**
 * API: notes for colleagues on a company card or a letter (module 064).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/notes.php';

$manager = requireAuth();

switch ($_GET['action'] ?? '') {
    case 'list':
        jsonData(['items' => Notes::forCard((int)($_GET['cp'] ?? 0) ?: null, (string)($_GET['thread_key'] ?? ''))]);

    case 'add':
        $in = getInput();
        try {
            $id = Notes::add((int)($in['cp'] ?? 0) ?: null, (string)($in['thread_key'] ?? ''),
                             (int)$manager['id'], (string)($in['text'] ?? ''));
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        }
        jsonOk(['id' => $id]);

    case 'delete':
        $id = (int)(getInput()['id'] ?? 0);
        if (!Notes::delete($id)) jsonError('Заметка не найдена', 404);
        jsonOk(['id' => $id]);

    default:
        jsonError('Unknown action');
}
