<?php
declare(strict_types=1);

use App\Core\{Auth, Authorization, Csrf, Database, Logger};
use App\Services\TranslationService;

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function request_action(): string
{
    $a = $_POST['action'] ?? $_GET['action'] ?? '';
    return is_string($a) ? preg_replace('/[^a-z_]/', '', $a) : '';
}

function wants_json(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    if ($code === 419) { header('HTTP/1.1 419 Page Expired'); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function current_page(): string
{
    $p = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    return $p === '' ? 'index.php' : $p;
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Post/Redirect/Get back to the same page (keeps GET query, drops "action"). */
function redirect_self(): never
{
    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
    unset($q['action']);
    redirect(current_page() . ($q ? '?' . http_build_query($q) : ''));
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(Csrf::token()) . '">';
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_render(): string
{
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $html .= '<div class="flash flash-' . e($f['type']) . '" role="status"'.($f['type']==='success'?' data-success-message':'').'>' . e(tr($f['message'])) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/** Turns an exception into a user-safe message; logs unexpected errors. */
function handle_exception(\Throwable $e): string
{
    if ($e instanceof \InvalidArgumentException || $e instanceof \DomainException) {
        return $e->getMessage();
    }
    if ($e instanceof \PDOException && ($e->errorInfo[0] ?? '') === '23000') {
        Logger::error($e);
        return 'That record already exists or is still in use by other data.';
    }
    Logger::error($e);
    return 'Something went wrong. Please try again.';
}

function forbidden(string $message = 'You do not have access to this resource.'): never
{
    if (wants_json()) {
        json_response(['ok' => false, 'error' => 'forbidden'], 403);
    }
    http_response_code(403);
    page_header('Access denied');
    echo '<div class="card"><h2>Access denied</h2><p>' . e($message) . '</p><p><a href="dashboard.php">Back to dashboard</a></p></div>';
    page_footer();
    exit;
}

function not_found(string $message = 'Not found.'): never
{
    if (wants_json()) {
        json_response(['ok' => false, 'error' => 'not_found'], 404);
    }
    http_response_code(404);
    page_header('Not found');
    echo '<div class="card"><h2>Not found</h2><p>' . e($message) . '</p><p><a href="dashboard.php">Back to dashboard</a></p></div>';
    page_footer();
    exit;
}

function setting(string $key, mixed $default = null): mixed
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (Database::all('SELECT setting_key, setting_value FROM app_settings') as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null ? $cache[$key] : $default;
}

function t(string $key, ?string $default = null): string
{
    return TranslationService::ui($key, $default);
}

function fmt_dt(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? fmt_date($dt) . ', ' . date('H:i', $ts) : '—';
}

function fmt_date(?string $dt): string
{
    $ts=$dt?strtotime($dt):false;
    return $ts ? date('d', $ts) . ' ' . tr(date('M', $ts)) . ' ' . date('Y', $ts) : '—';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(bool $c): string
{
    return $c ? ' checked' : '';
}

require_once __DIR__ . '/ui.php';
