<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\{LearningRepository, ProgressRepository};

/** Single source of truth for lock/unlock state of modules, lessons and tests. */
final class LearningService
{
    public static function subjectsOf(int $studentId): array
    {
        return Database::all(
            "SELECT s.* FROM subjects s JOIN student_subjects ss ON ss.subject_id = s.id WHERE ss.student_id = ? AND s.status = 'published' ORDER BY s.name",
            [$studentId]
        );
    }

    /**
     * @return array<int,array<string,mixed>> ordered modules with lessons and lock state; [] when not enrolled.
     */
    public static function tree(int $studentId, int $subjectId, ?string $lang = null): array
    {
        if (Database::val('SELECT COUNT(*) FROM student_subjects WHERE student_id = ? AND subject_id = ?', [$studentId, $subjectId]) < 1) {
            return [];
        }
        $mods = LearningRepository::modules($subjectId, true);
        if (!$mods) {
            return [];
        }
        $mids = array_map(static fn ($m) => (int) $m['id'], $mods);
        $lessonsBy = LearningRepository::lessonsForModules($mids);
        $lids = [];
        foreach ($lessonsBy as $ls) {
            foreach ($ls as $l) {
                $lids[] = (int) $l['id'];
            }
        }
        $done = ProgressRepository::completedLessonMap($studentId, $mids);
        $mp = ProgressRepository::moduleProgress($studentId, $mids);
        $tests = [];
        foreach (Database::all("SELECT id, module_id, title FROM tests WHERE status = 'published' AND module_id IN (" . Database::in($mids) . ') ORDER BY id', $mids) as $t) {
            $tests[(int) $t['module_id']] ??= $t;
        }
        $trM = TranslationService::bulkModules($mids, $lang);
        $trL = TranslationService::bulkLessons($lids, $lang);
        $now = date('Y-m-d H:i:s');

        $out = [];
        $prevPassed = true;
        foreach ($mods as $m) {
            $mid = (int) $m['id'];
            $unlocked = $prevPassed;
            $lessons = [];
            $prevDone = true;
            $doneCount = 0;
            foreach ($lessonsBy[$mid] ?? [] as $l) {
                $lid = (int) $l['id'];
                $c = isset($done[$lid]);
                $lessons[] = [
                    'id' => $lid,
                    'title' => $trL[$lid]['title'] ?? $l['title'],
                    'completed' => $c,
                    'unlocked' => $unlocked && ($c || $prevDone),
                ];
                $doneCount += $c ? 1 : 0;
                $prevDone = $c;
            }
            $total = count($lessons);
            $allDone = $total > 0 && $doneCount === $total;
            $test = $tests[$mid] ?? null;
            $row = $mp[$mid] ?? null;
            $due = $row['test_due_at'] ?? null;
            $passed = UnlockService::modulePassed($row['status'] ?? null, $test !== null, $allDone, $total);
            $out[] = [
                'id' => $mid,
                'subject_id' => $subjectId,
                'title' => $trM[$mid]['title'] ?? $m['title'],
                'description' => $trM[$mid]['description'] ?? $m['description'],
                'unlocked' => $unlocked,
                'passed' => $passed,
                'lessons' => $lessons,
                'lessons_done' => $doneCount,
                'lessons_total' => $total,
                'test' => $test,
                'test_due_at' => $due,
                'best_score' => $row['best_score'] ?? null,
                'test_available' => $unlocked && $test !== null && !$passed && $allDone && UnlockService::isTestDue($due, $now),
            ];
            $prevPassed = $passed;
        }
        return $out;
    }

    /** @return array{lesson:array,module:array,lesson_state:array}|null  null if not enrolled / not visible */
    public static function lessonState(int $studentId, int $lessonId): ?array
    {
        $lesson = LearningRepository::lesson($lessonId);
        if (!$lesson || $lesson['status'] !== 'published') {
            return null;
        }
        foreach (self::tree($studentId, (int) $lesson['subject_id']) as $m) {
            if ($m['id'] !== (int) $lesson['module_id']) {
                continue;
            }
            foreach ($m['lessons'] as $l) {
                if ($l['id'] === $lessonId) {
                    return ['lesson' => $lesson, 'module' => $m, 'lesson_state' => $l];
                }
            }
        }
        return null;
    }

    public static function moduleState(int $studentId, int $moduleId): ?array
    {
        $mod = LearningRepository::module($moduleId);
        if (!$mod) {
            return null;
        }
        foreach (self::tree($studentId, (int) $mod['subject_id']) as $m) {
            if ($m['id'] === $moduleId) {
                return $m;
            }
        }
        return null;
    }
}
