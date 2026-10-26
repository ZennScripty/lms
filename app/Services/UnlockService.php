<?php
declare(strict_types=1);

namespace App\Services;

/** Pure rule functions (no DB) so they can be unit tested. */
final class UnlockService
{
    /**
     * Integer-safe pass check. rule: 'gte' (>=) or 'gt' (>).
     * earned/total are marks; passPercent is e.g. 50.
     */
    public static function passes(int $earned, int $total, float $passPercent, string $rule = 'gte'): bool
    {
        if ($total <= 0) {
            return false;
        }
        $left = $earned * 100;
        $right = $passPercent * $total;
        return $rule === 'gt' ? $left > $right : $left >= $right;
    }

    public static function percent(int $earned, int $total): float
    {
        return $total > 0 ? round($earned * 100 / $total, 2) : 0.0;
    }

    /** A module counts as passed when its test is passed, or (no test exists) all lessons are done. */
    public static function modulePassed(?string $status, bool $hasTest, bool $allLessonsDone, int $lessonCount): bool
    {
        if ($status === 'passed') {
            return true;
        }
        if (!$hasTest) {
            return $lessonCount === 0 || $allLessonsDone;
        }
        return false;
    }

    /** Test due date. $mode: 'first_session' (start of module) or 'lesson_completion' (all lessons done). */
    public static function testDueAt(string $baseTime, int $days): string
    {
        return (new \DateTimeImmutable($baseTime))->modify('+' . max(0, $days) . ' days')->format('Y-m-d H:i:s');
    }

    public static function isTestDue(?string $dueAt, string $now): bool
    {
        return $dueAt !== null && $dueAt <= $now;
    }
}
