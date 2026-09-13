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

    /** Printed instead of an e-mail address when the buyer has no name yet. */
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
                'name'          => $buyerNameIsEmail ? self::BUYER_UNKNOWN : $buyerName,
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
        ), fn($r) => $r !== null));

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
        return is_array($decoded) && $decoded ? $decoded : self::snapshot($proposalId);
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
