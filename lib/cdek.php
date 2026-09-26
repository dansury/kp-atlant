<?php
/**
 * СДЭК: доставка считается, а не угадывается (issue #139, модуль 067).
 *
 * Строка «Доставка» в подборе принимала число, которое менеджер узнавал в
 * калькуляторе СДЭК в соседней вкладке, перепечатав туда город, вес и
 * габариты. Здесь то же самое — рядом с полем цены, по кнопке «🧮»:
 *
 *   — с ключом API (ЛК СДЭК → Интеграция) — тарифы самого СДЭК
 *     (`/v2/calculator/tarifflist`, коробка — услугой в `/v2/calculator/tariff`);
 *   — без ключа — по ставке, которую знает менеджер (₽ за отправление и за кг
 *     по его договору), и по тем же весу и габаритам. Ничего не выдумывается:
 *     нет ставки — окно говорит, какого поля не хватает.
 *
 * Вес позиции берётся из карточки МойСклад («Вес»), иначе из описания
 * («вес 2,3 кг», «Вес без батареек: 275 гр.»), и умножается на количество.
 * Пользоваться окном необязательно: число в поле цены по-прежнему пишут руками.
 */
final class Cdek {
    public const PROD_URL = 'https://api.cdek.ru/v2';
    public const TEST_URL = 'https://api.edu.cdek.ru/v2';

    /** Объёмный вес СДЭК: Д × Ш × В (см) / 5000 = кг. */
    public const VOLUME_DIVISOR = 5000;

    /** Договор → `type` калькулятора: 1 — «интернет-магазин», 2 — «доставка». */
    public const CONTRACT_TYPES = ['im' => 1, 'delivery' => 2];

    /** Режимы доставки из ответа тарифов. */
    public const MODES = [1 => 'дверь-дверь', 2 => 'дверь-склад', 3 => 'склад-дверь', 4 => 'склад-склад',
                          6 => 'дверь-постамат', 7 => 'склад-постамат', 8 => 'постамат-постамат'];

    /**
     * Коробки СДЭК — коды услуги «упаковка» калькулятора. Список правится в
     * настройке `CDEK_BOXES`, если у СДЭК он другой: `КОД; Название; ДxШxВ; кг`.
     */
    public const BOXES = [
        ['code' => 'CARTON_BOX_XS',   'name' => 'Коробка XS',    'l' => 17, 'w' => 12, 'h' => 9,  'max' => 0.5],
        ['code' => 'CARTON_BOX_S',    'name' => 'Коробка S',     'l' => 21, 'w' => 20, 'h' => 34, 'max' => 2],
        ['code' => 'CARTON_BOX_M',    'name' => 'Коробка M',     'l' => 33, 'w' => 25, 'h' => 15, 'max' => 5],
        ['code' => 'CARTON_BOX_L',    'name' => 'Коробка L',     'l' => 34, 'w' => 33, 'h' => 26, 'max' => 12],
        ['code' => 'CARTON_BOX_3KG',  'name' => 'Коробка 3 кг',  'l' => 24, 'w' => 24, 'h' => 21, 'max' => 3],
        ['code' => 'CARTON_BOX_5KG',  'name' => 'Коробка 5 кг',  'l' => 40, 'w' => 24, 'h' => 21, 'max' => 5],
        ['code' => 'CARTON_BOX_10KG', 'name' => 'Коробка 10 кг', 'l' => 40, 'w' => 35, 'h' => 28, 'max' => 10],
        ['code' => 'CARTON_BOX_15KG', 'name' => 'Коробка 15 кг', 'l' => 60, 'w' => 35, 'h' => 29, 'max' => 15],
        ['code' => 'CARTON_BOX_20KG', 'name' => 'Коробка 20 кг', 'l' => 47, 'w' => 40, 'h' => 43, 'max' => 20],
        ['code' => 'CARTON_BOX_30KG', 'name' => 'Коробка 30 кг', 'l' => 69, 'w' => 39, 'h' => 42, 'max' => 30],
    ];

