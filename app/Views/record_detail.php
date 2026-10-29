<?php
use App\Services\ManagementService as M;
// Only fields declared by the management schema are displayed; credentials are excluded.
?>
<section class="card record-detail"><div class="card-heading"><h2><?= e(tr('View')) ?> · <?= e(tr($def['title'])) ?></h2><?php if(M::canWrite($section,isset($detailRow['subject_id'])?(int)$detailRow['subject_id']:null)): ?><a class="btn" href="<?= e($link(['edit'=>$detailRow['id']])) ?>"><?= e(tr('Edit')) ?></a><?php endif; ?></div>
<?php if(in_array($section,['subjects','modules','lessons'],true))echo \App\Services\ContentImageService::thumbnail($detailRow['cover_asset_id']??null,''); ?>
<dl class="record-fields"><?php foreach($def['fields'] as $fieldKey=>$field): if($field[1]==='password')continue;$value=$detailRow[$fieldKey]??null;
if(str_starts_with($field[1],'ref')&&$value){$options=array_column(M::options($field[2]),'label','id');$value=$options[$value]??'—';}
elseif($field[1]==='bool')$value=$value?tr('Yes'):tr('No');
elseif($field[1]==='enum')$value=tr((string)$value);
?><div><dt><?= e(tr($field[0])) ?></dt><dd><?= nl2br(e($value===null||$value===''?'—':(string)$value)) ?></dd></div><?php endforeach; ?></dl></section>
