<?php
/**
 * API: расчёт доставки по тарифам СДЭК (модуль 067, issue #139).
 *
 * prefill — что панель знает о запросе заранее (города, вес позиций, коробка);
 * cities — подсказка городов СДЭК; quote — тарифы СДЭК или оценка без API.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/cdek.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'prefill':
        requireAuth();
        $id = (int)($_GET['request_id'] ?? 0);
        if (!Db::one("SELECT id FROM requests WHERE id=?", [$id])) jsonError('Запрос не найден', 404);
        jsonData(Cdek::prefill($id));

    case 'cities':
        requireAuth();
        if (!Cdek::hasApi()) jsonData(['items' => []]);
        try {
            jsonData(['items' => Cdek::cities((string)($_GET['q'] ?? ''))]);
        } catch (Throwable $e) {
            // Подсказка — удобство: без неё город вписывают руками
            jsonData(['items' => [], 'error' => $e->getMessage()]);
        }

    case 'quote': {
        requireAuth();
        $in = getInput();
        $packages = Cdek::packages((array)($in['packages'] ?? []));
        if (!$packages) jsonError('Укажите вес посылки — без него доставку не посчитать');
        $from = trim((string)($in['from'] ?? ''));
        $to = trim((string)($in['to'] ?? ''));
        $contract = (string)($in['contract'] ?? '');

        $why = '';
        $apiError = '';
        if (Cdek::hasApi() && isset(Cdek::CONTRACTS[$contract])) {
            if ($from === '' || $to === '') jsonError('Укажите города «откуда» и «куда» — по ним СДЭК считает тариф');
            try {
                jsonData(['mode' => 'api', 'tariffs' => Cdek::tariffs($from, $to, $packages, $contract)]);
            } catch (Throwable $e) {
                $apiError = $e->getMessage();
                Logger::warning('cdek', 'Тарифы СДЭК не получены: ' . $apiError, ['from' => $from, 'to' => $to]);
            }
        } elseif (!Cdek::hasApi()) {
            $why = 'нет ключа API СДЭК';
        } elseif ($contract === 'none') {
            $why = 'нет договора со СДЭК — API считает только по договору';
        } else {
            $why = 'не выбран тип договора';
        }
        jsonData(['mode' => 'estimate', 'estimate' => Cdek::estimate($packages),
                  'why' => $why, 'api_error' => $apiError]);
    }

    default:
        jsonError('Unknown action', 400);
}
