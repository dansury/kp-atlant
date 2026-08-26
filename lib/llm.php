<?php
/**
 * LLM wrapper (adapted from NeuroPro lib/llm.php).
 * Dual-provider: OpenRouter + Yandex FM. Fallback chain, json-mode, retry.
 */
class LLM {
    private static array $cfg = [];
    private static array $providers = [];

    // Init with config array
    public static function init(array $cfg): void {
        self::$cfg = $cfg;
        self::$providers = array_filter(
            array_map('trim', explode(',', $cfg['LLM_PROVIDER_PRIORITY'] ?? 'openrouter'))
        );
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
        for ($i = 0; $i < $maxRetries; $i++) {
            $raw = self::call($system, $user, $temp, true);
            // Strip markdown fences
            $raw = preg_replace('/^```\w*\n|```$/m', '', trim($raw));
            // Strip <think> tags
            $raw = preg_replace('/<think>.*?<\/think>/s', '', $raw);
            $raw = trim($raw);
            $data = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) return $data;
            // Retry with lower temp
            $temp = 0.05;
        }
        throw new LLMException("Failed to parse JSON after $maxRetries attempts: " . json_last_error_msg());
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
                // Continue to next provider
            }
        }
        throw new LLMException('All LLM providers failed: ' . ($lastErr ? $lastErr->getMessage() : 'none configured'));
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
        if ($code >= 500 || $code === 429) throw new LLMException("$provider returned HTTP $code");

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
