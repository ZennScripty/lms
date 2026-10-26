<?php
declare(strict_types=1);
use App\Core\{Auth, Authorization, Csrf, Database as DB, Validator as V};
use App\Services\ManagementService as M;

M::checkSection($section);
$def=M::definitions()[$section];
$canWrite=M::canWrite($section);
$error=null;$formData=null;
if(is_post()) {
    Csrf::verifyRequest();
    try {
        if(request_action()!=='save_record'){throw new DomainException('Unknown action.');}
        $id=V::optInt($_POST['id']??null,'ID');
        M::save($section,$id,$_POST);
        flash('success','Record saved.');
        $query=$_GET;unset($query['edit'],$query['new']);
        redirect(current_page().($query?'?'.http_build_query($query):''));
    }catch(Throwable $e){$error=handle_exception($e);$formData=$_POST;unset($formData['password']);}
}
$edit=null;
if(isset($_GET['edit'])) {
    $id=V::int($_GET['edit'],'ID',1);$edit=M::find($section,$id);
    if(!$edit){not_found();}
    if(!M::canWrite($section,isset($edit['subject_id'])?(int)$edit['subject_id']:null)){forbidden();}
    $edit=M::hydrate($section,$edit);
}
if($formData){$edit=$formData;}
$showForm=$canWrite&&(isset($_GET['new'])||isset($_GET['edit'])||$error);
$params=[];$where=M::scope($section,$params);
$q=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,100):'';
if($q!=='' && $def['search']) {
    $where.=' AND ('.implode(' OR ',array_map(fn($key)=>"x.`$key` LIKE ?",$def['search'])).')';
    foreach($def['search'] as $_){$params[]='%'.addcslashes($q,'%_\\').'%';}
}
$subjectFilter=filter_var($_GET['subject_id']??null,FILTER_VALIDATE_INT)?:null;
$extraFilters=[];
foreach(['role_id','centre_id','status'] as $filterKey){
    if(!isset($def['fields'][$filterKey]))continue;
    $f=$def['fields'][$filterKey];$fv=$_GET[$filterKey]??'';
    if(!is_scalar($fv)){$fv='';}
    $extraFilters[$filterKey]=[$f,(string)$fv];
    if($fv!==''){
        if($f[1]==='enum'&&!in_array($fv,$f[2],true)){continue;}
        if(str_starts_with($f[1],'ref')&&!ctype_digit((string)$fv)){continue;}
        $where.=" AND x.`$filterKey`=?";$params[]=$fv;
    }
}
if($subjectFilter && $section==='students'){$where.=' AND EXISTS (SELECT 1 FROM student_subjects sf WHERE sf.student_id=x.id AND sf.subject_id=? AND sf.status=\'active\')';$params[]=$subjectFilter;}
if($subjectFilter && isset($def['fields']['subject_id'])){$where.=' AND x.subject_id=?';$params[]=$subjectFilter;}
$table=$def['table'];
$perPage=in_array((int)($_GET['per_page']??20),[10,20,50],true)?(int)($_GET['per_page']??20):20;
$total=(int)DB::val("SELECT COUNT(*) FROM $table x WHERE $where",$params);$pages=max(1,(int)ceil($total/$perPage));
$page=max(1,min($pages,(int)($_GET['page']??1)));$offset=($page-1)*$perPage;
$rows=DB::all('SELECT '.M::translatedSelect($section)." FROM $table x WHERE $where ORDER BY x.id DESC LIMIT $perPage OFFSET $offset",$params);
$baseQuery=$_GET;unset($baseQuery['edit'],$baseQuery['new']);
$link=static fn(array $extra)=>current_page().'?'.http_build_query(array_merge($baseQuery,$extra));
page_header($def['title']);
if(in_array(current_page(),['modules.php','subjects.php','users.php','tests.php'],true)) {
    $tabs=match(current_page()) {
        'modules.php'=>['modules'=>'Modules','lessons'=>'Lessons','media'=>'Media library'],
        'subjects.php'=>Authorization::isSuperAdmin()?['subjects'=>'Subjects','assignments'=>'Teacher assignments']:['subjects'=>'Subjects'],
        'users.php'=>['users'=>'Users','enrolments'=>'Enrolments'],
        'tests.php'=>['tests'=>'Tests','questions'=>'Question bank'],
    };
    echo '<nav class="content-tabs" aria-label="'.e(tr('Content')).'">';
    foreach($tabs as $key=>$label){echo '<a class="btn'.($section===$key?' active':'').'" href="?view='.e($key).'">'.e(tr($label)).'</a>';}
    echo '</nav>';
}
?>
<div class="toolbar"><p><?= $total ?> <?= e(tr('records')) ?></p><?php if($canWrite): ?><a href="<?= e($link(['new'=>1])) ?>" class="btn btn-primary"><?= icon('plus') ?><?= e(tr('Add record')) ?></a><?php endif; ?></div>
<?php if($error): ?><div class="flash flash-error" role="alert"><?= e(tr($error)) ?></div><?php endif; ?>
<?php if($showForm): $row=$edit??[]; ?>
<section class="card" id="record-form"><h2><?= e(tr(isset($_GET['edit'])?'Edit record':'Add record')) ?></h2>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_record"><input type="hidden" name="id" value="<?= e((string)($row['id']??'')) ?>">
<?php foreach($def['fields'] as $key=>$field): [$label,$type]=$field;
    $default=match($key){'is_active','is_mandatory','is_downloadable'=>1,'week_no','sequence_no','default_marks'=>1,'estimated_minutes'=>10,'duration_minutes'=>10,'pass_percent'=>50,'min_complete_percent'=>80,'min_study_percent','retake_wait_hours'=>0,'attendance_date'=>date('Y-m-d'),default=>''};
    $value=$row[$key]??($key==='subject_id'?($subjectFilter??$default):$default);
