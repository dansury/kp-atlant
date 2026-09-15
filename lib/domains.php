<?php
/**
 * Чей это адрес — домена или самого ящика.
 *
 * Компании склеивались по домену: всё, что не в списке публичной почты,
 * считалось «доменом компании». Но заявки приходят и через сервисы-
 * ретрансляторы, где у каждой заявки свой адрес, а домен один на всех —
 * и тринадцать разных покупателей оказались в одной карточке (модуль 025).
 *
 * Здесь живёт единственный ответ на вопрос «значит ли домен компанию»:
 * встроенный список, добавленное руками в настройках и то, что сервис
 * понял сам, увидев два разных ИНН на одном домене.
 */
final class MailDomains {

    /** Почтовые сервисы и ретрансляторы: домен тут не про компанию. */
    private const BUILTIN = [
        // публичная почта
        'gmail.com', 'googlemail.com',
        'yandex.ru', 'yandex.com', 'ya.ru', 'yandex.by', 'yandex.kz', 'narod.ru',
        'mail.ru', 'bk.ru', 'list.ru', 'inbox.ru', 'internet.ru',
        'rambler.ru', 'lenta.ru', 'ro.ru', 'autorambler.ru',
        'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com',
        'proton.me', 'protonmail.com', 'yahoo.com', 'aol.com', 'qq.com', '163.com',
        'bcc.ru', 'vk.com', 'sberbank.ru',
        // ретрансляторы заявок: адрес у каждой заявки свой, компания за ним — разная
        'snipermail.ru',
    ];

    private static ?array $learned = null;
    private static ?string $configuredRaw = null;
    private static array $configured = [];

    /** Домен из адреса или из самого домена — в том виде, в каком его сравнивают. */
    public static function normalize(string $domainOrEmail): string {
        $s = mb_strtolower(trim($domainOrEmail));
        if ($s === '') return '';
        if (str_contains($s, '@')) $s = substr($s, strrpos($s, '@') + 1);
        return trim($s, " \t.@<>");
    }

    /** @return string[] */
    public static function builtin(): array {
        return self::BUILTIN;
    }

    /** Домены, дописанные в настройках руками. @return string[] */
    public static function configured(): array {
        $raw = (string)Settings::get('CRM_SHARED_DOMAINS', '');
        if ($raw === self::$configuredRaw) return self::$configured;

        $out = [];
        foreach (preg_split('/[\s,;]+/u', $raw) ?: [] as $d) {
            $d = self::normalize($d);
            if ($d !== '') $out[$d] = true;
        }
        self::$configuredRaw = $raw;
        return self::$configured = array_keys($out);
    }

    /** Домены, про которые сервис понял сам. @return string[] */
    public static function learned(): array {
        if (self::$learned !== null) return self::$learned;
        if (!Db::hasTable('shared_domains')) return self::$learned = [];
        return self::$learned = array_map(
            fn($r) => (string)$r['domain'],
            Db::all("SELECT domain FROM shared_domains")
        );
    }

    /** Значит ли домен компанию. Пустой домен не значит ничего. */
    public static function isShared(string $domainOrEmail): bool {
        $d = self::normalize($domainOrEmail);
        if ($d === '') return true;
        return in_array($d, self::BUILTIN, true)
            || in_array($d, self::configured(), true)
            || in_array($d, self::learned(), true);
    }

    /**
     * Запомнить общий домен — и снять его с карточек, иначе старый ключ
     * продолжит склеивать всех, кто с него напишет.
     */
    public static function markShared(string $domainOrEmail, string $reason = ''): bool {
        $d = self::normalize($domainOrEmail);
        if ($d === '') return false;

        $knew = self::isShared($d);
        if (!$knew && Db::hasTable('shared_domains')) {
            Db::q("INSERT OR IGNORE INTO shared_domains (domain, reason) VALUES (?, ?)", [$d, $reason]);
            self::$learned = null;
            if (class_exists('Logger') && Db::hasTable('logs')) {
                Logger::info('crm', "Домен $d признан общим: $reason");
            }
        }
        // Ключ снимается в любом случае, даже со встроенного домена: пока он
        // стоит в карточке, старая запись продолжает выглядеть как признак
        Db::q("UPDATE counterparties SET email_domain=NULL WHERE lower(email_domain)=?", [$d]);
        return !$knew;
    }

    /** Больше не общий: домен убирают из выученных, карточки склеиваются снова. */
    public static function unmarkShared(string $domainOrEmail): void {
        $d = self::normalize($domainOrEmail);
        if ($d === '' || !Db::hasTable('shared_domains')) return;
        Db::q("DELETE FROM shared_domains WHERE domain=?", [$d]);
        self::$learned = null;
    }

    /**
     * Ключ «с кем переписка»: домен, когда он и есть компания, иначе сам адрес.
     * Коллега с того же завода попадёт в ту же переписку, а две заявки с
     * ретранслятора — в разные.
     */
    public static function party(string $email): string {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) return '';
        return self::isShared($email) ? $email : self::normalize($email);
    }

    /** Первый адрес из строки «Имя <a@b.ru>, c@d.ru». */
    public static function firstAddress(string $raw): string {
        foreach (preg_split('/[,;]/', $raw) ?: [] as $candidate) {
            if (preg_match('/[\w.+-]+@[\w.-]+\.\w+/u', trim($candidate), $m)) return mb_strtolower($m[0]);
        }
        return '';
    }

    /** Сбросить кэши — нужно тестам и после правки настроек. */
    public static function reset(): void {
        self::$learned = null;
        self::$configuredRaw = null;
        self::$configured = [];
    }
}
