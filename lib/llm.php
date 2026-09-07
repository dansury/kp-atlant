<?php
/**
 * LLM wrapper (adapted from NeuroPro lib/llm.php).
 * Dual-provider: OpenRouter + Yandex FM. Fallback chain, json-mode, retry.
 */
class LLM {
    /**
     * Model catalog shown in the admin panel (same idea as NeuroPro AVAILABLE_MODELS).
     * Grouped so the picker can use <optgroup>; a slug can still be typed by hand,
     * and «Обновить каталог OpenRouter» replaces this list with the live one.
     */
    public const CATALOG = [
        'yandex' => [
            ['id' => 'yandexgpt/latest',      'label' => 'YandexGPT Pro — latest',    'group' => 'YandexGPT'],
            ['id' => 'yandexgpt/rc',          'label' => 'YandexGPT Pro — RC',        'group' => 'YandexGPT'],
            ['id' => 'yandexgpt-32k/latest',  'label' => 'YandexGPT Pro 32k',         'group' => 'YandexGPT'],
            ['id' => 'yandexgpt-lite/latest', 'label' => 'YandexGPT Lite — дешевле',  'group' => 'YandexGPT'],
            ['id' => 'yandexgpt-lite/rc',     'label' => 'YandexGPT Lite — RC',       'group' => 'YandexGPT'],
            ['id' => 'llama/latest',          'label' => 'Llama 70B',                 'group' => 'Открытые модели'],
            ['id' => 'llama-lite/latest',     'label' => 'Llama 8B',                  'group' => 'Открытые модели'],
            ['id' => 'qwen3-235b-a22b-fp8/latest', 'label' => 'Qwen3 235B',           'group' => 'Открытые модели'],
            // gpt-oss is a "common instance" model: Yandex serves it without a
            // /latest version segment — gpt://<folder>/gpt-oss-120b, not .../latest.
            ['id' => 'gpt-oss-120b',          'label' => 'GPT-OSS 120B',              'group' => 'Открытые модели'],
            ['id' => 'gpt-oss-20b',           'label' => 'GPT-OSS 20B',               'group' => 'Открытые модели'],
        ],
        'openrouter' => [
            ['id' => 'anthropic/claude-sonnet-4.5',       'label' => 'Claude Sonnet 4.5',      'group' => 'Anthropic'],
            ['id' => 'anthropic/claude-haiku-4.5',        'label' => 'Claude Haiku 4.5',       'group' => 'Anthropic'],
            ['id' => 'anthropic/claude-opus-4.1',         'label' => 'Claude Opus 4.1',        'group' => 'Anthropic'],
            ['id' => 'openai/gpt-5',                      'label' => 'GPT-5',                  'group' => 'OpenAI'],
            ['id' => 'openai/gpt-5-mini',                 'label' => 'GPT-5 mini',             'group' => 'OpenAI'],
            ['id' => 'openai/gpt-4.1',                    'label' => 'GPT-4.1',                'group' => 'OpenAI'],
            ['id' => 'openai/gpt-4.1-mini',               'label' => 'GPT-4.1 mini',           'group' => 'OpenAI'],
            ['id' => 'openai/gpt-4o-mini',                'label' => 'GPT-4o mini',            'group' => 'OpenAI'],
            ['id' => 'google/gemini-2.5-pro',             'label' => 'Gemini 2.5 Pro',         'group' => 'Google'],
            ['id' => 'google/gemini-2.5-flash',           'label' => 'Gemini 2.5 Flash',       'group' => 'Google'],
            ['id' => 'google/gemini-2.5-flash-lite',      'label' => 'Gemini 2.5 Flash Lite',  'group' => 'Google'],
            ['id' => 'deepseek/deepseek-chat-v3.1',       'label' => 'DeepSeek V3.1',          'group' => 'DeepSeek'],
            ['id' => 'deepseek/deepseek-r1',              'label' => 'DeepSeek R1',            'group' => 'DeepSeek'],
            ['id' => 'qwen/qwen3-235b-a22b',              'label' => 'Qwen3 235B',             'group' => 'Qwen'],
            ['id' => 'qwen/qwen-2.5-72b-instruct',        'label' => 'Qwen 2.5 72B',           'group' => 'Qwen'],
            ['id' => 'meta-llama/llama-3.3-70b-instruct', 'label' => 'Llama 3.3 70B',          'group' => 'Meta'],
            ['id' => 'mistralai/mistral-large',           'label' => 'Mistral Large',          'group' => 'Mistral'],
            ['id' => 'x-ai/grok-4',                       'label' => 'Grok 4',                 'group' => 'xAI'],
        ],
    ];

