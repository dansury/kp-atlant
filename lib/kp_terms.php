<?php
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/kp_content.php';

/**
 * Условия КП — один правимый блок вместо четырёх зашитых фраз (модуль 026).
 *
 * В документе внизу печатались четыре абзаца, написанные прямо в шаблоне:
 * упаковка и страхование, гарантия на год, срок исполнения, срок действия
 * цены. Два из них были неправдой: годовой гарантии мы по умолчанию не несём,
 * а доставка в стоимость не входит. Поправить их было негде — текст жил в
 * `templates/kp.html`, и менеджер дописывал опровержение руками в примечании.
 *
 * Теперь это один текст, который лежит на КП (`proposals.terms_text`), правится
 * там же, где правится остальной документ, и ПОСЛЕДНЯЯ правка становится
 * значением по умолчанию для следующих КП: house rule пишется один раз.
 *
 * Места, которые подставляются из полей КП, чтобы блок не расходился с
 * выпадающими списками «Срок исполнения» и «Срок действия»: {execution_term}
 * (срок исполнения словами — дни по списку или месяцы ожидания, если что-то
 * под заказ), {execution_days} и {validity_days}.
 */
final class KpTerms {

    /** Ключ настройки, в которой лежит текст для следующего КП. */
    public const SETTING = 'default_terms_text';

    /**
     * Условия, с которых начинает пустой аккаунт.
     *
     * Гарантии здесь нет нарочно: её несут не на всё и не всегда, и обещание,
     * напечатанное само по себе, дороже молчания. Доставка названа отдельно —
     * она считается строкой в таблице (см. «Доставка» в редакторе КП).
     */
    public const FACTORY_TEXT = <<<'TEXT'
Стоимость включает расходы на упаковку, маркировку, хранение, {delivery_in_price}подготовку и передачу документов.
{delivery_separate_clause}Сроки выполнения условий договора {execution_term} с момента получения предоплаты.
Предлагаемая цена продукции является твёрдой и не подлежит изменению в течение {validity_days} дней с даты настоящего предложения.
TEXT;

    /**
     * Текст, с которым собирается новое КП.
     *
     * Лежит там же, где остальные заготовки документа (`default_*` в таблице
     * `settings`), и правится на той же странице настроек.
     */
    public static function defaultText(): string {
        $stored = Db::val("SELECT value FROM settings WHERE key=?", [self::SETTING]);
        return $stored === null ? self::FACTORY_TEXT : (string)$stored;
    }

    /**
     * Запомнить правку на следующие КП.
     *
     * Пустой текст — тоже решение («условий не печатаем»), поэтому он тоже
     * запоминается: иначе очищенный блок возвращался бы на каждом новом КП.
     */
    public static function remember(string $text): void {
        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)", [self::SETTING, trim($text)]);
    }

    /**
     * Условия ЭТОГО КП, готовые к печати.
     *
     * КП, собранное до модуля 026, своего блока не имеет — его условия
     * складываются из прежних четырёх полей, слово в слово как печатались.
     * Переоткрытый через полгода документ обязан выглядеть так, как его
     * подписали.
     */
    public static function forProposal(array $proposal): string {
        return self::fill(self::rawForProposal($proposal), $proposal);
    }

    /**
     * Срок исполнения, как он печатается в условиях (модуль 034).
     *
     * «30 календарных дней» верно только тогда, когда весь товар есть на
     * складе. Стоит в КП хоть одна позиция под заказ — договор исполняется не
     * раньше, чем она приедет, и сроком становится САМОЕ ДОЛГОЕ ожидание из
     * таблицы подбора (по умолчанию 3 месяца). Клиенту нельзя обещать месяц
     * там, где мы сами ждём квартал.
     */
    public static function executionTerm(array $proposal): string {
        $months = self::waitMonths($proposal);
        if ($months > 0) return Terms::monthsWord($months);
        $days = (int)($proposal['execution_days'] ?? 30);
        return $days . ' ' . self::daysWord($days);
    }

    /**
     * Самый долгий срок ожидания среди позиций КП, у которых подняты условия
     * ожидания. Ноль — ждать нечего, весь товар в наличии.
     */
    private static function waitMonths(array $proposal): int {
        $id = (int)($proposal['id'] ?? 0);
        if (!$id) return 0;
        $max = 0;
        // Свёрнутая строка в документ не печатается — и срока не задаёт
        foreach (KpContent::printedItems($id) as $item) {
            $wait = Terms::waiting($item);
            if ($wait && $wait['months'] > $max) $max = $wait['months'];
        }
        return $max;
    }

    /** «30 календарных дней» — слово склоняется по числу. */
    private static function daysWord(int $n): string {
        $mod100 = $n % 100;
        $mod10 = $n % 10;
        if ($mod100 >= 11 && $mod100 <= 14) return 'календарных дней';
        if ($mod10 === 1) return 'календарный день';
        if ($mod10 >= 2 && $mod10 <= 4) return 'календарных дня';
        return 'календарных дней';
    }

    /**
     * Тот же текст, но как его правят: с {execution_days} и {validity_days}
     * на месте. Редактор показывает именно его — иначе первая же правка
     * впечатала бы «30» и «14» намертво, и выпадающие списки «Срок исполнения»
     * и «Срок действия» перестали бы что-либо значить.
     */
    public static function rawForProposal(array $proposal): string {
        $raw = $proposal['terms_text'] ?? null;
        return $raw === null ? self::legacyText($proposal) : (string)$raw;
    }

    /**
     * Подставить {execution_term}, {execution_days} и {validity_days} из полей КП.
     *
     * Текст, написанный до модуля 034, говорит «{execution_days} календарных
     * дней» — эта фраза целиком заменяется настоящим сроком, когда в КП есть
     * позиции под заказ: иначе документ обещал бы месяц по товару, который мы
     * сами ждём квартал.
     */
    public static function fill(string $text, array $proposal): string {
        $term = self::executionTerm($proposal);
        $text = (string)preg_replace(
            '/\{execution_days\}\s*календарн\w+\s+(?:дн\w+|день)/u', $term, $text);
        // Доставка включена в цену товара или печатается отдельной строкой —
        // это решает настройка, а условия говорят об этом тем же текстом,
        // что печатает документ (issue #60)
        $included = (string)Settings::get('KP_DELIVERY_MODE', 'included') === 'included';
        return strtr($text, [
            '{execution_term}' => $term,
            '{execution_days}' => (string)(int)($proposal['execution_days'] ?? 30),
            '{validity_days}'  => (string)(int)($proposal['validity_days'] ?? 14),
            '{delivery_in_price}' => $included ? 'доставку, ' : '',
            '{delivery_separate_clause}' => $included ? ''
                : "Доставка в стоимость не включена и оплачивается при получении по тарифам СДЭК.\n",
        ]);
    }

    /** Те самые четыре абзаца — для КП, собранных до модуля 026. */
    private static function legacyText(array $proposal): string {
        $parts = [];
        $conditions = trim((string)($proposal['conditions_text'] ?? ''));
        if ($conditions !== '') $parts[] = $conditions;
        $warranty = trim((string)($proposal['warranty_text'] ?? ''));
        if ($warranty !== '') $parts[] = $warranty;
        $parts[] = 'Сроки выполнения условий договора {execution_term} '
                 . 'с момента получения предоплаты.';
        $parts[] = 'Предлагаемая цена продукции является твёрдой и не подлежит изменению '
                 . 'в течение {validity_days} дней с даты настоящего предложения.';
        return implode("\n", $parts);
    }
}
