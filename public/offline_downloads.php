<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\{Auth, Authorization};
use App\Services\{LearningService, TranslationService};

$user = Auth::requireLogin();
Authorization::require('learn.access');
$uid = (int) $user['id'];
$lang = TranslationService::currentCode();

page_header(t('nav.downloads', 'Offline downloads'));
?>
<div class="card">
    <p>Download lessons while online to read them later without internet. Only lessons you have downloaded here (or opened earlier) work offline.
       Locked lessons cannot be downloaded. Text-to-speech offline depends on voices installed on your device.</p>
    <p class="muted" data-storage-info></p>
</div>
<form data-download-form data-lang="<?= e($lang) ?>">
<?php foreach (LearningService::subjectsOf($uid) as $s): ?>
    <div class="card"><h2><?= e($s['name']) ?></h2>
    <?php foreach (LearningService::tree($uid, (int) $s['id']) as $m): ?>
        <h3><?= e($m['title']) ?></h3>
        <?php foreach ($m['lessons'] as $l): ?>
            <?php if ($l['unlocked']): ?>
                <label class="check"><input type="checkbox" data-lesson-url="lesson.php?id=<?= (int) $l['id'] ?>&amp;lang=<?= e($lang) ?>"> <?= e($l['title']) ?> <span class="pill ok" data-downloaded hidden>downloaded</span></label>
            <?php else: ?><div class="muted">🔒 <?= e($l['title']) ?></div><?php endif; ?>
        <?php endforeach; ?>
    <?php endforeach; ?></div>
<?php endforeach; ?>
    <button type="button" class="btn btn-primary" data-download-selected>Download selected</button>
    <button type="button" class="btn" data-download-all>Select all</button>
    <button type="button" class="btn btn-danger" data-clear-downloads>Remove all downloads</button>
    <p class="muted" data-download-status></p>
</form>
<?php page_footer();
