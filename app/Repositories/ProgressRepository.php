<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\UnlockService;

final class ProgressRepository
{
    /** @return array<int,bool> lesson id => true */
    public static function completedLessonMap(int $studentId, array $moduleIds): array
    {
        if (!$moduleIds) {
            return [];
        }
        $rows = Database::all(
            "SELECT cp.content_item_id FROM content_progress cp JOIN content_items c ON c.id = cp.content_item_id
             WHERE cp.student_id = ? AND cp.status = 'completed' AND c.module_id IN (" . Database::in($moduleIds) . ')',
            array_merge([$studentId], $moduleIds)
        );
        $m = [];
        foreach ($rows as $r) {
            $m[(int) $r['content_item_id']] = true;
        }
        return $m;
    }

    /** @return array<int,array<string,mixed>> module id => row */
    public static function moduleProgress(int $studentId, array $moduleIds): array
    {
        if (!$moduleIds) {
            return [];
        }
        $m = [];
        foreach (Database::all('SELECT * FROM module_progress WHERE student_id = ? AND module_id IN (' . Database::in($moduleIds) . ')', array_merge([$studentId], $moduleIds)) as $r) {
            $m[(int) $r['module_id']] = $r;
        }
        return $m;
    }

    /** Server-side record that a student opened a lesson (used to sanity check study time). */
    public static function markOpened(int $studentId, int $lessonId): void
    {
        Database::run(
            "INSERT INTO content_progress (student_id, content_item_id, status, first_opened_at, time_spent_seconds) VALUES (?,?, 'in_progress', NOW(), 0)
             ON DUPLICATE KEY UPDATE first_opened_at = COALESCE(first_opened_at, VALUES(first_opened_at))",
            [$studentId, $lessonId]
        );
    }

    public static function firstOpenedAt(int $studentId, int $lessonId): ?string
    {
        $v = Database::val('SELECT first_opened_at FROM content_progress WHERE student_id = ? AND content_item_id = ?', [$studentId, $lessonId]);
        return $v === null ? null : (string) $v;
    }

    public static function isLessonCompleted(int $studentId, int $lessonId): bool
    {
        return Database::val("SELECT COUNT(*) FROM content_progress WHERE student_id = ? AND content_item_id = ? AND status = 'completed'", [$studentId, $lessonId]) > 0;
    }

    /**
     * Marks a lesson completed (idempotent), records the study session and starts / schedules the module test clock.
     * Caller must already have verified that the lesson is unlocked and the study-time rule is met.
     */
    public static function completeLesson(int $studentId, array $lesson, int $seconds, string $when): void
    {
        $lessonId = (int) $lesson['id'];
        $moduleId = (int) $lesson['module_id'];
        Database::run(
            "INSERT INTO content_progress (student_id, content_item_id, status, completed_at, time_spent_seconds, first_opened_at) VALUES (?,?, 'completed', ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = 'completed', completed_at = COALESCE(completed_at, VALUES(completed_at)),
                 time_spent_seconds = time_spent_seconds + VALUES(time_spent_seconds), first_opened_at = COALESCE(first_opened_at, VALUES(first_opened_at))",
            [$studentId, $lessonId, $when, $seconds, $when]
        );
        Database::run(
            'INSERT INTO study_sessions (student_id, module_id, content_item_id, started_at, ended_at, duration_seconds) VALUES (?,?,?,?,?,?)',
            [$studentId, $moduleId, $lessonId, date('Y-m-d H:i:s', strtotime($when) - $seconds), $when, $seconds]
        );

        $days = (int) setting('module_test_interval_days', 15);
        $startRule = (string) setting('module_test_interval_start', 'first_session');
        $due = $startRule === 'first_session' ? UnlockService::testDueAt($when, $days) : null;
        Database::run(
            "INSERT INTO module_progress (student_id, module_id, status, started_at, test_due_at) VALUES (?,?, 'in_progress', ?, ?)
             ON DUPLICATE KEY UPDATE started_at = COALESCE(started_at, VALUES(started_at)), test_due_at = COALESCE(test_due_at, VALUES(test_due_at))",
            [$studentId, $moduleId, $when, $due]
        );

        if ($startRule === 'lesson_completion') {
            $left = (int) Database::val(
                "SELECT COUNT(*) FROM content_items c LEFT JOIN content_progress cp ON cp.content_item_id = c.id AND cp.student_id = ? AND cp.status = 'completed'
                 WHERE c.module_id = ? AND c.status = 'published' AND cp.id IS NULL",
                [$studentId, $moduleId]
            );
            if ($left === 0) {
                Database::run('UPDATE module_progress SET test_due_at = COALESCE(test_due_at, ?) WHERE student_id = ? AND module_id = ?',
                    [UnlockService::testDueAt($when, $days), $studentId, $moduleId]);
            }
        }
    }

    public static function recordTestResult(int $studentId, int $moduleId, float $percent, bool $passed, string $when): void
    {
        Database::run(
            "INSERT INTO module_progress (student_id, module_id, status, started_at, best_score, passed_at) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE best_score = GREATEST(IFNULL(best_score, 0), VALUES(best_score)),
                 status = IF(status = 'passed', 'passed', VALUES(status)),
                 passed_at = IF(passed_at IS NOT NULL, passed_at, VALUES(passed_at))",
            [$studentId, $moduleId, $passed ? 'passed' : 'in_progress', $when, $percent, $passed ? $when : null]
        );
    }
}
