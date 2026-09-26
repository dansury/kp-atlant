<?php
/**
 * API: расчёт доставки СДЭК у строки доставки подбора (модуль 067, issue #139).
 * Ключи СДЭК живут на сервере и в браузер не уходят.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/cdek.php';

requireAuth();

switch ($_GET['action'] ?? '') {
    case 'state':
        jsonData(Cdek::state());

    // Позиции подбора с весом штуки: из карточки МойСклад или из описания
    case 'weights':
        $id = (int)($_GET['request_id'] ?? 0);
        if (!$id || !Db::one("SELECT id FROM requests WHERE id=?", [$id])) jsonError('Not found', 404);
        jsonData(['items' => Cdek::requestWeights($id)]);

    case 'cities':
        try {
            jsonData(['items' => Cdek::cities((string)($_GET['q'] ?? ''))]);
        } catch (Throwable $e) {
            Logger::warning('cdek', 'Города СДЭК не загрузились: ' . $e->getMessage());
            jsonError($e->getMessage(), 502);
        }

    /**
     * Посчитать: с ключом — тарифы СДЭК (коробка — услугой, сумма с ней),
     * без ключа — по ставке договора. Места посылки собирает окно.
     */
    case 'calc':
        $in = getInput();
        $packages = array_values(array_filter((array)($in['packages'] ?? []), 'is_array'));
        if (!$packages) jsonError('Нет мест посылки: укажите вес и габариты');
        foreach ($packages as $p) {
            if ((float)str_replace(',', '.', (string)($p['weight'] ?? 0)) <= 0) jsonError('У места посылки нет веса');
        }
        $box = trim((string)($in['box'] ?? ''));

        if (!Cdek::enabled()) {
            $base = isset($in['rate_base']) ? (float)$in['rate_base'] : (float)Settings::get('CDEK_RATE_BASE', 0);
            $kg   = isset($in['rate_kg'])   ? (float)$in['rate_kg']   : (float)Settings::get('CDEK_RATE_KG', 0);
            $quote = Cdek::rateQuote($packages, $base, $kg);
            jsonData(['mode' => 'rate', 'quote' => $quote,
                      'price' => $quote['sum'] === null ? null : Cdek::withMarkup($quote['sum'])]);
        }

        $to = (int)($in['to_code'] ?? 0);
        if (!$to) jsonError('Выберите город получателя из подсказки');
        $contract = (string)($in['contract'] ?? Cdek::contract());
        $type = Cdek::CONTRACT_TYPES[$contract] ?? 1;
        try {
            $from = Cdek::fromCode();
            if (!$from) jsonError('Город отправки «' . Settings::get('CDEK_FROM_CITY', 'Москва') . '» СДЭК не нашёл — проверьте настройку');
            $tariffs = Cdek::tariffs($from, $to, $packages, $type);
            // Коробка СДЭК — услуга, её цена входит в сумму: пересчитываем
            // дешёвые тарифы с ней, остальные остаются без упаковки
            if ($box !== '') {
                $service = [['code' => $box, 'parameter' => (string)count($packages)]];
                foreach (array_slice(array_keys($tariffs), 0, 3) as $i) {
                    try {
                        $one = Cdek::tariff($tariffs[$i]['code'], $from, $to, $packages, $type, $service);
                        $tariffs[$i]['sum'] = $one['sum'];
                        $tariffs[$i]['with_box'] = true;
                    } catch (Throwable $e) {
                        $tariffs[$i]['box_error'] = $e->getMessage();
                    }
                }
            }
            foreach ($tariffs as &$t) $t['price'] = Cdek::withMarkup($t['sum']);
            unset($t);
            jsonData(['mode' => 'api', 'tariffs' => $tariffs]);
        } catch (Throwable $e) {
            Logger::warning('cdek', 'Расчёт доставки СДЭК не удался: ' . $e->getMessage(), ['to' => $to]);
            jsonError($e->getMessage(), 502);
        }

    default:
        jsonError('Unknown action', 400);
}
