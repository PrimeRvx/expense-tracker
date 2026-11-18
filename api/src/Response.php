<?php
declare(strict_types=1);

final class Response
{
    public static function securityHeaders(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; frame-ancestors 'none'; base-uri 'none'");
    }

    /** @param mixed $data */
    public static function ok($data = null, int $status = 200): void
    {
        self::json(['ok' => true, 'data' => $data], $status);
    }

    /** @param array<string,mixed> $extra */
    public static function error(int $status, string $code, string $message, array $extra = []): void
    {
        self::json(array_merge(['ok' => false, 'code' => $code, 'message' => $message], $extra), $status);
    }

    /** @param array<string,mixed> $payload */
    private static function json(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
