<?php
/**
 * CRM: company identity (INN → email domain → name), unified chat feed,
 * contacts, internal notes, unanswered tracking. Module 002 (FR-033..FR-038).
 */
class Crm {

    /**
     * Legal forms a Russian company signs itself with, longest first — `\b`
     * already keeps «ГУП» out of «ФГУП», and the order keeps it that way if the
     * boundary ever moves.
     */
    private const LEGAL_FORMS = 'ФГБОУ|ФГБУ|ФГКУ|ФГУП|ГБУЗ|ГБОУ|ГБУ|ГКУ|МБУ|МУП|ГУП|АНО|НКО|ООО|ОАО|ЗАО|ПАО|НАО|АО|ИП';

    /**
     * Find or create the company card for an incoming message.
     * $hints: inn, name, email, contact_person, phone, text
     * Match order (C-012): INN → corporate email domain → normalized name.
     *
     * `text` is the letter itself, and it is the safety net under the model: a
     * КП went out addressed to «zakupki@…» because the only place «АО
     * "Уралэлемент"» appeared was the signature of a quoted letter and the
     * prompt never looked there (module 018). The regex looks there before the
     * e-mail is accepted as a company name.
     */
    public static function resolveCounterparty(array $hints): ?int {
        $id = self::findCounterparty($hints);
        if ($id) return $id;

        [$inn, $name, $email, $domain] = self::identityHints($hints);
        if ($name === '' && $email === '') return null;

        return Db::insert('counterparties', [
            'name'            => $name !== '' ? $name : $email,
            'name_normalized' => normalizeCompanyName($name !== '' ? $name : $email),
            'inn'             => $inn ?: null,
            'email_domain'    => $domain,
            'contact_person'  => $hints['contact_person'] ?? null,
            'contact_email'   => $email ?: null,
            'contact_phone'   => $hints['phone'] ?? null,
        ]);
    }

