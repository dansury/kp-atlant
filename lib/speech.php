<?php
/**
 * Голосовой ввод письма (модуль 058): запись из браузера → текст.
 *
 * Решение взято из dansury/kraskiweb (`Ai::transcribeVoice`): Yandex SpeechKit
 * STT v1, короткое распознавание. v1 читает только OggOpus / LPCM / MP3, а
 * Chrome и Android пишут Opus в WebM — его перекладываем в Ogg сами
 * (`AudioRemux`, без ffmpeg на хостинге). Ключ и каталог — те же, что у
 * YandexGPT; сервисному аккаунту нужна роль `ai.speechkit-stt.user`.
 */
require_once __DIR__ . '/audio_remux.php';

final class Speech {

    public const STT_URL = 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize';
    /** Предел v1 для короткого распознавания — 1 МБ и 30 секунд. */
    public const MAX_BYTES = 1048576;

    /** @var ?callable(string $bytes, string $format): array{code:int,body:string} подмена HTTP в тестах */
    public static $http = null;

    public static function ready(): bool {
        return trim((string)Settings::get('YANDEX_API_KEY', '')) !== ''
            && trim((string)Settings::get('YANDEX_FOLDER_ID', '')) !== '';
    }

    /**
     * Текст записи. Бросает исключение со словами для человека: пустой ответ
     * должен объяснить, почему поле осталось пустым.
     */
    public static function transcribe(string $path, string $mime): string {
        if (!self::ready()) {
            throw new RuntimeException('Голосовой ввод работает через Yandex SpeechKit — укажите ключ и Folder ID Yandex в «Настройки → Нейросети»');
        }
        $bytes = (string)@file_get_contents($path);
        if ($bytes === '') throw new RuntimeException('Запись пустая');

        // WebM → Ogg: иначе v1 не прочитает запись Chrome/Android
        if (str_contains(strtolower($mime), 'webm')) {
            try {
                $ogg = AudioRemux::webmOpusToOggOpus($bytes);
            } catch (Throwable $e) {
                $ogg = null;
                Logger::warning('speech', 'WebM не переложился в Ogg: ' . $e->getMessage());
            }
            if ($ogg) {
                $text = self::recognize($ogg, 'oggopus');
                if ($text !== '') return $text;
            }
        }
        $text = self::recognize($bytes, self::format($mime));
        if ($text === '') throw new RuntimeException('Речь не распознана — скажите ещё раз, чуть ближе к микрофону');
        return $text;
    }

    /** Формат SpeechKit v1 по MIME записи; по умолчанию — oggopus. */
    public static function format(string $mime): string {
        $m = strtolower($mime);
        if (str_contains($m, 'mp3') || str_contains($m, 'mpeg')) return 'mp3';
        if (str_contains($m, 'lpcm') || str_contains($m, 'pcm')) return 'lpcm';
        return 'oggopus';
    }

    private static function recognize(string $bytes, string $format): string {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Запись длиннее 30 секунд — надиктуйте письмо частями');
        }
        $r = self::$http ? (self::$http)($bytes, $format) : self::post($bytes, $format);
        if ($r['code'] >= 400 || $r['code'] === 0) {
            $j = json_decode($r['body'], true);
            $why = is_array($j) ? (string)($j['error_message'] ?? $j['message'] ?? '') : trim(mb_substr($r['body'], 0, 200));
            Logger::error('speech', 'SpeechKit не распознал запись: HTTP ' . $r['code'],
                          ['format' => $format, 'answer' => $why]);
            // Формат не подошёл — вызывающий попробует исходные байты
            if ($r['code'] === 400 && $format === 'oggopus') return '';
            throw new RuntimeException('SpeechKit ответил HTTP ' . $r['code'] . ($why !== '' ? ': ' . $why : ''));
        }
        $j = json_decode($r['body'], true);
        return is_array($j) ? trim((string)($j['result'] ?? '')) : '';
    }

    /** @return array{code:int,body:string} */
    private static function post(string $bytes, string $format): array {
        $url = self::STT_URL . '?' . http_build_query([
            'folderId' => trim((string)Settings::get('YANDEX_FOLDER_ID', '')),
            'lang'     => 'ru-RU',
            'format'   => $format,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Api-Key ' . trim((string)Settings::get('YANDEX_API_KEY', ''))],
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $p = LLM::proxyFor('yandex');
        if ($p) {
            curl_setopt($ch, CURLOPT_PROXY, $p['proxy']);
            if (str_starts_with($p['proxy'], 'socks5')) curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            if ($p['auth'] !== '') curl_setopt($ch, CURLOPT_PROXYUSERPWD, $p['auth']);
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'body' => $body === false ? $err : (string)$body];
    }
}
