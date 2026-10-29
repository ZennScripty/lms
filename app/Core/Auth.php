<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    /** Current authenticated user (re-read from DB each request) or null. */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return null;
        }
        $timeout = max(5, (int) setting('session_timeout_minutes', 120)) * 60;
        if (time() - (int) ($_SESSION['last_activity'] ?? 0) > $timeout) {
            self::destroySession();
            return null;
        }
        $user = Database::one(
            "SELECT u.id, u.full_name AS name, u.email, u.username, u.role_id, u.centre_id, u.batch_id, u.profile_asset_id, u.status, l.code AS preferred_language,
                    CASE r.code WHEN 'admin' THEN 'centre_admin' WHEN 'teacher' THEN 'subject_teacher' ELSE r.code END AS role_slug, r.code AS role_code, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN languages l ON l.id = u.preferred_language_id WHERE u.id = ? AND u.deleted_at IS NULL",
            [(int) $id]
        );
        if (!$user || $user['status'] !== 'active') {
            self::destroySession();
            return null;
        }
        $_SESSION['last_activity'] = time();
        return self::$user = $user;
    }

    /** Returns the user or redirects / answers 401 JSON. */
    public static function requireLogin(): array
    {
        $u = self::user();
        if ($u) {
            return $u;
        }
        if (wants_json()) {
            json_response(['ok' => false, 'error' => 'auth_required'], 401);
        }
        redirect('login.php');
    }

    public static function attempt(string $login, string $password): bool
    {
        $login = trim($login);
        $ip = client_ip();
        $max = max(3, (int) setting('login_max_attempts', 5));
        $window = max(1, (int) setting('login_lockout_minutes', 15));
        $key = 'login:' . strtolower(mb_substr($login, 0, 100));

        $failed = (int) Database::val(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'login_failed' AND created_at > (NOW() - INTERVAL ? MINUTE) AND (ip_address = ? OR JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.details')) = ?)",
            [$window, $ip, $key]
        );
        if ($failed >= $max) {
            throw new \DomainException("Too many failed attempts. Try again in $window minutes.");
        }

        $user = $login === '' ? null : Database::one(
            "SELECT id, password_hash FROM users WHERE (username = ? OR email = ?) AND status = 'active' AND deleted_at IS NULL LIMIT 1",
            [$login, $login]
        );
        // Constant-ish timing when user does not exist
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringforsaltuYQ3N8uZ1kA7jZ8b3Yg1Qk2xH5v0Ox5e';
        $ok = password_verify($password, $hash) && $user !== null;

        if (!$ok) {
            Logger::audit($user ? (int) $user['id'] : null, 'login_failed', 'user', $user ? (int) $user['id'] : null, $key);
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        $chosenLanguage = $_SESSION['lang'] ?? null;
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int) $user['id'], 'last_activity' => time()];
        if (in_array($chosenLanguage, ['en', 'hi'], true)) {
            $_SESSION['lang'] = $chosenLanguage;
            Database::run('UPDATE users SET preferred_language_id = (SELECT id FROM languages WHERE code = ?) WHERE id = ?', [$chosenLanguage, $user['id']]);
        }
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$loaded = false;
        self::$user = null;
        Logger::audit((int) $user['id'], 'login_success', 'user', (int) $user['id']);
        return true;
    }

    public static function logout(): void
    {
        $u = self::user();
        if ($u) {
            Logger::audit((int) $u['id'], 'logout', 'user', (int) $u['id']);
        }
        self::destroySession();
    }

    private static function destroySession(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            }
            session_destroy();
        }
        self::$user = null;
        self::$loaded = true;
    }
}
