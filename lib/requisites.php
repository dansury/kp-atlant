<?php
/**
 * Our own legal facts, from МойСклад into the КП, without a manager retyping
 * them and without a model touching them (module 013).
 *
 * НДС, реквизиты, адреса, банк and the договор are the part of a commercial
 * proposal that has to be RIGHT, not plausible. They come from one place —
 * the организация and the договор in МойСклад — are cached in `legal_entities`
 * and `counterparties`, and are FROZEN onto the proposal at the moment it is
 * generated (`proposals.requisites_json`). A КП reprinted six months later
 * therefore still carries the requisites it was signed with, not today's.
 *
 * Two rules the whole module rests on:
 *   - Nothing here is ever generated. Every field is a value МойСклад gave us,
 *     a value an admin typed in «Настройки», or absent.
 *   - Nothing here blocks a КП. A dead token leaves the last synced copy in
 *     place, and a field МойСклад does not fill simply does not print.
 */
require_once __DIR__ . '/moysklad.php';

final class Requisites {

    /** Where the VAT rate of a position comes from, in order. */
    public const VAT_SOURCES = ['позиция каталога', 'настройка НДС по умолчанию'];

    /**
     * Как КП печатает цену и налог (модуль 030):
     *   included — цена в каталоге уже с НДС, документ выделяет его из итога;
     *   added    — цена в каталоге без НДС, документ прибавляет его к итогу.
     */
    public const VAT_MODES = ['included', 'added'];

    /**
     * Осталось ради старых КП: снимок, замороженный до модуля 023, всё ещё
     * держит эту строку в `requisites_json`, и печатать её как имя покупателя
     * нельзя — `forProposal()` вычищает её на чтении.
     */
    public const BUYER_UNKNOWN = 'Покупатель уточняется';

    /**
     * Pull the организация from МойСклад into `legal_entities`.
     * The signature, the logo and the stamp are ours and stay untouched —
     * МойСклад knows nothing about them.
     */
    public static function syncOrganization(): array {
        $wanted = trim((string)Settings::get('MOYSKLAD_ORG_ID', ''));
        $org = MoySklad::getOrganizationFull($wanted !== '' ? $wanted : null);
        if (!$org) throw new MoySkladException('Организация в МойСклад не найдена — проверьте токен и ID организации');

        $bank = $org['account'] ?? [];
        $data = [
            'moysklad_id'    => $org['id'],
            'entity_type'    => $org['company_type'] === 'individual' ? 'ИП' : 'ООО',
            'full_name'      => $org['legal_title'] ?: $org['name'],
            'short_name'     => $org['name'],
            'inn'            => $org['inn'],
            'kpp'            => $org['kpp'],
            'ogrn'           => $org['ogrn'],
            'ogrnip'         => $org['ogrnip'],
            'okpo'           => $org['okpo'],
            'legal_address'  => $org['legal_address'],
            'address'        => $org['actual_address'] ?: $org['legal_address'],
            'phone'          => $org['phone'],
            'email'          => $org['email'],
            'pays_vat'       => $org['pays_vat'] ? 1 : 0,
            'bank_name'      => $bank['bank_name'] ?? '',
            'bank_bic'       => $bank['bic'] ?? '',
            'bank_account'   => $bank['account'] ?? '',
            'bank_corr'      => $bank['corr_account'] ?? '',
            'bank_details'   => self::bankLine($bank),
            'synced_at'      => date('Y-m-d H:i:s'),
        ];
        if ($org['signatory'] !== '') $data['signatory_name'] = $org['signatory'];

        $existing = Db::one("SELECT id FROM legal_entities WHERE moysklad_id=? OR inn=? ORDER BY is_active DESC LIMIT 1",
                            [$org['id'], $org['inn']])
                 ?: Db::one("SELECT id FROM legal_entities WHERE is_active=1 LIMIT 1");

        if ($existing) {
            Db::update('legal_entities', $data, 'id=?', [$existing['id']]);
            $id = (int)$existing['id'];
        } else {
            $id = Db::insert('legal_entities', $data + ['is_active' => 1, 'city' => '']);
        }
        Logger::info('moysklad', 'Реквизиты организации обновлены из МойСклад', ['legal_entity_id' => $id]);
        return self::legalEntity($id);
    }

