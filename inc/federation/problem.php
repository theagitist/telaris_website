<?php
declare(strict_types=1);

/**
 * RFC 9457 Problem Details emitter for the Pluriverse federation surface.
 *
 * Extracted from router.php so endpoint handlers can be exercised in
 * isolation by the PHPUnit suite (the handlers assume their caller has
 * defined federation_router_problem; in production that caller is
 * router.php, in tests it is the handler harness). Behaviour is identical
 * to the inline definition that lived in router.php through stage 6.
 */

if (!function_exists('federation_router_problem')) {
    /**
     * Emit an RFC 9457 Problem Details JSON error and set headers.
     */
    function federation_router_problem(int $status, string $code, string $detail, string $instance): void {
        http_response_code($status);
        header('Content-Type: application/problem+json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        echo json_encode([
            'type' => 'https://www.telaris.ca/docs/errors/' . $code,
            'title' => match ($status) {
                400 => 'Bad Request',
                401 => 'Unauthorized',
                404 => 'Not Found',
                405 => 'Method Not Allowed',
                409 => 'Conflict',
                413 => 'Payload Too Large',
                422 => 'Unprocessable Content',
                429 => 'Too Many Requests',
                500 => 'Internal Server Error',
                502 => 'Bad Gateway',
                503 => 'Service Unavailable',
                default => 'Error',
            },
            'status' => $status,
            'detail' => $detail,
            'instance' => $instance,
            'code' => $code,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
