<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit;}
require __DIR__.'/../app/autoload.php';
use App\Core\{Database as DB,Auth,Authorization};
use App\Services\{ManagementService as M,StudentService as S};
$pdo=DB::pdo();$pdo->beginTransaction();$passed=0;
function verifyMulti(bool $ok,string $label):void{global $passed;if(!$ok){throw new RuntimeException('FAIL '.$label);}echo 'PASS '.$label.PHP_EOL;$passed++;}
function loginMulti(int $id):void{$_SESSION=['uid'=>$id,'last_activity'=>time()];foreach(['loaded'=>false,'user'=>null] as $key=>$value){(new ReflectionProperty(Auth::class,$key))->setValue(null,$value);}}
try{
 $admin=(int)DB::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='admin' AND u.status='active' LIMIT 1");loginMulti($admin);
 $teacher=M::save('users',null,['full_name'=>'QA Multi Teacher','username'=>'qa.multi.teacher','role_id'=>DB::val("SELECT id FROM roles WHERE code='teacher'"),'password'=>'QaTest@2026!','status'=>'active']);
 $student=M::save('users',null,['full_name'=>'QA Multi Student','username'=>'qa.multi.student','role_id'=>DB::val("SELECT id FROM roles WHERE code='student'"),'password'=>'QaTest@2026!','status'=>'active']);
 $subjects=[];for($i=1;$i<=3;$i++){$subjects[]=M::save('subjects',null,['code'=>'QA-MULTI-'.$i,'name'=>'QA Multi '.$i,'is_active'=>1]);}
 $input=['teacher_id'=>$teacher,'subject_ids'=>array_slice($subjects,0,2),'can_manage_content'=>1];
 $r=M::assignSubjects('assignments',$input);verifyMulti($r['created']===2,'teacher receives two subjects in one operation');
 $input['subject_ids']=$subjects;$input['can_manage_tests']=1;$r=M::assignSubjects('assignments',$input);verifyMulti($r===['created'=>1,'existing'=>2],'existing teacher assignments preserved on retry');
 verifyMulti((int)DB::val('SELECT can_manage_tests FROM teacher_subjects WHERE teacher_id=? AND subject_id=?',[$teacher,$subjects[0]])===0,'existing subject permissions not overwritten');
 $r=M::assignSubjects('enrolments',['student_id'=>$student,'subject_ids'=>$subjects,'status'=>'active']);verifyMulti($r['created']===3,'student receives multiple subjects');
 verifyMulti((int)DB::val('SELECT COUNT(*) FROM student_week_state WHERE student_id=? AND week_no=1',[$student])===3,'week one initialized independently for every subject');
 DB::run('UPDATE student_subjects SET current_week_no=2 WHERE student_id=? AND subject_id=?',[$student,$subjects[0]]);
 M::assignSubjects('enrolments',['student_id'=>$student,'subject_ids'=>$subjects,'status'=>'active']);verifyMulti((int)DB::val('SELECT current_week_no FROM student_subjects WHERE student_id=? AND subject_id=?',[$student,$subjects[0]])===2,'bulk retry preserves student progress');
 loginMulti($student);verifyMulti(count(S::courses($student))===3,'student catalogue contains all enrolled subjects');
 loginMulti($teacher);verifyMulti(count(Authorization::visibleSubjectIds())===3,'teacher scope includes all assigned subjects');
 verifyMulti(count(M::options('students'))===1,'multi-subject student appears once in teacher options');
 loginMulti($admin);$extra=M::save('subjects',null,['code'=>'QA-MULTI-EXTRA','name'=>'QA extra','is_active'=>1]);
 try{M::assignSubjects('enrolments',['student_id'=>$student,'subject_ids'=>[$extra,2147483647],'status'=>'active']);throw new RuntimeException('Invalid subject accepted');}catch(DomainException $e){verifyMulti(!DB::val('SELECT id FROM student_subjects WHERE student_id=? AND subject_id=?',[$student,$extra]),'invalid bulk selection rolls back all additions');}
 echo "$passed passed; all fixtures rolled back.\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();}
