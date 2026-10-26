<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Database, Validator};
use App\Repositories\{AttendanceRepository, UserRepository};
use App\Services\LearningService;

$user = Auth::requireLogin();
try {
    $id = Validator::int($_GET['id'] ?? ($user['role_slug'] === 'student' ? (string) $user['id'] : ''), 'Student', 1);
} catch (\InvalidArgumentException) {
    not_found('Invalid student.');
}
// Server-side scope check: teacher => assigned subjects only, student => self only.
if (!Authorization::canAccessStudent($id)) {
    forbidden();
}
$student = UserRepository::find($id);
if (!$student) {
    not_found('Student not found.');
}
$scope = Authorization::visibleSubjectIds();   // teachers only see their own subjects' data
$subjects = LearningService::subjectsOf($id);
if ($scope !== null) {
    $subjects = array_values(array_filter($subjects, static fn ($s) => in_array((int) $s['id'], $scope, true)));
}
$attempts = Database::all(
    'SELECT ta.submitted_at, ta.score_percent, ta.passed, t.title, m.subject_id FROM test_attempts ta
     JOIN tests t ON t.id = ta.test_id JOIN modules m ON m.id = t.module_id WHERE ta.student_id = ? ORDER BY ta.submitted_at DESC LIMIT 100', [$id]);
if ($scope !== null) {
    $attempts = array_values(array_filter($attempts, static fn ($a) => in_array((int) $a['subject_id'], $scope, true)));
}
$attendance = AttendanceRepository::forStudent($id, $scope);

page_header($student['name']);
?>
<div class="card"><dl class="kv">
    <dt>Username</dt><dd><?= e($student['username']) ?></dd>
    <dt>Email</dt><dd><?= e($student['email']) ?></dd>
    <dt>Batch</dt><dd><?= e($student['batch_name']) ?></dd>
    <dt>Status</dt><dd><?= e($student['status']) ?></dd>
</dl></div>
<?php foreach ($subjects as $s): $tree = LearningService::tree($id, (int) $s['id']); ?>
<div class="card"><h2><?= e($s['name']) ?></h2>
    <table><thead><tr><th>Module</th><th>Lessons</th><th>Test due</th><th>Best score</th><th>State</th></tr></thead><tbody>
    <?php foreach ($tree as $m): ?>
        <tr><td><?= e($m['title']) ?></td><td><?= $m['lessons_done'] ?> / <?= $m['lessons_total'] ?></td>
            <td><?= e(fmt_dt($m['test_due_at'])) ?></td>
            <td><?= $m['best_score'] !== null ? e((string) $m['best_score']) . '%' : '—' ?></td>
            <td><?= $m['passed'] ? 'Passed' : ($m['unlocked'] ? 'In progress' : 'Locked') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
<?php endforeach; ?>
<div class="card"><h2>Test attempts</h2><table><thead><tr><th>Test</th><th>Submitted</th><th>Score</th><th>Result</th></tr></thead><tbody>
<?php foreach ($attempts as $a): ?>
    <tr><td><?= e($a['title']) ?></td><td><?= e(fmt_dt($a['submitted_at'])) ?></td><td><?= e((string) $a['score_percent']) ?>%</td><td><?= $a['passed'] ? 'Pass' : 'Fail' ?></td></tr>
<?php endforeach; if (!$attempts): ?><tr><td colspan="4" class="muted">No attempts.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="card"><h2>Attendance</h2><table><thead><tr><th>Date</th><th>Subject</th><th>Session</th><th>Status</th><th>Note</th></tr></thead><tbody>
<?php foreach ($attendance as $r): ?>
    <tr><td><?= e($r['session_date']) ?></td><td><?= e($r['subject_name']) ?></td><td><?= e($r['session_label']) ?></td><td><?= e($r['attendance_status']) ?></td><td><?= e($r['note']) ?></td></tr>
<?php endforeach; if (!$attendance): ?><tr><td colspan="5" class="muted">No attendance records.</td></tr><?php endif; ?>
</tbody></table></div>
<?php page_footer();
