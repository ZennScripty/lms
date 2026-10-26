<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\ProgressRepository;

final class TestService
{
    /**
     * Pure scoring.
     * @param array<int,array{marks:int,correct:array<int,int>,options:array<int,int>}> $questions keyed by question id
     * @param array<int|string,mixed> $answers question id => option id
     * @return array{earned:int,total:int,percent:float,detail:array<int,array{option:?int,correct:bool}>}
     */
    public static function calculate(array $questions, array $answers): array
    {
        $earned = 0;
        $total = 0;
        $detail = [];
        foreach ($questions as $qid => $q) {
            $total += $q['marks'];
            $given = isset($answers[$qid]) && is_scalar($answers[$qid]) ? (int) $answers[$qid] : null;
            $valid = $given !== null && in_array($given, $q['options'], true);
            $ok = $valid && in_array($given, $q['correct'], true);
            $earned += $ok ? $q['marks'] : 0;
            $detail[$qid] = ['option' => $valid ? $given : null, 'correct' => $ok];
        }
        return ['earned' => $earned, 'total' => $total, 'percent' => UnlockService::percent($earned, $total), 'detail' => $detail];
    }

    /** Questions + options for taking a test. The correct flag is never sent to the browser. */
    public static function questionsForTaking(int $testId): array
    {
        $qs = Database::all('SELECT id, question_text, marks FROM questions WHERE test_id = ? ORDER BY id', [$testId]);
        if (!$qs) {
            return [];
        }
        $ids = array_map(static fn ($q) => (int) $q['id'], $qs);
        $opts = [];
        foreach (Database::all('SELECT id, question_id, option_text FROM question_options WHERE question_id IN (' . Database::in($ids) . ') ORDER BY id', $ids) as $o) {
            $opts[(int) $o['question_id']][] = $o;
        }
        foreach ($qs as &$q) {
            $q['options'] = $opts[(int) $q['id']] ?? [];
        }
        return $qs;
    }

    /**
     * Server-side grading and unlock. Throws DomainException if the student may not take the test now.
     * @return array{attempt_id:int,percent:float,passed:bool,earned:int,total:int}
     */
    public static function submit(int $studentId, int $testId, array $answers, ?string $startedAt, string $source, string $when): array
    {
        $test = Database::one("SELECT id, module_id, title FROM tests WHERE id = ? AND status = 'published'", [$testId]);
        if (!$test) {
            throw new \DomainException('Test not found.');
        }
        $state = LearningService::moduleState($studentId, (int) $test['module_id']);
        if (!$state || !$state['unlocked']) {
            throw new \DomainException('This module is locked.');
        }
        if ($state['passed']) {
            throw new \DomainException('This module test is already passed.');
        }
        if (!$state['test_available'] || (int) ($state['test']['id'] ?? 0) !== $testId) {
            throw new \DomainException('The test is not available yet. Finish all lessons and wait for the test date.');
        }

        $qrows = Database::all('SELECT id, marks FROM questions WHERE test_id = ?', [$testId]);
        if (!$qrows) {
            throw new \DomainException('This test has no questions.');
        }
        $questions = [];
        foreach ($qrows as $q) {
            $questions[(int) $q['id']] = ['marks' => max(1, (int) $q['marks']), 'correct' => [], 'options' => []];
        }
        foreach (Database::all('SELECT id, question_id, is_correct FROM question_options WHERE question_id IN (' . Database::in(array_keys($questions)) . ')', array_keys($questions)) as $o) {
            $questions[(int) $o['question_id']]['options'][] = (int) $o['id'];
            if ((int) $o['is_correct'] === 1) {
                $questions[(int) $o['question_id']]['correct'][] = (int) $o['id'];
            }
        }

        $calc = self::calculate($questions, $answers);
        $passPercent = (float) setting('module_pass_percent', 50);
        $rule = (string) setting('module_pass_rule', 'gte');
        $passed = UnlockService::passes($calc['earned'], $calc['total'], $passPercent, $rule === 'gt' ? 'gt' : 'gte');

        $attemptId = Database::insert(
            "INSERT INTO test_attempts (test_id, student_id, started_at, submitted_at, earned_marks, total_marks, score_percent, passed, status, sync_source)
             VALUES (?,?,?,?,?,?,?,?, 'submitted', ?)",
            [$testId, $studentId, $startedAt ?: $when, $when, $calc['earned'], $calc['total'], $calc['percent'], $passed ? 1 : 0, $source]
        );
        foreach ($calc['detail'] as $qid => $d) {
            Database::run('INSERT INTO test_attempt_answers (attempt_id, question_id, option_id, is_correct) VALUES (?,?,?,?)',
                [$attemptId, $qid, $d['option'], $d['correct'] ? 1 : 0]);
        }
        ProgressRepository::recordTestResult($studentId, (int) $test['module_id'], $calc['percent'], $passed, $when);
        Database::run('INSERT INTO notifications (user_id, title, body, is_read, created_at) VALUES (?,?,?,0,NOW())', [
            $studentId,
            $passed ? 'Module test passed' : 'Module test not passed',
            sprintf('%s: %.2f%% — %s', $test['title'], $calc['percent'], $passed ? 'next module unlocked.' : 'the next module stays locked. You can retry.'),
        ]);

        return ['attempt_id' => $attemptId, 'percent' => $calc['percent'], 'passed' => $passed, 'earned' => $calc['earned'], 'total' => $calc['total']];
    }
}
