<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Csrf, Logger, Validator};
use App\Repositories\LearningRepository;
use App\Services\TranslationService;

$user = Auth::requireLogin();
Authorization::require('modules.view');
$canManage = Authorization::can('modules.manage');
$langs = TranslationService::enabled();
$defaultLang = TranslationService::defaultCode();

$subjectId = null;
if (isset($_GET['subject_id'])) {
    try {
        $subjectId = Validator::int($_GET['subject_id'], 'Subject', 1);
    } catch (\InvalidArgumentException) {
        not_found();
    }
    if (!Authorization::canAccessSubject($subjectId) || !LearningRepository::subject($subjectId)) {
        forbidden();
    }
}

if (is_post()) {
    Csrf::verifyRequest();
    try {
        if (!$canManage || $subjectId === null) {
            forbidden();
        }
        $action = request_action();
        $status = static fn () => Validator::inList($_POST['status'] ?? 'draft', ['draft', 'published'], 'Status');
        if ($action === 'save_module') {
            $id = Validator::optInt($_POST['module_id'] ?? null, 'Module');
            if ($id !== null) {
                $m = LearningRepository::module($id);
                if (!$m || (int) $m['subject_id'] !== $subjectId) {
                    forbidden();
                }
            }
            $mid = LearningRepository::saveModule($id, $subjectId, Validator::str($_POST['title'] ?? '', 'Title', 2, 200),
                Validator::optStr($_POST['description'] ?? '', 'Description', 2000), Validator::int($_POST['sort_order'] ?? '0', 'Order', 0, 100000), $status());
            foreach ($langs as $l) {
                if ($l['code'] === $defaultLang) {
                    continue;
                }
                LearningRepository::saveModuleTranslation($mid, $l['code'], trim((string) ($_POST['tr_title'][$l['code']] ?? '')), Validator::optStr($_POST['tr_desc'][$l['code']] ?? '', 'Description', 2000));
            }
            Logger::audit((int) $user['id'], 'module_saved', 'module', $mid);
            flash('success', 'Module saved.');
        } elseif ($action === 'delete_module') {
            $m = LearningRepository::module(Validator::int($_POST['module_id'] ?? '', 'Module', 1));
            if (!$m || (int) $m['subject_id'] !== $subjectId) {
                forbidden();
            }
            LearningRepository::deleteModule((int) $m['id']);
            flash('success', 'Module deleted.');
        } elseif ($action === 'save_lesson') {
            $m = LearningRepository::module(Validator::int($_POST['module_id'] ?? '', 'Module', 1));
            if (!$m || (int) $m['subject_id'] !== $subjectId) {
                forbidden();
            }
            $id = Validator::optInt($_POST['lesson_id'] ?? null, 'Lesson');
            if ($id !== null) {
                $l = LearningRepository::lesson($id);
                if (!$l || (int) $l['module_id'] !== (int) $m['id']) {
                    forbidden();
                }
            }
            $lid = LearningRepository::saveLesson($id, (int) $m['id'], Validator::str($_POST['title'] ?? '', 'Title', 2, 200),
                Validator::optStr($_POST['body'] ?? '', 'Body', 100000), Validator::int($_POST['sort_order'] ?? '0', 'Order', 0, 100000), $status());
            foreach ($langs as $l) {
                if ($l['code'] === $defaultLang) {
                    continue;
                }
                LearningRepository::saveLessonTranslation($lid, $l['code'], trim((string) ($_POST['tr_title'][$l['code']] ?? '')), Validator::optStr($_POST['tr_body'][$l['code']] ?? '', 'Body', 100000));
            }
            Logger::audit((int) $user['id'], 'lesson_saved', 'content_item', $lid);
            flash('success', 'Lesson saved.');
        } elseif ($action === 'delete_lesson') {
            $l = LearningRepository::lesson(Validator::int($_POST['lesson_id'] ?? '', 'Lesson', 1));
            if (!$l || (int) $l['subject_id'] !== $subjectId) {
                forbidden();
            }
            LearningRepository::deleteLesson((int) $l['id']);
            flash('success', 'Lesson deleted.');
        }
    } catch (\Throwable $e) {
        flash('error', handle_exception($e));
    }
    redirect_self();
}

