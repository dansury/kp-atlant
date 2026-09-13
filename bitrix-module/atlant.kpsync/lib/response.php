<?php
namespace Atlant\KpSync;

/**
 * The one shape every answer has. The КП service reads `url` from the top of
 * the object and falls back to `result[0].DETAIL_PAGE_URL`, so both are always
 * written — and an error is a 200 with `ok: false`, never an HTML error page,
 * because the service parses JSON and a Bitrix error screen is not that.
 */
final class Response
{
    public static function ok(array $payload): void
    {
        self::send(['ok' => true] + $payload);
    }

    public static function fail(string $message, int $status = 200): void
    {
        self::send(['ok' => false, 'error' => $message, 'result' => [], 'url' => ''], $status);
    }

    private static function send(array $body, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