    /** Pull one buyer's legal facts, so the КП addresses a real legal entity. */
    public static function syncCounterparty(int $counterpartyId): array {
        $row = Db::one("SELECT id, moysklad_id, inn FROM counterparties WHERE id=?", [$counterpartyId]);
        if (!$row || empty($row['moysklad_id'])) return [];

        $cp = MoySklad::getCounterpartyFull((string)$row['moysklad_id']);
        if (!$cp) return [];

        Db::update('counterparties', [
            'legal_title'   => $cp['legal_title'],
            'legal_address' => $cp['legal_address'],
            'kpp'           => $cp['kpp'],
            'ogrn'          => $cp['ogrn'] ?: $cp['ogrnip'],
            'inn'           => $cp['inn'] ?: (string)$row['inn'],
            'updated_at'    => date('Y-m-d H:i:s'),
        ], 'id=?', [$counterpartyId]);

        self::syncContract($counterpartyId, (string)$row['moysklad_id']);
        return $cp;
    }

    /**
     * The договор this buyer works with us under. МойСклад may hold several;
     * the most recent one of type «Договор купли-продажи» wins, and the choice
     * is remembered on the counterparty so the same КП prints the same number.
     */
    public static function syncContract(int $counterpartyId, string $agentId): ?array {
        $orgId = trim((string)Settings::get('MOYSKLAD_ORG_ID', ''));
        $contracts = MoySklad::getContracts($agentId, $orgId !== '' ? $orgId : null);
        if (!$contracts) return null;

        $contract = $contracts[0];
        Db::update('counterparties', [
            'contract_moysklad_id' => $contract['id'],
            'contract_name'        => $contract['name'],
            'contract_date'        => substr((string)$contract['moment'], 0, 10),
            'updated_at'           => date('Y-m-d H:i:s'),
        ], 'id=?', [$counterpartyId]);
        return $contract;
    }