    /**
     * The lookup half of `resolveCounterparty()`: an EXISTING card or nothing.
     *
     * Imported history goes through this door (module 021). A three-year mbox
     * carries thousands of senders, and creating a company card for each would
     * bury the real ones — old letters attach themselves to the cards that are
     * already there, and open no new ones unless the operator asked for it.
     */
    public static function findCounterparty(array $hints): ?int {
        [$inn, $name, $email, $domain] = self::identityHints($hints);
        $found = null;

        if ($inn) {
            $found = Db::one("SELECT id FROM counterparties WHERE inn = ? AND merged_into_id IS NULL", [$inn]);
        }
        if (!$found && $domain) {
            $byDomain = Db::one(
                "SELECT id, inn, name, name_normalized FROM counterparties
                 WHERE email_domain = ? AND merged_into_id IS NULL", [$domain]);
            // Домен один, а компания за ним другая — значит домен общий, и
            // объединять по нему больше нельзя ни это письмо, ни следующие.
            // Так тринадцать покупателей перестают быть одной карточкой.
            if ($byDomain && self::holdsAnotherCompany($byDomain, $inn, $name)) {
                MailDomains::markShared($domain, self::conflictReason($byDomain, $inn, $name));
                $domain = null;
            } else {
                $found = $byDomain;
            }
        }
        if (!$found && $name !== '') {
            $norm = normalizeCompanyName($name);
            if ($norm !== '') {
                $byName = Db::one(
                    "SELECT id, inn FROM counterparties WHERE name_normalized = ? AND merged_into_id IS NULL", [$norm]);
                // «Поставка» и «Поставка» с разными ИНН — две фирмы, а не одна:
                // название в России не уникально, ИНН уникален
                $innClash = $inn && !empty($byName['inn']) && (string)$byName['inn'] !== $inn;
                if ($byName && !$innClash) $found = $byName;
            }
        }

        if ($found) {
            $id = self::rootId((int)$found['id']);
            // Enrich the card with anything new we learned
            $fields = [];
            $cp = Db::one("SELECT * FROM counterparties WHERE id=?", [$id]);
            if ($inn && empty($cp['inn'])) $fields['inn'] = $inn;
            if ($domain && empty($cp['email_domain'])) $fields['email_domain'] = $domain;
            if ($email && empty($cp['contact_email'])) $fields['contact_email'] = $email;
            if ($name !== '' && empty($cp['name_normalized'])) $fields['name_normalized'] = normalizeCompanyName($cp['name']);
            if ($fields) {
                $fields['updated_at'] = date('Y-m-d H:i:s');
                Db::update('counterparties', $fields, 'id=?', [$id]);
            }
            return $id;
        }
        return null;
    }

    /** ИНН, название, адрес и корпоративный домен — в том виде, в каком по ним ищут. */
    private static function identityHints(array $hints): array {
        $inn    = self::cleanInn($hints['inn'] ?? '');
        $name   = trim((string)($hints['name'] ?? ''));
        $email  = trim((string)($hints['email'] ?? ''));
        $domain = self::corporateDomain($email);

        if ($name === '' || filter_var($name, FILTER_VALIDATE_EMAIL) !== false) {
            $fromText = self::companyFromText((string)($hints['text'] ?? ''));
            if ($fromText !== '') $name = $fromText;
        }
        return [$inn, $name, $email, $domain];
    }

    /**
     * «С уважением, начальник отдела снабжения АО "Уралэлемент"» — the company
     * name as a human writes it, found without a model call.
     *
     * Quoted forms («АО "Уралэлемент"») are read first because that is how a
     * signature is nearly always written; the unquoted form («ООО Технотрейд»,
     * «ИП Сурков К.А.») is the fallback. Our own организация is never returned —
     * every letter carries our signature under the quoted thread.
     */
    public static function companyFromText(string $text): string {
        $text = trim($text);
        if ($text === '') return '';
        $forms = self::LEGAL_FORMS;

        $candidates = [];
        // «АО "Уралэлемент"», «ООО «Ромашка-Плюс»» — quotes of any of the shapes
        // a Russian keyboard and a mail client produce between them
        if (preg_match_all('/\b(' . $forms . ')\s*[«"\x{201C}\x{201E}\x{2018}]\s*([^«»"\x{201C}\x{201D}\x{201E}\x{2018}\x{2019}]{2,80}?)\s*[»"\x{201D}\x{2019}]/u',
                           $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $candidates[] = mb_strtoupper($hit[1]) . ' «' . trim($hit[2]) . '»';
        }
        // Unquoted: a legal form followed by up to four capitalised words or initials
        $word = '(?:[А-ЯЁ][\p{L}\-]+|[А-ЯЁ]\.\s?[А-ЯЁ]?\.?)';
        if (preg_match_all('/\b(' . $forms . ')\s+(' . $word . '(?:\s+' . $word . '){0,3})/u',
                           $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $candidates[] = mb_strtoupper($hit[1]) . ' ' . trim($hit[2]);
        }

        // Our own организация signs every letter we ever quoted back
        $ours = [];
        foreach (Db::all("SELECT full_name, short_name FROM legal_entities") as $le) {
            foreach ([$le['full_name'] ?? '', $le['short_name'] ?? ''] as $n) {
                $norm = normalizeCompanyName((string)$n);
                if ($norm !== '') $ours[$norm] = true;
            }
        }
        foreach ($candidates as $candidate) {
            $norm = normalizeCompanyName($candidate);
            if ($norm === '' || isset($ours[$norm])) continue;
            return mb_substr($candidate, 0, 120);
        }
        return '';
    }

    // Follow the merge chain to the surviving card
    public static function rootId(int $id): int {
        $guard = 0;
        while ($guard++ < 10) {
            $row = Db::one("SELECT merged_into_id FROM counterparties WHERE id=?", [$id]);
            if (!$row || empty($row['merged_into_id'])) return $id;
            $id = (int)$row['merged_into_id'];
        }
        return $id;
    }

    // Record a contact person of the company (FR-035)
    /**
     * Is this one of OUR addresses? A letter the site form sends arrives from
     * `atlant@atlant-armour.ru`, and a visitor who left no email would otherwise
     * open a company card for our own domain (module 015).
     */
    public static function isOurAddress(string $email): bool {
        $email = mb_strtolower(trim($email));
        if ($email === '') return false;
        if (Db::val("SELECT 1 FROM mailboxes WHERE lower(email)=?", [$email])) return true;
        if (Db::val("SELECT 1 FROM legal_entities WHERE lower(email)=?", [$email])) return true;
        $domain = substr(strrchr($email, '@') ?: '', 1);
        foreach (Db::all("SELECT email FROM mailboxes WHERE email IS NOT NULL AND email <> ''") as $b) {
            $ours = mb_strtolower((string)$b['email']);
            if ($domain !== '' && str_ends_with($ours, '@' . $domain)) return true;
        }
        return false;
    }

    /**
     * ЭДО identifiers the client puts in his own signature (module 015).
     *
     * «Мы работаем с ЭДО. 1) Идентификатор участника ЭДО (GUID) КонтурДиадок
     * 2BM-7727473370-772701001-202111290821548642984» — 176 letters of the
     * archive are about ЭДО and 54 carry an identifier. The regex is what finds
     * it: the model may add the operator's name, it never invents the id.
     * Nothing is overwritten — a client who switches operator says so in words.
     */
    public static function rememberEdo(int $counterpartyId, string $text, $fromModel = null): void {
        $row = Db::one("SELECT edo_operator, edo_id, needs_paper_docs FROM counterparties WHERE id=?", [$counterpartyId]);
        if (!$row) return;

        $id = (string)($row['edo_id'] ?? '');
        if ($id === '' && preg_match('/\b(2[A-Z]{2}[-\s]?[A-Z0-9]{8,}(?:-[A-Z0-9]+){0,4})/u', $text, $m)) {
            $id = strtoupper(str_replace(' ', '', trim($m[1])));
        }

        $operator = (string)($row['edo_operator'] ?? '');
        if ($operator === '') {
            foreach (['Диадок' => '/контур[\s.]*диадок|диадок/iu', 'СБИС' => '/\bсбис\b/iu',
                      'Такском' => '/такском/iu', 'Астрал' => '/астрал/iu'] as $name => $re) {
                if (preg_match($re, $text)) { $operator = $name; break; }
            }
            if ($operator === '' && is_array($fromModel) && !empty($fromModel['operator'])) {
                $operator = mb_substr((string)$fromModel['operator'], 0, 40);
            }
        }

        $paper = (int)($row['needs_paper_docs'] ?? 0);
        if (!$paper && preg_match('/(?:на\s+)?бумажн\w+\s+носител|оригинал\w*\s+(?:документ|почтой)|дублир\w+\s+на\s+бумаг/iu', $text)) {
            $paper = 1;
        }
        if (!$paper && is_array($fromModel) && !empty($fromModel['paper_copy'])) $paper = 1;

        $upd = [];
        if ($id !== '' && $id !== (string)($row['edo_id'] ?? '')) $upd['edo_id'] = $id;
        if ($operator !== '' && $operator !== (string)($row['edo_operator'] ?? '')) $upd['edo_operator'] = $operator;
        if ($paper !== (int)($row['needs_paper_docs'] ?? 0)) $upd['needs_paper_docs'] = $paper;
        if (!$upd) return;

        Db::update('counterparties', $upd + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$counterpartyId]);
        Logger::info('crm', 'ЭДО контрагента #' . $counterpartyId . ' обновлён из письма: '
                     . implode(', ', array_keys($upd)), ['counterparty_id' => $counterpartyId]);
    }

    /**
     * Requisites arriving as a FILE (module 015). 206 incoming attachments of
     * the archive are «карточка предприятия» or a ЕГРЮЛ extract: the ИНН, КПП,
     * ОГРН and legal address were retyped by hand every time. Only empty fields
     * are filled — what МойСклад or a manager already put in wins.
     */
    public static function fillRequisitesFromAttachments(int $counterpartyId, array $attachments): void {
        $text = '';
        foreach ($attachments as $a) {
            $name = mb_strtolower((string)($a['filename'] ?? ''));
            $looksLikeCard = (bool)preg_match('/карточк|реквизит|егрюл|егрип|выписк|карта\s*партнёр|карта\s*партнер|информационн\w*\s*карт/iu', $name);
            if (!$looksLikeCard) continue;
            $text .= "\n" . (string)($a['extracted_text'] ?? '');
        }
        if (trim($text) === '') return;

        $found = self::requisitesFromText($text);
        if (!$found) return;

        $row = Db::one("SELECT * FROM counterparties WHERE id=?", [$counterpartyId]);
        if (!$row) return;
        $upd = [];
        foreach ($found as $field => $value) {
            if (trim((string)($row[$field] ?? '')) === '') $upd[$field] = $value;
        }
        if (!$upd) return;
        Db::update('counterparties', $upd + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$counterpartyId]);
        Logger::info('crm', 'Реквизиты контрагента #' . $counterpartyId . ' заполнены из вложения: '
                     . implode(', ', array_keys($upd)), ['counterparty_id' => $counterpartyId]);
    }

    /** ИНН/КПП/ОГРН/юр. адрес out of a company card. Digits are checked, not trusted. */
    public static function requisitesFromText(string $text): array {
        $out = [];
        if (preg_match('/\bИНН\D{0,12}(\d{10}|\d{12})\b/iu', $text, $m)) $out['inn'] = $m[1];
        if (preg_match('/\bКПП\D{0,12}(\d{9})\b/iu', $text, $m))          $out['kpp'] = $m[1];
        if (preg_match('/\bОГРНИП\D{0,12}(\d{15})\b/iu', $text, $m))      $out['ogrn'] = $m[1];
        elseif (preg_match('/\bОГРН\D{0,12}(\d{13})\b/iu', $text, $m))    $out['ogrn'] = $m[1];
        if (preg_match('/(?:Юридический|Почтовый|Юр\.?)\s*адрес\s*:?\s*(.{10,180}?)(?:\n|Тел|ИНН|КПП|ОГРН|Банк|E-?mail)/isu', $text, $m)) {
            $out['legal_address'] = trim((string)preg_replace('/\s+/u', ' ', $m[1]), " \t\n\r.,;");
        }
        if (preg_match('/(?:Полное\s+наименование|Наименование\s+организации)\s*:?\s*(.{4,160}?)(?:\n|ИНН|КПП|ОГРН)/isu', $text, $m)) {
            $out['legal_title'] = trim((string)preg_replace('/\s+/u', ' ', $m[1]), " \t\n\r.,;");
        }
        return $out;
    }

    /**
     * Чего не хватает письму, чтобы у него был контрагент в МойСклад (модуль 033).
     *
     * Входящее письмо от компании, которой в МойСклад ещё нет, упиралось в
     * тупик: счёт не выставить, заказ не создать, а завести контрагента можно
     * было только руками, перепечатав ИНН из подписи. ИНН здесь и находится —
     * на карточке или в самом письме, — и уходит в форму создания уже готовым.
     *
     * Ничего не создаёт и в сеть не ходит: это то, что показывает карточка письма.
     *
     * @param string $text письмо целиком: тело, подпись, текст вложений
     * @param array  $from from_name / from_email письма
     * @return array{counterparty_id:?int,name:string,inn:string,inn_from_letter:bool,
     *               email:string,phone:string,linked:bool,moysklad_id:string}
     */
    public static function moyskladHint(?int $counterpartyId, string $text, array $from = []): array {
        $cp = $counterpartyId
            ? Db::one("SELECT id, name, inn, contact_email, contact_phone, moysklad_id FROM counterparties WHERE id=?",
                      [self::rootId($counterpartyId)])
            : null;

        $found = self::requisitesFromText($text);
        $inn = self::cleanInn((string)($cp['inn'] ?? ''));
        $innFromLetter = false;
        if (!$inn) {
            $inn = self::cleanInn((string)($found['inn'] ?? ''));
            $innFromLetter = $inn !== null;
        }

        // Имя компании: карточка → «Полное наименование» из реквизитов →
        // подпись письма → имя отправителя. Адрес именем компании не считаем
        $name = trim((string)($cp['name'] ?? ''));
        if ($name === '' || filter_var($name, FILTER_VALIDATE_EMAIL) !== false) {
            $name = trim((string)($found['legal_title'] ?? ''))
                ?: (self::companyFromText($text) ?: trim((string)($from['name'] ?? '')));
        }

        $email = trim((string)($cp['contact_email'] ?? '')) ?: trim((string)($from['email'] ?? ''));
        return [
            'counterparty_id' => $cp ? (int)$cp['id'] : null,
            'name'            => $name,
            'inn'             => (string)($inn ?? ''),
            'inn_from_letter' => $innFromLetter,
            'email'           => $email,
            'phone'           => trim((string)($cp['contact_phone'] ?? '')),
            'moysklad_id'     => trim((string)($cp['moysklad_id'] ?? '')),
            'linked'          => trim((string)($cp['moysklad_id'] ?? '')) !== '',
        ];
    }

    /**
     * Вся переписка компании или цепочки — для поиска реквизитов (модуль 034).
     *
     * ИНН искали в ОДНОМ, последнем входящем письме. Он же чаще всего стоит в
     * первом — в подписи или в приложенной карточке предприятия, — и подсказка
     * честно писала «ИНН в письме не нашёлся», хотя он лежал двумя письмами
     * выше. Здесь просматривается вся переписка, от свежего к старому, вместе с
     * текстом вложений.
     *
     * @param ?int   $counterpartyId карточка компании, если она уже есть
     * @param string $threadKey      цепочка — когда компании ещё нет
     * @param int    $limit          сколько писем смотреть
     */
    public static function correspondenceText(?int $counterpartyId, string $threadKey = '', int $limit = 20): string {
        $rows = [];
        if ($counterpartyId) {
            $id = self::rootId($counterpartyId);
            $rows = Db::all(
                "SELECT id, body_text FROM mail_messages
                 WHERE (counterparty_id=? OR counterparty_id IN (SELECT id FROM counterparties WHERE merged_into_id=?))
                   AND direction='in'
                 ORDER BY date_at DESC, id DESC LIMIT ?", [$id, $id, $limit]);
        }
        if (!$rows && trim($threadKey) !== '') {
            $rows = Db::all(
                "SELECT id, body_text FROM mail_messages WHERE thread_key=? AND direction='in'
                 ORDER BY date_at DESC, id DESC LIMIT ?", [trim($threadKey), $limit]);
        }

        $parts = [];
        foreach ($rows as $row) $parts[] = self::letterText($row);
        return trim(implode("\n\n", array_filter($parts, fn($t) => trim($t) !== '')));
    }

    /** Письмо целиком для поиска реквизитов: тело и текст вложений. */
    public static function letterText(?array $msg): string {
        if (!$msg) return '';
        $text = (string)($msg['body_text'] ?? '');
        $id = (int)($msg['id'] ?? 0);
        if ($id) {
            foreach (Db::all("SELECT extracted_text FROM attachments WHERE mail_message_id=?", [$id]) as $a) {
                if (!empty($a['extracted_text'])) $text .= "\n" . $a['extracted_text'];
            }
        }
        return $text;
    }

    /**
     * Переписка неизвестного отправителя переезжает на карточку компании:
     * письма, их запросы и контакты. Без этого заведённая из письма компания
     * остаётся пустой карточкой, а переписка — висеть «новым адресом».
     *
     * @return int сколько писем переехало
     */
    public static function attachThread(string $threadKey, int $counterpartyId): int {
        $threadKey = trim($threadKey);
        if ($threadKey === '' || !$counterpartyId) return 0;
        $counterpartyId = self::rootId($counterpartyId);

        $moved = Db::update('mail_messages', ['counterparty_id' => $counterpartyId],
                            'thread_key=? AND counterparty_id IS NULL', [$threadKey]);
        Db::q("UPDATE requests SET counterparty_id=? WHERE counterparty_id IS NULL AND id IN
               (SELECT request_id FROM mail_messages WHERE thread_key=? AND request_id IS NOT NULL)",
              [$counterpartyId, $threadKey]);

        foreach (Db::all("SELECT DISTINCT from_email, from_name FROM mail_messages
                          WHERE thread_key=? AND direction='in'", [$threadKey]) as $m) {
            $addr = (string)($m['from_email'] ?? '');
            if ($addr !== '' && !self::isOurAddress($addr)) {
                self::upsertContact($counterpartyId, $m['from_name'] ?: null, $addr);
            }
        }
        self::recalcAnswerState($counterpartyId);
        return $moved;
    }

    public static function upsertContact(int $counterpartyId, ?string $name, ?string $email, ?string $phone = null): void {
        $email = $email ? mb_strtolower(trim($email)) : null;
        if (!$email) return;

        $existing = Db::one("SELECT id, name FROM contacts WHERE counterparty_id=? AND email=?", [$counterpartyId, $email]);
        if ($existing) {
            $fields = ['last_seen_at' => date('Y-m-d H:i:s')];
            if ($name && empty($existing['name'])) $fields['name'] = $name;
            if ($phone) $fields['phone'] = $phone;
            Db::update('contacts', $fields, 'id=?', [$existing['id']]);
            Db::q("UPDATE contacts SET messages_count = messages_count + 1 WHERE id=?", [$existing['id']]);
            return;
        }
        Db::insert('contacts', [
            'counterparty_id' => $counterpartyId,
            'name'            => $name ?: null,
            'email'           => $email,
            'phone'           => $phone ?: null,
            'messages_count'  => 1,
        ]);
    }

    /**
     * Append an event to the company feed and keep answer tracking current.
     * $direction: in | out | note. $opts: request_id, subject, email_from, email_to,
     * manager_id, event_type (system events), meta (array), at (letter's own date).
     *
     * `at` is for a letter that reaches us late: an answer written from the phone
     * and picked out of «Отправленные» on the next sync happened WHEN IT WAS
     * WRITTEN, not when the sync ran, and an old letter must never push the
     * answer clock forward — hence the timestamps only ever move ahead.
     */
    public static function logEvent(?int $counterpartyId, string $direction, string $body, array $opts = []): int {
        $at = trim((string)($opts['at'] ?? ''));
        $id = Db::insert('correspondence', [
            'request_id'      => $opts['request_id'] ?? null,
            'counterparty_id' => $counterpartyId,
            'direction'       => $direction,
            'subject'         => $opts['subject'] ?? null,
            'body'            => $body,
            'email_from'      => $opts['email_from'] ?? null,
            'email_to'        => $opts['email_to'] ?? null,
            'manager_id'      => $opts['manager_id'] ?? null,
            'event_type'      => $opts['event_type'] ?? null,
            'meta_json'       => isset($opts['meta']) ? json_encode($opts['meta'], JSON_UNESCAPED_UNICODE) : null,
        ] + ($at !== '' ? ['created_at' => $at] : []));

        if ($counterpartyId) {
            // Notes are not answers (FR-038); system events are not either
            $when = $at !== '' ? $at : date('Y-m-d H:i:s');
            $column = match ($direction) {
                'in'  => 'last_inbound_at',
                'out' => 'last_outbound_at',
                default => null,
            };
            if ($column) {
                $known = (string)Db::val("SELECT $column FROM counterparties WHERE id=?", [$counterpartyId]);
                if ($known === '' || strtotime($when) > strtotime($known)) {
                    Db::update('counterparties', [$column => $when], 'id=?', [$counterpartyId]);
                }
            }
        }
        return $id;
    }

    /**
     * Лента компании: заметки и события (FR-033, сузилось в модуле 020).
     *
     * Раньше сюда падало всё подряд, письма включительно, — и колонка «Заметки
     * и события» становилась вторым, худшим почтовым клиентом: тело письма без
     * цепочки, без вложений и без поля ответа, рядом с заметкой для коллег.
     * Письмо живёт в «Переписке», где на него можно ответить; здесь остаётся
     * то, ради чего лента и заводилась, — заметки и вехи сделки.
     *
     * `$withLetters` возвращает старое поведение для тех, кому нужна вся лента
     * целиком (выгрузка, история): само событие никуда не делось, оно просто
     * не показывается на карточке.
     */
    public static function chat(int $counterpartyId, int $limit = 50, int $offset = 0, bool $withLetters = false): array {
        // Письмо — это строка с адресом: входящее из синхронизации почты или
        // исходящее из «Отправить». Заметка и веха адреса не носят.
        $letter = "(c.direction IN ('in','out') AND c.event_type IS NULL)";
        $where = $withLetters ? '1=1' : "NOT $letter";

        $rows = Db::all(
            "SELECT c.id, c.direction, c.event_type, c.subject, c.body, c.email_from, c.email_to,
                    c.request_id, c.created_at, c.meta_json, m.name as manager_name
             FROM correspondence c
             LEFT JOIN managers m ON c.manager_id = m.id
             WHERE c.counterparty_id = ? AND $where
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT ? OFFSET ?",
            [$counterpartyId, $limit, $offset]
        );

        $ids = array_column($rows, 'id');
        $attachments = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT id, correspondence_id, filename, size, extract_status FROM attachments WHERE correspondence_id IN ($in)", $ids) as $a) {
                $attachments[$a['correspondence_id']][] = $a;
            }
        }

        foreach ($rows as &$r) {
            $r['attachments'] = $attachments[$r['id']] ?? [];
            $r['meta'] = $r['meta_json'] ? json_decode($r['meta_json'], true) : null;
            unset($r['meta_json']);
            // Чем строка является для экрана: заметка коллеге, веха сделки
            // («КП отправлено», «счёт выставлен») или письмо
            $r['kind'] = $r['event_type']
                ? 'event'
                : ($r['direction'] === 'note' ? 'note' : 'letter');
        }
        unset($r);

        return array_reverse($rows); // oldest first, chat style
    }

    /**
     * Организации карточки (модуль 029): сама компания плюс дописанные руками.
     *
     * Карточка всегда первая и удалению не подлежит — это она и есть. Счёт
     * выставляется на ту, что выбрана; ничего не выбрано — на карточку.
     */
    public static function orgs(int $counterpartyId): array {
        $cp = Db::one("SELECT id, name, inn, moysklad_id FROM counterparties WHERE id=?", [$counterpartyId]);
        if (!$cp) return [];
        $out = [[
            'id'          => 0,
            'name'        => (string)$cp['name'],
            'inn'         => (string)($cp['inn'] ?? ''),
            'kpp'         => '',
            'moysklad_id' => (string)($cp['moysklad_id'] ?? ''),
            'edo_id'      => '',
            'note'        => '',
            'primary'     => true,
        ]];
        foreach (Db::all("SELECT * FROM counterparty_orgs WHERE counterparty_id=? ORDER BY id",
                         [$counterpartyId]) as $row) {
            $out[] = [
                'id'          => (int)$row['id'],
                'name'        => (string)$row['name'],
                'inn'         => (string)($row['inn'] ?? ''),
                'kpp'         => (string)($row['kpp'] ?? ''),
                'moysklad_id' => (string)($row['moysklad_id'] ?? ''),
                'edo_id'      => (string)($row['edo_id'] ?? ''),
                'note'        => (string)($row['note'] ?? ''),
                'primary'     => false,
            ];
        }
        return $out;
    }

    public static function addOrg(int $counterpartyId, array $data): int {
        return Db::insert('counterparty_orgs', [
            'counterparty_id' => $counterpartyId,
            'name'            => (string)$data['name'],
            'inn'             => ($data['inn'] ?? '') !== '' ? (string)$data['inn'] : null,
            'kpp'             => ($data['kpp'] ?? '') !== '' ? (string)$data['kpp'] : null,
            'moysklad_id'     => ($data['moysklad_id'] ?? '') !== '' ? (string)$data['moysklad_id'] : null,
            'edo_id'          => ($data['edo_id'] ?? '') !== '' ? (string)$data['edo_id'] : null,
            'note'            => ($data['note'] ?? '') !== '' ? (string)$data['note'] : null,
        ]);
    }

    /** Организация по её id внутри карточки; 0 — сама карточка. */
    public static function org(int $counterpartyId, int $orgId): ?array {
        foreach (self::orgs($counterpartyId) as $o) {
            if ((int)$o['id'] === $orgId) return $o;
        }
        return null;
    }

    /**
     * Заказы и счета компании — строками для той же ленты (модуль 029).
     *
     * Раньше они стояли двумя отдельными карточками в правой колонке, а ссылки
     * на МойСклад — в третьем месте, под КП. Одна и та же сделка читалась из
     * трёх углов экрана. Теперь всё, что случилось с компанией, — один список
     * сверху вниз, и у каждой строки есть ссылка туда, где документ живёт.
     *
     * `request_id` у строки — чтобы экран мог приглушить то, что к открытому
     * запросу отношения не имеет: у компании их за год десятки.
     */
    public static function documents(int $counterpartyId): array {
        require_once __DIR__ . '/reserves.php';
        $out = [];
        foreach (Db::all(
            "SELECT id, moysklad_id, name, sum, state_name, moment, created_at, request_id, proposal_id,
                    COALESCE(applicable, 1) AS applicable,
                    reserve_until, reserve_reminded_at, reserve_released_at
             FROM orders WHERE counterparty_id=? ORDER BY id DESC LIMIT 50", [$counterpartyId]) as $o) {
            $out[] = [
                'kind'        => 'doc',
                'doc'         => 'order',
                'id'          => (int)$o['id'],
                'title'       => 'Заказ ' . (string)$o['name'],
                'sum'         => (float)$o['sum'],
                'state_name'  => $o['state_name'],
                'url'         => 'https://online.moysklad.ru/app/#customerorder/edit?id=' . (string)$o['moysklad_id'],
                'request_id'  => $o['request_id'] !== null ? (int)$o['request_id'] : null,
                'proposal_id' => $o['proposal_id'] !== null ? (int)$o['proposal_id'] : null,
                // Резерв под неоплаченный счёт (модуль 026) переехал сюда вместе
                // с заказом: кнопка «Снять резерв» стоит там же, где заказ
                'reserve'     => Reserves::state($o),
                'created_at'  => (string)($o['moment'] ?: $o['created_at']),
            ];
        }
        foreach (Db::all(
            "SELECT i.id, i.moysklad_id, i.name, i.sum, i.payed_sum, i.state_name, i.moment,
                    i.created_at, i.sent_at, i.proposal_id, o.request_id
             FROM invoices i LEFT JOIN orders o ON o.id = i.order_id
             WHERE i.counterparty_id=? ORDER BY i.id DESC LIMIT 50", [$counterpartyId]) as $i) {
            $out[] = [
                'kind'        => 'doc',
                'doc'         => 'invoice',
                'id'          => (int)$i['id'],
                'title'       => 'Счёт ' . (string)$i['name'],
                'sum'         => (float)$i['sum'],
                'payed_sum'   => (float)$i['payed_sum'],
                'state_name'  => $i['state_name'],
                'sent_at'     => $i['sent_at'],
                'url'         => 'https://online.moysklad.ru/app/#invoiceout/edit?id=' . (string)$i['moysklad_id'],
                'pdf_url'     => '/api/invoices.php?action=pdf&id=' . (int)$i['id'],
                'request_id'  => $i['request_id'] !== null ? (int)$i['request_id'] : null,
                'proposal_id' => $i['proposal_id'] !== null ? (int)$i['proposal_id'] : null,
                'created_at'  => (string)($i['moment'] ?: $i['created_at']),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
        return $out;
    }

    public static function chatCount(int $counterpartyId, bool $withLetters = false): int {
        $letter = "(direction IN ('in','out') AND event_type IS NULL)";
        $where = $withLetters ? '1=1' : "NOT $letter";
        return (int)Db::val("SELECT COUNT(*) FROM correspondence WHERE counterparty_id=? AND $where", [$counterpartyId]);
    }

    public static function contacts(int $counterpartyId): array {
        return Db::all(
            "SELECT id, name, email, phone, messages_count, first_seen_at, last_seen_at
             FROM contacts WHERE counterparty_id=? ORDER BY last_seen_at DESC",
            [$counterpartyId]
        );
    }

    // Email to prefill when sending an invoice: latest contact of the company
    public static function primaryEmail(int $counterpartyId): ?string {
        $c = Db::one("SELECT email FROM contacts WHERE counterparty_id=? ORDER BY last_seen_at DESC LIMIT 1", [$counterpartyId]);
        if ($c && $c['email']) return $c['email'];
        $cp = Db::one("SELECT contact_email FROM counterparties WHERE id=?", [$counterpartyId]);
        return $cp['contact_email'] ?? null;
    }

    /**
     * Unanswered state (FR-038): last event is inbound with no outbound after it.
     * Returns ['unanswered' => bool, 'hours' => int, 'level' => 'none|warn|critical'].
     */
    public static function answerState(?string $lastInbound, ?string $lastOutbound): array {
        if (!$lastInbound) return ['unanswered' => false, 'hours' => 0, 'level' => 'none'];
        if ($lastOutbound && strtotime($lastOutbound) >= strtotime($lastInbound)) {
            return ['unanswered' => false, 'hours' => 0, 'level' => 'none'];
        }
        $hours = (int)floor((time() - strtotime($lastInbound)) / 3600);
        static $critical = null;
        if ($critical === null) {
            $critical = (int)(Db::val("SELECT value FROM settings WHERE key='unanswered_critical_h'") ?: 24);
        }
        return [
            'unanswered' => true,
            'hours'      => $hours,
            'level'      => $hours >= $critical ? 'critical' : 'warn',
        ];
    }

    // Merge two company cards (FR-037). Everything moves to $targetId.
    public static function merge(int $sourceId, int $targetId): void {
        if ($sourceId === $targetId) throw new RuntimeException('Нельзя объединить карточку с самой собой');
        $source = Db::one("SELECT * FROM counterparties WHERE id=?", [$sourceId]);
        $target = Db::one("SELECT * FROM counterparties WHERE id=?", [$targetId]);
        if (!$source || !$target) throw new RuntimeException('Карточка не найдена');

        Db::begin();
        try {
            foreach (['requests', 'proposals', 'correspondence', 'attachments', 'orders', 'invoices', 'followups'] as $t) {
                Db::q("UPDATE $t SET counterparty_id=? WHERE counterparty_id=?", [$targetId, $sourceId]);
            }
            // Contacts: skip duplicates by email, then move the rest
            Db::q("DELETE FROM contacts WHERE counterparty_id=? AND email IN (SELECT email FROM contacts WHERE counterparty_id=?)", [$sourceId, $targetId]);
            Db::q("UPDATE contacts SET counterparty_id=? WHERE counterparty_id=?", [$targetId, $sourceId]);

            $fields = ['merged_into_id' => $targetId, 'updated_at' => date('Y-m-d H:i:s')];
            Db::update('counterparties', $fields, 'id=?', [$sourceId]);

            // Target inherits missing identity fields
            $inherit = [];
            foreach (['inn', 'email_domain', 'contact_email', 'contact_phone', 'contact_person', 'moysklad_id'] as $f) {
                if (empty($target[$f]) && !empty($source[$f])) $inherit[$f] = $source[$f];
            }
            if ($inherit) Db::update('counterparties', $inherit, 'id=?', [$targetId]);

            self::recalcAnswerState($targetId);
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    // Split one contact out into its own company card (FR-037)
    public static function splitContact(int $counterpartyId, string $email): int {
        $email = mb_strtolower(trim($email));
        $contact = Db::one("SELECT * FROM contacts WHERE counterparty_id=? AND email=?", [$counterpartyId, $email]);
        if (!$contact) throw new RuntimeException('Контакт не найден в этой карточке');
        $source = Db::one("SELECT * FROM counterparties WHERE id=?", [$counterpartyId]);

        Db::begin();
        try {
            $newId = Db::insert('counterparties', [
                'name'            => $contact['name'] ?: $email,
                'name_normalized' => normalizeCompanyName($contact['name'] ?: $email),
                'contact_person'  => $contact['name'],
                'contact_email'   => $email,
                'contact_phone'   => $contact['phone'],
                'notes'           => 'Отделено от карточки «' . $source['name'] . '»',
            ]);

            Db::q("UPDATE contacts SET counterparty_id=? WHERE id=?", [$newId, $contact['id']]);
            Db::q("UPDATE correspondence SET counterparty_id=? WHERE counterparty_id=? AND lower(email_from)=?", [$newId, $counterpartyId, $email]);
            Db::q("UPDATE requests SET counterparty_id=? WHERE counterparty_id=? AND lower(email_from)=?", [$newId, $counterpartyId, $email]);

            self::recalcAnswerState($counterpartyId);
            self::recalcAnswerState($newId);
            Db::commit();
            return $newId;
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * Стоит ли за этим доменом другая компания, а не та, что уже в карточке.
     *
     * Разные ИНН — довод окончательный. Названия сравниваются осторожно:
     * «Байтек» и «Байтек Интернэшнл» — одна фирма, названная короче, а вот
     * «ИП Попков» и «Байтек Интернэшнл» — две. Адрес вместо названия не
     * доказывает ничего.
     */
    private static function holdsAnotherCompany(array $card, ?string $inn, string $name): bool {
        if ($inn && !empty($card['inn']) && (string)$card['inn'] !== $inn) return true;

        // Сравниваются только названия фирм. «Пётр Иванов» из поля From — имя
        // человека, и то, что оно не похоже на «ООО Технотрейд», не значит
        // ровно ничего: коллега с того же завода должен остаться в карточке.
        $theirRaw = trim((string)($card['name'] ?? ''));
        if (!self::looksLikeCompany($name) || !self::looksLikeCompany($theirRaw)) return false;

        $mine  = normalizeCompanyName($name);
        $their = (string)($card['name_normalized'] ?? '') ?: normalizeCompanyName($theirRaw);
        if (mb_strlen($mine) < 3 || mb_strlen($their) < 3 || $mine === $their) return false;

        return !str_contains($mine, $their) && !str_contains($their, $mine);
    }

    /** Название с правовой формой — это фирма, а не имя человека из поля From. */
    private static function looksLikeCompany(string $name): bool {
        $name = trim($name);
        if ($name === '' || filter_var($name, FILTER_VALIDATE_EMAIL) !== false) return false;
        return (bool)preg_match('/\b(' . self::LEGAL_FORMS . ')\b/u', $name);
    }

    /** Строчка для журнала: чем именно домен себя выдал. */
    private static function conflictReason(array $card, ?string $inn, string $name): string {
        if ($inn && !empty($card['inn']) && (string)$card['inn'] !== $inn) {
            return 'ИНН ' . $inn . ' и ' . $card['inn'] . ' на одном домене';
        }
        return 'разные компании: «' . trim((string)($card['name'] ?? '')) . '» и «' . $name . '»';
    }

    /**
     * Разложить карточку по отправителям: каждому адресу — своя компания.
     *
     * Так чинится то, что уже слиплось: домен-ретранслятор собрал в одну
     * карточку тринадцать покупателей, и разбирать это руками по письму
     * никто не станет. Свой адрес карточка оставляет себе, остальные уходят
     * в новые — вместе с письмами, запросами, КП и счетами.
     *
     * @return int[] id заведённых карточек
     */
    public static function splitBySender(int $counterpartyId, bool $rekeyThreads = true): array {
        $id = self::rootId($counterpartyId);
        $card = Db::one("SELECT * FROM counterparties WHERE id=?", [$id]);
        if (!$card) throw new RuntimeException('Карточка не найдена');

        // Домен, на котором это случилось, больше не признак компании —
        // иначе следующее же письмо соберёт карточку заново
        if (!empty($card['email_domain'])) {
            MailDomains::markShared((string)$card['email_domain'], 'карточка разделена по отправителям');
        }

        $senders = self::sendersOf($id);
        if (count($senders) < 2) return [];

        // Карточка остаётся за своим адресом: контактным, иначе самым частым
        $keep = mb_strtolower(trim((string)($card['contact_email'] ?? '')));
        if ($keep === '' || !isset($senders[$keep])) $keep = (string)array_key_first($senders);

        $created = [];
        Db::begin();
        try {
            foreach (array_keys($senders) as $addr) {
                if ($addr === $keep) continue;
                $created[] = self::moveSenderOut($id, (string)$addr, $card);
            }
            Db::update('counterparties', [
                'email_domain'  => null,
                'contact_email' => $keep,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'id=?', [$id]);

            foreach (array_merge([$id], $created) as $cpId) self::recalcAnswerState($cpId);
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        // Переписки пересобираются под новых владельцев: «Запрос КП» от двух
        // разных фирм перестаёт быть одной цепочкой
        if ($rekeyThreads && class_exists('MailThreads')) {
            MailThreads::rekeyCounterparties(array_merge([$id], $created));
        }
        return $created;
    }

    /** Адреса, с которых в этой карточке писали, — частые первыми. @return array<string,int> */
    public static function sendersOf(int $counterpartyId): array {
        $counts = [];
        $bump = function (string $raw) use (&$counts) {
            $addr = MailDomains::firstAddress($raw);
            if ($addr !== '' && !self::isOurAddress($addr)) $counts[$addr] = ($counts[$addr] ?? 0) + 1;
        };

        if (Db::hasTable('mail_messages')) {
            foreach (Db::all("SELECT direction, from_email, to_emails FROM mail_messages WHERE counterparty_id=?",
                             [$counterpartyId]) as $m) {
                $bump((string)(($m['direction'] ?? '') === 'out' ? $m['to_emails'] : $m['from_email']));
            }
        }
        foreach (Db::all("SELECT direction, email_from, email_to FROM correspondence WHERE counterparty_id=?",
                         [$counterpartyId]) as $c) {
            $bump((string)(($c['direction'] ?? '') === 'out' ? $c['email_to'] : $c['email_from']));
        }
        foreach (Db::all("SELECT email_from FROM requests WHERE counterparty_id=?", [$counterpartyId]) as $r) {
            $bump((string)$r['email_from']);
        }
        if (Db::hasTable('contacts')) {
            foreach (Db::all("SELECT email FROM contacts WHERE counterparty_id=?", [$counterpartyId]) as $c) {
                $bump((string)$c['email']);
            }
        }
        arsort($counts);
        return $counts;
    }

    /** Увести один адрес со всем его хозяйством в свою карточку. */
    private static function moveSenderOut(int $fromId, string $addr, array $card): int {
        // Адрес идёт в LIKE, а `_` в нём — обычная буква, не подстановка:
        // без экранирования `adm_postavka@` утащил бы и `admXpostavka@`
        $like = '%' . addcslashes($addr, '%_\\') . '%';
        $newId = self::cardForSender($fromId, $addr, $card);

        if (Db::hasTable('contacts')) {
            // Уникальный индекс не даст двум одинаковым контактам сойтись в
            // одной карточке — свой уже есть, этот лишний
            Db::q("DELETE FROM contacts WHERE counterparty_id=? AND lower(email)=?
                     AND EXISTS (SELECT 1 FROM contacts t WHERE t.counterparty_id=? AND lower(t.email)=?)",
                  [$fromId, $addr, $newId, $addr]);
            Db::q("UPDATE contacts SET counterparty_id=? WHERE counterparty_id=? AND lower(email)=?",
                  [$newId, $fromId, $addr]);
        }
        Db::q("UPDATE requests SET counterparty_id=? WHERE counterparty_id=?
                 AND lower(email_from) LIKE ? ESCAPE '\\'", [$newId, $fromId, $like]);
        Db::q("UPDATE correspondence SET counterparty_id=? WHERE counterparty_id=?
                 AND ((direction='out' AND lower(email_to) LIKE ? ESCAPE '\\')
                   OR (direction<>'out' AND lower(email_from) LIKE ? ESCAPE '\\'))",
              [$newId, $fromId, $like, $like]);
        if (Db::hasTable('mail_messages')) {
            Db::q("UPDATE mail_messages SET counterparty_id=? WHERE counterparty_id=?
                     AND ((direction='out' AND lower(to_emails) LIKE ? ESCAPE '\\')
                       OR (direction<>'out' AND lower(from_email) LIKE ? ESCAPE '\\'))",
                  [$newId, $fromId, $like, $like]);
        }

        // КП, вложения, заказы, счета и напоминания идут за своим запросом
        Db::q("UPDATE proposals SET counterparty_id=? WHERE counterparty_id=?
                 AND request_id IN (SELECT id FROM requests WHERE counterparty_id=?)", [$newId, $fromId, $newId]);
        if (Db::hasTable('attachments')) {
            Db::q("UPDATE attachments SET counterparty_id=? WHERE counterparty_id=?
                     AND (request_id IN (SELECT id FROM requests WHERE counterparty_id=?)
                          OR correspondence_id IN (SELECT id FROM correspondence WHERE counterparty_id=?))",
                  [$newId, $fromId, $newId, $newId]);
        }
        if (Db::hasTable('orders')) {
            Db::q("UPDATE orders SET counterparty_id=? WHERE counterparty_id=?
                     AND request_id IN (SELECT id FROM requests WHERE counterparty_id=?)", [$newId, $fromId, $newId]);
        }
        if (Db::hasTable('invoices')) {
            Db::q("UPDATE invoices SET counterparty_id=? WHERE counterparty_id=?
                     AND order_id IN (SELECT id FROM orders WHERE counterparty_id=?)", [$newId, $fromId, $newId]);
        }
        Db::q("UPDATE followups SET counterparty_id=? WHERE counterparty_id=?
                 AND proposal_id IN (SELECT id FROM proposals WHERE counterparty_id=?)", [$newId, $fromId, $newId]);

        return $newId;
    }

    /**
     * Куда переселять адрес: в свою уже заведённую карточку, если такая есть,
     * иначе в новую. Иначе разделение плодило бы вторую карточку того же ИП.
     */
    private static function cardForSender(int $fromId, string $addr, array $card): int {
        $existing = Db::one("SELECT id FROM counterparties
                             WHERE merged_into_id IS NULL AND id <> ? AND lower(contact_email) = ?",
                            [$fromId, $addr]);
        if (!$existing && Db::hasTable('contacts')) {
            $existing = Db::one("SELECT c.counterparty_id AS id FROM contacts c
                                 JOIN counterparties p ON p.id = c.counterparty_id AND p.merged_into_id IS NULL
                                 WHERE c.counterparty_id <> ? AND lower(c.email) = ? LIMIT 1", [$fromId, $addr]);
        }
        if ($existing) return self::rootId((int)$existing['id']);

        $name = self::nameForSender($fromId, $addr);
        return Db::insert('counterparties', [
            'name'            => $name,
            'name_normalized' => normalizeCompanyName($name),
            'contact_person'  => Db::val("SELECT name FROM contacts WHERE counterparty_id=? AND lower(email)=?",
                                         [$fromId, $addr]),
            'contact_email'   => $addr,
            'notes'           => 'Отделено от карточки «' . $card['name'] . '»: домен общий, компании разные',
        ]);
    }

    /** Как назвать новую карточку: контакт, подпись под письмом, иначе адрес. */
    private static function nameForSender(int $cpId, string $addr): string {
        $contact = Db::val("SELECT name FROM contacts WHERE counterparty_id=? AND lower(email)=? AND name<>''",
                           [$cpId, $addr]);
        if ($contact) return (string)$contact;

        $rows = Db::hasTable('mail_messages')
            ? Db::all("SELECT from_name, body_text FROM mail_messages
                       WHERE counterparty_id=? AND direction<>'out' AND lower(from_email) LIKE ? ESCAPE '\\'
                       ORDER BY date_at DESC LIMIT 5", [$cpId, '%' . addcslashes($addr, '%_\\') . '%'])
            : [];
        foreach ($rows as $r) {
            $fromText = self::companyFromText((string)($r['body_text'] ?? ''));
            if ($fromText !== '') return $fromText;
        }
        foreach ($rows as $r) {
            $n = trim((string)($r['from_name'] ?? ''));
            if ($n !== '' && filter_var($n, FILTER_VALIDATE_EMAIL) === false) return $n;
        }
        return $addr;
    }

    // Recompute last inbound/outbound timestamps from the feed
    public static function recalcAnswerState(int $counterpartyId): void {
        $in  = Db::val("SELECT MAX(created_at) FROM correspondence WHERE counterparty_id=? AND direction='in'", [$counterpartyId]);
        $out = Db::val("SELECT MAX(created_at) FROM correspondence WHERE counterparty_id=? AND direction='out'", [$counterpartyId]);
        Db::update('counterparties', [
            'last_inbound_at'  => $in ?: null,
            'last_outbound_at' => $out ?: null,
        ], 'id=?', [$counterpartyId]);
    }

    // Домен, который и есть компания, — или null, если домен общий (C-012)
    public static function corporateDomain(string $email): ?string {
        if (!$email || !str_contains($email, '@')) return null;
        $domain = MailDomains::normalize($email);
        return ($domain === '' || MailDomains::isShared($domain)) ? null : $domain;
    }

    // INN is 10 (org) or 12 (individual) digits
    public static function cleanInn(string $inn): ?string {
        $digits = preg_replace('/\D+/', '', $inn);
        return preg_match('/^\d{10}$|^\d{12}$/', (string)$digits) ? $digits : null;
    }

    /**
     * Реквизиты, которых поиск по образцу не увидел, — нейросетью (модуль 034).
     *
     * ИНН приходит по-разному: словом «ИНН», строкой карточки предприятия,
     * шапкой скана счёта, просто числом после названия. Регулярное выражение
     * ловит первые два случая; остальные до сих пор менеджер перепечатывал
     * руками. Модель читает ту же переписку, что и `requisitesFromText()`, и
     * зовётся только тогда, когда обычный поиск уже ничего не дал.
     *
     * Ничего не сохраняет и ничего не решает: возвращает найденное, а записать
     * его на карточку — дело вызвавшего. Выдуманные цифры отсеиваются проверкой
     * длины, наш собственный ИНН — отдельно: он стоит в цитате нашего же ответа.
     *
     * @return array{inn:?string,kpp:?string,ogrn:?string,legal_title:?string,
     *               legal_address:?string,source:string}
     */
    public static function requisitesByLlm(string $text): array {
        $empty = ['inn' => null, 'kpp' => null, 'ogrn' => null,
                  'legal_title' => null, 'legal_address' => null, 'source' => ''];
        $text = trim($text);
        if ($text === '') return $empty;

        require_once __DIR__ . '/prompts.php';
        require_once __DIR__ . '/llm.php';

        // Столько текста хватает на подпись, карточку предприятия и шапку скана
        $letter = mb_substr($text, 0, 20000);
        try {
            $data = LLM::chatJson(Prompts::render('find_requisites', ['letter' => $letter]), $letter, 0.1);
        } catch (Throwable $e) {
            Logger::warning('crm', 'Нейросеть не нашла реквизиты: ' . $e->getMessage());
            return $empty;
        }

        $inn = self::cleanInn((string)($data['inn'] ?? ''));
        // Наш собственный ИНН приезжает из цитаты нашего же письма
        $ours = Db::val("SELECT inn FROM legal_entities WHERE is_active=1 LIMIT 1");
        if ($inn !== null && $ours && self::cleanInn((string)$ours) === $inn) $inn = null;
        // Модель обязана была списать ИНН из текста — проверяем, что он там есть
        if ($inn !== null && !str_contains(preg_replace('/\D+/', '', $text) ?: '', $inn)) {
            Logger::warning('crm', 'ИНН от нейросети не нашёлся в самом письме — отброшен', ['inn' => $inn]);
            $inn = null;
        }

        $str = function ($v): ?string {
            $v = trim((string)$v);
            return ($v === '' || strtolower($v) === 'null') ? null : $v;
        };
        $kpp = preg_replace('/\D+/', '', (string)($data['kpp'] ?? ''));
        $ogrn = preg_replace('/\D+/', '', (string)($data['ogrn'] ?? ''));

        return [
            'inn'           => $inn,
            'kpp'           => preg_match('/^\d{9}$/', (string)$kpp) ? $kpp : null,
            'ogrn'          => preg_match('/^\d{13}$|^\d{15}$/', (string)$ogrn) ? $ogrn : null,
            'legal_title'   => $str($data['legal_title'] ?? ''),
            'legal_address' => $str($data['legal_address'] ?? ''),
            'source'        => (string)$str($data['source'] ?? ''),
        ];
    }
}
