<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AttendanceRepository
{
    public const STATUSES = ['present', 'absent', 'late', 'excused'];

    public static function session(int $id): ?array
    {
        return Database::one(
            'SELECT s.*, sub.name AS subject_name, b.name AS batch_name FROM attendance_sessions s
             JOIN subjects sub ON sub.id = s.subject_id LEFT JOIN batches b ON b.id = s.batch_id WHERE s.id = ?',
            [$id]
        );
    }

    public static function sessions(?array $subjectIds, int $limit = 60): array
    {
        $sql = 'SELECT s.*, sub.name AS subject_name,
                    (SELECT COUNT(*) FROM attendance_records r WHERE r.session_id = s.id) AS marked
                FROM attendance_sessions s JOIN subjects sub ON sub.id = s.subject_id';
        $p = [];
        if ($subjectIds !== null) {
            if (!$subjectIds) {
                return [];
            }
            $sql .= ' WHERE s.subject_id IN (' . Database::in($subjectIds) . ')';
            $p = $subjectIds;
        }
        return Database::all($sql . ' ORDER BY s.session_date DESC, s.id DESC LIMIT ' . (int) $limit, $p);
    }

    public static function createSession(int $subjectId, ?int $batchId, string $date, ?string $label, int $by): int
    {
        return Database::insert(
            "INSERT INTO attendance_sessions (subject_id, batch_id, session_date, session_label, created_by, status) VALUES (?,?,?,?,?, 'open')",
            [$subjectId, $batchId, $date, $label, $by]
        );
    }

    /** Students enrolled in the session's subject (and batch, when the session has one). */
    public static function rosterFor(array $session): array
    {
        $sql = "SELECT u.id, u.name, r.id AS record_id, r.attendance_status, r.note
                FROM student_subjects ss JOIN users u ON u.id = ss.student_id AND u.status = 'active'
                LEFT JOIN attendance_records r ON r.session_id = ? AND r.student_id = u.id
                WHERE ss.subject_id = ?";
        $p = [(int) $session['id'], (int) $session['subject_id']];
        if (!empty($session['batch_id'])) {
            $sql .= ' AND u.batch_id = ?';
            $p[] = (int) $session['batch_id'];
        }
        return Database::all($sql . ' ORDER BY u.name', $p);
    }

    public static function studentInRoster(array $session, int $studentId): bool
    {
        foreach (self::rosterFor($session) as $r) {
            if ((int) $r['id'] === $studentId) {
                return true;
            }
        }
        return false;
    }

    /** Inserts a record. Throws DomainException when the student is already marked for this session. */
    public static function mark(int $sessionId, int $studentId, string $status, ?string $note, int $by, ?string $eventUuid, string $source, ?string $markedAt = null): void
    {
        try {
            Database::run(
                'INSERT INTO attendance_records (session_id, student_id, attendance_status, note, marked_by, client_event_uuid, marked_at, sync_source) VALUES (?,?,?,?,?,?,?,?)',
                [$sessionId, $studentId, $status, $note, $by, $eventUuid, $markedAt ?? date('Y-m-d H:i:s'), $source]
            );
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw new \DomainException('Attendance already marked for this student in this session.');
            }
            throw $e;
        }
    }

    public static function updateRecord(int $recordId, int $sessionId, string $status, ?string $note, int $by): int
    {
        return Database::run('UPDATE attendance_records SET attendance_status = ?, note = ?, marked_by = ?, marked_at = NOW() WHERE id = ? AND session_id = ?',
            [$status, $note, $by, $recordId, $sessionId]);
    }

    public static function setSessionStatus(int $id, string $status): void
    {
        Database::run('UPDATE attendance_sessions SET status = ? WHERE id = ?', [$status, $id]);
    }

    /** Attendance of one student; $subjectIds limits what a teacher may see. */
    public static function forStudent(int $studentId, ?array $subjectIds): array
    {
        $sql = 'SELECT s.session_date, s.session_label, sub.name AS subject_name, r.attendance_status, r.note
                FROM attendance_records r JOIN attendance_sessions s ON s.id = r.session_id JOIN subjects sub ON sub.id = s.subject_id
                WHERE r.student_id = ?';
        $p = [$studentId];
        if ($subjectIds !== null) {
            if (!$subjectIds) {
                return [];
            }
            $sql .= ' AND s.subject_id IN (' . Database::in($subjectIds) . ')';
            $p = array_merge($p, $subjectIds);
        }
        return Database::all($sql . ' ORDER BY s.session_date DESC, s.id DESC LIMIT 200', $p);
    }
}
