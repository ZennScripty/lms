<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Core\{Auth,Authorization as A,Csrf,Database as DB,Validator as V};
use App\Services\{RegistrationReviewService as R,ManagementService as M};
Auth::requireLogin();if(!A::isAdmin()){forbidden();}R::guard();
$error=null;$confirm=null;
if(is_post()){
    Csrf::verifyRequest();
    try{
        $id=V::int($_POST['id']??'','ID',1);$target=R::find($id);if(!$target){not_found();}
        if(!M::canWrite('users')){forbidden();}
        $decision=V::inList($_POST['decision']??'',['approve','reject','delete'],'Decision');
        if(in_array($decision,['reject','delete'],true)&&($_POST['confirmed']??'')!=='1'){$confirm=[$target,$decision];}
        else{R::act($id,$decision,($_POST['confirmed']??'')==='1');flash('success','Account updated.');redirect('registrations.php');}
    }catch(Throwable $e){$error=handle_exception($e);}
}
$detail=null;if(isset($_GET['id'])){$detail=R::find(V::int($_GET['id'],'ID',1));if(!$detail){not_found();}}
try{$list=R::listing($_GET);}catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();$list=R::listing([]);}
$p=[];$where=A::centreWhere('c.id',$p);$centres=DB::all("SELECT c.id,c.name FROM centres c WHERE $where ORDER BY c.name",$p);
page_header('Registration approvals');
function registrationActions(array $row): void { if(!M::canWrite('users'))return; ?>
<form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><?php if($row['status']==='pending'): ?><button class="btn btn-primary" name="decision" value="approve"><?= e(tr('Approve')) ?></button><button class="btn" name="decision" value="reject"><?= e(tr('Reject')) ?></button><?php endif; ?><button class="btn btn-danger" name="decision" value="delete"><?= e(tr('Delete')) ?></button></form>
<?php }
?>
<?php if($error): ?><div class="flash flash-error" role="alert"><?= e(tr($error)) ?></div><?php endif; ?>
<?php if($confirm): [$target,$decision]=$confirm; ?><section class="card" role="alert"><h2><?= e(tr('Confirm registration action')) ?></h2><p><?= e($target['full_name']) ?> · <?= e(tr($decision==='delete'?'Delete this account? Learning history will be retained.':'Reject this registration?')) ?></p><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$target['id'] ?>"><input type="hidden" name="decision" value="<?= e($decision) ?>"><input type="hidden" name="confirmed" value="1"><button class="btn btn-danger"><?= e(tr('Confirm')) ?></button><a class="btn" href="registrations.php"><?= e(tr('Cancel')) ?></a></form></section><?php endif; ?>
<?php if($detail): ?><section class="card"><h2><?= e($detail['full_name']) ?></h2><p><?= e($detail['email']??'') ?> · <?= e(tr($detail['account_type'])) ?> · <?= e($detail['centre_name']??'') ?> · <?= e(tr($detail['status'])) ?></p><?php $edit=$detail;if(!empty($detail['registration_submitted_at'])){require APP_ROOT.'/app/Views/registration_summary.php';}else{registrationActions($detail);} ?><p><?= e(tr('Reviewed on')) ?>: <?= e($detail['reviewed_at']?fmt_dt($detail['reviewed_at']):'—') ?></p><?php if($detail['status']!=='pending'||!empty($detail['registration_submitted_at'])): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>"><button class="btn btn-danger" name="decision" value="delete"><?= e(tr('Delete')) ?></button></form><?php endif; ?><a class="btn" href="registrations.php"><?= e(tr('Back')) ?></a></section><?php endif; ?>
<section class="card"><h2><?= e(tr('Registration approvals')) ?></h2><form method="get" class="form-grid">
<label><?= e(tr('Search')) ?><input name="q" maxlength="120" value="<?= e($list['q']) ?>"></label><label><?= e(tr('Status')) ?><select name="status"><?php foreach(['pending','active','rejected','all'] as $v): ?><option value="<?= $v ?>"<?= selected($list['status'],$v) ?>><?= e(tr($v==='all'?'All':$v)) ?></option><?php endforeach; ?></select></label>
<label><?= e(tr('Account type')) ?><select name="type"><?php foreach(['all','student','teacher'] as $v): ?><option value="<?= $v ?>"<?= selected($list['type'],$v) ?>><?= e(tr($v==='all'?'All':$v)) ?></option><?php endforeach; ?></select></label><label><?= e(tr('Centre')) ?><select name="centre_id"><option value=""><?= e(tr('All')) ?></option><?php foreach($centres as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($list['centre']??'',$c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label><div class="form-actions"><button class="btn btn-primary"><?= e(tr('Filter')) ?></button></div></form></section>
<?php if(!$list['rows']): ?><div class="card empty-state"><?= e(tr('No registrations found.')) ?></div><?php endif; ?>
<?php foreach($list['rows'] as $row): ?><article class="card"><h2><a href="registrations.php?id=<?= (int)$row['id'] ?>"><?= e($row['full_name']) ?></a></h2>
<?php if($row['profile_asset_id']): ?><img src="media.php?id=<?= (int)$row['profile_asset_id'] ?>" width="64" height="64" alt="<?= e(tr('Profile image')) ?>"><?php endif; ?>
<p><?= e($row['email']??'') ?> · <?= e(tr($row['account_type'])) ?> · <?= e(tr($row['status'])) ?></p><p><?= e($row['centre_name']??'') ?><?php if($row['batch_name']): ?> · <?= e($row['batch_name']) ?><?php endif; ?></p>
<?php if($row['account_type']==='teacher'): $names=DB::all('SELECT s.name FROM teacher_subjects ts JOIN subjects s ON s.id=ts.subject_id JOIN centre_subjects cs ON cs.subject_id=s.id AND cs.centre_id=? WHERE ts.teacher_id=? ORDER BY s.name',[$row['centre_id'],$row['id']]); ?><p><?= e(tr('Requested subjects')) ?>: <?= e(implode(', ',array_column($names,'name'))) ?></p><?php endif; ?>
<p><?= e(tr('Registration date')) ?>: <?= e(fmt_dt($row['registration_submitted_at']??$row['created_at'])) ?></p><a class="btn" href="registrations.php?id=<?= (int)$row['id'] ?>"><?= e(tr('View details')) ?></a><?php registrationActions($row); ?></article><?php endforeach; ?>
<div class="pagination"><span><?= $list['total'] ?> <?= e(tr('records')) ?></span><nav><?php foreach(['Previous'=>-1,'Next'=>1] as $label=>$step): $next=$list['page']+$step;if($next<1||$next>$list['pages'])continue;$query=['status'=>$list['status'],'type'=>$list['type'],'centre_id'=>$list['centre'],'q'=>$list['q'],'page'=>$next]; ?><a class="btn" href="?<?= e(http_build_query($query)) ?>"><?= e(tr($label)) ?></a><?php endforeach; ?></nav></div>
<?php page_footer();
