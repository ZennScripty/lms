<?php
use App\Core\{Authorization,Database as DB};
$registration=$edit;$batchName=!empty($registration['batch_id'])?DB::val('SELECT name FROM batches WHERE id=? AND centre_id=?',[$registration['batch_id'],$registration['centre_id']]):null;
$requestedSubjects=DB::all('SELECT s.name FROM teacher_subjects ts JOIN subjects s ON s.id=ts.subject_id JOIN centre_subjects cs ON cs.subject_id=s.id AND cs.centre_id=? WHERE ts.teacher_id=? ORDER BY s.name',[$registration['centre_id'],$registration['id']]);
$photo=!empty($registration['profile_asset_id'])?DB::one('SELECT id,centre_id FROM media_assets WHERE id=?',[$registration['profile_asset_id']]):null;
?>
<section class="registration-review"><h3><?= e(tr('Self-registration details')) ?></h3>
<?php if($photo&&(Authorization::isSuperAdmin()||(int)$photo['centre_id']===(int)$registration['centre_id'])): ?><img src="media.php?id=<?= (int)$photo['id'] ?>" width="86" height="86" alt="<?= e(tr('Profile image')) ?>"><?php endif; ?>
<p><?= e(tr('Registration date')) ?>: <?= e(fmt_dt($registration['registration_submitted_at'])) ?></p>
<?php if($batchName): ?><p><?= e(tr('Requested batch')) ?>: <?= e($batchName) ?></p><?php endif; ?>
<?php if($requestedSubjects): ?><p><?= e(tr('Requested subjects')) ?>: <?= e(implode(', ',array_column($requestedSubjects,'name'))) ?></p><?php endif; ?>
<?php if($registration['status']==='pending'&&\App\Services\ManagementService::canWrite('users')): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="review_user"><input type="hidden" name="id" value="<?= (int)$registration['id'] ?>"><button class="btn btn-primary" name="decision" value="approve"><?= e(tr('Approve')) ?></button><button class="btn btn-danger" name="decision" value="reject"><?= e(tr('Reject')) ?></button></form><?php endif; ?>
</section>
