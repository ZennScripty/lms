<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Csrf, Logger, Validator};
use App\Repositories\{LearningRepository, UserRepository};

$user = Auth::requireLogin();
Authorization::require('subjects.view');
$scope = Authorization::visibleSubjectIds();
$canManage = Authorization::can('subjects.manage');

if (is_post()) {
    Csrf::verifyRequest();
    try {
        if (!$canManage) {
            forbidden();
        }
        $action = request_action();
        if ($action === 'save_subject') {
            $id = Validator::optInt($_POST['subject_id'] ?? null, 'Subject');
            if ($id !== null && !Authorization::canAccessSubject($id)) {
                forbidden();
            }
            if ($id === null && $scope !== null) {
                forbidden('Only a super admin can create subjects.');
            }
            $name = Validator::str($_POST['name'] ?? '', 'Name', 2, 150);
            $desc = Validator::optStr($_POST['description'] ?? '', 'Description', 2000);
            $status = Validator::inList($_POST['status'] ?? 'draft', ['draft', 'published'], 'Status');
            $sid = LearningRepository::saveSubject($id, $name, $desc, $status);
            if (Authorization::isSuperAdmin() && isset($_POST['teacher_ids_present'])) {
                $valid = array_column(UserRepository::teachers(), 'id');
                $ids = array_values(array_intersect(Validator::intList($_POST['teacher_ids'] ?? []), array_map('intval', $valid)));
                LearningRepository::setTeachers($sid, $ids);
            }
            Logger::audit((int) $user['id'], 'subject_saved', 'subject', $sid);
            flash('success', 'Subject saved.');
        } elseif ($action === 'delete_subject') {
            $id = Validator::int($_POST['subject_id'] ?? '', 'Subject', 1);
            if (!Authorization::isSuperAdmin()) {
                forbidden();
            }
            LearningRepository::deleteSubject($id);
            Logger::audit((int) $user['id'], 'subject_deleted', 'subject', $id);
            flash('success', 'Subject deleted.');
        }
    } catch (\Throwable $e) {
        flash('error', handle_exception($e));
    }
    redirect_self();
}

$subjects = LearningRepository::subjects($scope);
$teachers = Authorization::isSuperAdmin() ? UserRepository::teachers() : [];
$edit = null;
if ($canManage && isset($_GET['edit'])) {
    $eid = Validator::int($_GET['edit'], 'Subject', 1);
    if (!Authorization::canAccessSubject($eid)) {
        forbidden();
    }
    $edit = LearningRepository::subject($eid);
}
$editTeachers = $edit ? LearningRepository::teacherIds((int) $edit['id']) : [];

page_header(t('nav.subjects', 'Subjects'));
?>
<div class="card"><table><thead><tr><th>Subject</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($subjects as $s): ?>
    <tr><td><a href="modules.php?subject_id=<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a></td>
        <td><span class="pill <?= $s['status'] === 'published' ? 'ok' : 'lock' ?>"><?= e($s['status']) ?></span></td>
        <td class="actions"><?php if ($canManage): ?><a class="btn btn-sm" href="?edit=<?= (int) $s['id'] ?>">Edit</a><?php endif; ?>
        <?php if (Authorization::isSuperAdmin()): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_subject"><input type="hidden" name="subject_id" value="<?= (int) $s['id'] ?>">
            <button class="btn btn-sm btn-danger" data-confirm="Delete this subject?">Delete</button></form>
        <?php endif; ?></td></tr>
<?php endforeach; if (!$subjects): ?><tr><td colspan="3" class="muted">No subjects.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($canManage && ($edit || Authorization::isSuperAdmin())): ?>
<div class="card"><h2><?= $edit ? 'Edit subject' : 'Add subject' ?></h2>
<form method="post" class="form-grid"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save_subject">
    <?php if ($edit): ?><input type="hidden" name="subject_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
    <label>Name <input name="name" required maxlength="150" value="<?= e($edit['name'] ?? '') ?>"></label>
    <label>Status <select name="status"><?php foreach (['draft', 'published'] as $st): ?><option<?= selected($st, $edit['status'] ?? 'draft') ?>><?= $st ?></option><?php endforeach; ?></select></label>
    <label class="wide">Description <textarea name="description" rows="3" maxlength="2000"><?= e($edit['description'] ?? '') ?></textarea></label>
    <?php if ($teachers): ?>
    <fieldset><legend>Teachers</legend><input type="hidden" name="teacher_ids_present" value="1">
        <?php foreach ($teachers as $t): ?><label class="check"><input type="checkbox" name="teacher_ids[]" value="<?= (int) $t['id'] ?>"<?= checked(in_array((int) $t['id'], $editTeachers, true)) ?>> <?= e($t['name']) ?></label><?php endforeach; ?>
    </fieldset>
    <?php endif; ?>
    <div><button class="btn btn-primary">Save</button><?php if ($edit): ?> <a class="btn" href="subjects.php">Cancel</a><?php endif; ?></div>
</form></div>
<?php endif;
page_footer();
