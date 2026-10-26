<?php
use App\Core\{Authorization,Database as DB,Validator as V};use App\Services\{ManagementService as M,TranslationService};
M::checkSection('lessons');$id=V::int($_GET['id']??'','Lesson',1);$lesson=M::find('lessons',$id);if(!$lesson){not_found();}
$row=M::hydrate('lessons',$lesson);$lang=TranslationService::currentCode();$title=$row['title_'.$lang]??$row['title_en']??$lesson['display_title'];$body=$row['body_'.$lang]??$row['body_en']??'';
page_header('Lesson content'); ?>
<div class="toolbar"><a class="btn" href="modules.php?view=lessons"><?= e(tr('Lessons')) ?></a><?php if(M::canWrite('lessons',(int)$lesson['subject_id'])): ?><a class="btn btn-primary" href="modules.php?view=lessons&edit=<?= $id ?>"><?= e(tr('Edit')) ?></a><?php endif; ?></div>
<section class="card"><div class="card-heading"><h2><?= e($title) ?></h2><span class="pill <?= e($lesson['status']) ?>"><?= e(tr($lesson['status'])) ?></span></div>
<?php if(!empty($row['media_asset_id'])): $url='media.php?id='.(int)$row['media_asset_id']; ?>
<?php if($lesson['item_type']==='video'): ?><video controls preload="metadata" src="<?= e($url) ?>"></video><?php elseif($lesson['item_type']==='audio'): ?><audio controls preload="metadata" src="<?= e($url) ?>"></audio><?php elseif($lesson['item_type']==='image'): ?><img class="lesson-image" src="<?= e($url) ?>" alt="<?= e($title) ?>"><?php else: ?><a class="btn" href="<?= e($url) ?>"><?= icon('download') ?><?= e(tr('Preview')) ?></a><?php endif; ?><?php endif; ?>
<article id="lesson-text"><?= nl2br(e($body)) ?></article><div class="spaced"><button class="btn" data-tts-target="lesson-text" data-tts-lang="<?= $lang==='hi'?'hi-IN':'en-IN' ?>" aria-pressed="false"><?= icon('play') ?><?= e(tr('Listen')) ?></button><p class="muted" data-tts-notice hidden></p></div></section><?php page_footer();