page_header(t('nav.modules', 'Modules'));
if ($subjectId === null) {
    echo '<div class="card"><h2>Choose a subject</h2><ul class="plain">';
    foreach (LearningRepository::subjects(Authorization::visibleSubjectIds()) as $s) {
        echo '<li><a href="modules.php?subject_id=' . (int) $s['id'] . '">' . e($s['name']) . '</a></li>';
    }
    echo '</ul></div>';
    page_footer();
    exit;
}
$subject = LearningRepository::subject($subjectId);
$modules = LearningRepository::modules($subjectId);
$editModule = isset($_GET['edit_module']) ? LearningRepository::module(Validator::int($_GET['edit_module'], 'Module', 1)) : null;
if ($editModule && (int) $editModule['subject_id'] !== $subjectId) {
    forbidden();
}
$editLesson = isset($_GET['edit_lesson']) ? LearningRepository::lesson(Validator::int($_GET['edit_lesson'], 'Lesson', 1)) : null;
if ($editLesson && (int) $editLesson['subject_id'] !== $subjectId) {
    forbidden();
}
$trM = $editModule ? LearningRepository::moduleTranslations((int) $editModule['id']) : [];
$trL = $editLesson ? LearningRepository::lessonTranslations((int) $editLesson['id']) : [];
?>
<p><a href="subjects.php">← Subjects</a> · <strong><?= e($subject['name']) ?></strong></p>
<?php foreach ($modules as $m): ?>
<div class="card">
    <h2><?= e($m['title']) ?> <small class="muted">#<?= (int) $m['sort_order'] ?> · <?= e($m['status']) ?></small></h2>
    <?php if ($canManage): ?>
        <a class="btn btn-sm" href="?subject_id=<?= $subjectId ?>&edit_module=<?= (int) $m['id'] ?>">Edit module</a>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_module"><input type="hidden" name="module_id" value="<?= (int) $m['id'] ?>">
            <button class="btn btn-sm btn-danger" data-confirm="Delete module and its lessons?">Delete</button></form>
    <?php endif; ?>
    <table><thead><tr><th>#</th><th>Lesson</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach (LearningRepository::lessons((int) $m['id']) as $l): ?>
        <tr><td><?= (int) $l['sort_order'] ?></td><td><a href="lesson.php?id=<?= (int) $l['id'] ?>"><?= e($l['title']) ?></a></td><td><?= e($l['status']) ?></td>
        <td class="actions"><?php if ($canManage): ?><a class="btn btn-sm" href="?subject_id=<?= $subjectId ?>&edit_lesson=<?= (int) $l['id'] ?>">Edit</a>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_lesson"><input type="hidden" name="lesson_id" value="<?= (int) $l['id'] ?>">
            <button class="btn btn-sm btn-danger" data-confirm="Delete lesson?">Delete</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
<?php endforeach; ?>

<?php if ($canManage): ?>
<div class="card"><h2><?= $editModule ? 'Edit module' : 'Add module' ?></h2>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_module">
    <?php if ($editModule): ?><input type="hidden" name="module_id" value="<?= (int) $editModule['id'] ?>"><?php endif; ?>
    <label>Title <input name="title" required maxlength="200" value="<?= e($editModule['title'] ?? '') ?>"></label>
    <label>Order <input type="number" name="sort_order" min="0" value="<?= (int) ($editModule['sort_order'] ?? count($modules) + 1) ?>"></label>
    <label>Status <select name="status"><?php foreach (['draft', 'published'] as $st): ?><option<?= selected($st, $editModule['status'] ?? 'draft') ?>><?= $st ?></option><?php endforeach; ?></select></label>
    <label class="wide">Description <textarea name="description" rows="2"><?= e($editModule['description'] ?? '') ?></textarea></label>
    <?php foreach ($langs as $l): if ($l['code'] === $defaultLang) continue; ?>
        <fieldset class="wide"><legend><?= e($l['name']) ?> translation</legend>
            <label>Title <input name="tr_title[<?= e($l['code']) ?>]" maxlength="200" value="<?= e($trM[$l['code']]['title'] ?? '') ?>"></label>
            <label>Description <textarea name="tr_desc[<?= e($l['code']) ?>]" rows="2"><?= e($trM[$l['code']]['description'] ?? '') ?></textarea></label>
        </fieldset>
    <?php endforeach; ?>
    <div><button class="btn btn-primary">Save module</button><?php if ($editModule): ?> <a class="btn" href="?subject_id=<?= $subjectId ?>">Cancel</a><?php endif; ?></div>
</form></div>

<?php if ($modules): ?>
<div class="card"><h2><?= $editLesson ? 'Edit lesson' : 'Add lesson' ?></h2>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_lesson">
    <?php if ($editLesson): ?><input type="hidden" name="lesson_id" value="<?= (int) $editLesson['id'] ?>"><?php endif; ?>
    <label>Module <select name="module_id"><?php foreach ($modules as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected($m['id'], $editLesson['module_id'] ?? '') ?>><?= e($m['title']) ?></option><?php endforeach; ?></select></label>
    <label>Title <input name="title" required maxlength="200" value="<?= e($editLesson['title'] ?? '') ?>"></label>
    <label>Order <input type="number" name="sort_order" min="0" value="<?= (int) ($editLesson['sort_order'] ?? 1) ?>"></label>
    <label>Status <select name="status"><?php foreach (['draft', 'published'] as $st): ?><option<?= selected($st, $editLesson['status'] ?? 'draft') ?>><?= $st ?></option><?php endforeach; ?></select></label>
    <label class="wide">Body (plain text) <textarea name="body" rows="8"><?= e($editLesson['body'] ?? '') ?></textarea></label>
    <?php foreach ($langs as $l): if ($l['code'] === $defaultLang) continue; ?>
        <fieldset class="wide"><legend><?= e($l['name']) ?> translation</legend>
            <label>Title <input name="tr_title[<?= e($l['code']) ?>]" maxlength="200" value="<?= e($trL[$l['code']]['title'] ?? '') ?>"></label>
            <label>Body <textarea name="tr_body[<?= e($l['code']) ?>]" rows="6"><?= e($trL[$l['code']]['body'] ?? '') ?></textarea></label>
        </fieldset>
    <?php endforeach; ?>
    <div><button class="btn btn-primary">Save lesson</button><?php if ($editLesson): ?> <a class="btn" href="?subject_id=<?= $subjectId ?>">Cancel</a><?php endif; ?></div>
</form></div>
<?php endif; endif;
page_footer();
