<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit;}
require __DIR__.'/../app/autoload.php';
use App\Core\{Database as DB,Auth};use App\Services\ManagementService as M;
$_SESSION=['uid'=>(int)DB::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='super_admin' AND u.status='active' AND u.deleted_at IS NULL LIMIT 1"),'last_activity'=>time()];
$pdo=DB::pdo();$pdo->beginTransaction();$passed=0;
function expect(bool $ok,string $name):void{global $passed;if(!$ok){throw new RuntimeException('FAIL '.$name);}$passed++;echo "PASS $name\n";}
try{
    $centre=M::save('centres',null,['name'=>'QA transaction centre','district'=>'QA','address'=>'Test only','is_active'=>1]);expect((bool)M::find('centres',$centre),'create centre');
    $batch=M::save('batches',null,['name'=>'QA batch','centre_id'=>$centre,'training_mode'=>'community','start_date'=>'2026-01-01','end_date'=>'2026-12-31','is_active'=>1]);expect((bool)M::find('batches',$batch),'create batch');
    $teacherRole=DB::val("SELECT id FROM roles WHERE code='teacher'");$studentRole=DB::val("SELECT id FROM roles WHERE code='student'");
    $userData=['full_name'=>'QA Teacher','username'=>'qa.transaction.teacher','email'=>'qa.teacher@example.test','phone'=>'','role_id'=>$teacherRole,'centre_id'=>$centre,'password'=>'QaTest@2026!','status'=>'active'];
    $teacher=M::save('users',null,$userData);expect(password_verify('QaTest@2026!',DB::val('SELECT password_hash FROM users WHERE id=?',[$teacher])),'teacher password hashed');
    $userData['full_name']='QA Student';$userData['username']='qa.transaction.student';$userData['email']='qa.student@example.test';$userData['role_id']=$studentRole;$student=M::save('users',null,$userData);
    $subject=M::save('subjects',null,['code'=>'QA-TRANSACTION','name'=>'QA subject','description'=>'Test subject','is_active'=>1]);expect((bool)M::find('subjects',$subject),'create subject');
    M::save('assignments',null,['teacher_id'=>$teacher,'subject_id'=>$subject,'can_manage_content'=>1,'can_manage_tests'=>1]);expect((int)DB::val('SELECT can_manage_tests FROM teacher_subjects WHERE teacher_id=? AND subject_id=?',[$teacher,$subject])===1,'teacher subject permissions saved');
    M::save('enrolments',null,['student_id'=>$student,'subject_id'=>$subject,'batch_id'=>$batch,'status'=>'active']);expect(DB::val('SELECT status FROM student_week_state WHERE student_id=? AND subject_id=?',[$student,$subject])==='open','enrolment opens first week');
    $moduleData=['subject_id'=>$subject,'code'=>'QA-M1','week_no'=>1,'sequence_no'=>1,'estimated_minutes'=>15,'status'=>'published','title_en'=>'QA module','title_hi'=>'नमूना मॉड्यूल','description_en'=>'English description','description_hi'=>'हिन्दी विवरण'];
    $module=M::save('modules',null,$moduleData);expect((int)DB::val('SELECT COUNT(*) FROM module_translations WHERE module_id=?',[$module])===2,'module bilingual translations');
    $lessonData=['module_id'=>$module,'item_type'=>'text','sequence_no'=>1,'status'=>'published','is_mandatory'=>1,'is_downloadable'=>1,'min_complete_percent'=>80,'title_en'=>'QA lesson','title_hi'=>'नमूना पाठ','body_en'=>'Text lesson','body_hi'=>'हिन्दी पाठ','media_asset_id'=>''];
    $lesson=M::save('lessons',null,$lessonData);expect((int)DB::val('SELECT subject_id FROM content_items WHERE id=?',[$lesson])===$subject,'lesson inherits module subject');
    $questionData=['subject_id'=>$subject,'module_id'=>$module,'difficulty'=>'easy','default_marks'=>2,'status'=>'published','question_en'=>'Safe tool?','question_hi'=>'सुरक्षित उपकरण?','correct_option'=>'A'];foreach(['a','b','c','d'] as $letter){$questionData['option_'.$letter.'_en']='Option '.$letter;$questionData['option_'.$letter.'_hi']='विकल्प '.$letter;}
    $question=M::save('questions',null,$questionData);expect((int)DB::val('SELECT COUNT(*) FROM question_options WHERE question_id=?',[$question])===4,'question with four options');expect((int)DB::val('SELECT SUM(is_correct) FROM question_options WHERE question_id=?',[$question])===1,'exactly one correct option');
    $testData=['title'=>'QA test','subject_id'=>$subject,'test_type'=>'module','module_id'=>$module,'week_no'=>1,'duration_minutes'=>10,'pass_percent'=>50,'pass_rule'=>'gte','max_attempts'=>'','retake_wait_hours'=>0,'min_study_percent'=>0,'status'=>'published','question_ids'=>[$question]];
    $test=M::save('tests',null,$testData);expect((float)DB::val('SELECT total_marks FROM tests WHERE id=?',[$test])===2.0,'test total derived from questions');
    $testData['question_ids']=[];try{M::save('tests',$test,$testData);expect(false,'empty published test rejected');}catch(DomainException $e){expect(str_contains($e->getMessage(),'question'),'empty published test rejected');}
    $attendanceData=['user_id'=>$student,'attendance_date'=>'2026-10-26','attendance_for'=>'student','status'=>'present','remarks'=>'QA'];$attendance=M::save('attendance',null,$attendanceData);expect((bool)M::find('attendance',$attendance),'attendance saved');
    try{M::save('attendance',null,$attendanceData);expect(false,'duplicate attendance rejected');}catch(DomainException $e){expect(str_contains($e->getMessage(),'already'),'duplicate attendance rejected');}
    $attendanceData['status']='late';M::save('attendance',$attendance,$attendanceData);expect(DB::val('SELECT status FROM attendance_records WHERE id=?',[$attendance])==='late','attendance correction');
    $moduleData['title_en']='Updated module';M::save('modules',$module,$moduleData);expect(DB::val("SELECT t.title FROM module_translations t JOIN languages l ON l.id=t.language_id WHERE t.module_id=? AND l.code='en'",[$module])==='Updated module','update module translations');
    expect((int)DB::val("SELECT COUNT(*) FROM audit_logs WHERE entity_id=? AND action='record_saved'",[$test])>0,'mutations audited');
    echo "$passed passed; all test records rolled back.\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