    /** Заглушка HTTP для тестов: fn(method, url, payload) => [code, body]. */
    public static ?Closure $transport = null;

    public static function enabled(): bool {
        return trim((string)Settings::get('CDEK_CLIENT_ID', '')) !== ''
            && trim((string)Settings::get('CDEK_CLIENT_SECRET', '')) !== '';
    }

    public static function base(): string {
        return (int)Settings::get('CDEK_TEST', 0) === 1 ? self::TEST_URL : self::PROD_URL;
    }

    /** Договор со СДЭК: none | im | delivery. */
    public static function contract(): string {
        $c = (string)Settings::get('CDEK_CONTRACT', 'none');
        return isset(self::CONTRACT_TYPES[$c]) ? $c : 'none';
    }

    /** Всё, что окну расчёта нужно знать заранее. Ключи в браузер не уходят. */
    public static function state(): array {
        return [
            'enabled'   => self::enabled(),
            'test'      => (int)Settings::get('CDEK_TEST', 0) === 1,
            'from'      => trim((string)Settings::get('CDEK_FROM_CITY', 'Москва')) ?: 'Москва',
            'contract'  => self::contract(),
            'rate_base' => (float)Settings::get('CDEK_RATE_BASE', 0),
            'rate_kg'   => (float)Settings::get('CDEK_RATE_KG', 0),
            'markup'    => (float)Settings::get('CDEK_MARKUP', 0),
            'boxes'     => self::boxes(),
            'divisor'   => self::VOLUME_DIVISOR,
        ];
    }