    public const PROVIDER_LABELS = ['yandex' => 'Yandex Foundation Models', 'openrouter' => 'OpenRouter'];

    /** Live OpenRouter catalog, refreshed by a button and cached here. */
    private const OR_CACHE_KEY = 'openrouter_models';

    private static array $cfg = [];
    private static array $providers = [];
    /** One-shot model override: ['provider' => …, 'model' => …] — see useModel(). */
    private static ?array $override = null;
    /** Last raw HTTP exchange per provider, for the diagnostics card. */
    private static array $lastHttp = [];

    // Init with config array
    public static function init(array $cfg): void {
        self::$cfg = $cfg;
        self::$providers = array_values(array_filter(
            array_map('trim', explode(',', (string)($cfg['LLM_PROVIDER_PRIORITY'] ?? 'openrouter')))
        ));
    }

    /**
     * Answer the next calls with this exact model instead of the fallback chain.
     * The manager picks it in the reply window; the choice lives for one request
     * only and never becomes a stored setting.
     */
    public static function useModel(?string $provider, ?string $model): void {
        $provider = trim((string)$provider);
        $model    = trim((string)$model);
        self::$override = ($provider !== '' && $model !== '' && isset(self::CATALOG[$provider]))
            ? ['provider' => $provider, 'model' => $model]
            : null;
    }

    /** «provider:slug» as the reply window sends it. */
    public static function useModelSpec(?string $spec): void {
        $spec = trim((string)$spec);
        if ($spec === '') { self::$override = null; return; }
        [$provider, $model] = array_pad(explode(':', $spec, 2), 2, '');
        self::useModel($provider, $model);
    }

    public static function currentModel(): array {
        if (self::$override) return self::$override;
        $provider = self::$providers[0] ?? 'openrouter';
        return ['provider' => $provider, 'model' => self::modelOf($provider)];
    }

    private static function modelOf(string $provider): string {
        if (self::$override && self::$override['provider'] === $provider) return self::$override['model'];
        return (string)(self::$cfg[$provider === 'yandex' ? 'YANDEX_MODEL' : 'OPENROUTER_MODEL'] ?? '');
    }

    /** Providers in their fallback order, with model and key status for the panel. */
    public static function status(): array {
        $out = [];
        foreach (array_keys(self::CATALOG) as $provider) {
            $key = $provider === 'yandex' ? 'YANDEX_API_KEY' : 'OPENROUTER_API_KEY';
            $modelKey = $provider === 'yandex' ? 'YANDEX_MODEL' : 'OPENROUTER_MODEL';
            $pos = array_search($provider, self::$providers, true);
            $out[] = [
                'provider' => $provider,
                'label'    => self::PROVIDER_LABELS[$provider] ?? $provider,
                'enabled'  => $pos !== false,
                'order'    => $pos === false ? null : $pos + 1,
                'model'    => (string)(self::$cfg[$modelKey] ?? ''),
                'models'   => self::catalog($provider),
                'key_set'  => (string)(self::$cfg[$key] ?? '') !== '',
                'ready'    => self::ready($provider),
            ];
        }
        return $out;
    }

    /**
     * Model list for the picker: the built-in catalog plus whatever the last
     * «Обновить каталог OpenRouter» downloaded. No network call happens here —
     * the cached list is read from settings on every request, like NeuroPro does.
     */
    public static function catalog(string $provider): array {
        $rows = self::CATALOG[$provider] ?? [];
        if ($provider !== 'openrouter') return $rows;

        $seen = array_column($rows, 'id');
        foreach (self::cachedOpenRouterModels() as $m) {
            if (in_array($m['id'], $seen, true)) continue;
            $rows[] = $m;
        }
        return $rows;
    }

