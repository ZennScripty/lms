<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function write(string $level, string $message): void
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $line = sprintf("[%s] %s: %s\n", date('c'), strtoupper($level), str_replace(["\r", "\n"], ' ', $message));
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(\Throwable $e): void
    {
        self::write('error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }

    /** Writes a row to audit_logs. Never throws. */
    public static function audit(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
    {
        try {
            Database::run(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address, created_at) VALUES (?,?,?,?,?,?,NOW())',
                [$userId, $action, $entityType, $entityId, $details !== null ? json_encode(['details' => mb_substr($details, 0, 500)], JSON_THROW_ON_ERROR) : null, client_ip()]
            );
        } catch (\Throwable $e) {
            self::error($e);
        }
    }
}
