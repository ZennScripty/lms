<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/autoload.php';
use App\Core\Database as DB;

// Synthetic local demonstration records, not real personal information.
$result=DB::transaction(function(){
    if(DB::val("SELECT setting_value FROM app_settings WHERE setting_key='demo_seed_version'")==='20261026-1'){return 'Demo data already exists; no records changed.';}
    $admin=(int)DB::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='admin' ORDER BY u.id LIMIT 1");
    $en=(int)DB::val("SELECT id FROM languages WHERE code='en'");$hi=(int)DB::val("SELECT id FROM languages WHERE code='hi'");
    $roles=array_column(DB::all('SELECT id,code FROM roles'),'id','code');
    $catalog=require APP_ROOT.'/app/Config/permissions.php';
    foreach($catalog['permissions'] as $code=>$description){DB::run('INSERT INTO permissions (code,description) VALUES (?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)',[$code,$description]);}
    foreach($catalog['defaults'] as $slug=>$codes){$role=$roles[$slug==='subject_teacher'?'teacher':$slug]??null;if(!$role)continue;foreach($codes as $code){DB::run('INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?',[$role,$code]);}}
    $centres=[];foreach([['Demo Skills Centre Bilaspur','Bilaspur'],['Demo Community Centre Korba','Korba']] as [$name,$district]){$existing=DB::val('SELECT id FROM centres WHERE name=?',[$name]);$centres[]=$existing?:DB::insert('INSERT INTO centres (name,district,address) VALUES (?,?,?)',[$name,$district,'Synthetic demonstration centre']);}
    array_unshift($centres,(int)DB::val('SELECT id FROM centres ORDER BY id LIMIT 1'));
    $batches=[];foreach($centres as $i=>$cid){$name='Demo Skills Batch '.($i+1).' / 2026';$existing=DB::val('SELECT id FROM batches WHERE name=?',[$name]);$batches[]=$existing?:DB::insert('INSERT INTO batches (centre_id,name,training_mode,start_date,end_date) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 6 MONTH))',[$cid,$name,$i===2?'community':'residential']);}
    $hash=password_hash('Demo@2026!',PASSWORD_DEFAULT);
    $createUser=function(string $username,string $name,string $role,int $centre,int $months=0)use($hash,$roles,$en,$admin){
        $id=DB::val('SELECT id FROM users WHERE username=?',[$username]);
        if($id){throw new RuntimeException('Demo username already exists: '.$username.'. No existing accounts have been overwritten.');}
        return DB::insert('INSERT INTO users (uuid,role_id,centre_id,full_name,username,email,password_hash,preferred_language_id,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,DATE_SUB(NOW(),INTERVAL ? MONTH))',[uuid_v4(),$roles[$role],$centre,$name,$username,$username.'@example.test',$hash,$en,$admin,$months]);
    };
    $subjects=DB::all('SELECT * FROM subjects ORDER BY id');$teachers=[];
    $names=['Rohit Kumar','Anita Verma','Suresh Patel','Meena Sahu','Deepak Singh'];
    foreach($subjects as $i=>$subject){$tid=$createUser('demo.teacher.'.strtolower($subject['code']),'Demo '.$names[$i%5],'teacher',$centres[$i%3]);$teachers[$subject['id']]=$tid;DB::run('INSERT INTO teacher_subjects (teacher_id,subject_id,can_manage_content,can_manage_tests,assigned_by) VALUES (?,?,1,1,?)',[$tid,$subject['id'],$admin]);}
    $learners=['Ravi Kumar','Priya Verma','Amit Sahu','Neha Patel','Rahul Sharma','Pooja Singh','Kiran Yadav','Sunita Das','Vikas Kumar','Asha Devi','Sanjay Sahu','Rina Verma','Manoj Patel','Kavita Singh','Ajay Das','Nisha Sahu','Vivek Yadav','Anjali Verma','Deepa Kumar','Rakesh Patel','Suman Das','Rajesh Sahu','Komal Singh','Arun Verma'];
    $students=[];
    foreach($learners as $i=>$name){$ci=$i%3;$student=$createUser('demo.student.'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT),'Demo '.$name,'student',$centres[$ci],$i%6);$sid=$subjects[$i%count($subjects)]['id'];$students[]=['id'=>$student,'subject'=>$sid,'i'=>$i];DB::run('INSERT INTO student_subjects (student_id,subject_id,batch_id,enrolled_by,enrolled_at) VALUES (?,?,?,?,DATE_SUB(NOW(),INTERVAL ? MONTH))',[$student,$sid,$batches[$ci],$admin,$i%6]);DB::run("INSERT INTO student_week_state (student_id,subject_id,week_no,status,opened_at) VALUES (?,?,1,'open',NOW())",[$student,$sid]);DB::run("INSERT INTO notifications (user_id,type,title,body) VALUES (?,'class','Welcome to TLMTI','Your learning journey starts here.')",[$student]);}
    foreach($subjects as $subject){
        $sid=(int)$subject['id'];$tid=$teachers[$sid];
        $titles=[['Introduction and safety','परिचय और सुरक्षा'],['Tools and equipment','उपकरण और सामग्री'],['Practical fundamentals','प्रैक्टिकल की बुनियाद']];
        foreach($titles as $j=>[$english,$hindi]){
            $mid=DB::insert("INSERT INTO modules (subject_id,week_no,sequence_no,code,estimated_minutes,status,created_by) VALUES (?,1,?,?,15,'published',?)",[$sid,$j+1,'DEMO-'.$subject['code'].'-'.($j+1),$tid]);
            foreach([[$en,$english],[$hi,$hindi]] as [$lid,$title]){DB::run('INSERT INTO module_translations (module_id,language_id,title,description) VALUES (?,?,?,?)',[$mid,$lid,$title,$lid===$en?'Demonstration module for '.$subject['name']:'प्रशिक्षण के लिए नमूना मॉड्यूल']);}
            $cid=DB::insert("INSERT INTO content_items (module_id,subject_id,item_type,sequence_no,status,created_by) VALUES (?,?,'text',1,'published',?)",[$mid,$sid,$tid]);
            DB::run('INSERT INTO content_item_translations (content_item_id,language_id,title,body_text) VALUES (?,?,?,?)',[$cid,$en,$english,"This is synthetic training content for local testing.\n\n1. Read the safety instructions.\n2. Identify the correct tools.\n3. Practise under your instructor's supervision.\n4. Review the steps before completing your lesson."]);
            DB::run('INSERT INTO content_item_translations (content_item_id,language_id,title,body_text) VALUES (?,?,?,?)',[$cid,$hi,$hindi,"यह स्थानीय परीक्षण के लिए नमूना प्रशिक्षण सामग्री है।\n\n1. सुरक्षा निर्देश पढ़ें।\n2. सही उपकरण पहचानें।\n3. शिक्षक की देखरेख में अभ्यास करें।\n4. पाठ पूरा करने से पहले चरण दोहराएँ।"]);
            $qid=DB::insert("INSERT INTO questions (subject_id,module_id,question_type,difficulty,default_marks,status,created_by) VALUES (?,?,'mcq_single','easy',1,'published',?)",[$sid,$mid,$tid]);
            foreach([[$en,'What should you do before starting practical work?'],[$hi,'प्रैक्टिकल शुरू करने से पहले क्या करना चाहिए?']] as [$lid,$text]){DB::run('INSERT INTO question_translations (question_id,language_id,question_text) VALUES (?,?,?)',[$qid,$lid,$text]);}
            foreach([['Read safety instructions','सुरक्षा निर्देश पढ़ें'],['Skip preparation','तैयारी छोड़ें'],['Use damaged tools','खराब उपकरण उपयोग करें'],['Work without supervision','बिना निगरानी काम करें']] as $oi=>[$et,$ht]){$oid=DB::insert('INSERT INTO question_options (question_id,option_order,is_correct) VALUES (?,?,?)',[$qid,$oi+1,$oi===0?1:0]);foreach([[$en,$et],[$hi,$ht]] as [$lid,$text]){DB::run('INSERT INTO question_option_translations (option_id,language_id,option_text) VALUES (?,?,?)',[$oid,$lid,$text]);}}
            $test=DB::insert("INSERT INTO tests (subject_id,test_type,week_no,module_id,title,duration_minutes,total_marks,status,created_by) VALUES (?,'module',1,?,?,10,1,'published',?)",[$sid,$mid,$english.' - Demo assessment',$tid]);DB::run('INSERT INTO test_questions (test_id,question_id,sort_order,marks) VALUES (?,?,1,1)',[$test,$qid]);
            foreach($students as $student){if($student['subject']!=$sid)continue;$complete=$student['i']%4>$j;$percent=$complete?100:($j===0?50:0);DB::run('INSERT INTO module_progress (student_id,module_id,subject_id,time_spent_seconds,items_completed,items_total,completion_percent,status,started_at,completed_at) VALUES (?,?,?,?,1,1,?,?,NOW(),?)',[$student['id'],$mid,$sid,$percent*8,$percent,$complete?'completed':($percent?'in_progress':'not_started'),$complete?date('Y-m-d H:i:s'):null]);DB::run('INSERT INTO content_progress (student_id,content_item_id,subject_id,module_id,completion_percent,watch_seconds,primary_language_id,first_opened_at,completed_at) VALUES (?,?,?,?,?,?,?,NOW(),?)',[$student['id'],$cid,$sid,$mid,$percent,$percent*8,$en,$complete?date('Y-m-d H:i:s'):null]);}
        }
        DB::run("INSERT INTO notifications (user_id,type,title,body) VALUES (?,'class','Welcome to TLMTI','Your teaching workspace is ready.')",[$tid]);
    }
    foreach($students as $student){for($day=0;$day<5;$day++){DB::run("INSERT INTO attendance_records (user_id,attendance_date,attendance_for,status,marked_by,remarks) VALUES (?,DATE_SUB(CURDATE(),INTERVAL ? DAY),'student',?,?,?)",[$student['id'],$day,($student['i']+$day)%8===0?'absent':'present',$teachers[$student['subject']],'Synthetic demonstration attendance']);}}
    DB::run("INSERT INTO app_settings (setting_key,setting_value) VALUES ('demo_seed_version','20261026-1')");
    return 'Inserted 5 demo teachers, 24 demo students, 3 batches, 15 bilingual modules, 15 lessons, 15 questions, 15 tests, progress and 120 attendance records. Existing admin unchanged.';
});
echo $result.PHP_EOL;
$result=DB::transaction(function(){
    if(DB::val("SELECT setting_value FROM app_settings WHERE setting_key='demo_result_seed_version'")==='1'){return 'Demo results already exist.';}
    $rows=DB::all("SELECT mp.*,u.preferred_language_id,t.id AS test_id,t.version AS test_version,q.id AS question_id FROM module_progress mp JOIN users u ON u.id=mp.student_id JOIN modules m ON m.id=mp.module_id JOIN tests t ON t.module_id=m.id JOIN test_questions tq ON tq.test_id=t.id JOIN questions q ON q.id=tq.question_id WHERE u.username LIKE 'demo.student.%' AND m.code LIKE 'DEMO-%' AND mp.status='completed'");
    foreach($rows as $i=>$r){
        $pass=$i%5!==0;$oid=(int)DB::val('SELECT id FROM question_options WHERE question_id=? AND is_correct=? ORDER BY option_order LIMIT 1',[$r['question_id'],$pass?1:0]);
        $id=DB::insert("INSERT INTO test_attempts (client_uuid,test_id,test_version,student_id,subject_id,week_no,language_id,started_at,submitted_at,duration_seconds,total_marks,score,percentage,result,graded_by,server_verified_at) VALUES (?,?,?,?,?,1,?,DATE_SUB(NOW(),INTERVAL 5 MINUTE),NOW(),300,1,?,?,?,'server',NOW())",[uuid_v4(),$r['test_id'],$r['test_version'],$r['student_id'],$r['subject_id'],$r['preferred_language_id'],$pass?1:0,$pass?100:0,$pass?'pass':'fail']);
        DB::run('INSERT INTO test_attempt_answers (attempt_id,question_id,selected_option_ids,is_correct,marks_awarded,answered_at) VALUES (?,?,?,?,?,NOW())',[$id,$r['question_id'],json_encode([$oid]),$pass?1:0,$pass?1:0]);
    }
    DB::run("UPDATE module_progress mp JOIN users u ON u.id=mp.student_id JOIN modules m ON m.id=mp.module_id SET mp.items_completed=IF(mp.completion_percent>=100,1,0) WHERE u.username LIKE 'demo.student.%' AND m.code LIKE 'DEMO-%'");
    DB::run("INSERT INTO app_settings (setting_key,setting_value) VALUES ('demo_result_seed_version','1')");
    return count($rows).' synthetic assessment results inserted.';
});
echo $result.PHP_EOL;

DB::run("INSERT IGNORE INTO test_translations (test_id,language_id,title) SELECT t.id,mt.language_id,CONCAT(mt.title,' - नमूना टेस्ट') FROM tests t JOIN modules m ON m.id=t.module_id JOIN module_translations mt ON mt.module_id=m.id JOIN languages l ON l.id=mt.language_id WHERE m.code LIKE 'DEMO-%' AND t.title LIKE '% - Demo assessment' AND l.code='hi'");