?>
<?php if($type==='bool'): ?><label class="check"><input type="checkbox" name="<?= e($key) ?>" value="1"<?= checked((bool)$value) ?>><?= e(tr($label)) ?></label>
<?php else: ?><label class="<?= $type==='textarea'?'wide':'' ?>"><?= e(tr($label)) ?>
<?php if(str_starts_with($type,'ref')||$type==='enum'): ?><select name="<?= e($key) ?>" <?= $type==='ref'?'required':'' ?>>
<?php if(str_starts_with($type,'ref')): ?><option value=""><?= e(tr('Select')) ?></option><?php foreach(M::options($field[2]) as $option): ?><option value="<?= (int)$option['id'] ?>"<?= selected($option['id'],$value) ?>><?= e($field[2]==='roles'?tr($option['label']):$option['label']) ?></option><?php endforeach; ?>
<?php else: foreach($field[2] as $option): ?><option value="<?= e($option) ?>"<?= selected($option,$value) ?>><?= e(tr($option)) ?></option><?php endforeach; endif; ?></select>
<?php elseif($type==='textarea'): ?><textarea name="<?= e($key) ?>" rows="4" maxlength="<?= (int)$field[2] ?>"><?= e((string)$value) ?></textarea>
<?php elseif($type==='password'): ?><input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="200" <?= isset($_GET['edit'])?'':'required' ?>><?php if(isset($_GET['edit'])): ?><small><?= e(tr('Leave blank to keep the current password.')) ?></small><?php endif; ?>
<?php else: $htmlType=match($type){'int','int_optional'=>'number','date','required_date'=>'date','email'=>'email',default=>'text'}; ?><input type="<?= $htmlType ?>" name="<?= e($key) ?>" value="<?= e((string)$value) ?>" <?= in_array($type,['text','int','required_date'],true)?'required':'' ?> <?php if($type==='int'||$type==='int_optional'): ?>min="<?= (int)$field[2] ?>" max="<?= (int)$field[3] ?>"<?php elseif(in_array($type,['text','optional'],true)): ?>maxlength="<?= (int)$field[2] ?>"<?php endif; ?>><?php endif; ?></label><?php endif; ?>
<?php endforeach; ?>
<?php if($section==='tests'): $selectedQuestions=isset($formData['question_ids'])?V::intList($formData['question_ids']):($edit?array_map('intval',array_column(DB::all('SELECT question_id FROM test_questions WHERE test_id=?',[$edit['id']]),'question_id')):[]);$qp=[];$qw=M::scope('questions',$qp);$questions=DB::all('SELECT '.M::translatedSelect('questions')." FROM questions x WHERE $qw AND x.status='published' ORDER BY x.subject_id,x.id",$qp); ?>
<fieldset><legend><?= e(tr('Test questions')) ?></legend><div class="assignment-grid"><?php foreach($questions as $question): ?><label class="check"><input type="checkbox" name="question_ids[]" value="<?= (int)$question['id'] ?>"<?= checked(in_array((int)$question['id'],$selectedQuestions,true)) ?>>#<?= (int)$question['subject_id'] ?> · <?= e($question['display_title']) ?> (<?= e((string)$question['default_marks']) ?>)</label><?php endforeach; ?></div></fieldset>
<?php endif; ?>
<div class="form-actions"><button class="btn btn-primary"><?= icon('check') ?><?= e(tr('Save')) ?></button><a class="btn" href="<?= e($link([])) ?>"><?= e(tr('Cancel')) ?></a></div></form></section>
<?php endif; ?>
<form method="get" class="filter-bar">
<?php if(isset($_GET['view'])): ?><input type="hidden" name="view" value="<?= e($section) ?>"><?php endif; ?>
<?php if($def['search']): ?><label class="search-field"><?= e(tr('Search')) ?><input name="q" value="<?= e($q) ?>" placeholder="<?= e(tr('Search records…')) ?>" maxlength="100"></label><?php endif; ?>
<?php foreach($extraFilters as $key=>[$field,$value]): ?><label><?= e(tr($field[0])) ?><select name="<?= e($key) ?>"><option value=""><?= e(tr('All')) ?></option><?php if($field[1]==='enum'): foreach($field[2] as $v): ?><option value="<?= e($v) ?>"<?= selected($v,$value) ?>><?= e(tr($v)) ?></option><?php endforeach; else: foreach(M::options($field[2]) as $o): ?><option value="<?= (int)$o['id'] ?>"<?= selected($o['id'],$value) ?>><?= e($field[2]==='roles'?tr($o['label']):$o['label']) ?></option><?php endforeach; endif; ?></select></label><?php endforeach; ?>
<?php if(isset($def['fields']['subject_id'])||$section==='students'): ?><label><?= e(tr('Subject')) ?><select name="subject_id"><option value=""><?= e(tr('All')) ?></option><?php foreach(M::options('subjects') as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'],$subjectFilter??'') ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label><?= e(tr('Per page')) ?><select name="per_page"><?php foreach([10,20,50] as $n): ?><option<?= selected($n,$perPage) ?>><?= $n ?></option><?php endforeach; ?></select></label><button class="btn"><?= icon('search') ?><?= e(tr('Search')) ?></button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><?php foreach($def['columns'] as $label): ?><th><?= e(tr($label)) ?></th><?php endforeach; ?><th><?= e(tr('Actions')) ?></th></tr></thead><tbody>
<?php
$refKinds=['centre_id'=>'centres','batch_id'=>'batches','role_id'=>'roles','subject_id'=>'subjects','teacher_id'=>'teachers','student_id'=>'students','module_id'=>'modules','user_id'=>'attendance_users'];$refMaps=[];
foreach($refKinds as $column=>$kind){if(isset($def['columns'][$column])){$refMaps[$column]=array_column(M::options($kind),'label','id');}}
foreach($rows as $r): ?>
<tr><?php foreach($def['columns'] as $key=>$label): ?><td>
<?php if($key==='status'): ?><span class="pill <?= e($r[$key]) ?>"><?= e(tr($r[$key])) ?></span>
<?php elseif(in_array($key,['is_active','can_manage_content','can_manage_tests'],true)): ?><span class="pill <?= $r[$key]?'ok':'' ?>"><?= e(tr($r[$key]?'Active':'inactive')) ?></span>
<?php elseif(isset($refMaps[$key])): ?><?= e($key==='role_id'?tr($refMaps[$key][$r[$key]]??'—'):($refMaps[$key][$r[$key]]??'—')) ?>
<?php elseif(in_array($key,['full_name','name'],true)): ?><div class="name-cell"><span class="avatar"><?= e(mb_substr($r[$key],0,1)) ?></span><strong><?= e($r[$key]) ?></strong></div>
<?php else: ?><?= e(in_array($key,['training_mode','attendance_for','difficulty','test_type','item_type'],true)?tr((string)($r[$key]??'')):(string)($r[$key]??'—')) ?><?php endif; ?></td><?php endforeach; ?>
<td class="actions"><?php if(M::canWrite($section,isset($r['subject_id'])?(int)$r['subject_id']:null)): ?><a class="btn btn-sm" href="<?= e($link(['edit'=>$r['id']])) ?>"><?= e(tr('Edit')) ?></a><?php endif; ?>
<?php if($section==='lessons'): ?><a class="btn btn-sm" href="lesson.php?id=<?= (int)$r['id'] ?>"><?= e(tr('Preview')) ?></a><?php elseif($section==='subjects'): ?><a class="btn btn-sm" href="modules.php?subject_id=<?= (int)$r['id'] ?>"><?= e(tr('Modules')) ?></a><?php elseif(($section==='students'||($section==='users'&&(int)$r['role_id']===(int)DB::val("SELECT id FROM roles WHERE code='student'")))): ?><a class="btn btn-sm" href="student_detail.php?id=<?= (int)$r['id'] ?>"><?= e(tr('View')) ?></a><?php endif; ?></td></tr>
<?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="<?= count($def['columns'])+1 ?>"><div class="empty-state"><?= icon('search') ?><p><?= e(tr('No results found.')) ?></p></div></td></tr><?php endif; ?>
</tbody></table></div></section>
<div class="pagination"><span><?= $total ?> <?= e(tr('records')) ?> · <?= e(tr('Page')) ?> <?= $page ?> <?= e(tr('of')) ?> <?= $pages ?></span><nav aria-label="<?= e(tr('Page')) ?>"><?php if($page>1): ?><a class="btn btn-sm" href="<?= e($link(['page'=>$page-1])) ?>"><?= e(tr('Previous')) ?></a><?php endif; ?><?php if($page<$pages): ?><a class="btn btn-sm" href="<?= e($link(['page'=>$page+1])) ?>"><?= e(tr('Next')) ?></a><?php endif; ?></nav></div>
<?php page_footer();
