<?php
/**
 * API: обратная связь из панели (модуль 038).
 *
 * Отправить обращение может любой вошедший — в этом весь смысл: жалуется тот,
 * кто увидел поломку. Ревью и отправка в GitHub — только администратор.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/outbox.php';
require_once ROOT . '/lib/support.php';

$action  = $_GET['action'] ?? '';
$manager = requireAuth();
$isAdmin = !empty($manager['is_admin']);
$input   = in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true) ? getInput() : [];

try {
    switch ($action) {

        case 'list':
            jsonData([
                'items'     => Support::listFor($manager, (string)($_GET['status'] ?? '')),
                'kinds'     => Support::KINDS,
                'statuses'  => Support::STATUSES,
                'enabled'   => Support::enabled(),
                'is_admin'  => $isAdmin,
                'pending'   => $isAdmin ? Support::pending() : 0,
                // Чего не хватает, чтобы обращение уехало в GitHub, — видно ДО отправки
                'repo'      => Support::repo(),
                'token_set' => Support::token() !== '',
                'max_mb'    => (int)Settings::get('SUPPORT_MAX_MB', 25),
            ]);

        // Файл формы — в ту же папку, куда складываются вложения письма
        case 'upload':
            if (empty($_FILES['file'])) jsonError('Файл не передан');
            jsonOk(['file' => Outbox::accept($_FILES['file'], (int)$manager['id'])]);

        case 'submit':
            if (!Support::enabled()) jsonError('Обратная связь выключена в настройках');
            // Администратор сам и есть ревью: его обращение сразу уходит в issue (issue #90)
            $res = Support::submit((int)$manager['id'], $input + ($isAdmin ? ['quiet' => 1] : []),
                                   (array)($input['files'] ?? []));
            if ($isAdmin && Support::repo() !== '' && Support::token() !== '') {
                try {
                    $gh = Support::approve((int)$res['id'], (int)$manager['id']);
                    $res += ['issue_number' => $gh['number'], 'issue_url' => $gh['url']];
                } catch (Throwable $e) {
                    Logger::exception('support', $e, ['ticket' => $res['id']]);
                    $res['issue_error'] = $e->getMessage();
                }
            }
            jsonOk($res + ['items' => Support::listFor($manager)]);

        /**
         * Файл обращения отдаётся из `storage/`, а не ссылкой на GitHub:
         * в приватном репозитории такая ссылка анонимному браузеру не откроется,
         * а свой файл менеджер должен видеть всегда.
         */
        case 'file': {
            $id  = (int)($_GET['id'] ?? 0);
            $row = Db::one("SELECT f.*, t.manager_id FROM support_files f
                            JOIN support_tickets t ON t.id = f.ticket_id WHERE f.id=?", [$id]);
            if (!$row) jsonError('Файл не найден', 404);
            if (!$isAdmin && (int)$row['manager_id'] !== (int)$manager['id']) jsonError('Чужое обращение', 403);

            $path = ROOT . '/' . ltrim((string)$row['path'], '/');
            if (!is_file($path)) jsonError('Файла больше нет на сервере', 404);
            header('Content-Type: ' . ((string)$row['mime'] ?: 'application/octet-stream'));
            header('Content-Length: ' . (string)filesize($path));
            header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode((string)$row['filename']));
            readfile($path);
            exit;
        }

        case 'approve': {
            if (!$isAdmin) jsonError('Отправляет в GitHub администратор', 403);
            $res = Support::approve((int)($input['id'] ?? 0), (int)$manager['id'], [
                'title' => $input['title'] ?? null,
                'body'  => $input['body'] ?? null,
            ]);
            jsonOk($res + ['items' => Support::listFor($manager)]);
        }

        case 'decline':
            if (!$isAdmin) jsonError('Решение принимает администратор', 403);
            Support::decline((int)($input['id'] ?? 0), (int)$manager['id'], (string)($input['note'] ?? ''));
            jsonOk(['items' => Support::listFor($manager)]);

        default:
            jsonError('Unknown action', 400);
    }
} catch (Throwable $e) {
    Logger::exception('support', $e, ['action' => $action, 'manager_id' => $manager['id']]);
    jsonError($e->getMessage());
}
