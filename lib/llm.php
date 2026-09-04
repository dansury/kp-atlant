<?php
/**
 * LLM wrapper (adapted from NeuroPro lib/llm.php).
 * Dual-provider: OpenRouter + Yandex FM. Fallback chain, json-mode, retry.
 */
class LLM {
    /**
     * Model catalog shown in the admin panel (same idea as NeuroPro AVAILABLE_MODELS).
     * The field is a plain list: any slug can still be typed by hand.
     */
    public const CATALOG = [
        'yandex' => [
            ['id' => 'yandexgpt/latest',      'label' => 'YandexGPT Pro — latest'],
            ['id' => 'yandexgpt/rc',          'label' => 'YandexGPT Pro — release candidate'],
            ['id' => 'yandexgpt-lite/latest', 'label' => 'YandexGPT Lite — дешевле и быстрее'],
            ['id' => 'llama/latest',          'label' => 'Llama (Yandex AI Studio)'],
        ],
        'openrouter' => [
            ['id' => 'google/gemini-2.5-flash',            'label' => 'Gemini 2.5 Flash — быстрый и дешёвый'],
            ['id' => 'google/gemini-2.5-pro',              'label' => 'Gemini 2.5 Pro'],
            ['id' => 'anthropic/claude-sonnet-4.5',        'label' => 'Claude Sonnet 4.5'],
            ['id' => 'openai/gpt-4.1-mini',                'label' => 'GPT-4.1 mini'],
            ['id' => 'openai/gpt-4o-mini',                 'label' => 'GPT-4o mini'],
            ['id' => 'qwen/qwen-2.5-72b-instruct',         'label' => 'Qwen 2.5 72B'],
            ['id' => 'meta-llama/llama-3.3-70b-instruct',  'label' => 'Llama 3.3 70B'],
        ],
    ];

    public const PROVIDER_LABELS = ['yandex' => 'Yandex Foundation Models', 'openrouter' => 'OpenRouter'];

    private static array $cfg = [];
    private static array $providers = [];

    // Init with config array
    public static function init(array $cfg): void {
        self::$cfg = $cfg;
        self::$providers = array_values(array_filter(
            array_map('trim', explode(',', (string)($cfg['LLM_PROVIDER_PRIORITY'] ?? 'openrouter')))
        ));
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
                'models'   => self::CATALOG[$provider],
                'key_set'  => (string)(self::$cfg[$key] ?? '') !== '',
                'ready'    => self::ready($provider),
            ];
        }
        return $out;
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
            'model'    => (string)(self::$cfg[$provider === 'yandex' ? 'YANDEX_MODEL' : 'OPENROUTER_MODEL'] ?? ''),
            'answer'   => mb_substr(trim($answer), 0, 200),
            'ms'       => (int)round((microtime(true) - $started) * 1000),
        ];
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
        $lastErr = null;
        foreach (self::$providers as $provider) {
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
        Logger::error('llm', $message, ['providers' => self::$providers]);
        throw new LLMException($message);
    }

    // OpenRouter API call
    private static function callOpenRouter(string $system, string $user, float $temp, bool $jsonMode): string {
        $key = self::$cfg['OPENROUTER_API_KEY'] ?? '';
        if (!$key) throw new LLMException('OPENROUTER_API_KEY not set');
        $model = self::$cfg['OPENROUTER_MODEL'] ?? 'google/gemini-2.5-flash';

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

        return self::httpPost(
            'https://openrouter.ai/api/v1/chat/completions',
            $body,
            ['Authorization: Bearer ' . $key, 'HTTP-Referer: ' . (self::$cfg['APP_URL'] ?? '')],
            'openrouter'
        );
    }

    // Yandex Foundation Models API call
    private static function callYandex(string $system, string $user, float $temp, bool $jsonMode): string {
        $key = self::$cfg['YANDEX_API_KEY'] ?? '';
        $folder = self::$cfg['YANDEX_FOLDER_ID'] ?? '';
        if (!$key || !$folder) throw new LLMException('YANDEX_API_KEY or YANDEX_FOLDER_ID not set');
        $model = self::$cfg['YANDEX_MODEL'] ?? 'yandexgpt/latest';
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

    // HTTP POST with cURL, parse provider response
    private static function httpPost(string $url, array $body, array $headers, string $provider): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)(self::$cfg['LLM_TIMEOUT_SEC'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) throw new LLMException("cURL error ($provider): $err");
        if ($code >= 400) {
            $detail = mb_substr((string)$resp, 0, 400);
            throw new LLMException("$provider returned HTTP $code: $detail");
        }

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
}

class LLMException extends RuntimeException {}
