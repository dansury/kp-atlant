<?php
/**
 * Доставка по тарифам СДЭК (модуль 067, issue #139).
 *
 * Расчёт — необязательная помощь к полю «Доставка»: цену по-прежнему можно
 * вписать руками. С ключами API и договором — живые тарифы СДЭК
 * (`/calculator/tarifflist`), без них — ОЦЕНКА по ставке из настроек, и
 * панель прямо говорит, что это оценка, а не тариф.
 *
 * Вес берётся из каталога: «Вес» карточки МойСклад, иначе вес из описания
 * товара («Вес: 2,5 кг»), и умножается на количество позиции.
 */
require_once __DIR__ . '/markup.php';

final class Cdek {
    /** Договор → `type` запроса СДЭК: 1 — интернет-магазин, 2 — доставка. */
    public const CONTRACTS = ['im' => 1, 'delivery' => 2];

    /** Объёмный вес СДЭК: Д×Ш×В (см) / 5000. */
    public const VOLUME_DIVISOR = 5000;

    /**
     * Для тестов: fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string}
     * — код ответа и тело вместо настоящего запроса.
     */
    public static ?Closure $transport = null;

    /** Токен этого запроса — СДЭК выдаёт его на час, спрашивать на каждый вызов незачем. */
    private static ?string $token = null;

    public static function hasApi(): bool {
        return trim((string)Settings::get('CDEK_CLIENT_ID', '')) !== ''
            && trim((string)Settings::get('CDEK_CLIENT_SECRET', '')) !== '';
    }

    public static function base(): string {
        $url = trim((string)Settings::get('CDEK_API_URL', 'https://api.cdek.ru/v2'));
        return rtrim($url !== '' ? $url : 'https://api.cdek.ru/v2', '/');
    }

    /** Договор из настроек: im | delivery | none | '' (не указан — спросить). */
    public static function contract(): string {
        $c = trim((string)Settings::get('CDEK_CONTRACT', ''));
        return in_array($c, ['im', 'delivery', 'none'], true) ? $c : '';
    }

    // ---------- упаковка и вес ----------

