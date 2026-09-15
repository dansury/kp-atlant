<?php
/**
 * Cron: резерв под неоплаченный счёт (модуль 026).
 *
 * Кнопка «Счёт» ставит заказ в «Резерв» — товар с этой минуты числится за
 * клиентом. Клиент может не заплатить, и тогда товар лежит мёртвым грузом, а
 * второму покупателю мы отвечаем «нет в наличии». Через настроенный срок (по
 * умолчанию две недели) менеджер получает напоминание с кнопкой, снимающей
 * проведение заказа в МойСклад.
 *
 * Сам скрипт ничего не снимает: решение про деньги клиента принимает человек.
 *
 * Запуск:  php cron/check_reserves.php
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/sync.php';
require_once ROOT . '/lib/reserves.php';
require_once ROOT . '/lib/notifier.php';

MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');

$candidates = Reserves::due();
if (!$candidates) {
    echo "Резервов, по которым пора напомнить, нет\n";
    exit(0);
}

$sent = 0;
foreach ($candidates as $order) {
    $orderId = (int)$order['id'];

    // Оплату спрашиваем у МойСклад, а не у своей копии: напомнить про
    // оплаченный счёт хуже, чем не напомнить вовсе
    try {
        MsSync::syncInvoicesForOrder($orderId);
    } catch (Throwable $e) {
        Logger::warning('moysklad', 'Счета заказа не перечитались: ' . $e->getMessage(),
                        ['order_id' => $orderId]);
    }

    $fresh = Db::one("SELECT * FROM orders WHERE id=?", [$orderId]);
    if (!$fresh) continue;
    $state = Reserves::state($fresh);
    if (!$state['due']) {
        // Оплатили, сняли или провели обратно — напоминать не о чем
        Reserves::markReminded($orderId);
        continue;
    }

    $company = trim((string)($order['counterparty_name'] ?? '')) ?: 'без компании';
    $days = Reserves::days();
    Notifier::notify(
        'reserve_hold',
        "Резерв держится $days дн.: $company",
        "Заказ {$fresh['name']} в резерве, счёт не оплачен на "
            . number_format($state['unpaid'], 2, ',', ' ') . ' ₽. Снять резерв?',
        'counterparty',
        $fresh['counterparty_id'] ? (int)$fresh['counterparty_id'] : null,
        $fresh['manager_id'] ? (int)$fresh['manager_id'] : null
    );
    Reserves::markReminded($orderId);
    $sent++;
    echo "Напоминание по заказу {$fresh['name']} ($company)\n";
}

echo "Напоминаний отправлено: $sent\n";
