<?php
$chartData=\App\Services\ProgressAnalyticsService::summary($analyticsCentre??null);
$overall=(float)($chartData['overall']['progress']??0);
?>
<section class="card analytics-overview"><div class="progress-ring p<?= (int)round($overall) ?>"><span><?= e((string)$overall) ?>%</span></div><div><h2><?= e(tr('Learning progress')) ?></h2><p class="muted"><?= e(tr('Average completion across student subject memberships')) ?></p><strong><?= (int)$chartData['overall']['students'] ?> <?= e(tr('Students')) ?> · <?= (int)$chartData['overall']['memberships'] ?> <?= e(tr('Subject enrolments')) ?></strong></div><a class="btn" href="reports.php"><?= e(tr('Detailed reports')) ?> →</a></section>
<div class="analytics-grid">
<?php foreach(['centres'=>'Centre-wise progress','batches'=>'Batch-wise progress','subjects'=>'Subject-wise progress'] as $key=>$heading): ?>
<section class="card"><h2><?= e(tr($heading)) ?></h2><div class="analytics-bars" role="list" aria-label="<?= e(tr($heading)) ?>">
<?php foreach($chartData[$key] as $row): ?><div class="analytics-bar" role="listitem"><div><strong><?= e($row['name']) ?></strong><span><?= (float)$row['progress'] ?>%</span></div><div class="progress-track" role="progressbar" aria-label="<?= e($row['name']) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (float)$row['progress'] ?>"><div class="progress-fill w<?= (int)round((float)$row['progress']) ?>"></div></div><small class="muted"><?= (int)$row['students'] ?> <?= e(tr('Students')) ?></small></div><?php endforeach; ?>
<?php if(!$chartData[$key]): ?><p class="empty-state"><?= e(tr('No learning data available yet.')) ?></p><?php endif; ?></div></section>
<?php endforeach; ?>
<section class="card"><h2><?= e(tr('Test performance trend')) ?></h2><p class="muted"><?= e(tr('Last six months')) ?></p><div class="score-columns">
<?php $trend=array_column($chartData['trend'],null,'month');for($i=5;$i>=0;$i--):$month=(new DateTimeImmutable('first day of this month'))->modify("-$i months");$r=$trend[$month->format('Y-m')]??null; ?><div><strong><?= $r?e($r['score']).'%':'—' ?></strong><div class="score-column"><div class="h<?= $r?(int)round((float)$r['score']):0 ?>"></div></div><small><?= e(tr($month->format('M'))) ?></small><small><?= (int)($r['attempts']??0) ?> <?= e(tr('Attempts')) ?></small></div><?php endfor; ?></div></section>
</div>
