<?php
declare(strict_types=1);
use App\Core\{Auth, Authorization, Csrf, Database as DB, Validator as V};
use App\Services\ManagementService as M;

M::checkSection($section);
$def=M::definitions()[$section];
$canWrite=M::canWrite($section);
$roleContextId=isset($userRoleCode)?(int)DB::val('SELECT id FROM roles WHERE code=?',[$userRoleCode]):null;
if($roleContextId){
    if(!Authorization::isAdmin()||!Authorization::isSuperAdmin()&&in_array($userRoleCode,['admin','super_admin'],true))forbidden();
    $def['title']=match($userRoleCode){'student'=>'Students','teacher'=>'Teachers / Staff','admin'=>'Centre Admins',default=>'Super Admins'};
    if(is_post()&&request_action()==='save_record'){
        $target=V::optInt($_POST['id']??null,'ID');if($target&&DB::val('SELECT role_id FROM users WHERE id=?',[$target])!=$roleContextId)forbidden();
        $_POST['role_id']=$roleContextId;
    }
}

if($section==='questions'&&!is_post()&&request_action()==='question_sample'){
    $sample=APP_ROOT.'/storage/templates/question-bank-sample.xlsx';if(!is_file($sample))not_found();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="TLMTI_Question_Bank_Sample.xlsx"');header('Cache-Control: private,no-store');readfile($sample);exit;
}
$error=null;$formData=null;
if(is_post()) {
    Csrf::verifyRequest();
    if(!$canWrite){forbidden();}
    try {
        if($section==='questions'&&request_action()==='import_questions'){
            $file=$_FILES['question_file']??null;if(!$file||($file['error']??null)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new DomainException('Upload an Excel or CSV file under 2 MB.');
            $rows=\App\Services\QuestionImportService::read($file['tmp_name'],(string)$file['name']);
            $result=\App\Services\QuestionImportService::import(V::int($_POST['subject_id']??'','Subject',1),V::optInt($_POST['module_id']??null,'Module'),$rows);
            flash('success',tr('Questions imported').': '.$result['created'].' · '.tr('Duplicates skipped').': '.$result['skipped']);redirect('question_bank.php');
        }
        if($section==='users'&&in_array(request_action(),['delete_user','review_user'],true)){
            $target=V::int($_POST['id']??'','ID',1);
            if(request_action()==='delete_user'){M::deleteUser($target,($_POST['confirm_delete']??'')==='1');}
            else{M::reviewUser($target,is_string($_POST['decision']??null)?$_POST['decision']:'');}
            flash('success','Account updated.');redirect(current_page());
        }
        if(request_action()==='assign_subjects'){
            $result=M::assignSubjects($section,$_POST);
            flash('success',tr('Subjects added').': '.$result['created'].' · '.tr('Existing assignments kept').': '.$result['existing']);
            redirect(current_page().'?view='.$section);
        }
        if(request_action()!=='save_record'){throw new DomainException('Unknown action.');}
        $id=V::optInt($_POST['id']??null,'ID');
        if(in_array($section,['subjects','modules','lessons'],true)){\App\Services\ContentImageService::save($section,$id,$_POST,$_FILES['cover_image']??null);}else{M::save($section,$id,$_POST);}
        flash('success','Record saved.');
        $query=$_GET;unset($query['edit'],$query['new'],$query['detail']);
        redirect(current_page().($query?'?'.http_build_query($query):''));
    }catch(Throwable $e){$error=handle_exception($e);$formData=$_POST;unset($formData['password']);}
}
$edit=null;
if(isset($_GET['edit'])) {
    $id=V::int($_GET['edit'],'ID',1);$edit=M::find($section,$id);
    if(!$edit||$roleContextId&&(int)$edit['role_id']!==$roleContextId){not_found();}
    if(!M::canWrite($section,isset($edit['subject_id'])?(int)$edit['subject_id']:null)){forbidden();}
    $edit=M::hydrate($section,$edit);
}
if($formData){$edit=$formData;if(!empty($formData['id'])&&in_array($section,['subjects','modules','lessons'],true)){$oldCover=M::find($section,(int)$formData['id']);$edit['cover_asset_id']=$oldCover['cover_asset_id']??null;}}
$detailRow=null;if(isset($_GET['detail'])){$detailRow=M::find($section,V::int($_GET['detail'],'ID',1));if(!$detailRow||$roleContextId&&(int)$detailRow['role_id']!==$roleContextId)not_found();$detailRow=M::hydrate($section,$detailRow);}
$showForm=$canWrite&&(isset($_GET['new'])||isset($_GET['edit'])||$error&&request_action()==='save_record');
$params=[];$where=M::scope($section,$params);if($roleContextId){$where.=' AND x.role_id=?';$params[]=$roleContextId;}
$q=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,100):'';
if($q!=='' && $def['search']) {
    $where.=' AND ('.implode(' OR ',array_map(fn($key)=>"x.`$key` LIKE ?",$def['search'])).')';
    foreach($def['search'] as $_){$params[]='%'.addcslashes($q,'%_\\').'%';}
}
$subjectFilter=filter_var($_GET['subject_id']??null,FILTER_VALIDATE_INT)?:null;
$extraFilters=[];
foreach(['role_id','centre_id','status'] as $filterKey){
    if(!isset($def['fields'][$filterKey])||$roleContextId&&$filterKey==='role_id')continue;
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
$baseQuery=$_GET;unset($baseQuery['edit'],$baseQuery['new'],$baseQuery['detail']);
$link=static fn(array $extra)=>current_page().'?'.http_build_query(array_merge($baseQuery,$extra));
page_header($def['title']);
if(Authorization::isCentreAdmin()&&$section==='subjects'){echo '<p class="muted">'.e(tr('Shared subjects are read-only when another centre is affected. Contact Super Admin for shared curriculum changes.')).'</p>';}
if($section==='attendance'&&!$showForm&&!$detailRow){
    require APP_ROOT.'/app/Views/attendance_checkin.php';
    if(Authorization::isSuperAdmin()){echo '<p><a class="btn" href="app_settings.php">'.e(tr('Attendance settings')).'</a></p>';}
}
if(!$showForm&&!$detailRow&&in_array(current_page(),['modules.php','subjects.php','users.php','tests.php'],true)) {
    $tabs=match(current_page()) {
        'modules.php'=>['modules'=>'Modules','lessons'=>'Lessons','media'=>'Media library'],
        'subjects.php'=>Authorization::isSuperAdmin()?['subjects'=>'Subjects','assignments'=>'Teacher assignments','centre_subjects'=>'Centre subjects']:(Authorization::isCentreAdmin()?['subjects'=>'Subjects','assignments'=>'Teacher assignments']:['subjects'=>'Subjects']),
        'users.php'=>['users'=>'Users','enrolments'=>'Student batches'],
        'tests.php'=>['tests'=>'Tests','questions'=>'Question bank'],
    };
    echo '<nav class="content-tabs" aria-label="'.e(tr('Content')).'">';
    foreach($tabs as $key=>$label){echo '<a class="btn'.($section===$key?' active':'').'" href="'.e(['modules'=>'modules.php','lessons'=>'lessons.php','media'=>'media_library.php','subjects'=>'subjects.php','assignments'=>'teacher_assignments.php','centre_subjects'=>'centre_subjects.php','users'=>'users.php','enrolments'=>'enrolments.php','tests'=>'tests.php','questions'=>'question_bank.php'][$key]).'">'.e(tr($label)).'</a>';}
    echo '</nav>';
}
?>
<?php if($showForm||$detailRow): ?><p><a class="btn" href="<?= e($link([])) ?>">← <?= e(tr('Back to List')) ?></a></p><?php endif; ?>
<?php if($detailRow){require APP_ROOT.'/app/Views/record_detail.php';page_footer();return;} ?>
<?php if(!$showForm): ?><div class="toolbar"><p><?= $total ?> <?= e(tr('records')) ?></p><?php if($canWrite): ?><a href="<?= e($link(['new'=>1])) ?>" class="btn btn-primary"><?= icon('plus') ?><?= e(tr('Add record')) ?></a><?php endif; ?></div>

<?php if($section==='questions'&&$canWrite): ?><details class="card"><summary><strong><?= e(tr('Import question bank from Excel')) ?></strong></summary><p><?= e(tr('Select subject/module here. Upload XLSX or UTF-8 CSV, up to 500 questions and 2 MB. Keep the sample headers. Blank Hindi fields copy English; no translation is performed. Existing questions are skipped, never overwritten.')) ?></p><a class="btn" href="question_bank.php?action=question_sample"><?= e(tr('Download Excel sample')) ?></a>
<form method="post" enctype="multipart/form-data" class="form-grid spaced" data-management-form="question_import"><?= csrf_field() ?><input type="hidden" name="action" value="import_questions"><label><?= e(tr('Subject')) ?><select name="subject_id" required><option value=""><?= e(tr('Select')) ?></option><?php foreach(M::options('subjects') as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['label']) ?></option><?php endforeach; ?></select></label><label><?= e(tr('Module')) ?><select name="module_id"><option value=""><?= e(tr('Select')) ?></option><?php foreach(M::options('modules') as $o): ?><option value="<?= (int)$o['id'] ?>" data-subject="<?= (int)DB::val('SELECT subject_id FROM modules WHERE id=?',[$o['id']]) ?>"><?= e($o['label']) ?></option><?php endforeach; ?></select></label><label><?= e(tr('Excel or CSV file')) ?><input type="file" name="question_file" accept=".xlsx,.csv" required></label><div class="form-actions"><button class="btn btn-primary"><?= e(tr('Import questions')) ?></button></div></form></details><?php endif; ?>
<?php if(Authorization::isAdmin()&&in_array($section,['assignments','enrolments'],true)): $personKey=$section==='assignments'?'teacher_id':'student_id'; ?>
<details class="card"<?= $formData&&request_action()==='assign_subjects'?' open':'' ?>><summary><strong><?= e(tr('Assign multiple subjects')) ?></strong></summary>
<p class="muted"><?= e(tr('Select all required subjects. Existing assignments and progress will be kept.')) ?></p>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="assign_subjects">
<label><?= e(tr($section==='assignments'?'Teacher':'Student')) ?><select name="<?= $personKey ?>" required><option value=""><?= e(tr('Select')) ?></option><?php foreach(M::options($section==='assignments'?'teachers':'students') as $o): ?><option value="<?= (int)$o['id'] ?>"<?= selected($o['id'],$formData[$personKey]??'') ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></label>
<?php if($section==='enrolments'): ?><label><?= e(tr('Batch')) ?><select name="batch_id"><option value=""><?= e(tr('Select')) ?></option><?php foreach(M::options('batches') as $o): ?><option value="<?= (int)$o['id'] ?>"<?= selected($o['id'],$formData['batch_id']??'') ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></label><input type="hidden" name="status" value="active">
<?php else: foreach(['can_manage_content'=>'Can manage content','can_manage_tests'=>'Can manage tests'] as $key=>$label): ?><label class="check"><input type="checkbox" name="<?= $key ?>" value="1"<?= checked(isset($formData[$key])) ?>><?= e(tr($label)) ?></label><?php endforeach; endif; ?>
<fieldset class="wide"><legend><?= e(tr('Subjects')) ?></legend><div class="assignment-grid"><?php foreach(M::options('subjects') as $o): if(!$o['is_active'])continue; ?><label class="check"><input type="checkbox" name="subject_ids[]" value="<?= (int)$o['id'] ?>"<?= checked(in_array((string)$o['id'],array_map('strval',is_array($formData['subject_ids']??null)?$formData['subject_ids']:[]),true)) ?>><?= e($o['label']) ?></label><?php endforeach; ?></div></fieldset>
<div class="form-actions"><button class="btn btn-primary"><?= e(tr('Assign selected subjects')) ?></button></div></form></details>
<?php endif; ?>
<?php endif; ?>
<?php if($error): ?><div class="flash flash-error" role="alert"><?= e(tr($error)) ?></div><?php endif; ?>
<?php if($showForm): $row=$edit??[]; ?>
<section class="card" id="record-form"><h2><?= e(tr(isset($_GET['edit'])?'Edit record':'Add record')) ?></h2>
<?php if($section==='users'&&!empty($edit['registration_submitted_at'])){require APP_ROOT.'/app/Views/registration_summary.php';} ?>
<form method="post" enctype="multipart/form-data" class="form-grid" data-catalogue-form data-management-form="<?= e($section) ?>" data-editing="<?= !empty($row['id'])?'1':'0' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save_record"><input type="hidden" name="id" value="<?= e((string)($row['id']??'')) ?>">
<?php foreach($def['fields'] as $key=>$field): [$label,$type]=$field;if($roleContextId&&$key==='role_id'){echo '<input type="hidden" name="role_id" value="'.$roleContextId.'" data-role="'.e($userRoleCode).'">';continue;}
    $default=match($key){'is_active','is_mandatory','is_downloadable'=>1,'week_no','sequence_no','default_marks'=>1,'estimated_minutes'=>10,'duration_minutes'=>10,'pass_percent'=>50,'min_complete_percent'=>80,'min_study_percent','retake_wait_hours'=>0,'attendance_date'=>date('Y-m-d'),default=>''};
    $value=$row[$key]??($key==='subject_id'?($subjectFilter??$default):$default);
?>
<?php if($type==='bool'): ?><label class="check"><input type="checkbox" name="<?= e($key) ?>" value="1"<?= checked((bool)$value) ?>><?= e(tr($label)) ?></label>
<?php else: ?><label class="<?= $type==='textarea'?'wide':'' ?>"><?= e(tr($label)) ?>
<?php if(str_starts_with($type,'ref')||$type==='enum'): ?><select name="<?= e($key) ?>" <?= $type==='ref'?'required':'' ?>>
<?php if(str_starts_with($type,'ref')): ?><option value=""><?= e(tr('Select')) ?></option><?php foreach(M::options($field[2]) as $option): $meta=[];
if($field[2]==='batches')$meta['centre']=DB::val('SELECT centre_id FROM batches WHERE id=?',[$option['id']]);
if($field[2]==='roles')$meta['role']=DB::val('SELECT code FROM roles WHERE id=?',[$option['id']]);
if($field[2]==='modules'){$meta['subject']=DB::val('SELECT subject_id FROM modules WHERE id=?',[$option['id']]);foreach(DB::all('SELECT l.code,t.title FROM module_translations t JOIN languages l ON l.id=t.language_id WHERE t.module_id=? AND l.code IN (\'en\',\'hi\')',[$option['id']]) as $mt)$meta['title-'.$mt['code']]=$mt['title'];}
?><option value="<?= (int)$option['id'] ?>"<?php foreach($meta as $mk=>$mv): ?> data-<?= e($mk) ?>="<?= e((string)$mv) ?>"<?php endforeach; ?><?= selected($option['id'],$value) ?>><?= e($field[2]==='roles'?tr($option['label']):$option['label']) ?></option><?php endforeach; ?>
<?php else: foreach($field[2] as $option): ?><option value="<?= e($option) ?>"<?= selected($option,$value) ?>><?= e(tr($option)) ?></option><?php endforeach; endif; ?></select>
<?php elseif($type==='textarea'): ?><textarea name="<?= e($key) ?>" rows="4" maxlength="<?= (int)$field[2] ?>"><?= e((string)$value) ?></textarea>
<?php elseif($type==='password'): ?><input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="200" <?= isset($_GET['edit'])?'':'required' ?>><?php if(isset($_GET['edit'])): ?><small><?= e(tr('Leave blank to keep the current password.')) ?></small><?php endif; ?>
<?php else: $htmlType=match($type){'int','int_optional'=>'number','date','required_date'=>'date','email'=>'email',default=>'text'}; ?><input type="<?= $htmlType ?>" name="<?= e($key) ?>" value="<?= e((string)$value) ?>" <?= in_array($type,['text','int','required_date'],true)?'required':'' ?> <?php if($type==='int'||$type==='int_optional'): ?>min="<?= (int)$field[2] ?>" max="<?= (int)$field[3] ?>"<?php elseif(in_array($type,['text','optional'],true)): ?>maxlength="<?= (int)$field[2] ?>"<?php endif; ?>><?php endif; ?></label><?php endif; ?>
<?php endforeach; ?>
<?php if($section==='subjects'&&Authorization::isSuperAdmin()):
$selectedCourses=$formData['course_ids']??(!empty($row['id'])?array_column(DB::all('SELECT course_id FROM course_subjects WHERE subject_id=? AND is_active=1',[$row['id']]),'course_id'):[]);
$selectedCourses=is_array($selectedCourses)?array_map('intval',$selectedCourses):[];
$courseOptions=DB::all('SELECT id,name,is_active FROM courses ORDER BY name'); ?>
<fieldset class="wide" data-subject-courses><legend><?= e(tr('Courses')) ?> *</legend><p class="muted"><?= e(tr('Select at least one course. A subject can be shared by multiple courses.')) ?></p><input type="hidden" name="course_ids[]" value=""><div class="assignment-grid">
<?php foreach($courseOptions as $c): if(!$c['is_active']&&!in_array((int)$c['id'],$selectedCourses,true))continue; ?><label class="check"><input type="checkbox" name="course_ids[]" value="<?= (int)$c['id'] ?>"<?= checked(in_array((int)$c['id'],$selectedCourses,true)) ?>><?= e($c['name']) ?><?= !$c['is_active']?' · '.e(tr('inactive')):'' ?></label><?php endforeach; ?></div>
<?php if(!$courseOptions): ?><p><a href="courses.php?new=1"><?= e(tr('Add course')) ?></a></p><?php endif; ?>
<?php if(!empty($row['id'])): ?><label class="check spaced"><input type="checkbox" name="confirm_course_removal" value="1"><?= e(tr('Confirm course removal. Batch access may be affected; history will be retained.')) ?></label><?php endif; ?></fieldset>
<?php endif; ?>
<?php if(in_array($section,['subjects','modules','lessons'],true)){require APP_ROOT.'/app/Views/cover_editor.php';} ?>
<?php if($section==='users'&&Authorization::isSuperAdmin()&&(!$roleContextId||$userRoleCode==='admin')): $assignedCentres=$formData['centre_ids']??($edit?array_column(DB::all('SELECT centre_id FROM admin_centres WHERE admin_id=?',[$edit['id']]),'centre_id'):[]); ?>
<input type="hidden" name="centres_submitted" value="1">
<fieldset class="wide"><legend><?= e(tr('Admin centre access')) ?></legend><p class="muted"><?= e(tr('Required for Admin accounts. Super Admin keeps access to all centres.')) ?></p><div class="assignment-grid"><?php foreach(M::options('centres') as $centre): ?><label class="check"><input type="checkbox" name="centre_ids[]" value="<?= (int)$centre['id'] ?>"<?= checked(in_array((string)$centre['id'],array_map('strval',is_array($assignedCentres)?$assignedCentres:[]),true)) ?>><?= e($centre['label']) ?></label><?php endforeach; ?></div></fieldset>
<?php endif; ?>
<?php if($section==='tests'): $selectedQuestions=isset($formData['question_ids'])?V::intList($formData['question_ids']):($edit?array_map('intval',array_column(DB::all('SELECT question_id FROM test_questions WHERE test_id=?',[$edit['id']]),'question_id')):[]);$qp=[];$qw=M::scope('questions',$qp);$questions=DB::all('SELECT '.M::translatedSelect('questions')." FROM questions x WHERE $qw AND x.status='published' ORDER BY x.subject_id,x.id",$qp); ?>
<fieldset><legend><?= e(tr('Test questions')) ?></legend><div class="assignment-grid"><?php foreach($questions as $question): ?><label class="check" data-question-subject="<?= (int)$question['subject_id'] ?>" data-question-module="<?= e((string)($question['module_id']??'')) ?>"><input type="checkbox" name="question_ids[]" value="<?= (int)$question['id'] ?>"<?= checked(in_array((int)$question['id'],$selectedQuestions,true)) ?>>#<?= (int)$question['subject_id'] ?> · <?= e($question['display_title']) ?> (<?= e((string)$question['default_marks']) ?>)</label><?php endforeach; ?></div></fieldset>
<?php endif; ?>
<div class="form-actions"><button class="btn btn-primary"><?= icon('check') ?><?= e(tr('Save')) ?></button><a class="btn" href="<?= e($link([])) ?>"><?= e(tr('Cancel')) ?></a></div></form></section>
<?php endif; ?>
<?php if($showForm){page_footer();return;} ?>
<form method="get" class="filter-bar">
<?php if(isset($_GET['view'])): ?><input type="hidden" name="view" value="<?= e($section) ?>"><?php endif; ?>
<?php if($def['search']): ?><label class="search-field"><?= e(tr('Search')) ?><input name="q" value="<?= e($q) ?>" placeholder="<?= e(tr('Search records…')) ?>" maxlength="100"></label><?php endif; ?>
<?php foreach($extraFilters as $key=>[$field,$value]): ?><label><?= e(tr($field[0])) ?><select name="<?= e($key) ?>"><option value=""><?= e(tr('All')) ?></option><?php if($field[1]==='enum'): foreach($field[2] as $v): ?><option value="<?= e($v) ?>"<?= selected($v,$value) ?>><?= e(tr($v)) ?></option><?php endforeach; else: foreach(M::options($field[2]) as $o): ?><option value="<?= (int)$o['id'] ?>"<?= selected($o['id'],$value) ?>><?= e($field[2]==='roles'?tr($o['label']):$o['label']) ?></option><?php endforeach; endif; ?></select></label><?php endforeach; ?>
<?php if(isset($def['fields']['subject_id'])||$section==='students'): ?><label><?= e(tr('Subject')) ?><select name="subject_id"><option value=""><?= e(tr('All')) ?></option><?php foreach(M::options('subjects') as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'],$subjectFilter??'') ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label><?= e(tr('Per page')) ?><select name="per_page"><?php foreach([10,20,50] as $n): ?><option<?= selected($n,$perPage) ?>><?= $n ?></option><?php endforeach; ?></select></label><button class="btn"><?= icon('search') ?><?= e(tr('Search')) ?></button></form>
<?php if(in_array($section,['subjects','modules','lessons'],true)){require APP_ROOT.'/app/Views/content_cards.php';}else{ ?>
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
<td class="actions"><a class="btn btn-sm" href="<?= e($link(['detail'=>$r['id']])) ?>"><?= e(tr('View')) ?></a><?php if(M::canWrite($section,isset($r['subject_id'])?(int)$r['subject_id']:null)): ?><a class="btn btn-sm" href="<?= e($link(['edit'=>$r['id']])) ?>"><?= e(tr('Edit')) ?></a><?php endif; ?>
<?php if($section==='users'&&M::canWrite('users')&&Authorization::canManageUser((int)$r['id'])): ?>
<?php if($r['status']==='pending'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="review_user"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm" name="decision" value="approve"><?= e(tr('Approve')) ?></button><button class="btn btn-sm" name="decision" value="reject"><?= e(tr('Reject')) ?></button></form><?php endif; ?>
<?php if((int)$r['id']!==(int)Auth::user()['id']): ?><details><summary><?= e(tr('Delete account')) ?></summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><label class="check"><input type="checkbox" name="confirm_delete" value="1" required><?= e(tr('Confirm deletion. History will be retained.')) ?></label><button class="btn btn-sm btn-danger"><?= e(tr('Delete account')) ?></button></form></details><?php endif; ?>
<?php endif; ?>
<?php if($section==='attendance'&&!empty($r['selfie_path'])): ?><a class="btn btn-sm" href="attendance_selfie.php?id=<?= (int)$r['id'] ?>"><?= e(tr('View selfie')) ?></a><?php endif; ?>
<?php if($section==='lessons'): ?><a class="btn btn-sm" href="lesson.php?id=<?= (int)$r['id'] ?>"><?= e(tr('Preview')) ?></a><?php elseif($section==='subjects'): ?><a class="btn btn-sm" href="modules.php?subject_id=<?= (int)$r['id'] ?>"><?= e(tr('Modules')) ?></a><?php elseif(($section==='students'||($section==='users'&&(int)$r['role_id']===(int)DB::val("SELECT id FROM roles WHERE code='student'")))): ?><a class="btn btn-sm" href="student_detail.php?id=<?= (int)$r['id'] ?>"><?= e(tr('View progress')) ?></a><?php endif; ?></td></tr>
<?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="<?= count($def['columns'])+1 ?>"><div class="empty-state"><?= icon('search') ?><p><?= e(tr('No results found.')) ?></p></div></td></tr><?php endif; ?>
</tbody></table></div></section><?php } ?>
<div class="pagination"><span><?= $total ?> <?= e(tr('records')) ?> · <?= e(tr('Page')) ?> <?= $page ?> <?= e(tr('of')) ?> <?= $pages ?></span><nav aria-label="<?= e(tr('Page')) ?>"><?php if($page>1): ?><a class="btn btn-sm" href="<?= e($link(['page'=>$page-1])) ?>"><?= e(tr('Previous')) ?></a><?php endif; ?><?php if($page<$pages): ?><a class="btn btn-sm" href="<?= e($link(['page'=>$page+1])) ?>"><?= e(tr('Next')) ?></a><?php endif; ?></nav></div>
<?php page_footer();
