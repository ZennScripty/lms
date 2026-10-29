<?php
declare(strict_types=1);
require __DIR__.'/../app/autoload.php';
use App\Core\{Auth,Database as DB};
use App\Services\{CourseAssignmentService as C,ContentImageService as Images};
$pdo=DB::pdo();$pdo->beginTransaction();$passed=0;
function scCheck(bool $ok,string $label): void {global $passed;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$passed++;}
function scReject(callable $f,string $label): void {try{$f();}catch(DomainException|InvalidArgumentException $e){scCheck(true,$label);return;}throw new RuntimeException($label);}
try{
    $_SESSION=['uid'=>(int)DB::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='super_admin' AND u.status='active' AND u.deleted_at IS NULL LIMIT 1"),'last_activity'=>time()];
    $a=C::saveCourse(null,'QA-SC-A','QA Subject Course A');$b=C::saveCourse(null,'QA-SC-B','QA Subject Course B');
    $form=['code'=>'QA-SC-S','name'=>'QA Subject','is_active'=>1];
    scReject(fn()=>Images::save('subjects',null,$form),'missing course rejected');scCheck(!DB::val('SELECT id FROM subjects WHERE code=?',[$form['code']]),'failed save leaves no orphan subject');
    scReject(fn()=>Images::save('subjects',null,$form+['course_ids'=>['']]),'blank checkbox sentinel rejected');
    scReject(fn()=>Images::save('subjects',null,$form+['course_ids'=>'bad']),'malformed course list rejected');
    $id=Images::save('subjects',null,$form+['course_ids'=>[$a,$b,$a]]);scCheck((int)DB::val('SELECT COUNT(*) FROM course_subjects WHERE subject_id=? AND is_active=1',[$id])===2,'multiple courses linked without duplicates');
    scReject(fn()=>Images::save('subjects',$id,array_replace($form,['name'=>'Wrong edit','course_ids'=>[$a]])),'removal requires explicit confirmation');scCheck(DB::val('SELECT name FROM subjects WHERE id=?',[$id])==='QA Subject','failed course edit rolls back subject details');
    Images::save('subjects',$id,$form+['course_ids'=>[$a],'confirm_course_removal'=>'1']);scCheck((int)DB::val('SELECT is_active FROM course_subjects WHERE subject_id=? AND course_id=?',[$id,$b])===0,'confirmed removal deactivates rather than deletes link');
    scReject(fn()=>Images::save('subjects',$id,$form+['course_ids'=>[99999999]]),'forged course rejected');
    Images::save('subjects',$id,$form);scCheck((int)DB::val('SELECT COUNT(*) FROM course_subjects WHERE subject_id=? AND is_active=1',[$id])===1,'older edit clients preserve existing links');
    scReject(fn()=>Images::save('subjects',$id,$form+['course_ids'=>[],'confirm_course_removal'=>'1']),'subject editor cannot remove every course');
    DB::run('UPDATE courses SET is_active=0 WHERE id=?',[$a]);Images::save('subjects',$id,$form+['course_ids'=>[$a]]);scCheck(true,'existing inactive course preserved during edit');
    DB::run('UPDATE courses SET is_active=0 WHERE id=?',[$b]);scReject(fn()=>Images::save('subjects',$id,$form+['course_ids'=>[$a,$b]]),'new inactive course assignment rejected');
    echo "$passed subject course checks passed; fixtures rolled back.\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();}