    /** Коробки: из настройки, если она заполнена и разбирается, иначе встроенные. */
    public static function boxes(): array {
        $raw = trim((string)Settings::get('CDEK_BOXES', ''));
        if ($raw === '') return self::BOXES;
        $out = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $parts = array_map('trim', explode(';', $line));
            if (count($parts) < 4 || $parts[0] === '') continue;
            if (!preg_match('/^(\d+(?:[.,]\d+)?)\s*[xх×*]\s*(\d+(?:[.,]\d+)?)\s*[xх×*]\s*(\d+(?:[.,]\d+)?)$/iu', $parts[2], $m)) continue;
            $out[] = ['code' => $parts[0], 'name' => $parts[1] ?: $parts[0],
                      'l' => self::num($m[1]), 'w' => self::num($m[2]), 'h' => self::num($m[3]),
                      'max' => self::num($parts[3])];
        }
        return $out ?: self::BOXES;
    }

    // ==== Вес ====

    /**
     * Вес из текста описания, кг: «вес 2,3 кг», «Масса: 850 г», «Вес без
     * батареек: 275 гр.». Первое упоминание; не нашлось — null.
     */
    public static function weightFromText(string $text): ?float {
        $text = str_replace("\xC2\xA0", ' ', $text);
        if (!preg_match('/(?<!\p{L})(?:вес|масса)(?!\p{L}{2})[^\d\n]{0,40}?(\d+(?:[.,]\d+)?)\s*(кг|килограмм\p{L}*|kg|гр|грамм\p{L}*|г|g)(?!\p{L})/iu',
                        $text, $m)) {
            return null;
        }
        $value = self::num($m[1]);
        $unit = mb_strtolower($m[2]);
        if (!in_array($unit, ['кг', 'kg'], true) && !str_starts_with($unit, 'килограмм')) $value /= 1000;
        return $value > 0 ? round($value, 3) : null;
    }

    /** Вес одной штуки, кг: карточка МойСклад, потом описание; товар — за модификацию. */
    public static function weightFor(string $productId): ?float {
        if ($productId === '') return null;
        $row = Db::one("SELECT moysklad_id, parent_id, weight, description, specs_text, characteristics
                        FROM products_cache WHERE moysklad_id=?", [$productId]);
        if (!$row) return null;
        $parent = trim((string)($row['parent_id'] ?? '')) !== ''
            ? Db::one("SELECT weight, description, specs_text, characteristics FROM products_cache WHERE moysklad_id=?",
                      [$row['parent_id']]) : null;
        foreach ([$row, $parent] as $r) {
            if ($r && (float)($r['weight'] ?? 0) > 0) return round((float)$r['weight'], 3);
        }
        require_once __DIR__ . '/markup.php';
        foreach ([$row, $parent] as $r) {
            if (!$r) continue;
            $text = Markup::toPlainText((string)($r['description'] ?? '')) . "\n"
                  . Markup::toPlainText((string)($r['specs_text'] ?? '')) . "\n" . (string)($r['characteristics'] ?? '');
            $w = self::weightFromText($text);
            if ($w !== null) return $w;
        }
        return null;
    }

    /** Позиции подбора с весом штуки — то, из чего окно складывает посылку. */
    public static function requestWeights(int $requestId): array {
        $out = [];
        foreach (Db::all("SELECT id, raw_name, product_name, moysklad_product_id, quantity, is_out_of_scope
                          FROM request_items WHERE request_id=? ORDER BY position, id", [$requestId]) as $r) {
            if ((int)($r['is_out_of_scope'] ?? 0) === 1) continue;
            $name = trim((string)($r['product_name'] ?? '')) ?: trim((string)($r['raw_name'] ?? ''));
            if ($name === '') continue;
            $out[] = [
                'id'     => (int)$r['id'],
                'name'   => $name,
                'qty'    => max(0, (int)$r['quantity']),
                'weight' => self::weightFor((string)($r['moysklad_product_id'] ?? '')),
            ];
        }
        return $out;
    }

    // ==== Посылка и ставка ====

    /** Вес к оплате места: больший из настоящего и объёмного. */
    public static function chargeable(array $package): float {
        $volume = self::num($package['l'] ?? 0) * self::num($package['w'] ?? 0) * self::num($package['h'] ?? 0) / self::VOLUME_DIVISOR;
        return round(max(self::num($package['weight'] ?? 0), $volume), 3);
    }

    /** Сколько коробок этого размера нужно под вес. */
    public static function places(float $weight, array $box): int {
        $max = self::num($box['max'] ?? 0);
        if ($max <= 0 || $weight <= 0) return 1;
        return max(1, (int)ceil(round($weight / $max, 6)));
    }

    /**
     * Без ключа — по ставке договора: ₽ за отправление + ₽ за кг веса к
     * оплате всех мест. Ставки нет — сумма не считается, окно говорит почему.
     *
     * @return array{sum:?float,chargeable:float,places:int,missing:string[]}
     */
    public static function rateQuote(array $packages, float $base, float $perKg): array {
        $weight = 0.0;
        foreach ($packages as $p) $weight += self::chargeable($p);
        $missing = [];
        if ($perKg <= 0) $missing[] = 'rate_kg';
        return [
            'sum'        => $missing ? null : round(max(0.0, $base) + $perKg * $weight, 2),
            'chargeable' => round($weight, 3),
            'places'     => count($packages),
            'missing'    => $missing,
        ];
    }

    /** Наценка к цене СДЭК, % из настроек. */
    public static function withMarkup(float $sum): float {
        $markup = max(0.0, (float)Settings::get('CDEK_MARKUP', 0));
        return round($sum * (1 + $markup / 100), 2);
    }

    // ==== API ====

    /** Города по началу названия: [{code, name}]. */
    public static function cities(string $q): array {
        $q = trim($q);
        if (mb_strlen($q) < 2 || !self::enabled()) return [];
        try {
            $data = self::api('GET', '/location/suggest/cities?' . http_build_query(['name' => $q, 'country_code' => 'RU']));
            $out = [];
            foreach ((array)$data as $c) {
                if (!isset($c['code'])) continue;
                $out[] = ['code' => (int)$c['code'], 'name' => (string)($c['full_name'] ?? $c['city'] ?? '')];
            }
            if ($out) return array_slice($out, 0, 10);
        } catch (Throwable $e) {
            Logger::warning('cdek', 'Подсказка городов не ответила, спрашиваем список: ' . $e->getMessage());
        }
        $data = self::api('GET', '/location/cities?' . http_build_query(['city' => $q, 'country_codes' => 'RU', 'size' => 10]));
        $out = [];
        foreach ((array)$data as $c) {
            if (!isset($c['code'])) continue;
            $out[] = ['code' => (int)$c['code'],
                      'name' => trim((string)($c['city'] ?? '') . (!empty($c['region']) ? ', ' . $c['region'] : ''))];
        }
        return $out;
    }

    /** Код города отправки: число в настройке — сам код, иначе первый найденный город. */
    public static function fromCode(): ?int {
        $from = trim((string)Settings::get('CDEK_FROM_CITY', 'Москва')) ?: 'Москва';
        if (ctype_digit($from)) return (int)$from;
        $cached = json_decode((string)(Db::val("SELECT value FROM settings WHERE key='cdek.from'") ?: ''), true);
        if (is_array($cached) && ($cached['name'] ?? '') === $from && !empty($cached['code'])) return (int)$cached['code'];
        $found = self::cities($from)[0]['code'] ?? null;
        if ($found) {
            Db::q("INSERT INTO settings (key, value) VALUES ('cdek.from', ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                  [json_encode(['name' => $from, 'code' => $found], JSON_UNESCAPED_UNICODE)]);
        }
        return $found ? (int)$found : null;
    }

    /** Места посылки в форме калькулятора СДЭК: граммы и сантиметры. */
    public static function apiPackages(array $packages): array {
        return array_map(fn($p) => [
            'weight' => max(1, (int)round(self::num($p['weight'] ?? 0) * 1000)),
            'length' => max(1, (int)round(self::num($p['l'] ?? 0))),
            'width'  => max(1, (int)round(self::num($p['w'] ?? 0))),
            'height' => max(1, (int)round(self::num($p['h'] ?? 0))),
        ], array_values($packages));
    }

    /**
     * Все тарифы между городами, дешёвые сверху.
     *
     * @return list<array{code:int,name:string,mode:string,sum:float,days_min:int,days_max:int}>
     */
    public static function tariffs(int $from, int $to, array $packages, int $type): array {
        $data = self::api('POST', '/calculator/tarifflist', [
            'type' => $type, 'currency' => 1, 'lang' => 'rus',
            'from_location' => ['code' => $from], 'to_location' => ['code' => $to],
            'packages' => self::apiPackages($packages),
        ]);
        if (!empty($data['errors']) && empty($data['tariff_codes'])) {
            throw new RuntimeException('СДЭК: ' . self::errorText($data['errors']));
        }
        $out = [];
        foreach ((array)($data['tariff_codes'] ?? []) as $t) {
            if (!isset($t['tariff_code'], $t['delivery_sum'])) continue;
            $out[] = [
                'code'     => (int)$t['tariff_code'],
                'name'     => (string)($t['tariff_name'] ?? ('Тариф ' . $t['tariff_code'])),
                'mode'     => self::MODES[(int)($t['delivery_mode'] ?? 0)] ?? '',
                'sum'      => (float)$t['delivery_sum'],
                'days_min' => (int)($t['period_min'] ?? 0),
                'days_max' => (int)($t['period_max'] ?? 0),
            ];
        }
        usort($out, fn($a, $b) => [$a['sum'], $a['days_max']] <=> [$b['sum'], $b['days_max']]);
        return $out;
    }

    /**
     * Один тариф с услугами (коробка СДЭК): сумма уже с упаковкой.
     *
     * @return array{sum:float,delivery:float,days_min:int,days_max:int}
     */
    public static function tariff(int $code, int $from, int $to, array $packages, int $type, array $services = []): array {
        $body = [
            'type' => $type, 'currency' => 1, 'lang' => 'rus', 'tariff_code' => $code,
            'from_location' => ['code' => $from], 'to_location' => ['code' => $to],
            'packages' => self::apiPackages($packages),
        ];
        if ($services) $body['services'] = array_values($services);
        $data = self::api('POST', '/calculator/tariff', $body);
        if (!empty($data['errors']) && !isset($data['total_sum'])) {
            throw new RuntimeException('СДЭК: ' . self::errorText($data['errors']));
        }
        return [
            'sum'      => (float)($data['total_sum'] ?? $data['delivery_sum'] ?? 0),
            'delivery' => (float)($data['delivery_sum'] ?? 0),
            'days_min' => (int)($data['period_min'] ?? 0),
            'days_max' => (int)($data['period_max'] ?? 0),
        ];
    }

    /** Токен OAuth: живёт час, хранится зашифрованным до конца срока. */
    public static function token(): string {
        $id = trim((string)Settings::get('CDEK_CLIENT_ID', ''));
        $secret = trim((string)Settings::get('CDEK_CLIENT_SECRET', ''));
        if ($id === '' || $secret === '') throw new RuntimeException('Ключ API СДЭК не задан');
        $scope = sha1(self::base() . '|' . $id);
        $raw = (string)(Db::val("SELECT value FROM settings WHERE key='cdek.token'") ?: '');
        $cached = $raw !== '' ? json_decode((string)Crypt::decrypt($raw), true) : null;
        if (is_array($cached) && ($cached['scope'] ?? '') === $scope && (int)($cached['until'] ?? 0) > time() + 60) {
            return (string)$cached['token'];
        }
        [$code, $body] = self::http('POST', self::base() . '/oauth/token', null, [
            'grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret,
        ]);
        $data = is_array($body) ? $body : json_decode((string)$body, true);
        if ($code !== 200 || empty($data['access_token'])) {
            throw new RuntimeException('СДЭК не выдал токен (HTTP ' . $code . ')'
                . (is_array($data) && !empty($data['error_description']) ? ': ' . $data['error_description'] : ''));
        }
        $until = time() + max(60, (int)($data['expires_in'] ?? 3600));
        Db::q("INSERT INTO settings (key, value) VALUES ('cdek.token', ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
              [Crypt::encrypt(json_encode(['scope' => $scope, 'token' => $data['access_token'], 'until' => $until]))]);
        return (string)$data['access_token'];
    }

    /** Запрос к API с токеном; ошибка называет код и то, что ответил СДЭК. */
    private static function api(string $method, string $path, ?array $json = null): array {
        [$code, $body] = self::http($method, self::base() . $path, $json, null, self::token());
        $data = is_array($body) ? $body : json_decode((string)$body, true);
        if ($code < 200 || $code >= 300) {
            $what = is_array($data) ? self::errorText($data['errors'] ?? $data['requests'][0]['errors'] ?? [])
                                    : mb_substr(trim((string)$body), 0, 200);
            throw new RuntimeException('СДЭК ответил HTTP ' . $code . ($what !== '' ? ': ' . $what : ''));
        }
        return is_array($data) ? $data : [];
    }

    private static function http(string $method, string $url, ?array $json, ?array $form, ?string $token = null): array {
        if (self::$transport) return (self::$transport)($method, $url, $json ?? $form);
        $headers = ['Accept: application/json'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
        } elseif ($form !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($resp === false) throw new RuntimeException('СДЭК недоступен: ' . $err);
        return [$code, (string)$resp];
    }

    private static function errorText(mixed $errors): string {
        if (!is_array($errors)) return (string)$errors;
        $out = [];
        foreach ($errors as $e) {
            $out[] = is_array($e) ? trim((string)($e['message'] ?? $e['code'] ?? '')) : (string)$e;
        }
        return implode('; ', array_filter($out));
    }

    private static function num(mixed $v): float {
        return (float)str_replace(',', '.', trim((string)$v));
    }
}