    /**
     * The frozen block a КП is printed from.
     *
     * Written once, when the proposal is generated, and read by the PDF from
     * then on. A field that changes in МойСклад afterwards does not silently
     * rewrite a document that has already gone out.
     */
    public static function snapshot(int $proposalId): array {
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        if (!$proposal) return [];

        $legal = Db::one("SELECT * FROM legal_entities WHERE is_active=1 LIMIT 1") ?: [];
        $buyer = $proposal['counterparty_id']
            ? Db::one("SELECT * FROM counterparties WHERE id=?", [$proposal['counterparty_id']])
            : null;

        $vat = self::vatFor($proposalId, $legal, (int)($proposal['vat_rate'] ?? 0));

        // A company card opened from a letter whose signature the model did not
        // read holds the sender's e-mail where the name belongs (`Crm::
        // resolveCounterparty` falls back to it). That address must never be
        // printed as the buyer of an official document: the КП says the name is
        // still being established, and the editor shows the manager why.
        $buyerName = trim((string)($buyer['name'] ?? ''));
        $buyerNameIsEmail = $buyerName !== '' && filter_var($buyerName, FILTER_VALIDATE_EMAIL) !== false;

        return [
            'captured_at' => date('Y-m-d H:i:s'),
            'seller' => [
                'full_name'     => (string)($legal['full_name'] ?? ''),
                'short_name'    => (string)($legal['short_name'] ?? ''),
                'inn'           => (string)($legal['inn'] ?? ''),
                'kpp'           => (string)($legal['kpp'] ?? ''),
                'ogrn'          => (string)($legal['ogrn'] ?? ''),
                'ogrnip'        => (string)($legal['ogrnip'] ?? ''),
                'okpo'          => (string)($legal['okpo'] ?? ''),
                'legal_address' => (string)($legal['legal_address'] ?? ''),
                'address'       => trim((string)($legal['city'] ?? '') . ' ' . (string)($legal['address'] ?? '')),
                'phone'         => (string)($legal['phone'] ?? ''),
                'email'         => (string)($legal['email'] ?? ''),
                'signatory'     => (string)($legal['signatory_name'] ?? ''),
                'bank'          => [
                    'name'         => (string)($legal['bank_name'] ?? ''),
                    'bic'          => (string)($legal['bank_bic'] ?? ''),
                    'account'      => (string)($legal['bank_account'] ?? ''),
                    'corr_account' => (string)($legal['bank_corr'] ?? ''),
                    'line'         => (string)($legal['bank_details'] ?? ''),
                ],
                'synced_at'     => (string)($legal['synced_at'] ?? ''),
            ],
            'buyer' => $buyer ? [
                // Пусто — значит в документе про покупателя не печатается НИЧЕГО.
                // Раньше здесь стояло «Покупатель уточняется»: фраза, которая в
                // подписанном КП выглядит как небрежность, а не как честность
                // (модуль 023). Данных нет — блока нет.
                'name'          => $buyerNameIsEmail ? '' : $buyerName,
                // What the card actually holds, kept for the manager's screen —
                // hidden from the document, not lost
                'name_source'   => $buyerNameIsEmail ? $buyerName : '',
                'name_is_email' => $buyerNameIsEmail,
                'legal_title'   => (string)($buyer['legal_title'] ?? ''),
                'inn'           => (string)($buyer['inn'] ?? ''),
                'kpp'           => (string)($buyer['kpp'] ?? ''),
                'ogrn'          => (string)($buyer['ogrn'] ?? ''),
                'legal_address' => (string)($buyer['legal_address'] ?? ''),
                'contact'       => (string)($buyer['contact_person'] ?? ''),
                'email'         => (string)($buyer['contact_email'] ?? ''),
            ] : null,
            'contract' => $buyer && !empty($buyer['contract_name']) ? [
                'name' => (string)$buyer['contract_name'],
                'date' => (string)($buyer['contract_date'] ?? ''),
            ] : null,
            'vat' => $vat,
            'terms' => [
                'execution_days' => (int)($proposal['execution_days'] ?? 30),
                'validity_days'  => (int)($proposal['validity_days'] ?? 14),
            ],
        ];
    }

