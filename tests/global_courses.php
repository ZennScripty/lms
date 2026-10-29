<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/autoload.php';
ob_start(); // Keep renderer headers valid while assertions are accumulated by this CLI test.
use App\Core\{Auth,Database as DB};
use App\Services\{ManagementService as M,CourseAssignmentService as C,AssignmentAccessService as Access,StudentService as Student};
date_default_timezone_set('Asia/Kolkata');$pdo=DB::pdo();$pdo->beginTransaction();$passed=0;
function gcCheck(bool $ok,string $label): void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;$passed++;}
function gcReject(callable $fn,string $label): void {try{$fn();}catch(DomainException|InvalidArgumentException $e){gcCheck(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
function gcDbReject(callable $fn,string $label): void {try{$fn();}catch(PDOException $e){if($e->getCode()==='23000'){gcCheck(true,$label);return;}throw $e;}throw new RuntimeException('FAIL '.$label);}
function gcAs(int $id): void {$_SESSION=['uid'=>$id,'last_activity'=>time()];foreach(['loaded'=>false,'user'=>null] as $k=>$v)(new ReflectionProperty(Auth::class,$k))->setValue(null,$v);}
function gcRender(string $view,array $vars=[]): string {extract($vars);$_GET=[];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/lms/public/'.($view==='staff_reports'?'reports.php':($view==='student_courses'?'courses.php':'dashboard.php'));ob_start();try{require APP_ROOT.'/app/Views/'.$view.'.php';return (string)ob_get_contents();}finally{ob_end_clean();}}
try{
    DB::run("INSERT INTO app_settings(setting_key,setting_value) VALUES ('batch_learning_managed_rollout','0') ON DUPLICATE KEY UPDATE setting_value='0'");
    $root=(int)DB::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='super_admin' AND u.status='active' AND u.deleted_at IS NULL LIMIT 1");gcAs($root);
    $centres=[];$batches=[];$admins=[];$students=[];$teachers=[];
    foreach(['A','B'] as $tag){
        $centres[$tag]=M::save('centres',null,['name'=>'QA Global '.$tag,'is_active'=>1]);
        $batches[$tag]=M::save('batches',null,['centre_id'=>$centres[$tag],'name'=>'QA Global Batch '.$tag,'training_mode'=>'community','is_active'=>1]);
        foreach(['admin','student','teacher'] as $role){$id=M::save('users',null,['full_name'=>'QA Global '.$tag.' '.$role,'username'=>'qa.global.'.$tag.'.'.$role,'role_id'=>DB::val('SELECT id FROM roles WHERE code=?',[$role]),'centre_id'=>$centres[$tag],'centre_ids'=>[$centres[$tag]],'password'=>'QaTest@2026!','status'=>'active']);if($role==='admin')$admins[$tag]=$id;elseif($role==='student'){$students[$tag]=$id;DB::run('UPDATE users SET batch_id=? WHERE id=?',[$batches[$tag],$id]);}else $teachers[$tag]=$id;}
    }
    $subjects=[];foreach(['HTML','JS','PHP','OTHER'] as $tag){$subjects[$tag]=M::save('subjects',null,['code'=>'QA-GLOBAL-'.$tag,'name'=>'QA Global '.$tag,'is_active'=>1]);foreach($centres as $centre)M::save('centre_subjects',null,['centre_id'=>$centre,'subject_id'=>$subjects[$tag]]);}
    $course=C::saveCourse(null,'QA-WEB','QA Web Development');$other=C::saveCourse(null,'QA-OTHER','QA Other Course');
    foreach(['HTML','JS','PHP'] as $tag)C::setCourseSubject($course,$subjects[$tag]);C::setCourseSubject($other,$subjects['HTML']);C::setCourseSubject($other,$subjects['OTHER']);
    gcCheck((int)DB::val('SELECT COUNT(*) FROM course_subjects WHERE subject_id=?',[$subjects['HTML']])===2,'one global subject reused by multiple courses');
    foreach($centres as $centre)C::setCentreCourse($centre,$course);C::setCentreCourse($centres['A'],$other);
    gcAs($admins['A']);gcReject(fn()=>C::saveCourse(null,'BAD','Forbidden'),'Centre Admin cannot create global course');gcReject(fn()=>C::setCourseSubject($course,$subjects['OTHER']),'Centre Admin cannot edit global curriculum');gcReject(fn()=>C::setCentreCourse($centres['A'],$other),'Centre Admin cannot expand course availability');gcReject(fn()=>C::setBatchCourse($batches['B'],$course),'foreign batch assignment denied');
    C::setBatchCourse($batches['A'],$course);C::setBatchSubjects($batches['A'],$course,[$subjects['HTML'],$subjects['JS'],$subjects['HTML']]);
    gcCheck((int)DB::val('SELECT COUNT(*) FROM batch_subjects WHERE batch_id=?',[$batches['A']])===2,'duplicate batch subjects collapse into unique mappings');
    gcReject(fn()=>C::setBatchSubjects($batches['A'],$course,[$subjects['OTHER']]),'subject outside course denied');gcReject(fn()=>C::setBatchSubjects($batches['A'],$course,[$subjects['HTML'],'bad']),'malformed bulk selection denied');
    C::setBatchCourse($batches['A'],$other);C::setBatchSubjects($batches['A'],$other,[$subjects['HTML']]);
    gcAs($admins['B']);gcReject(fn()=>C::setBatchCourse($batches['B'],$other),'course unavailable to centre denied');C::setBatchCourse($batches['B'],$course);C::setBatchSubjects($batches['B'],$course,[$subjects['HTML'],$subjects['PHP']]);
    gcDbReject(fn()=>DB::run('INSERT INTO batch_subjects(batch_id,course_id,subject_id) VALUES (?,?,?)',[$batches['A'],$other,$subjects['JS']]),'database rejects subject outside selected course');
    gcDbReject(fn()=>DB::run('INSERT INTO batch_subjects(batch_id,course_id,subject_id) VALUES (?,?,?)',[$batches['B'],$other,$subjects['HTML']]),'database rejects missing batch-course relationship');
    gcDbReject(fn()=>DB::run('INSERT INTO batch_subjects(batch_id,course_id,subject_id) VALUES (?,?,?)',[$batches['A'],$course,$subjects['HTML']]),'database rejects duplicate batch-course-subject tuple');
    gcDbReject(fn()=>DB::run('INSERT INTO teacher_batch_subjects(teacher_id,batch_id,course_id,subject_id) VALUES (?,?,?,?)',[$teachers['A'],$batches['A'],$other,$subjects['OTHER']]),'database rejects Teacher tuple without batch subject');
    gcAs($students['A']);$ids=Access::studentSubjectIds($students['A']);sort($ids);$expected=[$subjects['HTML'],$subjects['JS']];sort($expected);gcCheck($ids===$expected,'A1 sees HTML and JS, with cross-course duplicate deduplicated');gcCheck(!Access::canStudy($students['A'],$subjects['PHP']),'A1 cannot access unassigned PHP');gcCheck(Access::studentSubjectIds($students['B'])===[],'Student cannot inspect another Student assignments');gcReject(fn()=>C::setBatchSubjects($batches['A'],$course,[]),'Student cannot change assignments');
    gcAs($students['B']);gcCheck(Access::canStudy($students['B'],$subjects['PHP'])&&!Access::canStudy($students['B'],$subjects['JS']),'B1 sees PHP and cannot access JS');
    gcAs($admins['A']);gcCheck(Access::studentSubjectIds($students['B'])===[],'Centre Admin cannot inspect foreign Student assignments');
    M::save('enrolments',null,['student_id'=>$students['A'],'subject_id'=>$subjects['PHP'],'batch_id'=>$batches['A'],'status'=>'active']);
    $legacy=DB::one('SELECT * FROM student_subjects WHERE student_id=? AND subject_id=?',[$students['A'],$subjects['PHP']]);
    gcCheck(!Access::canStudy($students['A'],$subjects['PHP']),'stale individual enrolment does not bypass new batch rules');gcCheck(count(Student::courses($students['A']))===1,'Phase B leaves existing StudentService behavior unchanged');
    gcReject(fn()=>C::setBatchSubjects($batches['A'],$course,[$subjects['HTML']]),'subject removal requires confirmation');C::setBatchSubjects($batches['A'],$course,[$subjects['HTML']],true);
    gcCheck(!Access::canStudy($students['A'],$subjects['JS']),'confirmed removal revokes new-model subject access');gcCheck(DB::one('SELECT * FROM student_subjects WHERE id=?',[$legacy['id']])===$legacy,'assignment removal preserves legacy progression row');
    C::setBatchSubjects($batches['A'],$course,[$subjects['HTML'],$subjects['JS']]);gcCheck(Access::canStudy($students['A'],$subjects['JS']),'reactivation restores same global subject');
    gcAs($root);$ts=M::save('assignments',null,['teacher_id'=>$teachers['A'],'subject_id'=>$subjects['HTML']]);
    gcReject(fn()=>C::setTeacherAssignment($teachers['B'],$batches['A'],$course,$subjects['HTML']),'foreign Teacher cannot be assigned to batch');gcReject(fn()=>C::setTeacherAssignment($teachers['A'],$batches['A'],$course,$subjects['JS']),'batch does not implicitly grant Teacher subject');
    C::setTeacherAssignment($teachers['A'],$batches['A'],$course,$subjects['HTML']);gcCheck((int)DB::val('SELECT can_manage_content+can_manage_tests FROM teacher_subjects WHERE id=?',[$ts])===0,'Teacher batch assignment cannot elevate authoring permissions');
    gcAs($teachers['A']);gcCheck(Access::canTeach($teachers['A'],$batches['A'],$course,$subjects['HTML']),'explicit Teacher batch-course-subject tuple readable');gcCheck(!Access::canTeach($teachers['A'],$batches['B'],$course,$subjects['HTML']),'shared subject cannot widen Teacher batch access');gcReject(fn()=>C::setTeacherAssignment($teachers['A'],$batches['A'],$other,$subjects['HTML']),'Teacher cannot self-assign another course');
    gcAs($root);C::setTeacherAssignment($teachers['A'],$batches['A'],$course,$subjects['HTML'],false);gcCheck(Access::teacherSubjectIds($teachers['A'])===[],'Teacher mapping deactivation revokes access');C::setTeacherAssignment($teachers['A'],$batches['A'],$course,$subjects['HTML']);
    DB::run('UPDATE teacher_subjects SET can_manage_content=1 WHERE id=?',[$ts]);$grants=DB::one('SELECT * FROM teacher_subjects WHERE id=?',[$ts]);C::setTeacherAssignment($teachers['A'],$batches['A'],$course,$subjects['HTML']);gcCheck(DB::one('SELECT * FROM teacher_subjects WHERE id=?',[$ts])===$grants,'mapping retry preserves previously delegated grants and attribution');
    C::setCentreCourse($centres['A'],$course,false);gcCheck(Access::teacherAssignments($teachers['A'])===[],'Teacher loses effective access when centre course unavailable');C::setCentreCourse($centres['A'],$course);
    gcReject(fn()=>C::setBatchSubjects($batches['A'],$course,[],false),'remove-all requires confirmation');
    foreach(['course','centre-course','batch-course','course-subject','batch','centre','subject','centre-subject'] as $parent){
        if($parent==='course')DB::run('UPDATE courses SET is_active=0 WHERE id=?',[$course]);
        if($parent==='centre-course')DB::run('UPDATE centre_courses SET is_active=0 WHERE centre_id=? AND course_id=?',[$centres['A'],$course]);
        if($parent==='batch-course')DB::run('UPDATE batch_courses SET is_active=0 WHERE batch_id=? AND course_id=?',[$batches['A'],$course]);
        if($parent==='course-subject')DB::run('UPDATE course_subjects SET is_active=0 WHERE course_id=? AND subject_id=?',[$course,$subjects['JS']]);
        if($parent==='batch')DB::run('UPDATE batches SET is_active=0 WHERE id=?',[$batches['A']]);
        if($parent==='centre')DB::run('UPDATE centres SET is_active=0 WHERE id=?',[$centres['A']]);
        if($parent==='subject')DB::run('UPDATE subjects SET is_active=0 WHERE id=?',[$subjects['JS']]);
        if($parent==='centre-subject')DB::run('DELETE FROM centre_subjects WHERE centre_id=? AND subject_id=?',[$centres['A'],$subjects['JS']]);
        gcCheck(!Access::canStudy($students['A'],$subjects['JS']),'inactive/unavailable '.$parent.' fails closed');
        DB::run('UPDATE courses SET is_active=1 WHERE id=?',[$course]);DB::run('UPDATE centre_courses SET is_active=1 WHERE centre_id=? AND course_id=?',[$centres['A'],$course]);DB::run('UPDATE batch_courses SET is_active=1 WHERE batch_id=? AND course_id=?',[$batches['A'],$course]);DB::run('UPDATE course_subjects SET is_active=1 WHERE course_id=? AND subject_id=?',[$course,$subjects['JS']]);DB::run('UPDATE batches SET is_active=1 WHERE id=?',[$batches['A']]);DB::run('UPDATE centres SET is_active=1 WHERE id=?',[$centres['A']]);DB::run('UPDATE subjects SET is_active=1 WHERE id=?',[$subjects['JS']]);if($parent==='centre-subject')M::save('centre_subjects',null,['centre_id'=>$centres['A'],'subject_id'=>$subjects['JS']]);
    }
    gcReject(fn()=>C::setBatchSubjects($batches['A'],$course,[$subjects['HTML'],2147483647],true),'invalid subject rolls back entire desired-set update');gcCheck(Access::canStudy($students['A'],$subjects['JS']),'failed replacement retains previous subjects');
    DB::run('UPDATE users SET batch_id=? WHERE id=?',[$batches['B'],$students['A']]);gcCheck(Access::studentSubjectIds($students['A'])===[],'forged cross-centre canonical batch fails closed');
    // Strict mode is visible only inside this uncommitted transaction; the live flag stays OFF.
    DB::run('UPDATE users SET batch_id=? WHERE id=?',[$batches['A'],$students['A']]);
    DB::run("INSERT INTO app_settings(setting_key,setting_value) VALUES ('batch_learning_strict_access','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    gcAs($root);
    $md=['subject_id'=>$subjects['HTML'],'code'=>'QA-GC-M','week_no'=>1,'sequence_no'=>1,'estimated_minutes'=>10,'status'=>'published','title_en'=>'Strict HTML module','title_hi'=>'एचटीएमएल','description_en'=>'','description_hi'=>''];$module=M::save('modules',null,$md);
    $ld=['module_id'=>$module,'item_type'=>'text','sequence_no'=>1,'status'=>'published','is_mandatory'=>1,'is_downloadable'=>1,'min_complete_percent'=>80,'title_en'=>'Strict lesson','title_hi'=>'पाठ','body_en'=>'Content','body_hi'=>'पाठ','media_asset_id'=>''];$lesson=M::save('lessons',null,$ld);
    $qd=['subject_id'=>$subjects['HTML'],'difficulty'=>'easy','default_marks'=>1,'status'=>'published','module_id'=>'','question_en'=>'One?','question_hi'=>'एक?','correct_option'=>'A'];foreach(['a','b','c','d'] as $letter){$qd['option_'.$letter.'_en']=$letter;$qd['option_'.$letter.'_hi']=$letter;}$question=M::save('questions',null,$qd);
    $td=['title'=>'Strict practice','title_hi'=>'अभ्यास','subject_id'=>$subjects['HTML'],'test_type'=>'practice','module_id'=>'','week_no'=>1,'duration_minutes'=>0,'pass_percent'=>50,'pass_rule'=>'gte','max_attempts'=>'','retake_wait_hours'=>0,'min_study_percent'=>0,'status'=>'published','question_ids'=>[$question]];$test=M::save('tests',null,$td);
    gcAs($students['A']);gcCheck(Student::enrolment($students['A'],$subjects['HTML'])!==null&&!DB::val('SELECT id FROM student_subjects WHERE student_id=? AND subject_id=?',[$students['A'],$subjects['HTML']]),'strict access works without manufacturing individual enrolments');
    gcCheck(Student::enrolment($students['A'],$subjects['PHP'])===null,'actual StudentService rejects stale unassigned enrolment in strict mode');
    $page=Student::coursePage($students['A'],'','all',1);gcCheck($page['total']===2&&count($page['courses'])===2,'strict catalogue SQL pagination includes only deduplicated batch subjects');
    gcCheck(Student::lesson($students['A'],$lesson)!==null,'actual lesson resolver accepts authorized batch subject');
    $attempt=Student::beginTest($students['A'],$test);$answer=(int)DB::val('SELECT id FROM question_options WHERE question_id=? AND is_correct=1',[$question]);$result=Student::submitTest($students['A'],$attempt,[$question=>$answer]);gcCheck($result['passed'],'actual test start and grading work without individual enrolment');
    gcAs($root);C::setBatchSubjects($batches['A'],$course,[$subjects['JS']],true);C::setBatchSubjects($batches['A'],$other,[],true);gcAs($students['A']);gcCheck(Student::lesson($students['A'],$lesson)===null,'direct lesson resolution denied after last batch assignment removed');
    gcReject(fn()=>Student::study($students['A'],['lesson_id'=>$lesson,'seconds'=>30,'cycle_no'=>1,'complete'=>1],uuid_v4(),true),'offline study sync rechecks revoked batch assignment');
    gcReject(fn()=>Student::beginTest($students['A'],$test),'new test attempt denied after assignment removal');
    gcCheck(Student::attempt($students['A'],$attempt)!==null,'submitted historical attempt remains owned and readable after removal');
    gcAs($root);C::setBatchSubjects($batches['A'],$course,[$subjects['HTML'],$subjects['JS']]);gcAs($students['A']);$pending=Student::beginTest($students['A'],$test);
    gcAs($root);C::setBatchSubjects($batches['A'],$course,[$subjects['JS']],true);gcAs($students['A']);gcReject(fn()=>Student::submitTest($students['A'],$pending,[$question=>$answer]),'submission rechecks assignment revocation');
    gcAs($root);C::setBatchSubjects($batches['A'],$course,[$subjects['HTML'],$subjects['JS']]);DB::run('UPDATE users SET batch_id=NULL WHERE id=?',[$students['A']]);gcAs($students['A']);gcCheck(Student::courses($students['A'])===[],'legacy Student without canonical batch gains no strict access');
    gcAs($root);DB::run('UPDATE users SET batch_id=? WHERE id=?',[$batches['A'],$students['A']]);
    gcAs($teachers['A']);gcCheck(\App\Core\Authorization::canAccessStudent($students['A'])&&!\App\Core\Authorization::canAccessStudent($students['B']),'actual Teacher authorization enforces explicit batch isolation');
    $params=[];$scope=M::scope('modules',$params);gcCheck((int)DB::val("SELECT COUNT(*) FROM modules x WHERE $scope",$params)===1,'actual Teacher module scope uses explicit batch subjects');
    $params=[];$scope=M::scope('students',$params);gcCheck((int)DB::val("SELECT COUNT(*) FROM users x WHERE $scope",$params)===1,'actual Teacher Student scope uses canonical batch');
    gcCheck(count(M::options('students'))===1,'Teacher dropdown supports batch members without legacy enrolment');
    $dashboardHtml=gcRender('staff_dashboard');gcCheck(str_contains($dashboardHtml,'QA Global HTML')&&!str_contains($dashboardHtml,'QA Global PHP'),'strict Teacher dashboard renders assigned subjects only');
    gcCheck(str_contains(gcRender('staff_reports'),'QA Global HTML'),'strict Teacher reports render canonical batch subjects');
    gcAs($students['A']);gcCheck(str_contains(gcRender('student_courses',['student'=>$students['A']]),'QA Global HTML'),'strict student course screen renders batch-only access');
    gcAs($root);$source=Access::studentPairsSql();gcCheck((int)DB::val("SELECT COUNT(*) FROM $source ss WHERE ss.student_id=?",[$students['A']])===2,'strict staff reporting source includes eligible batch-only members');
    gcAs(0);gcCheck(Access::studentSubjectIds($students['B'])===[],'anonymous cannot inspect assignments');gcReject(fn()=>C::saveCourse(null,'NOAUTH','No auth'),'anonymous writes denied');
    gcCheck((int)DB::val("SELECT COUNT(*) FROM audit_logs WHERE action='course_mapping_updated' AND user_id=?",[$root])>0,'new mapping mutations audited');
    echo "$passed passed; all global-course fixtures rolled back.\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();ob_end_flush();}