    /** What the last catalog refresh stored: rows + when it happened. */
    public static function openRouterCache(): array {
        $raw = Db::val("SELECT value FROM settings WHERE key=?", [self::OR_CACHE_KEY]);
        $data = $raw ? json_decode((string)$raw, true) : null;
        return is_array($data) ? $data + ['models' => [], 'synced_at' => null] : ['models' => [], 'synced_at' => null];
    }

    private static function cachedOpenRouterModels(): array {
        $rows = [];
        foreach ((array)(self::openRouterCache()['models'] ?? []) as $m) {
            if (!is_array($m) || empty($m['id'])) continue;
            $rows[] = [
                'id'    => (string)$m['id'],
                'label' => (string)($m['label'] ?? $m['id']),
                'group' => (string)($m['group'] ?? 'OpenRouter'),
            ];
        }
        return $rows;
    }

    /**
     * Download the real OpenRouter catalog and store it. Free models get their
     * own group, so the operator can pick one without reading price tables.
     */
    public static function refreshOpenRouterModels(): array {
        [$code, $body] = self::httpGet(self::openRouterBase() . '/models', self::openRouterHeaders(), 'openrouter');
        if ($code >= 400 || $body === '') {
            throw new LLMException(self::explain('openrouter', $code, $body));
        }
        $data = json_decode($body, true);
        $list = $data['data'] ?? $data['models'] ?? (is_array($data) ? $data : []);
        if (!is_array($list)) throw new LLMException('OpenRouter вернул неожиданный формат каталога');

        $rows = [];
        foreach ($list as $m) {
            $id = is_array($m) ? (string)($m['id'] ?? '') : (string)$m;
            if (!preg_match('~^[\w.\-]+/[\w.\-:]+$~', $id)) continue;
            $name  = is_array($m) ? trim((string)($m['name'] ?? '')) : '';
            $price = is_array($m) ? (float)($m['pricing']['prompt'] ?? 0) + (float)($m['pricing']['completion'] ?? 0) : 0.0;
            $free  = $price <= 0 || str_ends_with($id, ':free');
            $rows[] = [
                'id'    => $id,
                'label' => ($name !== '' ? $name : $id) . ($free ? ' — бесплатно' : ''),
                'group' => $free ? 'OpenRouter · бесплатные' : 'OpenRouter · ' . (explode('/', $id)[0]),
            ];
        }
        if (!$rows) throw new LLMException('В ответе OpenRouter не нашлось ни одной модели');

        usort($rows, fn($a, $b) => [$a['group'], $a['label']] <=> [$b['group'], $b['label']]);
        $payload = ['models' => $rows, 'synced_at' => date('Y-m-d H:i:s')];
        Db::q("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
              [self::OR_CACHE_KEY, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        return $payload;
    }

    public static function forgetOpenRouterModels(): void {
        Db::q("DELETE FROM settings WHERE key=?", [self::OR_CACHE_KEY]);
    }

    public static function ready(string $provider): bool {
        return match ($provider) {
            'yandex'     => (string)(self::$cfg['YANDEX_API_KEY'] ?? '') !== '' && (string)(self::$cfg['YANDEX_FOLDER_ID'] ?? '') !== '',
            'openrouter' => (string)(self::$cfg['OPENROUTER_API_KEY'] ?? '') !== '',
            default      => false,
        };
    }

    /** One short round-trip — the «проверить нейросеть» button in the panel. */
    public static function test(string $provider): array {
        $started = microtime(true);
        $answer = match ($provider) {
            'openrouter' => self::callOpenRouter('Отвечай одним словом.', 'Скажи «готово».', 0, false),
            'yandex'     => self::callYandex('Отвечай одним словом.', 'Скажи «готово».', 0, false),
            default      => throw new LLMException("Неизвестный провайдер: $provider"),
        };
        return [
            'provider' => $provider,
            'model'    => self::modelOf($provider),
            'answer'   => mb_substr(trim($answer), 0, 200),
            'ms'       => (int)round((microtime(true) - $started) * 1000),
            'route'    => self::routeLabel($provider),
        ];
    }

    /**
     * Where the request actually lands. A key can be perfectly valid and still
     * get a 403 from something on the way (hosting WAF, national filtering) —
     * this probe separates «ключ не тот» from «запрос не доехал».
     */
    public static function diagnose(string $provider): array {
        $url = $provider === 'yandex'
            ? 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion'
            : self::openRouterBase() . '/models';
        $headers = $provider === 'yandex'
            ? ['Authorization: Api-Key ' . (string)(self::$cfg['YANDEX_API_KEY'] ?? '')]
            : self::openRouterHeaders();

        $started = microtime(true);
        [$code, $body, $err] = self::httpGet($url, $headers, $provider, true);
        $intercepted = self::looksIntercepted($code, $body);

        return [
            'provider'    => $provider,
            'url'         => $url,
            'route'       => self::routeLabel($provider),
            'http_code'   => $code,
            'curl_error'  => $err,
            'body_head'   => mb_substr(trim($body), 0, 300),
            'intercepted' => $intercepted,
            'ms'          => (int)round((microtime(true) - $started) * 1000),
            'hint'        => self::explain($provider, $code, $body, $err),
        ];
    }

    /** «напрямую» / «через прокси …» — printed next to every probe result. */
    public static function routeLabel(string $provider): string {
        $p = self::proxyFor($provider);
        return $p === null ? 'напрямую' : 'через прокси ' . preg_replace('~^\w+://~', '', $p['proxy']);
    }

    /**
     * Proxy address + credentials for one provider's requests, or null when
     * this provider talks to its API directly (item 6 of the mobile/UX pass).
     * Both providers share one proxy address and one set of credentials
     * (LLM_PROXY / LLM_PROXY_AUTH) — only whether it is actually used is
     * per-provider: OpenRouter is typically blocked from a Russian IP, so its
     * toggle defaults on; Yandex Cloud is usually reachable directly from a
     * Russian host, so its toggle defaults off.
     */
    public static function proxyFor(string $provider): ?array {
        $toggleKey = $provider === 'yandex' ? 'LLM_PROXY_YANDEX' : 'LLM_PROXY_OPENROUTER';
        $default = $provider === 'yandex' ? 0 : 1;
        $enabled = (int)(self::$cfg[$toggleKey] ?? $default) === 1;
        if (!$enabled) return null;
        $proxy = trim((string)(self::$cfg['LLM_PROXY'] ?? ''));
        if ($proxy === '') return null;
        return ['proxy' => $proxy, 'auth' => trim((string)(self::$cfg['LLM_PROXY_AUTH'] ?? ''))];
    }

    // Text completion — returns plain string
    public static function chatText(string $system, string $user, float $temp = 0.3): string {
        $raw = self::call($system, $user, $temp, false);
        // Strip <think>...</think> tags
        $raw = preg_replace('/<think>.*?<\/think>/s', '', $raw);
        // Strip markdown fences
        $raw = preg_replace('/^```\w*\n|```$/m', '', $raw);
        return trim($raw);
    }

    // JSON completion — returns parsed array
    public static function chatJson(string $system, string $user, float $temp = 0.1): array {
        $maxRetries = 3;
        $raw = '';
        $why = 'пустой ответ';
        for ($i = 0; $i < $maxRetries; $i++) {
            $raw = self::call($system, $user, $temp, true);
            // Strip markdown fences
            $raw = preg_replace('/^```\w*\n|```$/m', '', trim($raw));
            // Strip <think> tags
            $raw = preg_replace('/<think>.*?<\/think>/s', '', $raw);
            $raw = trim($raw);
            $data = json_decode($raw, true);
            // Models like to wrap the object in a sentence — take the object itself
            if (!is_array($data) && preg_match('/[{\[].*[}\]]/s', $raw, $m)) {
                $data = json_decode($m[0], true);
            }
            if (is_array($data)) return $data;
            // json_last_error() is reset by any json_encode() further down (logging,
            // for one), so the reason has to be captured right here
            $why = json_last_error() === JSON_ERROR_NONE ? 'ответ не является объектом JSON' : json_last_error_msg();
            // Retry with lower temp
            $temp = 0.05;
        }
        Logger::error('llm', 'Модель вернула не-JSON после ' . $maxRetries . ' попыток: ' . $why, ['tail' => mb_substr($raw, -400)]);
        throw new LLMException("Failed to parse JSON after $maxRetries attempts: $why");
    }

    // Core call with fallback chain
    private static function call(string $system, string $user, float $temp, bool $jsonMode): string {
        // A model picked by hand answers alone: falling back to another one would
        // quietly ignore the choice the manager just made
        $chain = self::$override ? [self::$override['provider']] : self::$providers;

        $lastErr = null;
        foreach ($chain as $provider) {
            try {
                return match ($provider) {
                    'openrouter' => self::callOpenRouter($system, $user, $temp, $jsonMode),
                    'yandex' => self::callYandex($system, $user, $temp, $jsonMode),
                    default => throw new LLMException("Unknown provider: $provider"),
                };
            } catch (LLMException $e) {
                $lastErr = $e;
                Logger::warning('llm', "Провайдер $provider не ответил: " . $e->getMessage(), ['provider' => $provider]);
                // Continue to next provider
            }
        }
        $message = 'All LLM providers failed: ' . ($lastErr ? $lastErr->getMessage() : 'none configured');
        Logger::error('llm', $message, ['providers' => $chain]);
        throw new LLMException($message);
    }

    /** Base address of the OpenRouter API — a mirror can be put here instead. */
    private static function openRouterBase(): string {
        $base = trim((string)(self::$cfg['OPENROUTER_BASE_URL'] ?? ''));
        return rtrim($base !== '' ? $base : 'https://openrouter.ai/api/v1', '/');
    }

    private static function openRouterHeaders(): array {
        return [
            'Authorization: Bearer ' . (string)(self::$cfg['OPENROUTER_API_KEY'] ?? ''),
            'HTTP-Referer: ' . (string)(self::$cfg['APP_URL'] ?? ''),
            'X-Title: Atlant Armour KP',
        ];
    }

    // OpenRouter API call
    private static function callOpenRouter(string $system, string $user, float $temp, bool $jsonMode): string {
        $key = self::$cfg['OPENROUTER_API_KEY'] ?? '';
        if (!$key) throw new LLMException('OPENROUTER_API_KEY not set');
        $model = self::modelOf('openrouter') ?: 'google/gemini-2.5-flash';

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $temp,
        ];
        if ($jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return self::httpPost(self::openRouterBase() . '/chat/completions', $body, self::openRouterHeaders(), 'openrouter');
    }

    // Yandex Foundation Models API call
    private static function callYandex(string $system, string $user, float $temp, bool $jsonMode): string {
        $key = self::$cfg['YANDEX_API_KEY'] ?? '';
        $folder = self::$cfg['YANDEX_FOLDER_ID'] ?? '';
        if (!$key || !$folder) throw new LLMException('YANDEX_API_KEY or YANDEX_FOLDER_ID not set');
        $model = self::modelOf('yandex') ?: 'yandexgpt/latest';
        $uri = "gpt://$folder/$model";

        $body = [
            'modelUri' => $uri,
            'completionOptions' => [
                'stream' => false,
                'temperature' => $temp,
                'maxTokens' => 4096,
            ],
            'messages' => [
                ['role' => 'system', 'text' => $system],
                ['role' => 'user', 'text' => $user],
            ],
        ];
        if ($jsonMode) {
            $body['completionOptions']['responseFormat'] = ['type' => 'json_object'];
        }

        return self::httpPost(
            'https://llm.api.cloud.yandex.net/foundationModels/v1/completion',
            $body,
            ['Authorization: Api-Key ' . $key, 'x-folder-id: ' . $folder],
            'yandex'
        );
    }

    /**
     * Outbound proxy for every model request. On a Russian shared host the API
     * itself is reachable only through one — without it the request is answered
     * by the filter on the way, not by the provider.
     */
    private static function applyTransport(\CurlHandle $ch, string $provider): void {
        $p = self::proxyFor($provider);
        if ($p === null) return;

        curl_setopt($ch, CURLOPT_PROXY, $p['proxy']);
        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
        // socks5h:// in the URL makes cURL resolve the host on the proxy side —
        // the point of the exercise when local DNS is the thing being poisoned
        if (str_starts_with($p['proxy'], 'socks5h://') || str_starts_with($p['proxy'], 'socks5://')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        }
        if ($p['auth'] !== '') curl_setopt($ch, CURLOPT_PROXYUSERPWD, $p['auth']);
    }

    private static function timeout(): int {
        return max(5, (int)(self::$cfg['LLM_TIMEOUT_SEC'] ?? 30));
    }

    /** GET used by the catalog refresh and the connectivity probe. */
    private static function httpGet(string $url, array $headers, string $provider, bool $short = false): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $short ? min(15, self::timeout()) : self::timeout(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'AtlantArmourKP/1.0',
        ]);
        self::applyTransport($ch, $provider);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $body = $resp === false ? '' : (string)$resp;
        self::$lastHttp[$provider] = ['code' => $code, 'body' => mb_substr($body, 0, 500), 'error' => $err];
        return [$code, $body, $err];
    }