    /**
     * The VAT of this КП, decided and not guessed.
     *
     * The организация says whether we charge it at all (`payerVat`); when we do,
     * the rate is the one the catalog carries for the positions of this very КП,
     * and the настройка is only reached for when the catalog is silent. Two
     * different rates among the positions is not an error — the document then
     * says «в т.ч. НДС по ставке позиции» and every line prints its own.
     */
    public static function vatFor(int $proposalId, array $legal, int $proposalRate): array {
        $default = (int)(Db::val("SELECT value FROM settings WHERE key='default_vat_rate'") ?: 5);
        $paysVat = !array_key_exists('pays_vat', $legal) || (int)$legal['pays_vat'] === 1;

        if (!$paysVat) {
            return [
                'pays_vat'  => false,
                'rate'      => 0,
                'mixed'     => false,
                'statement' => (string)Settings::get('KP_VAT_EXEMPT_NOTE', 'НДС не облагается'),
                'source'    => 'организация в МойСклад не является плательщиком НДС',
            ];
        }

        $rates = array_values(array_filter(array_map(
            fn($r) => $r['vat'] === null ? null : (int)$r['vat'],
            Db::all("SELECT DISTINCT p.vat AS vat
                     FROM proposal_items i JOIN products_cache p ON p.moysklad_id = i.moysklad_product_id
                     WHERE i.proposal_id=?", [$proposalId])
        // 0 у плательщика НДС — «на товаре ставка не задана» (МойСклад
        // `vatEnabled: false`), а не «без НДС»: тогда работает настройка, и
        // колонка не пишет «без НДС» при цене «в т.ч. НДС» (issue #67)
        ), fn($r) => $r !== null && $r > 0));

        if (count($rates) === 1) {
            $rate = $rates[0];
            $source = 'ставка позиции каталога (МойСклад)';
        } elseif (count($rates) > 1) {
            $rate = max($rates);
            $source = 'в КП позиции с разными ставками — у каждой своя';
        } else {
            $rate = $proposalRate ?: $default;
            $source = 'настройка «НДС по умолчанию»';
        }

        return [
            'pays_vat'  => true,
            'rate'      => $rate,
            'mixed'     => count($rates) > 1,
            'statement' => 'в т.ч. НДС ' . $rate . '%',
            'source'    => $source,
        ];
    }

    /**
     * Способ печати цены: «в т.ч. НДС» или «цена + НДС».
     *
     * В отличие от ставки и от того, плательщики ли мы, это НЕ факт из МойСклад,
     * а оформление документа — поэтому он и не заморожен в снимке: переключили
     * настройку, и так печатаются все КП, в том числе собранные вчера. У
     * отдельного КП может стоять своё значение (`proposals.vat_mode`), пустое —
     * «как в настройках».
     */
    public static function vatMode(array $proposal = []): string {
        $own = trim((string)($proposal['vat_mode'] ?? ''));
        if (in_array($own, self::VAT_MODES, true)) return $own;
        $mode = trim((string)Settings::get('KP_VAT_MODE', 'included'));
        return in_array($mode, self::VAT_MODES, true) ? $mode : 'included';
    }

    /**
     * Налог под итогом КП — ОДНО место, где он считается и называется словами.
     *
     * Документ, письмо и Word печатают одни и те же строки: сумма налога,
     * посчитанная дважды разными формулами, — это две разные суммы в одном
     * предложении. НДС печатается ВСЕГДА: «в т.ч. НДС», «НДС сверху» или
     * «НДС не облагается» — молчания среди этих трёх ответов нет.
     *
     * @param float  $sum сумма строк таблицы, как они напечатаны
     * @param array  $vat блок `vat` из снимка реквизитов
     * @param string $mode included | added
     * @return array{mode:string,rate:int,net:float,amount:float,gross:float,total:float,column:string,note:string,lines:array}
     */
    public static function vatTotals(float $sum, array $vat, string $mode = 'included'): array {
        $paysVat = !array_key_exists('pays_vat', $vat) || (bool)$vat['pays_vat'];
        $rate    = (int)($vat['rate'] ?? 0);
        $sum     = round($sum, 2);

        // Не плательщик — ставки нет вовсе, и это печатается словами организации
        if (!$paysVat || $rate <= 0) {
            $note = $paysVat
                ? 'без НДС'
                : (string)($vat['statement'] ?? Settings::get('KP_VAT_EXEMPT_NOTE', 'НДС не облагается'));
            return [
                'mode'   => 'none',
                'rate'   => 0,
                'net'    => $sum,
                'amount' => 0.0,
                'gross'  => $sum,
                'total'  => $sum,
                'column' => $note,
                'note'   => $note,
                'lines'  => [
                    ['label' => 'Итого', 'amount' => $sum,  'total' => true],
                    ['label' => $note,   'amount' => null,  'total' => false],
                ],
            ];
        }

        if ($mode === 'added') {
            // Цена без налога, налог сверху: клиент платит больше суммы таблицы
            $amount = round($sum * $rate / 100, 2);
            $gross  = round($sum + $amount, 2);
            return [
                'mode'   => 'added',
                'rate'   => $rate,
                'net'    => $sum,
                'amount' => $amount,
                'gross'  => $gross,
                'total'  => $gross,
                'column' => 'без НДС',
                // Про НАПЕЧАТАННЫЙ итог: налог в него уже вошёл, хоть цены и без него
                'note'   => 'в т.ч. НДС ' . $rate . '%',
                'lines'  => [
                    ['label' => 'Итого без НДС',  'amount' => $sum,    'total' => false],
                    ['label' => 'НДС ' . $rate . '%', 'amount' => $amount, 'total' => false],
                    ['label' => 'Итого с НДС',    'amount' => $gross,  'total' => true],
                ],
            ];
        }

        // Цена уже с налогом: итог тот же, налог выделяется из него
        $net    = round($sum / (1 + $rate / 100), 2);
        $amount = round($sum - $net, 2);
        return [
            'mode'   => 'included',
            'rate'   => $rate,
            'net'    => $net,
            'amount' => $amount,
            'gross'  => $sum,
            'total'  => $sum,
            'column' => 'в т.ч. НДС ' . $rate . '%',
            'note'   => 'в т.ч. НДС ' . $rate . '%',
            'lines'  => [
                ['label' => 'Итого', 'amount' => $sum, 'total' => true],
                ['label' => 'в т.ч. НДС ' . $rate . '%', 'amount' => $amount, 'total' => false],
            ],
        ];
    }

    /**
     * Что сказать МойСклад про налог в заказе и счёте, созданных по КП.
     *
     * Документ в МойСклад считает НДС по своим полям: `vatEnabled` — облагается
     * ли он вообще, `vatIncluded` — сидит ли налог в цене позиции. Оба ответа
     * уже даны в КП, и счёт обязан повторить их, а не решать заново: КП «цена +
     * НДС», выставленное счётом «в т.ч. НДС», — это скидка размером в налог.
     *
     * @param array $proposal строка `proposals`; пустая — берём организацию и настройку
     * @return array{vat_enabled:bool,vat_included:bool}
     */
    public static function msVatFlags(array $proposal = []): array {
        $vat = !empty($proposal['id']) ? (self::forProposal((int)$proposal['id'])['vat'] ?? []) : [];
        if (!$vat) {
            $legal = Db::one("SELECT pays_vat FROM legal_entities WHERE is_active=1 LIMIT 1") ?: [];
            $vat = ['pays_vat' => !array_key_exists('pays_vat', $legal) || (int)$legal['pays_vat'] === 1];
        }
        $enabled = !array_key_exists('pays_vat', $vat) || (bool)$vat['pays_vat'];
        return [
            'vat_enabled'  => $enabled,
            'vat_included' => $enabled && self::vatMode($proposal) !== 'added',
        ];
    }

    /**
     * Everything «Оформление КП» prints, and where each value came from.
     *
     * The tab used to hold typed-in numbers only, so НДС, ИНН and the addresses
     * of the КП lived in two places at once — МойСклад and somebody's memory.
     * This is the one answer to «что подставится в документ»: the организация as
     * МойСклад has it, the VAT rate the catalog actually carries, and the
     * manual defaults that only apply where МойСклад is silent.
     */
    public static function kpDefaults(): array {
        $legal = Db::one("SELECT * FROM legal_entities WHERE is_active=1 LIMIT 1") ?: [];
        $orgId = trim((string)Settings::get('MOYSKLAD_ORG_ID', ''));
        return [
            'org_id'        => $orgId,                       // shown in full: it is not a secret
            'token_set'     => trim((string)Settings::get('MOYSKLAD_TOKEN', '')) !== '',
            'autosync'      => (int)Settings::get('REQUISITES_AUTOSYNC', 1) === 1,
            'block_in_kp'   => (int)Settings::get('KP_REQUISITES_BLOCK', 1) === 1,
            'exempt_note'   => (string)Settings::get('KP_VAT_EXEMPT_NOTE', 'НДС не облагается'),
            'seller'        => [
                'moysklad_id'   => (string)($legal['moysklad_id'] ?? ''),
                'entity_type'   => (string)($legal['entity_type'] ?? ''),
                'full_name'     => (string)($legal['full_name'] ?? ''),
                'short_name'    => (string)($legal['short_name'] ?? ''),
                'inn'           => (string)($legal['inn'] ?? ''),
                'kpp'           => (string)($legal['kpp'] ?? ''),
                'ogrn'          => (string)($legal['ogrn'] ?? ''),
                'ogrnip'        => (string)($legal['ogrnip'] ?? ''),
                'okpo'          => (string)($legal['okpo'] ?? ''),
                'legal_address' => (string)($legal['legal_address'] ?? ''),
                'address'       => trim((string)($legal['city'] ?? '') . ' ' . (string)($legal['address'] ?? '')),
                'phone'         => (string)($legal['phone'] ?? ''),
                'email'         => (string)($legal['email'] ?? ''),
                'signatory'     => (string)($legal['signatory_name'] ?? ''),
                'pays_vat'      => !array_key_exists('pays_vat', $legal) || (int)$legal['pays_vat'] === 1,
                'bank'          => [
                    'name'         => (string)($legal['bank_name'] ?? ''),
                    'bic'          => (string)($legal['bank_bic'] ?? ''),
                    'account'      => (string)($legal['bank_account'] ?? ''),
                    'corr_account' => (string)($legal['bank_corr'] ?? ''),
                    'line'         => (string)($legal['bank_details'] ?? ''),
                ],
                'synced_at'     => (string)($legal['synced_at'] ?? ''),
            ],
            'catalog_vat'   => self::catalogVat(),
            'vat_sources'   => self::VAT_SOURCES,
            'vat_mode'      => self::vatMode(),
        ];
    }

    /**
     * The VAT rate the synced catalog actually carries. `vatFor()` reads it per
     * КП from the positions of that КП; here it answers the flat question the
     * settings tab asks — «какая ставка у нас на складе» — so the manual default
     * can be set to what МойСклад says instead of to what someone remembers.
     */
    public static function catalogVat(): array {
        $rows = Db::all(
            "SELECT vat, COUNT(*) AS n FROM products_cache
             WHERE is_archived IS NOT 1 AND vat IS NOT NULL
             GROUP BY vat ORDER BY n DESC"
        );
        $total = array_sum(array_column($rows, 'n'));
        return [
            'rate'  => $rows ? (int)$rows[0]['vat'] : null,
            'items' => (int)$total,
            'share' => $total ? (int)round(100 * (int)$rows[0]['n'] / $total) : 0,
            'rates' => array_map(fn($r) => ['rate' => (int)$r['vat'], 'items' => (int)$r['n']], $rows),
        ];
    }

    /** The snapshot a КП was generated with, or a fresh one for an older КП. */
    public static function forProposal(int $proposalId): array {
        $stored = Db::val("SELECT requisites_json FROM proposals WHERE id=?", [$proposalId]);
        $decoded = $stored ? json_decode((string)$stored, true) : null;
        $snapshot = is_array($decoded) && $decoded ? $decoded : self::snapshot($proposalId);

        // КП, замороженное до модуля 023, держит в имени покупателя фразу
        // «Покупатель уточняется». Перепечатывать её нельзя: нет данных — нет
        // строки. Снимок в базе при этом не трогаем: он на то и снимок.
        if (($snapshot['buyer']['name'] ?? '') === self::BUYER_UNKNOWN) {
            $snapshot['buyer']['name'] = '';
        }
        return $snapshot;
    }

    /** Freeze the requisites onto a proposal. Called once, at generation. */
    public static function freeze(int $proposalId): array {
        $snapshot = self::snapshot($proposalId);
        Db::update('proposals', ['requisites_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)],
                   'id=?', [$proposalId]);
        return $snapshot;
    }

    private static function legalEntity(int $id): array {
        return Db::one("SELECT * FROM legal_entities WHERE id=?", [$id]) ?: [];
    }

    private static function bankLine(array $bank): string {
        if (!$bank) return '';
        $parts = array_filter([
            $bank['bank_name'] ?? '',
            ($bank['bic'] ?? '') !== '' ? 'БИК ' . $bank['bic'] : '',
            ($bank['account'] ?? '') !== '' ? 'р/с ' . $bank['account'] : '',
            ($bank['corr_account'] ?? '') !== '' ? 'к/с ' . $bank['corr_account'] : '',
        ], fn($v) => trim((string)$v) !== '');
        return implode(', ', $parts);
    }
}
