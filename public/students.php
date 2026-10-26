<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Csrf, Logger, Validator};
use App\Repositories\{LearningRepository, UserRepository};

$user = Auth::requireLogin();
Authorization::require('students.view');
$scope = Authorization::visibleSubjectIds();
$canManage = Authorization::can('students.manage');

if (is_post()) {
    Csrf::verifyRequest();
    try {
        if (!$canManage) {
            forbidden();
        }
        $action = request_action();
        $fields = static fn () => [
            'name' => Validator::str($_POST['name'] ?? '', 'Name', 2, 120),
            'email' => Validator::email($_POST['email'] ?? ''),
            'username' => Validator::str($_POST['username'] ?? '', 'Username', 3, 60),
            'batch_id' => Validator::optInt($_POST['batch_id'] ?? null, 'Batch'),
        ];
        if ($action === 'create_student') {
            $d = $fields() + ['password' => Validator::password($_POST['password'] ?? '')];
            $id = UserRepository::createStudent($d);
            UserRepository::setSubjects($id, Validator::intList($_POST['subject_ids'] ?? []), $scope);
            Logger::audit((int) $user['id'], 'student_created', 'user', $id);
            flash('success', 'Student created.');
        } elseif (in_array($action, ['update_student', 'toggle_status', 'assign_subjects'], true)) {
            $id = Validator::int($_POST['student_id'] ?? '', 'Student', 1);
            if (!Authorization::canAccessStudent($id)) {
                forbidden();
            }
            if ($action === 'update_student') {
                $d = $fields();
                $pw = (string) ($_POST['password'] ?? '');
                $d['password'] = $pw !== '' ? Validator::password($pw) : null;
                UserRepository::updateStudent($id, $d);
                UserRepository::setSubjects($id, Validator::intList($_POST['subject_ids'] ?? []), $scope);
                flash('success', 'Student updated.');
            } elseif ($action === 'toggle_status') {
                $st = Validator::inList($_POST['status'] ?? '', ['active', 'inactive'], 'Status');
                UserRepository::setStatus($id, $st);
                flash('success', 'Status changed.');
            } else {
                UserRepository::setSubjects($id, Validator::intList($_POST['subject_ids'] ?? []), $scope);
                flash('success', 'Subjects updated.');
            }
            Logger::audit((int) $user['id'], $action, 'user', $id);
        }
    } catch (\Throwable $e) {
        flash('error', handle_exception($e));
    }
    redirect_self();
}

$search = trim((string) ($_GET['q'] ?? ''));
$students = UserRepository::students($search, $scope);
$subjects = LearningRepository::subjects($scope);
$batches = UserRepository::batches();
$edit = null;
if ($canManage && isset($_GET['edit'])) {
    $eid = Validator::int($_GET['edit'], 'Student', 1);
    if (!Authorization::canAccessStudent($eid)) {
        forbidden();
    }
    $edit = UserRepository::find($eid);
}
$editSubjects = $edit ? UserRepository::subjectIdsOf((int) $edit['id']) : [];

page_header(t('nav.students', 'Students'));
?>
<form method="get" class="inline-form">
    <input name="q" value="<?= e($search) ?>" placeholder="Search name, email, username" maxlength="100">
    <button class="btn">Search</button>
</form>
<div class="card"><table>
    <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Batch</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($students as $s): ?>
        <tr>
            <td><a href="student_detail.php?id=<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a></td>
            <td><?= e($s['username']) ?></td><td><?= e($s['email']) ?></td><td><?= e($s['batch_name']) ?></td>
            <td><span class="pill <?= $s['status'] === 'active' ? 'ok' : 'lock' ?>"><?= e($s['status']) ?></span></td>
            <td class="actions">
                <?php if ($canManage): ?>
                    <a class="btn btn-sm" href="?edit=<?= (int) $s['id'] ?>">Edit</a>
                    <form method="post" class="inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_status"><input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                        <input type="hidden" name="status" value="<?= $s['status'] === 'active' ? 'inactive' : 'active' ?>">
                        <button class="btn btn-sm" data-confirm="Change status?"><?= $s['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$students): ?><tr><td colspan="6" class="muted">No students found.</td></tr><?php endif; ?>
    </tbody>
</table></div>

<?php if ($canManage): ?>
<div class="card">
    <h2><?= $edit ? 'Edit student' : 'Add student' ?></h2>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $edit ? 'update_student' : 'create_student' ?>">
        <?php if ($edit): ?><input type="hidden" name="student_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
        <label>Name <input name="name" required maxlength="120" value="<?= e($edit['name'] ?? '') ?>"></label>
        <label>Username <input name="username" required maxlength="60" value="<?= e($edit['username'] ?? '') ?>"></label>
        <label>Email <input type="email" name="email" required maxlength="190" value="<?= e($edit['email'] ?? '') ?>"></label>
        <label>Password <?= $edit ? '(leave blank to keep)' : '' ?>
            <input type="password" name="password" minlength="8" maxlength="200" autocomplete="new-password" <?= $edit ? '' : 'required' ?>></label>
        <label>Batch
            <select name="batch_id"><option value="">—</option>
            <?php foreach ($batches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $edit['batch_id'] ?? '') ?>><?= e($b['name']) ?></option><?php endforeach; ?>
            </select></label>
        <fieldset><legend>Subjects</legend>
            <?php foreach ($subjects as $s): ?>
                <label class="check"><input type="checkbox" name="subject_ids[]" value="<?= (int) $s['id'] ?>"<?= checked(in_array((int) $s['id'], $editSubjects, true)) ?>> <?= e($s['name']) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div><button class="btn btn-primary">Save</button><?php if ($edit): ?> <a class="btn" href="students.php">Cancel</a><?php endif; ?></div>
    </form>
</div>
<?php endif;
page_footer();
