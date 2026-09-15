<?php
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
 * Два места подставляются из полей КП, чтобы блок не расходился с выпадающими
 * списками «Срок исполнения» и «Срок действия»: {execution_days} и
 * {validity_days}.
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
Стоимость включает расходы на упаковку, маркировку, хранение, погрузку, подготовку и передачу документов. Доставка в стоимость не включена и считается отдельной строкой.
Сроки выполнения условий договора {execution_days} календарных дней с момента получения предоплаты.
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
     * Тот же текст, но как его правят: с {execution_days} и {validity_days}
     * на месте. Редактор показывает именно его — иначе первая же правка
     * впечатала бы «30» и «14» намертво, и выпадающие списки «Срок исполнения»
     * и «Срок действия» перестали бы что-либо значить.
     */
    public static function rawForProposal(array $proposal): string {
        $raw = $proposal['terms_text'] ?? null;
        return $raw === null ? self::legacyText($proposal) : (string)$raw;
    }

    /** Подставить {execution_days} и {validity_days} из полей КП. */
    public static function fill(string $text, array $proposal): string {
        return strtr($text, [
            '{execution_days}' => (string)(int)($proposal['execution_days'] ?? 30),
            '{validity_days}'  => (string)(int)($proposal['validity_days'] ?? 14),
        ]);
    }

    /** Те самые четыре абзаца — для КП, собранных до модуля 026. */
    private static function legacyText(array $proposal): string {
        $parts = [];
        $conditions = trim((string)($proposal['conditions_text'] ?? ''));
        if ($conditions !== '') $parts[] = $conditions;
        $warranty = trim((string)($proposal['warranty_text'] ?? ''));
        if ($warranty !== '') $parts[] = $warranty;
        $parts[] = 'Сроки выполнения условий договора {execution_days} календарных дней '
                 . 'с момента получения предоплаты.';
        $parts[] = 'Предлагаемая цена продукции является твёрдой и не подлежит изменению '
                 . 'в течение {validity_days} дней с даты настоящего предложения.';
        return implode("\n", $parts);
    }
}
