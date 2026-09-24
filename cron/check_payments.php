<?php
/**
 * Cron: оплаты из Т-Банка и отправка заказов (модуль 047). Раз в 10 минут.
 *
 * 1. Выписка Т-Банка: оплаченный счёт → «Входящий платёж» в МойСклад,
 *    карточка в «Сборку», менеджеру — «Сообщить складу».
 * 2. Карточки доски кроме «Закрыто»: у заказа заполнен «ТРЕК-НОМЕР» → черновик письма клиенту.
 *
 * Запуск:  php cron/check_payments.php
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/payments.php';

MoySklad::init((string)Settings::get('MOYSKLAD_TOKEN', ''));

$p = Payments::check();
if (!empty($p['skipped'])) {
    echo "Т-Банк не настроен (TBANK_TOKEN, TBANK_ACCOUNTS) — выписка не читается\n";
} else {
    echo "Выписка: операций {$p['read']}, новых {$p['new']}, по счёту {$p['matched']}, без счёта {$p['unmatched']}\n";
    foreach ($p['errors'] as $e) echo "  ошибка: $e\n";
}

$s = Fulfillment::checkShipments();
echo "Доска: заказов проверено {$s['checked']}, отправлено {$s['shipped']}\n";
foreach ($s['errors'] as $e) echo "  ошибка: $e\n";
