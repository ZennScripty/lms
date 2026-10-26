<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Database};
use App\Services\LearningService;

$user = Auth::requireLogin();
page_header(t('nav.dashboard', 'Dashboard'));

if (Authorization::isStudent()) {
    Authorization::require('learn.access');
    $sid = (int) $user['id'];
    $subjects = LearningService::subjectsOf($sid);
    echo '<p>Welcome, ' . e($user['name']) . '.</p>';
    if (!$subjects) {
        echo '<div class="card">You are not enrolled in any subject yet.</div>';
    }
    foreach ($subjects as $s) {
        $tree = LearningService::tree($sid, (int) $s['id']);
        $total = array_sum(array_column($tree, 'lessons_total'));
        $done = array_sum(array_column($tree, 'lessons_done'));
        echo '<div class="card"><h2>' . e($s['name']) . '</h2>';
        echo '<progress value="' . $done . '" max="' . max(1, $total) . '"></progress> <small>' . $done . ' / ' . $total . ' lessons</small>';
        foreach ($tree as $m) {
            echo '<h3>' . e($m['title']) . ($m['passed'] ? ' <span class="pill ok">passed</span>' : (!$m['unlocked'] ? ' <span class="pill lock">locked</span>' : '')) . '</h3><ul class="plain">';
            foreach ($m['lessons'] as $l) {
                if ($l['unlocked']) {
                    echo '<li>' . ($l['completed'] ? '✔ ' : '▶ ') . '<a href="lesson.php?id=' . $l['id'] . '">' . e($l['title']) . '</a></li>';
                } else {
                    echo '<li class="muted">🔒 ' . e($l['title']) . '</li>';
                }
            }
            echo '</ul>';
            if ($m['test_available']) {
                echo '<p><a class="btn btn-primary" href="tests.php?id=' . (int) $m['test']['id'] . '">Take module test</a></p>';
            } elseif ($m['test'] && $m['unlocked'] && !$m['passed']) {
                echo '<p class="muted">Test opens after all lessons are done' . ($m['test_due_at'] ? ' and on/after ' . e(fmt_dt($m['test_due_at'])) : '') . '.</p>';
            }
        }
        echo '</div>';
    }
} else {
    $scope = Authorization::visibleSubjectIds();
    $in = $scope === null ? '' : ($scope ? ' AND ss.subject_id IN (' . Database::in($scope) . ')' : ' AND 1=0');
    $p = $scope ?: [];
    $students = (int) Database::val('SELECT COUNT(DISTINCT ss.student_id) FROM student_subjects ss WHERE 1=1' . $in, $p);
    $subjects = $scope === null ? (int) Database::val('SELECT COUNT(*) FROM subjects') : count($scope);
    $sessIn = $scope === null ? '' : ($scope ? ' AND subject_id IN (' . Database::in($scope) . ')' : ' AND 1=0');
    $sessions = (int) Database::val('SELECT COUNT(*) FROM attendance_records ar WHERE ar.attendance_date = CURDATE()' . ($scope === null ? '' : ($scope ? ' AND EXISTS (SELECT 1 FROM student_subjects ss WHERE ss.student_id = ar.user_id AND ss.subject_id IN (' . Database::in($scope) . '))' : ' AND 1=0')), $p);
    echo '<div class="grid">';
    foreach ([['Students', $students], ['Subjects', $subjects], ["Today's attendance records", $sessions]] as [$l, $v]) {
        echo '<div class="card stat"><div class="num">' . $v . '</div><div>' . e($l) . '</div></div>';
    }
    echo '</div>';
}
page_footer();
