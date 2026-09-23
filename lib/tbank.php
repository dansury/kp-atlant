<?php
/**
 * Т-Банк: выписка по счёту (модуль 047).
 *
 * Читаем только входящие операции — оплату счетов покупателями. Токен T-API
 * выдаётся в интернет-банке (Интеграции → T-API) с доступом к выписке.
 */
final class TBank {

    public const DEFAULT_URL = 'https://business.tbank.ru/openapi';

    public static function configured(): bool {
        return trim((string)Settings::get('TBANK_TOKEN', '')) !== '' && self::accounts() !== [];
    }

    /** Счета из настройки: только 20–22 цифры, без повторов. */
    public static function accounts(): array {
        $raw = (string)Settings::get('TBANK_ACCOUNTS', '');
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $a) {
            if (preg_match('/^\d{20,22}$/', $a)) $out[$a] = true;
        }
        return array_keys($out);
    }

    /**
     * Входящие операции счёта за период (ISO 8601), все страницы.
     *
     * @return list<array> строки normalize()
     */
    public static function statement(string $account, string $from, string $to): array {
        $out = [];
        $cursor = '';
        for ($page = 0; $page < 50; $page++) {
            $q = ['accountNumber' => $account, 'from' => $from, 'to' => $to,
                  'operationStatus' => 'Transaction', 'limit' => 1000];
            if ($cursor !== '') $q['cursor'] = $cursor;
            $data = self::get('/api/v1/statement?' . http_build_query($q));
            foreach ((array)($data['operations'] ?? []) as $op) {
                $row = self::normalize((array)$op, $account);
                if ($row) $out[] = $row;
            }
            $cursor = (string)($data['nextCursor'] ?? '');
            if ($cursor === '' || empty($data['operations'])) break;
        }
        return $out;
    }

    /** Операция выписки → строка платежа; расход и пустое — null. */
    public static function normalize(array $op, string $account = ''): ?array {
        $type = mb_strtolower((string)($op['typeOfOperation'] ?? ''));
        if ($type !== '' && $type !== 'credit') return null;
        $id = trim((string)($op['operationId'] ?? ''));
        $amount = (float)($op['accountAmount'] ?? $op['operationAmount'] ?? $op['rubleAmount'] ?? 0);
        if ($id === '' || $amount <= 0) return null;

        $party = (array)($op['counterParty'] ?? $op['payer'] ?? []);
        $date = (string)($op['operationDate'] ?? $op['chargeDate'] ?? $op['authorizationDate'] ?? '');
        $ts = $date !== '' ? strtotime($date) : false;
        return [
            'id'         => $id,
            'account'    => $account,
            'date'       => $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s'),
            'amount'     => round($amount, 2),
            'purpose'    => trim((string)($op['payPurpose'] ?? $op['description'] ?? '')),
            'payer_inn'  => preg_replace('/\D/', '', (string)($party['inn'] ?? '')),
            'payer_name' => trim((string)($party['name'] ?? '')),
            'number'     => trim((string)($op['documentNumber'] ?? '')),
        ];
    }

    private static function get(string $path): array {
        $token = trim((string)Settings::get('TBANK_TOKEN', ''));
        if ($token === '') throw new RuntimeException('Токен Т-Банка не задан');
        $base = rtrim((string)Settings::get('TBANK_API_URL', self::DEFAULT_URL) ?: self::DEFAULT_URL, '/');

        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if ($code < 200 || $code >= 300) {
            $data = json_decode($body, true);
            $msg = is_array($data) ? (string)($data['errorMessage'] ?? $data['message'] ?? '') : '';
            throw new RuntimeException('Т-Банк ответил HTTP ' . $code
                . ($msg !== '' ? ": $msg" : ($err !== '' ? ": $err" : '')));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('Т-Банк прислал не JSON');
        return $data;
    }
}
