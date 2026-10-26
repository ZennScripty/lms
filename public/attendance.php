<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Csrf, Logger, Validator};
use App\Repositories\{AttendanceRepository, LearningRepository, UserRepository};
use App\Services\SyncService;

$user = Auth::requireLogin();
$uid = (int) $user['id'];
$isStudent = Authorization::isStudent();
$canMark = Authorization::can('attendance.mark');
$canView = Authorization::can('attendance.view') || $canMark;
$scope = Authorization::visibleSubjectIds();

/** Loads a session the current user may manage (scope + open status when $mustBeOpen). */
function manageable_session(int $id, bool $mustBeOpen = true): array
{
    $s = AttendanceRepository::session($id);
    if (!$s || !Authorization::can('attendance.mark') || !Authorization::canAccessSubject((int) $s['subject_id'])) {
        forbidden();
    }
    if ($mustBeOpen && $s['status'] !== 'open') {
        throw new DomainException('This attendance session is not open.');
    }
    return $s;
}

// ---- Same-page JSON action: offline attendance events ----------------------------------------
if (is_post() && request_action() === 'sync_push') {
    Csrf::verifyRequest();
    if (!$canMark) {
        json_response(['ok' => false, 'error' => 'forbidden'], 403);
    }
    try {
        $events = SyncService::parseEvents();
    } catch (\InvalidArgumentException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    $results = SyncService::process($events, ['attendance_mark'], $uid, static function (array $ev) use ($uid): array {
        $p = $ev['payload'];
        $session = manageable_session(Validator::int($p['session_id'] ?? '', 'Session', 1));
        $studentId = Validator::int($p['student_id'] ?? '', 'Student', 1);
        if (!AttendanceRepository::studentInRoster($session, $studentId)) {
            throw new DomainException('Student is not part of this session.');
        }
        $status = Validator::inList($p['status'] ?? '', AttendanceRepository::STATUSES, 'Status');
        $note = Validator::optStr($p['note'] ?? '', 'Note', 500);
        AttendanceRepository::mark((int) $session['id'], $studentId, $status, $note, $uid, $ev['event_uuid'], 'offline_sync', SyncService::effectiveTime($ev['client_ts'] ?? null));
        return ['message' => 'Attendance saved.'];
    });
    json_response(['ok' => true, 'results' => $results, 'server_time' => date('c')]);
}

if (is_post()) {
    Csrf::verifyRequest();
    try {
        $action = request_action();
        if ($action === 'create_session') {
            if (!$canMark) {
                forbidden();
            }
            $subjectId = Validator::int($_POST['subject_id'] ?? '', 'Subject', 1);
            if (!Authorization::canAccessSubject($subjectId)) {
                forbidden();
            }
            $id = AttendanceRepository::createSession($subjectId, Validator::optInt($_POST['batch_id'] ?? null, 'Batch'), Validator::date($_POST['session_date'] ?? ''),
                Validator::optStr($_POST['session_label'] ?? '', 'Label', 120), $uid);
            Logger::audit($uid, 'attendance_session_created', 'attendance_session', $id);
            redirect('attendance.php?session=' . $id);
        } elseif ($action === 'save_attendance') {
            $s = manageable_session(Validator::int($_POST['session_id'] ?? '', 'Session', 1));
            $saved = 0;
            $dups = 0;
            foreach ((is_array($_POST['status'] ?? null) ? $_POST['status'] : []) as $sid => $st) {
                $studentId = Validator::int((string) $sid, 'Student', 1);
                if (!AttendanceRepository::studentInRoster($s, $studentId)) {
                    throw new DomainException('Student is not part of this session.');
                }
                $st = Validator::inList($st, AttendanceRepository::STATUSES, 'Status');
                try {
                    AttendanceRepository::mark((int) $s['id'], $studentId, $st, Validator::optStr($_POST['note'][$sid] ?? '', 'Note', 500), $uid, null, 'online');
                    $saved++;
                } catch (\DomainException) {
                    $dups++;
                }
            }
            flash($dups ? 'error' : 'success', "$saved saved" . ($dups ? ", $dups rejected as duplicates (already marked)." : '.'));
        } elseif ($action === 'update_record') {
            $s = manageable_session(Validator::int($_POST['session_id'] ?? '', 'Session', 1));
            AttendanceRepository::updateRecord(Validator::int($_POST['record_id'] ?? '', 'Record', 1), (int) $s['id'],
                Validator::inList($_POST['status'] ?? '', AttendanceRepository::STATUSES, 'Status'), Validator::optStr($_POST['note'] ?? '', 'Note', 500), $uid);
            Logger::audit($uid, 'attendance_corrected', 'attendance_session', (int) $s['id']);
            flash('success', 'Record updated.');
        } elseif ($action === 'submit_session' || $action === 'lock_session') {
            $s = manageable_session(Validator::int($_POST['session_id'] ?? '', 'Session', 1), $action === 'submit_session');
            if ($action === 'lock_session' && !Authorization::isSuperAdmin()) {
                forbidden();
            }
            AttendanceRepository::setSessionStatus((int) $s['id'], $action === 'submit_session' ? 'submitted' : 'locked');
            flash('success', 'Session ' . ($action === 'submit_session' ? 'submitted' : 'locked') . '.');
        }
    } catch (\Throwable $e) {
        flash('error', handle_exception($e));
    }
    redirect_self();
}

page_header(t('nav.attendance', 'Attendance'));

if ($isStudent) {
    $rows = AttendanceRepository::forStudent($uid, null);
    echo '<div class="card"><table><thead><tr><th>Date</th><th>Subject</th><th>Session</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . e($r['session_date']) . '</td><td>' . e($r['subject_name']) . '</td><td>' . e($r['session_label']) . '</td><td>' . e($r['attendance_status']) . '</td></tr>';
    }
    echo $rows ? '' : '<tr><td colspan="4" class="muted">No attendance recorded.</td></tr>';
    echo '</tbody></table></div>';
    page_footer();
    exit;
}
if (!$canView) {
    forbidden();
}

