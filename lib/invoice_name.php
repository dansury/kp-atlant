<?php
/**
 * Имя файла счёта, которое клиент найдёт у себя в папке (модуль 029).
 *
 * «Счет_от_Атлант_Армор_для_ТИКО-Пластик_16.09.26.pdf» ищется через неделю,
 * «Счет 00123.pdf» — нет. Шаблон живёт в настройках (`INVOICE_FILE_NAME`),
 * а менеджер правит готовое имя прямо в письме перед отправкой.
 */
final class InvoiceName {

    /** Подстановки шаблона: что во что превращается. */
    public const TOKENS = ['{company}', '{client}', '{date}', '{number}'];

    /**
     * Имя файла счёта без расширения не бывает: `.pdf` дописывается сам, даже
     * если оператор написал шаблон с ним или без него.
     */
    public static function forInvoice(int $invoiceId): string {
        $inv = Db::one("SELECT * FROM invoices WHERE id=?", [$invoiceId]);
        if (!$inv) return 'Счет.pdf';

        return self::build([
            'company' => self::ourCompany(),
            'client'  => self::clientName((int)($inv['counterparty_id'] ?? 0), (int)($inv['org_id'] ?? 0)),
            'date'    => self::date((string)($inv['moment'] ?? '')),
            'number'  => (string)($inv['name'] ?? ''),
        ]);
    }

    /** Одно и то же имя, собранное из уже готовых частей — для предпросмотра. */
    public static function build(array $parts): string {
        $template = trim((string)Settings::get('INVOICE_FILE_NAME', 'Счет_от_{company}_для_{client}_{date}'));
        if ($template === '') $template = 'Счет_от_{company}_для_{client}_{date}';
        $template = preg_replace('/\.pdf$/iu', '', $template) ?? $template;

        $name = strtr($template, [
            '{company}' => self::part((string)($parts['company'] ?? '')),
            '{client}'  => self::part((string)($parts['client'] ?? '')),
            '{date}'    => (string)($parts['date'] ?? date('d.m.y')),
            '{number}'  => self::part((string)($parts['number'] ?? '')),
        ]);
        // Пустая подстановка не должна оставлять «Счет_от__для_»
        $name = (string)preg_replace('/_{2,}/u', '_', $name);
        $name = trim($name, '_ ');
        return ($name !== '' ? mb_substr($name, 0, 150) : 'Счет') . '.pdf';
    }

    /** Наша организация: настройка → название из МойСклад → бренд КП. */
    public static function ourCompany(): string {
        $own = trim((string)Settings::get('INVOICE_FILE_COMPANY', ''));
        if ($own !== '') return $own;

        // Название нашей организации — то же, что печатается в КП: оно приходит
        // из МойСклад вместе с реквизитами (`Requisites::syncOrganization`)
        $org = Db::val("SELECT short_name FROM legal_entities WHERE is_active=1 ORDER BY id LIMIT 1");
        if (trim((string)$org) !== '') return (string)$org;

        return (string)Settings::get('KP_FILE_BRAND', 'Атлант Армор');
    }

    /** Кому счёт: организация счёта → компания карточки → ФИО контакта. */
    public static function clientName(int $counterpartyId, int $orgId = 0): string {
        if ($orgId > 0) {
            $org = Db::one("SELECT name FROM counterparty_orgs WHERE id=?", [$orgId]);
            if ($org && trim((string)$org['name']) !== '') return (string)$org['name'];
        }
        if (!$counterpartyId) return '';
        $cp = Db::one("SELECT name, contact_person, contact_email FROM counterparties WHERE id=?", [$counterpartyId]);
        if (!$cp) return '';
        foreach (['name', 'contact_person'] as $field) {
            $value = trim((string)($cp[$field] ?? ''));
            if ($value !== '') return $value;
        }
        $email = trim((string)($cp['contact_email'] ?? ''));
        return $email !== '' ? (strstr($email, '@', true) ?: $email) : '';
    }

    /** ДД.ММ.ГГ — как в имени файла и просят. */
    private static function date(string $moment): string {
        $ts = $moment !== '' ? strtotime($moment) : false;
        return date('d.m.y', $ts ?: time());
    }

    /**
     * Кусок имени файла. Кириллица остаётся кириллицей — её понимают и Windows,
     * и почта; всё, что ломает файловые системы и заголовок вложения, уходит в
     * подчёркивание. То же правило, что у имени файла КП (`PdfGenerator`).
     */
    private static function part(string $value): string {
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        $value = str_replace(['«', '»', '"', "'", '“', '”'], '', $value);
        $value = (string)preg_replace('#[\\\\/:*?<>|\#%&{}$!@+`=\[\]]+#u', ' ', $value);
        $value = (string)preg_replace('/[\s,.;]+/u', '_', $value);
        return mb_substr(trim($value, '_'), 0, 60);
    }
}
