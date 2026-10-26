<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Database, Logger, Validator};

final class SyncService
{
    public const MAX_EVENTS = 100;

    /** Clamp a client timestamp into [now-30d, now]. */
    public static function effectiveTime(?string $iso, ?int $now = null): string
    {
        $now ??= time();
        $t = $iso ? strtotime($iso) : false;
        if ($t === false || $t > $now) {
            $t = $now;
        }
        if ($t < $now - 30 * 86400) {
            $t = $now - 30 * 86400;
        }
        return date('Y-m-d H:i:s', $t);
    }

    /** Reads and structurally validates the events from $_POST['events'] (JSON array). */
    public static function parseEvents(): array
    {
        $raw = $_POST['events'] ?? '';
        $list = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($list)) {
            throw new \InvalidArgumentException('events must be a JSON array.');
        }
        if (count($list) > self::MAX_EVENTS) {
            throw new \InvalidArgumentException('Too many events in one request.');
        }
        $events = [];
        foreach ($list as $e) {
            if (!is_array($e)) {
                continue;
            }
            $events[] = $e;
        }
        usort($events, static fn ($a, $b) => strcmp((string) ($a['client_ts'] ?? ''), (string) ($b['client_ts'] ?? '')));
        return $events;
    }

    /**
     * Idempotent processing. $apply(array $event): array{status?:string,message?:string,data?:array}
     * Throw \DomainException inside $apply to reject an event (recorded as rejected, never retried).
     *
     * @param array<int,string> $allowedTypes
     * @return array<int,array<string,mixed>>
     */
    public static function process(array $events, array $allowedTypes, int $userId, callable $apply): array
    {
        $results = [];
        foreach ($events as $ev) {
            $uuid = '';
            try {
                $uuid = Validator::uuid($ev['event_uuid'] ?? null);
                $type = Validator::str($ev['type'] ?? '', 'type', 1, 60);
                $payload = is_array($ev['payload'] ?? null) ? $ev['payload'] : [];
                $ev['event_uuid'] = $uuid;
                $ev['payload'] = $payload;

                if (!in_array($type, $allowedTypes, true)) {
                    $results[] = ['event_uuid' => $uuid, 'status' => 'rejected', 'message' => 'Unsupported event type for this page.'];
                    continue;
                }
                $existing = Database::one('SELECT user_id, status FROM activity_events WHERE event_uuid = ?', [$uuid]);
                if ($existing) {
                    $results[] = (int) $existing['user_id'] !== $userId
                        ? ['event_uuid' => $uuid, 'status' => 'rejected', 'message' => 'Event id conflict.']
                        : ['event_uuid' => $uuid, 'status' => 'duplicate', 'message' => 'Already processed.'];
                    continue;
                }
                try {
                    $res = Database::transaction(function () use ($apply, $ev, $userId, $type, $uuid, $payload) {
                        $r = $apply($ev);
                        Database::run(
                            'INSERT INTO activity_events (event_uuid, user_id, entity_type, entity_id, action, payload, client_timestamp, schema_version, status, created_at)
                             VALUES (?,?,?,?,?,?,?,?,?,NOW())',
                            [
                                $uuid, $userId, mb_substr((string) ($ev['entity_type'] ?? ''), 0, 60), (int) ($ev['entity_id'] ?? 0), $type,
                                json_encode($payload, JSON_UNESCAPED_UNICODE), self::effectiveTime($ev['client_ts'] ?? null),
                                (int) ($ev['schema_version'] ?? 1), 'accepted',
                            ]
                        );
                        return $r;
                    });
                    $results[] = ['event_uuid' => $uuid, 'status' => 'accepted', 'message' => (string) ($res['message'] ?? 'OK'), 'data' => $res['data'] ?? null];
                } catch (\DomainException | \InvalidArgumentException $d) {
                    self::recordRejected($uuid, $userId, $ev, $d->getMessage());
                    $results[] = ['event_uuid' => $uuid, 'status' => 'rejected', 'message' => $d->getMessage()];
                } catch (\PDOException $p) {
                    if (($p->errorInfo[0] ?? '') === '23000' && Database::val('SELECT COUNT(*) FROM activity_events WHERE event_uuid = ?', [$uuid]) > 0) {
                        $results[] = ['event_uuid' => $uuid, 'status' => 'duplicate', 'message' => 'Already processed.'];
                    } else {
                        throw $p;
                    }
                }
            } catch (\InvalidArgumentException $v) {
                $results[] = ['event_uuid' => $uuid, 'status' => 'rejected', 'message' => $v->getMessage()];
            } catch (\Throwable $t) {
                Logger::error($t);
                $results[] = ['event_uuid' => $uuid, 'status' => 'error', 'message' => 'Server error, will retry.'];
            }
        }
        foreach ($results as $r) {
            self::log($userId, (string) $r['event_uuid'], (string) $r['status'], (string) $r['message']);
        }
        return $results;
    }

    private static function recordRejected(string $uuid, int $userId, array $ev, string $why): void
    {
        try {
            Database::run(
                'INSERT IGNORE INTO activity_events (event_uuid, user_id, entity_type, entity_id, action, payload, client_timestamp, schema_version, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW())',
                [$uuid, $userId, mb_substr((string) ($ev['entity_type'] ?? ''), 0, 60), (int) ($ev['entity_id'] ?? 0), (string) $ev['type'],
                 json_encode($ev['payload'], JSON_UNESCAPED_UNICODE), self::effectiveTime($ev['client_ts'] ?? null), (int) ($ev['schema_version'] ?? 1), 'rejected']
            );
        } catch (\Throwable $t) {
            Logger::error($t);
        }
    }

    private static function log(int $userId, string $uuid, string $status, string $message): void
    {
        try {
            Database::run('INSERT INTO sync_logs (user_id, event_uuid, status, message, created_at) VALUES (?,?,?,?,NOW())',
                [$userId, $uuid !== '' ? $uuid : null, $status, mb_substr($message, 0, 250)]);
        } catch (\Throwable $t) {
            Logger::error($t);
        }
    }
}