if (isset($_GET['session'])) {
    $s = AttendanceRepository::session(Validator::int($_GET['session'], 'Session', 1));
    if (!$s || !Authorization::canAccessSubject((int) $s['subject_id'])) {
        forbidden();
    }
    $roster = AttendanceRepository::rosterFor($s);
    $open = $s['status'] === 'open' && $canMark;
    ?>
    <p><a href="attendance.php">← Sessions</a></p>
    <div class="card"><h2><?= e($s['subject_name']) ?> — <?= e($s['session_date']) ?> <small class="muted"><?= e($s['session_label']) ?> · <?= e($s['status']) ?></small></h2>
    <form method="post" data-offline-attendance data-session-id="<?= (int) $s['id'] ?>" data-page="attendance.php"><?= csrf_field() ?>
        <input type="hidden" name="action" value="save_attendance"><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>">
        <table><thead><tr><th>Student</th><th>Attendance</th><th>Note</th></tr></thead><tbody>
        <?php foreach ($roster as $r): ?>
            <tr data-att-row data-student-id="<?= (int) $r['id'] ?>"><td><?= e($r['name']) ?> <span class="pill warn" data-pending-mark hidden>pending sync</span></td>
            <?php if ($r['record_id']): ?>
                <td colspan="2"><?= e($r['attendance_status']) ?><?= $r['note'] ? ' — ' . e($r['note']) : '' ?></td>
            <?php elseif ($open): ?>
                <td><?php foreach (AttendanceRepository::STATUSES as $st): ?><label class="check"><input type="radio" name="status[<?= (int) $r['id'] ?>]" value="<?= $st ?>"<?= $st === 'present' ? ' checked' : '' ?>> <?= $st ?></label><?php endforeach; ?></td>
                <td><input name="note[<?= (int) $r['id'] ?>]" maxlength="500"></td>
            <?php else: ?><td colspan="2" class="muted">Not marked</td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php if ($open): ?><button class="btn btn-primary">Save attendance</button> <span class="muted" data-att-status>Works offline: entries are queued and marked pending until the server accepts them.</span><?php endif; ?>
    </form>
    <?php if ($open): foreach ($roster as $r): if (!$r['record_id']) continue; ?>
        <form method="post" id="fix-<?= (int) $r['record_id'] ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="update_record">
            <input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="record_id" value="<?= (int) $r['record_id'] ?>">
            <small><?= e($r['name']) ?>:</small> <select name="status"><?php foreach (AttendanceRepository::STATUSES as $st): ?><option<?= selected($st, $r['attendance_status']) ?>><?= $st ?></option><?php endforeach; ?></select>
            <input name="note" maxlength="500" value="<?= e($r['note']) ?>" placeholder="note"><button class="btn btn-sm">Update</button></form>
    <?php endforeach; endif; ?>
    <?php if ($canMark && $s['status'] === 'open'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="submit_session"><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>"><button class="btn" data-confirm="Submit session? Marking will close.">Submit session</button></form>
    <?php endif; ?>
    <?php if (Authorization::isSuperAdmin() && $s['status'] === 'submitted'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="lock_session"><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>"><button class="btn">Lock session</button></form>
    <?php endif; ?></div>
    <?php
    page_footer();
    exit;
}

$sessions = AttendanceRepository::sessions($scope);
$subjects = LearningRepository::subjects($scope);
$batches = UserRepository::batches();
?>
<?php if ($canMark): ?>
<div class="card"><h2>New session</h2>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create_session">
    <label>Subject <select name="subject_id" required><?php foreach ($subjects as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
    <label>Batch <select name="batch_id"><option value="">All</option><?php foreach ($batches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></label>
    <label>Date <input type="date" name="session_date" value="<?= e(date('Y-m-d')) ?>" required></label>
    <label>Label <input name="session_label" maxlength="120" placeholder="e.g. Morning class"></label>
    <div><button class="btn btn-primary">Create</button></div>
</form></div>
<?php endif; ?>
<div class="card"><table><thead><tr><th>Date</th><th>Subject</th><th>Label</th><th>Marked</th><th>Status</th></tr></thead><tbody>
<?php foreach ($sessions as $s): ?>
    <tr><td><a href="attendance.php?session=<?= (int) $s['id'] ?>"><?= e($s['session_date']) ?></a></td><td><?= e($s['subject_name']) ?></td><td><?= e($s['session_label']) ?></td><td><?= (int) $s['marked'] ?></td><td><?= e($s['status']) ?></td></tr>
<?php endforeach; if (!$sessions): ?><tr><td colspan="5" class="muted">No sessions yet.</td></tr><?php endif; ?>
</tbody></table></div>
<?php page_footer();
