<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit;}
require __DIR__.'/../app/autoload.php';
use App\Core\Database as DB;use App\Services\StudentService as S;
$pdo=DB::pdo();$pdo->beginTransaction();$passed=0;
function expectStudent(bool $ok,string $label):void{global $passed;if(!$ok){throw new RuntimeException('FAIL '.$label);}$passed++;echo 'PASS '.$label.PHP_EOL;}
function deniedStudent(callable $f,string $label):void{try{$f();}catch(DomainException $e){expectStudent(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
try{
 $role=DB::val("SELECT id FROM roles WHERE code='student'");$en=DB::val("SELECT id FROM languages WHERE code='en'");
 $student=DB::insert("INSERT INTO users (uuid,role_id,full_name,username,password_hash,preferred_language_id) VALUES (?,?,?,'qa.student.rules',?,?)",[uuid_v4(),$role,'QA Student',password_hash('QaTest@2026!',PASSWORD_DEFAULT),$en]);
 $_SESSION=['uid'=>$student,'last_activity'=>time()];
 $subject=DB::insert("INSERT INTO subjects (code,name) VALUES ('QA-STUDENT','QA Course')");DB::run('INSERT INTO student_subjects (student_id,subject_id,current_week_no) VALUES (?,?,1)',[$student,$subject]);DB::run("INSERT INTO student_week_state (student_id,subject_id,week_no,status) VALUES (?,?,1,'open')",[$student,$subject]);
 $modules=[];$lessons=[];
 for($i=1;$i<=3;$i++){$m=DB::insert("INSERT INTO modules (subject_id,week_no,sequence_no,code,status) VALUES (?,?,?,?,'published')",[$subject,$i===3?2:1,$i,'QA-M'.$i]);$modules[]=$m;DB::run('INSERT INTO module_translations (module_id,language_id,title) VALUES (?,?,?)',[$m,$en,'QA Module '.$i]);$l=DB::insert("INSERT INTO content_items (module_id,subject_id,item_type,status) VALUES (?,?,'text','published')",[$m,$subject]);$lessons[]=$l;DB::run('INSERT INTO content_item_translations (content_item_id,language_id,title,body_text) VALUES (?,?,?,?)',[$l,$en,'QA Lesson '.$i,'Test text']);}
 $questions=[];$options=[];
 for($i=0;$i<2;$i++){$q=DB::insert("INSERT INTO questions (subject_id,module_id,status) VALUES (?,?,'published')",[$subject,$modules[0]]);$questions[]=$q;DB::run('INSERT INTO question_translations (question_id,language_id,question_text) VALUES (?,?,?)',[$q,$en,'QA Question '.$i]);foreach([1,0] as $correct){$o=DB::insert('INSERT INTO question_options (question_id,is_correct,option_order) VALUES (?,?,?)',[$q,$correct,$correct?1:2]);$options[$q][$correct]=$o;DB::run('INSERT INTO question_option_translations (option_id,language_id,option_text) VALUES (?,?,?)',[$o,$en,$correct?'Safe option':'Unsafe option']);}}
 $makeTest=function(string $type,?int $module,int $duration=0,?int $max=null)use($subject,$questions){$id=DB::insert("INSERT INTO tests (subject_id,test_type,week_no,module_id,title,duration_minutes,pass_percent,max_attempts,status,min_study_percent) VALUES (?,?,1,?,'QA Test',?,50,?,'published',?)",[$subject,$type,$module,$duration,$max,$type==='weekly'?100:0]);foreach($questions as $i=>$q){DB::run('INSERT INTO test_questions (test_id,question_id,sort_order,marks) VALUES (?,?,?,1)',[$id,$q,$i+1]);}return $id;};
 $moduleTest=$makeTest('module',$modules[0]);$weekly=$makeTest('weekly',null);$timed=$makeTest('practice',$modules[0],1,1);
 $tree=S::tree($student,$subject);expectStudent($tree[0]['unlocked']&&!$tree[1]['unlocked']&&!$tree[2]['unlocked'],'sequential module and week locks');
 deniedStudent(fn()=>S::study($student,['lesson_id'=>$lessons[1],'seconds'=>30,'cycle_no'=>1,'complete'=>1],uuid_v4(),true),'locked lesson cannot be completed');
 deniedStudent(fn()=>S::beginTest($student,$moduleTest),'module test requires completed lessons');
 $lesson=S::lesson($student,$lessons[0]);$nonce=S::openLesson($student,$lesson);$required=S::requiredSeconds($lesson);
 $result=S::study($student,['lesson_id'=>$lessons[0],'seconds'=>$required,'cycle_no'=>1,'open_token'=>$nonce,'complete'=>1],uuid_v4(),false);expectStudent(!$result['completed'],'online study time capped by server clock');
 $_SESSION['study_clock'][$lessons[0]]['at']=time()-$required-1;$uuid=uuid_v4();$payload=['lesson_id'=>$lessons[0],'seconds'=>$required,'cycle_no'=>1,'open_token'=>$nonce,'complete'=>1];$result=S::study($student,$payload,$uuid,false);expectStudent($result['completed'],'lesson completes after required time');
 $duplicate=S::study($student,$payload,$uuid,false);expectStudent($duplicate['duplicate']&&$duplicate['seconds']===$result['seconds'],'duplicate sync cannot double count study');
 expectStudent(!S::tree($student,$subject)[1]['unlocked'],'module test pass is required to open next module');
 $attempt=S::beginTest($student,$moduleTest);expectStudent(S::beginTest($student,$moduleTest)===$attempt,'start retries resume a single pending attempt');
 $public=S::takingQuestions(S::attempt($student,$attempt));expectStudent(!str_contains(json_encode($public),'is_correct'),'taking payload contains no answer key');
 $wrong=array_combine($questions,array_map(fn($q)=>$options[$q][0],$questions));$correct=array_combine($questions,array_map(fn($q)=>$options[$q][1],$questions));
 $failed=S::submitTest($student,$attempt,$wrong);expectStudent(!$failed['passed']&&$failed['percent']===0.0,'wrong answers fail on server');
 $repeat=S::submitTest($student,$attempt,$correct);expectStudent(!$repeat['passed']&&(int)DB::val('SELECT COUNT(*) FROM test_attempt_answers WHERE attempt_id=?',[$attempt])===2,'resubmission does not change grade or duplicate answers');
 $attempt=S::beginTest($student,$moduleTest);$half=$correct;$half[$questions[1]]=$options[$questions[1]][0];$result=S::submitTest($student,$attempt,$half);expectStudent($result['passed']&&$result['percent']===50.0,'exactly 50 percent passes the gte rule');
 expectStudent(S::tree($student,$subject)[1]['unlocked'],'passing module test unlocks next module');
 $l=S::lesson($student,$lessons[1]);S::study($student,['lesson_id'=>$l['id'],'seconds'=>S::requiredSeconds($l),'cycle_no'=>1,'complete'=>1],uuid_v4(),true);
 $attempt=S::beginTest($student,$weekly);S::submitTest($student,$attempt,$wrong);$tree=S::tree($student,$subject);expectStudent($tree[0]['cycle']===2&&!$tree[0]['lessons'][0]['completed']&&!$tree[1]['unlocked'],'failed weekly test starts a fresh study cycle');
 deniedStudent(fn()=>S::study($student,['lesson_id'=>$lessons[0],'seconds'=>30,'cycle_no'=>1,'complete'=>1],uuid_v4(),true),'stale-cycle offline activity rejected');
 $l=S::lesson($student,$lessons[0]);S::study($student,['lesson_id'=>$l['id'],'seconds'=>S::requiredSeconds($l),'cycle_no'=>2,'complete'=>1],uuid_v4(),true);$attempt=S::beginTest($student,$moduleTest);S::submitTest($student,$attempt,$correct);
 $l=S::lesson($student,$lessons[1]);S::study($student,['lesson_id'=>$l['id'],'seconds'=>S::requiredSeconds($l),'cycle_no'=>2,'complete'=>1],uuid_v4(),true);$attempt=S::beginTest($student,$weekly);S::submitTest($student,$attempt,$correct);
 expectStudent((int)DB::val('SELECT current_week_no FROM student_subjects WHERE student_id=? AND subject_id=?',[$student,$subject])===2&&S::tree($student,$subject)[2]['unlocked'],'weekly pass opens next week on server');
 $attempt=S::beginTest($student,$timed);DB::run('UPDATE test_attempts SET started_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE) WHERE id=?',[$attempt]);$result=S::submitTest($student,$attempt,$correct);expectStudent(!$result['passed']&&$result['expired'],'expired timed attempt cannot pass');
 deniedStudent(fn()=>S::beginTest($student,$timed),'maximum attempts enforced');
 DB::run("UPDATE student_subjects SET status='completed' WHERE student_id=? AND subject_id=?",[$student,$subject]);expectStudent(count(S::coursePage($student,'','complete',1)['courses'])===1,'completed course remains in completed catalogue');expectStudent(S::lesson($student,$lessons[2])['unlocked'],'completed course can be reviewed');deniedStudent(fn()=>S::beginTest($student,$weekly),'completed enrolment cannot create attempts');deniedStudent(fn()=>S::study($student,['lesson_id'=>$lessons[2],'seconds'=>30,'cycle_no'=>1,'complete'=>1],uuid_v4(),true),'completed enrolment is read only');
 DB::run("UPDATE student_subjects SET status='dropped' WHERE student_id=? AND subject_id=?",[$student,$subject]);expectStudent(!S::courses($student),'withdrawn enrolment disappears');deniedStudent(fn()=>S::beginTest($student,$weekly),'withdrawn student cannot start test');
 echo "$passed passed; all student fixtures rolled back.\n";
}finally{if($pdo->inTransaction()){$pdo->rollBack();}}
