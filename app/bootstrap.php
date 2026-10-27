<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use App\Core\{Auth, Csrf, Database, Logger};
use App\Services\TranslationService;

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Kolkata') ?: 'Asia/Kolkata');
ini_set('display_errors', env('APP_DEBUG', '0') === '1' ? '1' : '0');
error_reporting(E_ALL);

set_exception_handler(static function (\Throwable $e): void {
    Logger::error($e);
    http_response_code(500);
    if (wants_json()) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'server_error']);
    } else {
        echo 'Something went wrong. Please try again later.';
    }
});

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('TLMTI_SESS');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
\App\Core\SessionStore::configure();
if (!session_start()) {
    throw new \RuntimeException('Session storage is unavailable.');
}

// Global same-page actions (handled by whichever page is open): logout, set_language
if (is_post() && in_array(request_action(), ['logout', 'set_language'], true)) {
    Csrf::verifyRequest();
    if (request_action() === 'logout') {
        Auth::logout();
        redirect('login.php');
    }
    $code = (string) ($_POST['language'] ?? '');
    if (TranslationService::isEnabled($code)) {
        $_SESSION['lang'] = $code;
        if (Auth::user()) {
            Database::run('UPDATE users SET preferred_language_id = (SELECT id FROM languages WHERE code = ?) WHERE id = ?', [$code, Auth::user()['id']]);
        }
    }
    redirect_self();
}

// Page opens only read attendance. In/Out are explicit CSRF-protected POST actions.
