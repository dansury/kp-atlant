<?php
/**
 * КП текстом письма (модуль 023).
 *
 * Не всякому клиенту нужен файл. Закупщику нужен Word, чтобы перенести позиции
 * в свою форму; человеку, который спросил «сколько стоит шлем», файл только
 * мешает — он открывает письмо с телефона и хочет прочитать ответ, а не
 * скачивать вложение.
 *
 * Поэтому третий формат отправки: то же самое КП, теми же цифрами и теми же
 * условиями, но прямо в теле письма. Отличие ровно одно — нет QR-кода: его
 * незачем фотографировать с экрана, на котором ссылка и так нажимается.
 *
 * Считает здесь только `Terms` и сама база. Ни одной цифры, придуманной по
 * дороге, в письме быть не может — это то же КП, а не его пересказ.
 */
require_once __DIR__ . '/kp_content.php';
require_once __DIR__ . '/requisites.php';
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/kp_terms.php';
require_once __DIR__ . '/delivery_share.php';
require_once __DIR__ . '/mail_text.php';

final class KpText {

    /**
     * @return array{text:string,html:string}
     */
    public static function render(int $proposalId): array {
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        if (!$proposal) throw new RuntimeException("Proposal $proposalId not found");

        $items = KpContent::printedItems($proposalId);
        $requisites = Requisites::forProposal($proposalId);
        $lines = [];

        // Доставка, включённая в стоимость, входит в цену за единицу — теми же
        // долями, что в файле и в счёте (модуль 045)
        $items = array_values($items);
        $shares = DeliveryShare::included($proposal)
            ? DeliveryShare::perUnit(array_map(fn($i) => ['unit' => Terms::price($i), 'qty' => (float)$i['quantity']], $items),
                                     (float)$proposal['delivery_price'])
            : array_fill(0, count($items), 0.0);
        $spread = array_sum($shares) > 0;

        $n = 0;
        $total = 0.0;
        // Хоть одна вилка — и «Итого» называется «от»: сумма низов вилок и есть
        // то, с чего начинается предложение (модуль 036)
        $totalIsFrom = false;
        foreach ($items as $k => $item) {
            $n++;
            $price = round(Terms::price($item) + $shares[$k], 2);
            $sum = $price * (float)$item['quantity'];
            $total += $sum;
            // Та же вилка, что и в файле (модуль 036): письмо и документ не
            // имеют права назвать клиенту разные цены
            $top = Terms::priceTop($item);
            if ($top > 0) $top = round($top + $shares[$k], 2);
            if ($top > 0 || !empty($item['price_from'])) $totalIsFrom = true;

            $lines[] = sprintf('%d. %s — %s %s × %s = %s',
                $n,
                (string)$item['product_name'],
                self::num((float)$item['quantity']),
                (string)($item['unit'] ?: 'шт.'),
                $top > 0 ? 'от ' . self::money($price) . ' до ' . self::money($top) : self::money($price),
                $top > 0 ? 'от ' . self::money($sum) . ' до ' . self::money($top * (float)$item['quantity'])
                         : self::money($sum)
            );

            // Скидка — словами, как столбец «Со скидкой» в файле (issue #60)
            $discount = Terms::totalDiscount($item);
            $manual = (float)($item['discount_percent'] ?? 0);
            if ($manual > 0) $lines[] = '   скидка ' . self::pct($manual) . '%';
            elseif ($discount > 0 && Terms::note($item) === '') $lines[] = '   скидка ' . self::pct($discount) . '%';

            foreach ([$item['notes'] ?? '', Terms::note($item)] as $note) {
                $note = trim((string)$note);
                if ($note !== '') $lines[] = '   ' . $note;
            }
            // Описание позиции — то же и тем же выбором, что в файле: слова
            // менеджера, если он их написал, иначе описание из МойСклад. Один
            // блок, а не два подряд (модуль 032).
            $comment = trim(Markup::toPlainText((string)($item['comment_text'] ?? '')));
            if ($comment === '') $comment = trim(Markup::toPlainText((string)($item['description_text'] ?? '')));
            if ($comment !== '') $lines[] = '   ' . mb_substr($comment, 0, 600);
            // Ссылка на товар — словами «см. на сайте» (issue #60): в HTML-части
            // письма они становятся ссылкой, `MailText::textToHtml()`
            if (trim((string)($item['site_url'] ?? '')) !== '') {
                $lines[] = '   ' . MailText::SITE_LINK_LABEL . ': ' . trim((string)$item['site_url']);
            }
        }

        // Доставка отдельной строкой — когда она не разложена по позициям
        if ((int)($proposal['delivery_on'] ?? 0) === 1 && !$spread) {
            $deliveryPrice = (float)($proposal['delivery_price'] ?? 0);
            $total += $deliveryPrice;
            $lines[] = sprintf('%d. %s — %s', $n + 1,
                trim((string)($proposal['delivery_name'] ?? '')) ?: 'Доставка',
                self::money($deliveryPrice));
        }

        // «Не наша номенклатура» — как в таблице файла, с прочерком (issue #60)
        foreach (KpContent::outOfScopeRows($proposal) as $row) {
            $lines[] = '— ' . $row['requested'] . ' — не поставляем';
        }

        $body = [];
        $body[] = 'Коммерческое предложение' . ($proposal['number'] ? ' № ' . $proposal['number'] : '')
                . ' от ' . date('d.m.Y');
        $buyer = trim((string)(($requisites['buyer']['legal_title'] ?? '') ?: ($requisites['buyer']['name'] ?? '')));
        if ($buyer !== '') $body[] = 'Покупатель: ' . $buyer;
        $body[] = '';
        $body[] = $lines ? implode("\n", $lines) : 'Позиции уточняются.';
        $body[] = '';
        // Итог и налог — теми же словами и теми же цифрами, что в файле
        // (модуль 030): письмо и документ не могут называть клиенту разные
        // суммы, поэтому строки приходят из `Requisites::vatTotals()`
        $vat = $requisites['vat'] ?? [];
        if (!array_key_exists('rate', $vat)) $vat['rate'] = (int)($proposal['vat_rate'] ?? 5);
        foreach (Requisites::vatTotals($total, $vat, Requisites::vatMode($proposal))['lines'] as $line) {
            $body[] = $line['label'] . ($line['amount'] === null
                ? '' : ': ' . ($totalIsFrom ? 'от ' : '') . self::money($line['amount']));
        }

        // Позиции, на которые каталог не ответил, — словами клиента, как в файле
        $unmatched = KpContent::unmatchedRows($proposalId);
        if ($unmatched) {
            $body[] = '';
            $body[] = 'Позиции запроса, по которым нужно уточнение:';
            foreach ($unmatched as $row) {
                $body[] = '— ' . $row['requested'] . ' — ' . $row['quantity'] . ' ' . $row['unit'];
            }
            $note = trim((string)Settings::get('KP_UNMATCHED_NOTE', ''));
            if ($note !== '') $body[] = $note;
        }

        // Условия — тот же блок, что в файле (модуль 026): письмо и документ
        // не должны обещать клиенту разного
        $terms = trim(KpTerms::forProposal($proposal));
        if ($terms !== '') {
            $body[] = '';
            $body[] = $terms;
        }

        $text = implode("\n", $body);
        return ['text' => $text, 'html' => MailText::textToHtml($text)];
    }

    private static function pct(float $v): string {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }

    private static function money(float $v): string {
        return number_format($v, 2, ',', ' ') . ' руб.';
    }

    /** 3.0 → «3», 2.5 → «2,5»: количество в письме читает человек. */
    private static function num(float $v): string {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }
}
