<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/autoload.php';use App\Core\{Auth,Database as DB};use App\Services\{ManagementService as M,CourseAssignmentService as C,AssignmentAccessService as Access,StudentService as S};
$pdo=DB::pdo();$pdo->beginTransaction();$passed=0;
function brCheck(bool $ok,string $label): void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;$passed++;}
function brReject(callable $f,string $label): void {try{$f();}catch(DomainException $e){brCheck(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
function brAs(int $id): void {$_SESSION=['uid'=>$id,'last_activity'=>time()];foreach(['loaded'=>false,'user'=>null] as $k=>$v)(new ReflectionProperty(Auth::class,$k))->setValue(null,$v);}
try{
    DB::run("INSERT INTO app_settings(setting_key,setting_value) VALUES ('batch_learning_managed_rollout','0') ON DUPLICATE KEY UPDATE setting_value='0'");DB::run("INSERT INTO app_settings(setting_key,setting_value) VALUES ('batch_learning_strict_access','0') ON DUPLICATE KEY UPDATE setting_value='0'");
    $root=(int)DB::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='super_admin' AND u.status='active' AND u.deleted_at IS NULL LIMIT 1");brAs($root);
    $centre=M::save('centres',null,['name'=>'QA Batch Rollout','is_active'=>1]);$batch=M::save('batches',null,['name'=>'QA Rollout A1','centre_id'=>$centre,'training_mode'=>'community','is_active'=>1]);$other=M::save('batches',null,['name'=>'QA Unmanaged','centre_id'=>$centre,'training_mode'=>'community','is_active'=>1]);
    $users=[];foreach(['admin'=>'admin','teacher'=>'teacher','student'=>'student','legacy'=>'student'] as $tag=>$role)$users[$tag]=M::save('users',null,['full_name'=>'QA Rollout '.$tag,'username'=>'qa.rollout.'.$tag,'password'=>'QaTest@2026!','role_id'=>DB::val('SELECT id FROM roles WHERE code=?',[$role]),'centre_id'=>$centre,'centre_ids'=>[$centre],'status'=>'active']);
    $subjects=[];foreach(['HTML','PHP'] as $name){$subjects[$name]=M::save('subjects',null,['code'=>'QA-ROLLOUT-'.$name,'name'=>'QA Rollout '.$name,'is_active'=>1]);M::save('centre_subjects',null,['centre_id'=>$centre,'subject_id'=>$subjects[$name]]);}
    M::save('enrolments',null,['student_id'=>$users['student'],'subject_id'=>$subjects['PHP'],'batch_id'=>$batch,'status'=>'active']);M::save('enrolments',null,['student_id'=>$users['legacy'],'subject_id'=>$subjects['PHP'],'batch_id'=>$other,'status'=>'active']);
    $course=C::saveCourse(null,'QA-ROLLOUT','QA Course');C::setCourseSubject($course,$subjects['HTML']);C::setCentreCourse($centre,$course);C::setBatchCourse($batch,$course);C::setBatchSubjects($batch,$course,[$subjects['HTML']]);M::assignStudentBatch($users['student'],$batch);
    M::save('assignments',null,['teacher_id'=>$users['teacher'],'subject_id'=>$subjects['HTML'],'can_manage_tests'=>1]);
    brAs($users['teacher']);brCheck(!\App\Core\Authorization::canAccessSubject($subjects['HTML']),'subject grant alone cannot grant Teacher access');brCheck(!\App\Core\Authorization::canAccessStudent($users['legacy']),'legacy unmapped Student denied to Teacher');brAs($root);
    C::setTeacherAssignment($users['teacher'],$batch,$course,$subjects['HTML']);
    M::save('assignments',null,['teacher_id'=>$users['teacher'],'subject_id'=>$subjects['PHP'],'can_manage_tests'=>1]);
    brAs($users['teacher']);brCheck(\App\Core\Authorization::canAccessSubject($subjects['HTML']),'explicit Teacher tuple grants assigned subject');brCheck(!\App\Core\Authorization::canAccessSubject($subjects['PHP'])&&!M::canWrite('tests',$subjects['PHP']),'unassigned subject denied despite authoring flag');brCheck(\App\Core\Authorization::canAccessStudent($users['student']),'assigned batch Student permitted');brAs($root);
    M::assignStudentBatch($users['legacy'],$other);brAs($users['teacher']);brCheck(!\App\Core\Authorization::canAccessStudent($users['legacy']),'same-centre other batch Student denied');$fp=[];$fw=Access::teacherStudentFilter('u',null,$fp);brCheck(!DB::val('SELECT u.id FROM users u WHERE u.id=? AND '.$fw,array_merge([$users['legacy']],$fp)),'list filter blocks other batch');brAs($root);

    $qd=['subject_id'=>$subjects['HTML'],'module_id'=>'','difficulty'=>'easy','default_marks'=>1,'status'=>'published','question_en'=>'A?','question_hi'=>'ए?','correct_option'=>'A'];foreach(['a','b','c','d'] as $l){$qd['option_'.$l.'_en']=$l;$qd['option_'.$l.'_hi']=$l;}$q=M::save('questions',null,$qd);
    $td=['title'=>'','title_hi'=>'','subject_id'=>$subjects['HTML'],'module_id'=>'','test_type'=>'practice','week_no'=>1,'duration_minutes'=>0,'pass_percent'=>50,'pass_rule'=>'gte','max_attempts'=>'','retake_wait_hours'=>0,'min_study_percent'=>0,'status'=>'published','question_ids'=>[$q]];$tests=[];
    foreach(['Super'=>$root,'Admin'=>$users['admin'],'Teacher'=>$users['teacher']] as $label=>$actor){brAs($actor);$td['title']=$label.' created test';$tests[$label]=M::save('tests',null,$td);}
    brAs($root);DB::run("UPDATE app_settings SET setting_value='1' WHERE setting_key='batch_learning_managed_rollout'");brCheck(!Access::enabled(),'global strict mode stays disabled');brAs($users['student']);
    brCheck(Access::forStudent($users['student'])&&array_column(S::courses($users['student']),'id')==[$subjects['HTML']],'configured batch automatically supplies subject without individual grant');brCheck(S::enrolment($users['student'],$subjects['PHP'])===null,'stale individual grant cannot bypass configured batch');
    foreach($tests as $label=>$test){$state=S::testState($users['student'],$test);brCheck($state!==null&&$state['available'],$label.' published test visible and available to batch Student');}
    brAs($users['teacher']);foreach($tests as $label=>$test)brCheck(M::find('tests',$test)!==null,$label.' test visible to subject-assigned Teacher');
    brAs($users['legacy']);brCheck(!Access::forStudent($users['legacy'])&&count(S::courses($users['legacy']))===1,'unmapped legacy Student access preserved');brAs($root);M::assignStudentBatch($users['legacy'],$batch);brAs($users['legacy']);brCheck(array_column(S::courses($users['legacy']),'id')==[$subjects['HTML']],'explicit batch change immediately follows configured subjects');
    brAs($root);C::setBatchSubjects($batch,$course,[],true);brAs($users['student']);brCheck(Access::forStudent($users['student'])&&S::courses($users['student'])===[],'removing last subject never falls back to legacy enrolments');brAs($root);C::setBatchCourse($batch,$course,false);brCheck(Access::forStudent($users['student']),'inactive course retains managed-batch boundary');
    brReject(fn()=>M::assignSubjects('enrolments',['student_id'=>$users['student'],'subject_ids'=>[$subjects['PHP']]]),'individual grants blocked for managed Student');
    echo "$passed batch rollout checks passed; all fixtures rolled back.\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();}
