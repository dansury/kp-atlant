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

        $n = 0;
        $total = 0.0;
        // Хоть одна вилка — и «Итого» называется «от»: сумма низов вилок и есть
        // то, с чего начинается предложение (модуль 036)
        $totalIsFrom = false;
        foreach ($items as $item) {
            $n++;
            $price = Terms::price($item);
            $sum = $price * (float)$item['quantity'];
            $total += $sum;
            // Та же вилка, что и в файле (модуль 036): письмо и документ не
            // имеют права назвать клиенту разные цены
            $top = Terms::priceTop($item);
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
            // Ссылка на товар остаётся: в письме она нажимается, в отличие от бумаги
            if (trim((string)($item['site_url'] ?? '')) !== '') $lines[] = '   ' . (string)$item['site_url'];
        }

        // Доставка отдельной строкой — как и в файле (модуль 026): в цену
        // товара она не входит, и в письме это должно быть видно цифрой
        if ((int)($proposal['delivery_on'] ?? 0) === 1) {
            $deliveryPrice = (float)($proposal['delivery_price'] ?? 0);
            $total += $deliveryPrice;
            $lines[] = sprintf('%d. %s — %s', $n + 1,
                trim((string)($proposal['delivery_name'] ?? '')) ?: 'Доставка',
                self::money($deliveryPrice));
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
        return ['text' => $text, 'html' => '<p>' . nl2br(htmlspecialchars($text)) . '</p>'];
    }

    private static function money(float $v): string {
        return number_format($v, 2, ',', ' ') . ' руб.';
    }

    /** 3.0 → «3», 2.5 → «2,5»: количество в письме читает человек. */
    private static function num(float $v): string {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }
}