    // HTTP POST with cURL, parse provider response
    private static function httpPost(string $url, array $body, array $headers, string $provider): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::timeout(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'AtlantArmourKP/1.0',
        ]);
        self::applyTransport($ch, $provider);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        self::$lastHttp[$provider] = ['code' => (int)$code, 'body' => mb_substr((string)$resp, 0, 500), 'error' => $err];

        if ($resp === false) throw new LLMException(self::explain($provider, 0, '', $err));
        if ($code >= 400) throw new LLMException(self::explain($provider, (int)$code, (string)$resp));

        $data = json_decode($resp, true);
        if (!$data) throw new LLMException("$provider: invalid JSON response");

        // Extract text from provider-specific format
        if ($provider === 'openrouter') {
            return $data['choices'][0]['message']['content']
                ?? throw new LLMException('openrouter: no content in response');
        }
        if ($provider === 'yandex') {
            return $data['result']['alternatives'][0]['message']['text']
                ?? throw new LLMException('yandex: no text in response');
        }
        throw new LLMException("Unknown provider: $provider");
    }

    /**
     * A refusal written by something that is not the provider: no model API
     * answers «Access denied by security policy», and none of them speak HTML.
     */
    private static function looksIntercepted(int $code, string $body): bool {
        if ($code === 0) return true;                       // never connected at all
        if ($code < 400) return false;
        $head = mb_strtolower(mb_substr(trim($body), 0, 400));
        if ($head === '') return true;
        if (str_starts_with($head, '<')) return true;        // an HTML block page
        foreach (['security policy', 'access denied', 'заблокирован', 'ограничен доступ',
                  'blocked', 'forbidden by', 'proxy'] as $needle) {
            if (str_contains($head, $needle)) return true;
        }
        return false;
    }

    /** Error text a non-developer can act on. */
    private static function explain(string $provider, int $code, string $body, string $curlError = ''): string {
        $detail = mb_substr(trim($body), 0, 400);
        $head = "$provider returned HTTP $code" . ($detail !== '' ? ": $detail" : '');
        if ($curlError !== '') $head = "cURL error ($provider): $curlError";

        $route = self::routeLabel($provider);
        if (self::looksIntercepted($code, $body)) {
            $toggle = $provider === 'yandex' ? 'LLM_PROXY_YANDEX' : 'LLM_PROXY_OPENROUTER';
            return $head . '. Так отвечает не сам провайдер, а фильтр на пути запроса '
                 . "($route): ключ здесь ни при чём. Помогает прокси вне фильтрации — "
                 . "«Настройки → Нейросети → Доступ к API» (адрес в LLM_PROXY, включить для $provider — $toggle), "
                 . 'либо свой зеркальный адрес API в OPENROUTER_BASE_URL.';
        }
        if ($code === 401) return $head . '. Ключ неверный, отозван или скопирован не целиком.';
        if ($code === 402) return $head . '. На счёте провайдера нет средств.';
        if ($code === 404) return $head . '. Такой модели у провайдера нет — проверьте слаг в каталоге.';
        if ($code === 429) return $head . '. Провайдер ограничил частоту запросов — попробуйте позже или смените модель.';
        return $head;
    }
}

class LLMException extends RuntimeException {}
