<?php
use App\Core\{Authorization as A,Csrf,Database as DB,Validator as V};
use App\Services\{ManagementService as M,AssignmentAccessService as Access};
if(!A::isAdmin())forbidden();M::checkSection('users');$error=null;
if(is_post()){
    Csrf::verifyRequest();try{
        if(request_action()!=='assign_student_batch')forbidden();
        M::assignStudentBatch(V::int($_POST['student_id']??'','Student',1),V::int($_POST['batch_id']??'','Batch',1));flash('success','Student batch assigned. Subjects follow the batch automatically.');redirect_self();
    }catch(Throwable $e){$error=handle_exception($e);}
}
$p=[];$w=M::scope('students',$p);$q=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,100):'';
if($q!==''){$w.=' AND (x.full_name LIKE ? OR x.username LIKE ?)';$p[]='%'.addcslashes($q,'%_\\').'%';$p[]='%'.addcslashes($q,'%_\\').'%';}
$total=(int)DB::val("SELECT COUNT(*) FROM users x WHERE $w",$p);$pages=max(1,(int)ceil($total/20));$page=max(1,min($pages,(int)($_GET['page']??1)));$offset=($page-1)*20;
$rows=DB::all("SELECT x.*,c.name AS centre_name,b.name AS batch_name FROM users x LEFT JOIN centres c ON c.id=x.centre_id LEFT JOIN batches b ON b.id=x.batch_id AND b.centre_id=x.centre_id WHERE $w ORDER BY x.full_name,x.id LIMIT 20 OFFSET $offset",$p);
page_header('Student batches'); ?>
<p class="muted"><?= e(tr('Assign a batch to the Student. Subjects and published tests follow the configured batch; individual subject assignment is not needed.')) ?></p><a class="btn" href="course_assignments.php"><?= e(tr('Configure batch subjects')) ?></a>
<?php if($error): ?><div class="flash flash-error" role="alert"><?= e(tr($error)) ?></div><?php endif; ?>
<form method="get" class="filter-bar"><label><?= e(tr('Search')) ?><input name="q" value="<?= e($q) ?>"></label><button class="btn"><?= e(tr('Search')) ?></button></form>
<div class="assignment-columns"><?php foreach($rows as $r): $batches=DB::all('SELECT id,name FROM batches WHERE centre_id=? AND is_active=1 ORDER BY name',[$r['centre_id']]);$ids=Access::forStudent((int)$r['id'])?Access::studentSubjectIds((int)$r['id']):[]; ?>
<section class="card"><h2><?= e($r['full_name']) ?></h2><p><?= e($r['centre_name']??'—') ?> · <?= e($r['batch_name']??tr('No batch selected')) ?></p>
<?php if(A::can('students.manage')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign_student_batch"><input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>"><label><?= e(tr('Student batch')) ?><select name="batch_id" required><option value=""><?= e(tr('Select')) ?></option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>"<?= selected($b['id'],$r['batch_id']??'') ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary"><?= e(tr('Assign batch')) ?></button></form><?php endif; ?>
<p class="muted"><?= e(tr(Access::forStudent((int)$r['id'])?'Subjects from batch':'Legacy access preserved until batch setup')) ?></p><?php if($ids): $names=DB::all('SELECT name FROM subjects WHERE id IN ('.DB::in($ids).') ORDER BY name',$ids); ?><p><?= e(implode(' · ',array_column($names,'name'))) ?></p><?php else: ?><p><?= e(tr('Configure courses and subjects for the selected batch.')) ?></p><?php endif; ?></section>
<?php endforeach; ?></div><?php if(!$rows): ?><section class="card empty-state"><?= e(tr('No results found.')) ?></section><?php endif; ?>
<div class="pagination"><span><?= $total ?> <?= e(tr('records')) ?></span><nav><?php foreach(['Previous'=>$page-1,'Next'=>$page+1] as $label=>$n): if($n<1||$n>$pages)continue; ?><a class="btn" href="?<?= e(http_build_query(['q'=>$q,'page'=>$n])) ?>"><?= e(tr($label)) ?></a><?php endforeach; ?></nav></div><?php page_footer();
