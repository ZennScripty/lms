<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function verify(?string $token): bool
    {
        return is_string($token) && $token !== ''
            && isset($_SESSION['csrf']) && is_string($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    /** Verifies the token from POST field or X-CSRF-Token header; terminates the request on failure. */
    public static function verifyRequest(): void
    {
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::verify(is_string($token) ? $token : null)) {
            if (wants_json()) {
                json_response(['ok' => false, 'error' => 'csrf_invalid'], 419);
            }
            http_response_code(419);
            header('HTTP/1.1 419 Page Expired');
            exit('Security token invalid or expired. Please reload the page and try again.');
        }
    }
}
