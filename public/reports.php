<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Database, Validator};
use App\Repositories\LearningRepository;

Auth::requireLogin();
Authorization::require('reports.view');
$scope = Authorization::visibleSubjectIds();
$subjects = LearningRepository::subjects($scope);
$subjectId = isset($_GET['subject_id']) ? Validator::int($_GET['subject_id'], 'Subject', 1) : (int) ($subjects[0]['id'] ?? 0);
if ($subjectId && !Authorization::canAccessSubject($subjectId)) {
    forbidden();
}

$rows = [];
if ($subjectId) {
    $rows = Database::all(
        "SELECT u.id, u.name,
            (SELECT COUNT(*) FROM content_progress cp JOIN content_items c ON c.id = cp.content_item_id JOIN modules m ON m.id = c.module_id
               WHERE cp.student_id = u.id AND cp.status = 'completed' AND m.subject_id = ss.subject_id) AS lessons_done,
            (SELECT COUNT(*) FROM content_items c JOIN modules m ON m.id = c.module_id WHERE m.subject_id = ss.subject_id AND c.status = 'published') AS lessons_total,
            (SELECT COUNT(*) FROM module_progress mp JOIN modules m ON m.id = mp.module_id WHERE mp.student_id = u.id AND mp.status = 'passed' AND m.subject_id = ss.subject_id) AS modules_passed,
            (SELECT ROUND(AVG(ta.score_percent), 1) FROM test_attempts ta JOIN tests t ON t.id = ta.test_id JOIN modules m ON m.id = t.module_id
               WHERE ta.student_id = u.id AND m.subject_id = ss.subject_id) AS avg_score,
            (SELECT COUNT(*) FROM attendance_records r JOIN attendance_sessions s ON s.id = r.session_id WHERE r.student_id = u.id AND s.subject_id = ss.subject_id) AS att_total,
            (SELECT COUNT(*) FROM attendance_records r JOIN attendance_sessions s ON s.id = r.session_id
               WHERE r.student_id = u.id AND s.subject_id = ss.subject_id AND r.attendance_status IN ('present','late')) AS att_present
         FROM student_subjects ss JOIN users u ON u.id = ss.student_id WHERE ss.subject_id = ? ORDER BY u.name",
        [$subjectId]
    );
}

if (request_action() === 'export_csv' && $subjectId) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-subject-' . $subjectId . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student', 'Lessons done', 'Lessons total', 'Modules passed', 'Avg test %', 'Attendance %']);
    foreach ($rows as $r) {
        $att = (int) $r['att_total'] > 0 ? round($r['att_present'] * 100 / $r['att_total'], 1) : '';
        // Neutralise spreadsheet formula injection
        $name = preg_match('/^[=+\-@]/', (string) $r['name']) ? "'" . $r['name'] : $r['name'];
        fputcsv($out, [$name, $r['lessons_done'], $r['lessons_total'], $r['modules_passed'], $r['avg_score'], $att]);
    }
    fclose($out);
    exit;
}

page_header(t('nav.reports', 'Reports'));
?>
<form method="get" class="inline-form">
    <select name="subject_id"><?php foreach ($subjects as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected($s['id'], $subjectId) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
    <button class="btn">Show</button>
    <?php if ($subjectId): ?><a class="btn" href="?subject_id=<?= $subjectId ?>&action=export_csv">Export CSV</a><?php endif; ?>
</form>
<div class="card"><table><thead><tr><th>Student</th><th>Lessons</th><th>Modules passed</th><th>Avg test</th><th>Attendance</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
    <tr><td><a href="student_detail.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
    <td><?= (int) $r['lessons_done'] ?> / <?= (int) $r['lessons_total'] ?></td><td><?= (int) $r['modules_passed'] ?></td>
    <td><?= $r['avg_score'] !== null ? e((string) $r['avg_score']) . '%' : '—' ?></td>
    <td><?= (int) $r['att_total'] > 0 ? round($r['att_present'] * 100 / $r['att_total'], 1) . '%' : '—' ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">No data.</td></tr><?php endif; ?>
</tbody></table></div>
<?php page_footer();
