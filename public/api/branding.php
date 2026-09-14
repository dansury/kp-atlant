<?php
/**
 * API: логотипы — отдача и загрузка (модуль 021).
 *
 * GET открыт всем: значок вкладки и иконки приложения браузер просит ДО входа
 * (а манифест — вообще без cookies), и запароленный favicon — это пустой
 * квадрат в закладках. Секрета в логотипе нет.
 * POST и DELETE — только администратор.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/branding.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ---------- Отдача картинки ----------
if ($method === 'GET' && $action === '') {
    $kind = (string)($_GET['kind'] ?? 'kp');
    $size = (int)($_GET['size'] ?? 0);

    $path = $kind === 'icon'
        ? Branding::icon($size ?: 192, ($_GET['purpose'] ?? '') === 'maskable')
        : Branding::resolve(in_array($kind, Branding::KINDS, true) ? $kind : 'kp');

    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Логотип не найден';
        exit;
    }

    $etag = '"' . md5($path . '-' . (string)filemtime($path)) . '"';
    // Загруженный знак должен появиться у менеджера сегодня, а не когда
    // браузер сам решит перепроверить кэш — отсюда обязательная ревалидация
    header('Content-Type: ' . Branding::mime($path));
    header('Cache-Control: public, max-age=300, must-revalidate');
    header('ETag: ' . $etag);
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

// ---------- Панель ----------
switch ($action) {
    case 'list':
        requireAuth();
        // «Загружен» и «печатается» — не одно и то же: mPDF без GD выбрасывает
        // прозрачный PNG молча, и КП уходило клиенту без знака (модуль 022)
        jsonData(['items' => Branding::describe(), 'kp_warning' => Branding::documentWarning('kp')]);

    case 'upload':
        $admin = requireAdmin();
        $kind = (string)($_POST['kind'] ?? '');
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonError('Файл не загрузился. Сервер принимает не больше ' . ini_get('upload_max_filesize') . '.');
        }
        try {
            $res = Branding::store($kind, $_FILES['file']);
        } catch (Throwable $e) {
            jsonError($e->getMessage());
        }
        jsonOk(['items' => Branding::describe(), 'kind' => $res['kind']]);

    case 'reset':
        requireAdmin();
        $input = getInput();
        $kind = (string)($input['kind'] ?? '');
        if (!in_array($kind, Branding::KINDS, true)) jsonError('Неизвестный вид логотипа');
        Branding::remove($kind);
        jsonOk(['items' => Branding::describe()]);

    default:
        jsonError('Unknown action', 400);
}