    /**
     * Коробки из настроек: «Название: Д×Ш×В, до N кг» по строке.
     * @return list<array{name:string,l:int,w:int,h:int,max:float}> по возрастанию предельного веса
     */
    public static function boxes(?string $text = null): array {
        $text ??= (string)Settings::get('CDEK_BOXES', '');
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (!preg_match('/^\s*(.+?)\s*[:—–]\s*(\d+(?:[.,]\d+)?)\s*[x×хX*]\s*(\d+(?:[.,]\d+)?)\s*[x×хX*]\s*(\d+(?:[.,]\d+)?)(.*)$/u', $line, $m)) continue;
            $max = preg_match('/до\s*(\d+(?:[.,]\d+)?)\s*кг/u', $m[5], $w) ? self::num($w[1]) : 0.0;
            $out[] = ['name' => trim($m[1]), 'l' => (int)round(self::num($m[2])), 'w' => (int)round(self::num($m[3])),
                      'h' => (int)round(self::num($m[4])), 'max' => $max];
        }
        usort($out, fn($a, $b) => [$a['max'] ?: INF, $a['l'] * $a['w'] * $a['h']] <=> [$b['max'] ?: INF, $b['l'] * $b['w'] * $b['h']]);
        return $out;
    }

    /**
     * Коробка под вес: самая маленькая, что его выдержит. Не выдержит ни одна —
     * несколько самых больших, поровну.
     * @return array{box:?array,count:int}
     */
    public static function pickBox(float $kg, array $boxes): array {
        $sized = array_values(array_filter($boxes, fn($b) => $b['max'] > 0));
        if (!$sized) return ['box' => $boxes[0] ?? null, 'count' => 1];
        foreach ($sized as $b) if ($kg <= $b['max']) return ['box' => $b, 'count' => 1];
        $largest = end($sized);
        return ['box' => $largest, 'count' => max(1, (int)ceil($kg / $largest['max']))];
    }

    /**
     * Вес из текста карточки, кг: «Вес: 2,5 кг», «масса плиты — 800 г»,
     * «Вес (кг): 3». Диапазон «2,3–2,5 кг» — его верх: доставку считают с запасом.
     */
    public static function weightFromText(string $text): ?float {
        $text = str_replace("\xC2\xA0", ' ', $text);
        $num = '(\d{1,3}(?: \d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)?)';   // «1 200 г» — с разрядами
        // Слово целиком: «вес» внутри «навесной» — не вес
        $word = '(?<![\p{L}])(?:вес(?:ом|ит|а)?|масс(?:а|ой|ы)?)(?![\p{L}])';
        if (preg_match('/' . $word . '\s*\(\s*(кг|г|гр|kg|g)\s*\)\s*[:\-–—]?\s*' . $num . '(?:\s*[-–—]\s*' . $num . ')?/iu', $text, $m)) {
            $value = self::num(($m[3] ?? '') !== '' ? $m[3] : $m[2]);
            return self::toKg($value, $m[1]);
        }
        if (preg_match('/' . $word . '[^\d\n]{0,30}?' . $num . '(?:\s*[-–—]\s*' . $num . ')?\s*(кг|kg|гр|г|g)(?![\p{L}])/iu', $text, $m)) {
            $value = self::num(($m[2] ?? '') !== '' ? $m[2] : $m[1]);
            return self::toKg($value, $m[3]);
        }
        return null;
    }

    /** Город из адреса: «355000, г. Ставрополь, ул. Ленина» → «Ставрополь». */
    public static function cityFromAddress(string $address): string {
        $a = trim(str_replace("\xC2\xA0", ' ', $address));
        if ($a === '') return '';
        if (preg_match('/(?:^|[\s,])(?:г\.?|гор\.?|город)\s+([А-ЯЁ][А-Яа-яЁё\-]+(?:[\s\-][А-ЯЁ][А-Яа-яЁё\-]+)?)/u', $a, $m)
            || preg_match('/(?:^|[\s,])г\.([А-ЯЁ][А-Яа-яЁё\-]+)/u', $a, $m)) {
            return trim($m[1]);
        }
        // «Россия, Москва, ул. …» / «355000, Ставрополь, …» — первая часть без цифр, сокращений и страны
        foreach (preg_split('/,/u', $a) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || preg_match('/\d|(?<![\p{L}])(?:обл|край|респ|р-н|район|ул|пр|д)\.?(?:\s|$)|область|республика|росси|^рф$/iu', $part)) continue;
            if (preg_match('/^[А-ЯЁ][А-Яа-яЁё\-]+(?:\s[А-ЯЁ][А-Яа-яЁё\-]+)?$/u', $part)) return $part;
        }
        return '';
    }

    /**
     * Вес позиций запроса на единицу, кг, и откуда он: «МойСклад», «описание».
     * «Не наша номенклатура» в посылку не едет.
     *
     * @return list<array{id:int,name:string,qty:float,kg:?float,source:string}>
     */
    public static function itemWeights(int $requestId): array {
        $rows = Db::all("SELECT id, product_name, raw_name, quantity, moysklad_product_id FROM request_items
                         WHERE request_id=? AND COALESCE(is_out_of_scope, 0)=0 ORDER BY position, id", [$requestId]);
        $out = [];
        foreach ($rows as $r) {
            [$kg, $source] = self::productWeight((string)($r['moysklad_product_id'] ?? ''));
            $out[] = [
                'id'     => (int)$r['id'],
                'name'   => (string)($r['product_name'] ?: $r['raw_name']),
                'qty'    => max(0.0, (float)$r['quantity']),
                'kg'     => $kg,
                'source' => $source,
            ];
        }
        return $out;
    }

    /** @return array{0:?float,1:string} вес единицы товара и откуда он взят */
    public static function productWeight(string $moyskladId): array {
        if ($moyskladId === '') return [null, ''];
        $p = Db::one("SELECT * FROM products_cache WHERE moysklad_id=?", [$moyskladId]);
        if (!$p) return [null, ''];
        $parent = trim((string)($p['parent_id'] ?? '')) !== ''
            ? Db::one("SELECT * FROM products_cache WHERE moysklad_id=?", [(string)$p['parent_id']]) : null;
        // «Вес» МойСклад: у модификации своего нет — её товара
        foreach ([$p, $parent] as $row) {
            if ($row && (float)($row['weight'] ?? 0) > 0) return [(float)$row['weight'], 'МойСклад'];
        }
        foreach ([$p, $parent] as $row) {
            if (!$row) continue;
            $text = implode("\n", [(string)($row['characteristics'] ?? ''),
                                   Markup::toPlainText((string)($row['description'] ?? '')),
                                   Markup::toPlainText((string)($row['specs_text'] ?? ''))]);
            $kg = self::weightFromText($text);
            if ($kg !== null && $kg > 0) return [$kg, 'описание'];
        }
        return [null, ''];
    }

    /** Что панель расчёта знает о запросе заранее. */
    public static function prefill(int $requestId): array {
        $req = Db::one("SELECT counterparty_id FROM requests WHERE id=?", [$requestId]) ?: [];
        $to = '';
        if (!empty($req['counterparty_id'])) {
            $to = self::cityFromAddress((string)(Db::val("SELECT legal_address FROM counterparties WHERE id=?",
                                                          [(int)$req['counterparty_id']]) ?: ''));
        }
        $items = self::itemWeights($requestId);
        $total = 0.0;
        foreach ($items as $i) if ($i['kg'] !== null) $total += $i['kg'] * $i['qty'];
        $boxes = self::boxes();
        $pick = self::pickBox($total, $boxes);
        return [
            'from'     => trim((string)Settings::get('CDEK_FROM_CITY', '')),
            'to'       => $to,
            'items'    => $items,
            'weight'   => round($total, 3),
            'boxes'    => $boxes,
            'box'      => $pick['box']['name'] ?? '',
            'count'    => $pick['count'],
            'contract' => self::contract(),
            'api'      => self::hasApi(),
            'rate'     => ['base' => (float)Settings::get('CDEK_RATE_BASE', 400), 'per_kg' => (float)Settings::get('CDEK_RATE_PER_KG', 60)],
        ];
    }

    /** Оплачиваемый вес посылки, кг: больший из фактического и объёмного. */
    public static function billable(array $package): float {
        $volume = max(0, (float)($package['l'] ?? 0)) * max(0, (float)($package['w'] ?? 0)) * max(0, (float)($package['h'] ?? 0));
        return max(max(0.0, (float)($package['kg'] ?? 0)), $volume / self::VOLUME_DIVISOR);
    }

    /**
     * Оценка без API: база + ставка × оплачиваемый вес всех посылок.
     * @return array{sum:float,base:float,per_kg:float,billable_kg:float}
     */
    public static function estimate(array $packages): array {
        $base = max(0.0, (float)Settings::get('CDEK_RATE_BASE', 400));
        $perKg = max(0.0, (float)Settings::get('CDEK_RATE_PER_KG', 60));
        $kg = 0.0;
        foreach ($packages as $p) $kg += self::billable($p);
        $kg = round($kg, 2);
        return ['sum' => (float)ceil($base + $perKg * $kg), 'base' => $base, 'per_kg' => $perKg, 'billable_kg' => $kg];
    }

    /**
     * Посылки из того, что пришло с экрана: вес в кг, размеры в см. Пустые —
     * отброшены, лишние — нет: СДЭК сам откажет в слишком тяжёлой.
     * @return list<array{kg:float,l:int,w:int,h:int}>
     */
    public static function packages(array $raw): array {
        $out = [];
        foreach (array_slice($raw, 0, 50) as $p) {
            $pkg = ['kg' => round(max(0.0, (float)($p['kg'] ?? 0)), 3),
                    'l' => max(0, (int)round((float)($p['l'] ?? 0))), 'w' => max(0, (int)round((float)($p['w'] ?? 0))),
                    'h' => max(0, (int)round((float)($p['h'] ?? 0)))];
            if ($pkg['kg'] > 0) $out[] = $pkg;
        }
        return $out;
    }

    // ---------- API СДЭК ----------

    /** Токен OAuth: из памяти этого запроса, из базы, пока жив, иначе новый. */
    public static function token(): string {
        if (self::$token !== null) return self::$token;
        $id = trim((string)Settings::get('CDEK_CLIENT_ID', ''));
        $secret = trim((string)Settings::get('CDEK_CLIENT_SECRET', ''));
        if ($id === '' || $secret === '') throw new RuntimeException('Нет ключей API СДЭК — «Настройки → Все параметры → Доставка СДЭК»');

        // Токен принадлежит учётной записи и адресу API: сменили любое — новый
        $owner = sha1(self::base() . '|' . $id);
        $saved = json_decode((string)(Db::val("SELECT value FROM settings WHERE key='cdek_token'") ?: ''), true);
        if (is_array($saved) && ($saved['owner'] ?? '') === $owner && (int)($saved['exp'] ?? 0) > time() + 60
            && (string)($saved['token'] ?? '') !== '') {
            return self::$token = (string)$saved['token'];
        }

        [$code, $body] = self::send('POST', self::base() . '/oauth/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret]));
        $data = json_decode($body, true);
        $token = is_array($data) ? (string)($data['access_token'] ?? '') : '';
        if ($code >= 400 || $token === '') {
            throw new RuntimeException('СДЭК не выдал токен (HTTP ' . $code . '): ' . self::errorText($data, $body));
        }
        $exp = time() + max(60, (int)($data['expires_in'] ?? 3600));
        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES ('cdek_token', ?)",
              [json_encode(['owner' => $owner, 'token' => $token, 'exp' => $exp])]);
        return self::$token = $token;
    }

    /** Подсказка городов СДЭК: [{code, name}]. */
    public static function cities(string $q, int $limit = 8): array {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        try {
            $rows = self::api('GET', '/location/suggest/cities', null, ['name' => $q, 'country_code' => 'RU']);
            $out = [];
            foreach ($rows as $r) {
                if (!isset($r['code'])) continue;
                $out[] = ['code' => (int)$r['code'], 'name' => (string)($r['full_name'] ?? $r['city'] ?? '')];
            }
        } catch (Throwable $e) {
            // Старый адрес API подсказки не знает — список городов по имени
            $rows = self::api('GET', '/location/cities', null, ['city' => $q, 'country_codes' => 'RU', 'size' => $limit]);
            $out = array_map(fn($r) => ['code' => (int)$r['code'],
                'name' => trim((string)($r['city'] ?? '') . (($r['region'] ?? '') !== '' ? ', ' . $r['region'] : ''))], $rows);
        }
        return array_slice(array_values(array_filter($out, fn($c) => $c['code'] > 0)), 0, $limit);
    }

    /** Код города СДЭК по названию: первый из подсказки. */
    public static function cityCode(string $name): int {
        $name = trim($name);
        if (preg_match('/^\d+$/', $name)) return (int)$name;
        $found = self::cities($name, 1);
        if (!$found) throw new RuntimeException('СДЭК не знает город «' . $name . '» — проверьте название');
        return (int)$found[0]['code'];
    }

    /**
     * Тарифы СДЭК на эти посылки — от дешёвых к дорогим.
     * @return list<array{code:int,name:string,sum:float,days_min:?int,days_max:?int,mode:int}>
     */
    public static function tariffs(string $from, string $to, array $packages, string $contract): array {
        if (!isset(self::CONTRACTS[$contract])) throw new RuntimeException('Для тарифов СДЭК нужен тип договора');
        $body = [
            'type'          => self::CONTRACTS[$contract],
            'currency'      => 1,
            'lang'          => 'rus',
            'from_location' => ['code' => self::cityCode($from)],
            'to_location'   => ['code' => self::cityCode($to)],
            'packages'      => array_map(fn($p) => array_filter([
                'weight' => max(1, (int)round($p['kg'] * 1000)),   // граммы
                'length' => $p['l'] ?: null, 'width' => $p['w'] ?: null, 'height' => $p['h'] ?: null,
            ], fn($v) => $v !== null), $packages),
        ];
        $data = self::api('POST', '/calculator/tarifflist', $body);
        $out = [];
        foreach ((array)($data['tariff_codes'] ?? []) as $t) {
            if (!isset($t['delivery_sum'])) continue;
            $out[] = [
                'code'     => (int)($t['tariff_code'] ?? 0),
                'name'     => (string)($t['tariff_name'] ?? ''),
                'sum'      => (float)$t['delivery_sum'],
                'days_min' => isset($t['period_min']) ? (int)$t['period_min'] : null,
                'days_max' => isset($t['period_max']) ? (int)$t['period_max'] : null,
                'mode'     => (int)($t['delivery_mode'] ?? 0),
            ];
        }
        if (!$out) {
            $why = self::errorText($data, '');
            throw new RuntimeException('СДЭК не вернул ни одного тарифа' . ($why !== '' ? ': ' . $why : ''));
        }
        usort($out, fn($a, $b) => [$a['sum'], $a['days_max'] ?? 99] <=> [$b['sum'], $b['days_max'] ?? 99]);
        return $out;
    }

    /** Запрос к API с токеном: ответ JSON массивом или исключение с текстом СДЭК. */
    private static function api(string $method, string $path, ?array $json = null, array $query = [], bool $retry = true): array {
        $url = self::base() . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Authorization: Bearer ' . self::token(), 'Accept: application/json'];
        if ($json !== null) $headers[] = 'Content-Type: application/json';
        [$code, $body] = self::send($method, $url, $headers, $json === null ? null : json_encode($json, JSON_UNESCAPED_UNICODE));
        $data = json_decode($body, true);
        if ($code === 401) {
            // Токен отозвали раньше срока — забыть его и один раз спросить с новым
            self::$token = null;
            Db::q("DELETE FROM settings WHERE key='cdek_token'");
            if ($retry) return self::api($method, $path, $json, $query, false);
        }
        if ($code >= 400 || !is_array($data)) {
            throw new RuntimeException('СДЭК ответил HTTP ' . $code . ': ' . self::errorText($data, $body));
        }
        return $data;
    }

    /** Текст ошибки СДЭК из любого из его форматов. */
    private static function errorText(mixed $data, string $raw): string {
        $msgs = [];
        if (is_array($data)) {
            foreach ((array)($data['errors'] ?? []) as $e) $msgs[] = (string)($e['message'] ?? $e['code'] ?? '');
            foreach ((array)($data['requests'] ?? []) as $r) {
                foreach ((array)($r['errors'] ?? []) as $e) $msgs[] = (string)($e['message'] ?? $e['code'] ?? '');
            }
            if (!$msgs && isset($data['error_description'])) $msgs[] = (string)$data['error_description'];
            if (!$msgs && isset($data['error'])) $msgs[] = (string)$data['error'];
        }
        $msgs = array_values(array_filter(array_map('trim', $msgs)));
        return $msgs ? implode('; ', $msgs) : mb_substr(trim($raw), 0, 200);
    }

    /** @return array{0:int,1:string} */
    private static function send(string $method, string $url, array $headers, ?string $body): array {
        if (self::$transport) return (self::$transport)($method, $url, $headers, $body);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_merge($headers, ['User-Agent: atlant-kp']),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($resp === false) throw new RuntimeException('СДЭК недоступен: ' . $err);
        return [$code, (string)$resp];
    }

    private static function num(string $s): float {
        return (float)str_replace([',', ' '], ['.', ''], $s);
    }

    private static function toKg(float $value, string $unit): float {
        $u = mb_strtolower($unit);
        return round(in_array($u, ['г', 'гр', 'g'], true) ? $value / 1000 : $value, 3);
    }
}
