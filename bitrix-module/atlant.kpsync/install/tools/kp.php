<?php
/**
 * The endpoint the КП service talks to.
 *
 * Installed at /bitrix/tools/atlant.kpsync/kp.php — that URL, with the token,
 * is what goes into «Настройки → Сайт (Битрикс) → Входящий вебхук».
 *
 *   ?token=…&article=ABC-1                 one product, best match first
 *   ?token=…&action=export&offset=0        a page of the catalog
 *   ?token=…&action=ping                   what the module sees from here
 *   ?token=…&action=export_xlsx            the catalog as an Excel file
 *
 * Nothing here writes. The whole module is read-only against the shop, which
 * is why it is safe to point a cron at it.
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Atlant\KpSync\Catalog;
use Atlant\KpSync\Config;
use Atlant\KpSync\Response;
use Bitrix\Main\Loader;

if (!Loader::includeModule('atlant.kpsync')) {
    Response::fail('Модуль atlant.kpsync не установлен');
    die();
}

if (!Config::enabled()) {
    Response::fail('Выгрузка выключена в настройках модуля');
    die();
}

// A token is compared in constant time, and an empty one means the
// administrator deliberately left the endpoint open
$token = (string)($_REQUEST['token'] ?? $_SERVER['HTTP_X_KP_TOKEN'] ?? '');
if (!Config::tokenOk($token)) {
    Response::fail('Неверный токен', 403);
    die();
}

$action = (string)($_REQUEST['action'] ?? 'find');

switch ($action) {

    case 'ping':
        Response::ok(['result' => Catalog::diagnose(), 'url' => '']);
        break;

    case 'export':
        $limit  = (int)($_REQUEST['limit'] ?? 0) ?: Config::exportLimit();
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, (int)($_REQUEST['offset'] ?? 0));

        $page = Catalog::export($offset, $limit);
        $next = $offset + count($page['items']);
        Response::ok([
            'result' => $page['items'],
            'total'  => $page['total'],
            'offset' => $offset,
            // null means «that was the last page» — the caller stops on it
            'next'   => ($next < $page['total'] && $page['items']) ? $next : null,
            'url'    => '',
        ]);
        break;

    case 'export_xlsx':
        // Каталог в Excel: код, название, модификации, описание, ссылка (issue #67)
        try {
            \Atlant\KpSync\Export::download();
        } catch (\Throwable $e) {
            Response::fail('Выгрузка не собралась: ' . $e->getMessage(), 500);
        }
        break;

    case 'find':
    default:
        $article = trim((string)($_REQUEST['article'] ?? ''));
        $code    = trim((string)($_REQUEST['code'] ?? ''));
        $name    = trim((string)($_REQUEST['name'] ?? ''));

        if ($article === '' && $code === '' && $name === '') {
            Response::fail('Нужен хотя бы один из параметров: article, code, name');
            break;
        }

        $rows = Catalog::find($article, $code, $name);
        Response::ok([
            'result' => $rows,
            // The service reads this first; an empty string is a clear
            // «ничего не нашли», which it caches as such
            'url'    => $rows ? (string)$rows[0]['url'] : '',
            'total'  => count($rows),
        ]);
        break;
}

die();
