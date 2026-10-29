<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Database as DB,Validator as V};

/** Student access, current-cycle progress and assessment decisions for the supplied schema. */
final class StudentService
{
    public static function enrolment(int $student,int $subject,bool $lock=false): ?array
    {
        if(AssignmentAccessService::forStudent($student)){
            if($lock)DB::one('SELECT id FROM users WHERE id=? FOR UPDATE',[$student]);
            if(!AssignmentAccessService::canStudy($student,$subject))return null;
            $row=DB::one('SELECT * FROM student_subjects WHERE student_id=? AND subject_id=?'.($lock?' FOR UPDATE':''),[$student,$subject])??[];
            $next=(int)DB::val("SELECT COALESCE(MAX(week_no)+1,1) FROM student_week_state WHERE student_id=? AND subject_id=? AND status='passed'",[$student,$subject]);
            return array_merge($row,['student_id'=>$student,'subject_id'=>$subject,'batch_id'=>DB::val('SELECT batch_id FROM users WHERE id=?',[$student]),'status'=>($row['status']??'')==='completed'?'completed':'active','current_week_no'=>max($next,(int)($row['current_week_no']??1))]);
        }
        $row=DB::one("SELECT ss.* FROM student_subjects ss JOIN subjects s ON s.id=ss.subject_id WHERE ss.student_id=? AND ss.subject_id=? AND ss.status IN ('active','completed') AND s.is_active=1".($lock?' FOR UPDATE':''),[$student,$subject]);
        return $lock&&$row&&$row['status']!=='active'?null:$row;
    }
    public static function languageId(): int { return (int)DB::val('SELECT id FROM languages WHERE code=?',[TranslationService::currentCode()]); }
    public static function courses(int $student,?array $onlyIds=null): array
    {
        if(AssignmentAccessService::forStudent($student)){
            $ids=AssignmentAccessService::studentSubjectIds($student);if($onlyIds!==null)$ids=array_values(array_intersect($ids,$onlyIds));if(!$ids)return [];
            $rows=DB::all('SELECT s.* FROM subjects s WHERE s.id IN ('.DB::in($ids).') ORDER BY s.name,s.id',$ids);
            $batch=DB::val('SELECT b.name FROM users u JOIN batches b ON b.id=u.batch_id WHERE u.id=?',[$student]);
            foreach($rows as &$s){$enrol=self::enrolment($student,(int)$s['id']);$s['current_week_no']=$enrol['current_week_no'];$s['enrolment_status']=$enrol['status'];$s['batch_name']=$batch;$s['modules']=self::tree($student,(int)$s['id']);$s['percent']=count($s['modules'])?(int)round(array_sum(array_column($s['modules'],'percent'))/count($s['modules'])):0;$s['completed']=count(array_filter($s['modules'],fn($m)=>$m['passed']));}unset($s);return $rows;
        }
        if($onlyIds===[]){return [];}$params=[$student];$filter='';if($onlyIds!==null){$filter=' AND s.id IN ('.DB::in($onlyIds).')';$params=array_merge($params,$onlyIds);}
        $rows=DB::all("SELECT s.*,ss.current_week_no,ss.status AS enrolment_status,b.name AS batch_name FROM student_subjects ss JOIN subjects s ON s.id=ss.subject_id LEFT JOIN batches b ON b.id=ss.batch_id WHERE ss.student_id=? AND ss.status IN ('active','completed') AND s.is_active=1".$filter.' ORDER BY s.name,s.id',$params);
        foreach($rows as &$s){$s['modules']=self::tree($student,(int)$s['id']);$s['percent']=count($s['modules'])?(int)round(array_sum(array_column($s['modules'],'percent'))/count($s['modules'])):0;$s['completed']=count(array_filter($s['modules'],fn($m)=>$m['passed']));}
        return $rows;
    }
    public static function coursePage(int $student,string $search,string $filter,int $page): array
    {
        $params=[$student];$where="ss.student_id=? AND ss.status IN ('active','completed') AND s.is_active=1";
        $join='JOIN student_subjects ss ON ss.subject_id=s.id';
        if(AssignmentAccessService::forStudent($student)){$params=[];$where=AssignmentAccessService::subjectFilter('s.id',$student,$params);$join='LEFT JOIN student_subjects ss ON ss.subject_id=s.id AND ss.student_id='.(int)$student;}

        if($search!==''){$where.=' AND (s.name LIKE ? OR s.code LIKE ?)';$like='%'.addcslashes($search,'%_\\').'%';$params[]=$like;$params[]=$like;}
        $sql="SELECT s.id,s.name,CASE WHEN ss.status='completed' THEN 'completed' ELSE 'active' END AS enrolment_status,COALESCE((SELECT AVG(COALESCE(mp.completion_percent,0)) FROM modules m LEFT JOIN student_week_state ws ON ws.student_id=COALESCE(ss.student_id,$student) AND ws.subject_id=m.subject_id AND ws.week_no=m.week_no LEFT JOIN module_progress mp ON mp.module_id=m.id AND mp.student_id=COALESCE(ss.student_id,$student) AND mp.cycle_no=COALESCE(ws.cycle_no,1) WHERE m.subject_id=s.id AND m.status='published' AND m.deleted_at IS NULL),0) AS progress FROM subjects s $join WHERE $where";
        if($filter==='complete'){$sql.=" HAVING enrolment_status='completed' OR progress>=100";}elseif($filter==='progress'){$sql.=" HAVING enrolment_status<>'completed' AND progress>0 AND progress<100";}
        $total=(int)DB::val('SELECT COUNT(*) FROM ('.$sql.') AS courses',$params);$pages=max(1,(int)ceil($total/12));$page=max(1,min($pages,$page));$offset=($page-1)*12;
        $ids=array_map('intval',array_column(DB::all($sql." ORDER BY name,id LIMIT 12 OFFSET $offset",$params),'id'));return ['courses'=>self::courses($student,$ids),'total'=>$total,'page'=>$page,'pages'=>$pages];
    }
    public static function tree(int $student,int $subject): array
    {
        $enrol=self::enrolment($student,$subject);if(!$enrol){return [];}
        $lang=TranslationService::currentCode();
        $modules=DB::all("SELECT m.*,COALESCE(t.title,en.title,m.code,CONCAT('#',m.id)) AS title,COALESCE(t.description,en.description,'') AS description FROM modules m LEFT JOIN module_translations t ON t.module_id=m.id AND t.language_id=(SELECT id FROM languages WHERE code=?) LEFT JOIN module_translations en ON en.module_id=m.id AND en.language_id=(SELECT id FROM languages WHERE code='en') WHERE m.subject_id=? AND m.status='published' AND m.deleted_at IS NULL ORDER BY m.week_no,m.sequence_no,m.id",[$lang,$subject]);
        $cycles=array_column(DB::all('SELECT week_no,cycle_no FROM student_week_state WHERE student_id=? AND subject_id=?',[$student,$subject]),'cycle_no','week_no');
        $lessons=DB::all("SELECT c.*,COALESCE(t.title,en.title,CONCAT('#',c.id)) AS title,COALESCE(t.body_text,en.body_text,'') AS body,COALESCE(t.media_asset_id,en.media_asset_id) AS media_asset_id FROM content_items c JOIN modules m ON m.id=c.module_id LEFT JOIN content_item_translations t ON t.content_item_id=c.id AND t.language_id=(SELECT id FROM languages WHERE code=?) LEFT JOIN content_item_translations en ON en.content_item_id=c.id AND en.language_id=(SELECT id FROM languages WHERE code='en') WHERE c.subject_id=? AND c.status='published' AND m.status='published' AND m.deleted_at IS NULL ORDER BY c.sequence_no,c.id",[$lang,$subject]);
        $progress=[];foreach(DB::all('SELECT * FROM content_progress WHERE student_id=? AND subject_id=?',[$student,$subject]) as $p){$progress[$p['cycle_no']][$p['content_item_id']]=$p;}
        $byModule=[];foreach($lessons as $l){$byModule[$l['module_id']][]=$l;}
        $tests=DB::all("SELECT * FROM tests WHERE subject_id=? AND status='published' ORDER BY id",[$subject]);
        $passed=[];foreach(DB::all("SELECT test_id,cycle_no FROM test_attempts WHERE student_id=? AND subject_id=? AND result='pass'",[$student,$subject]) as $a){$passed[$a['cycle_no']][$a['test_id']]=true;}
        $out=[];$previousPassed=true;$lastWeek=null;
        foreach($modules as $m){
            $week=(int)$m['week_no'];$cycle=(int)($cycles[$week]??1);
            if($lastWeek!==$week){$previousPassed=true;}$lastWeek=$week;
            $m['cycle']=$cycle;$m['enrolment_status']=$enrol['status'];$m['unlocked']=$enrol['status']==='completed'||$week<=(int)$enrol['current_week_no']&&$previousPassed;
            $m['lessons']=[];$priorDone=true;$percentSum=0;$required=0;$requiredDone=0;
            foreach($byModule[$m['id']]??[] as $l){$p=$progress[$cycle][$l['id']]??[];$l['percent']=(float)($p['completion_percent']??0);$l['completed']=!empty($p['completed_at']);$l['unlocked']=$m['unlocked']&&($enrol['status']==='completed'||$priorDone||$l['completed']);$l['enrolment_status']=$enrol['status'];$l['seconds']=(int)($p['watch_seconds']??0);$l['position']=(int)($p['last_position_seconds']??0);$l['cycle']=$cycle;$l['week_no']=$week;
                $percentSum+=$l['percent'];if($l['is_mandatory']){$required++;$requiredDone+=$l['completed']?1:0;$priorDone=$priorDone&&$l['completed'];}$m['lessons'][]=$l;
            }
            $m['percent']=count($m['lessons'])?(int)round($percentSum/count($m['lessons'])):0;$m['lessons_done']=count(array_filter($m['lessons'],fn($l)=>$l['completed']));$m['lessons_total']=count($m['lessons']);$m['all_done']=$required>0?$requiredDone===$required:count($m['lessons'])>0;
            $m['tests']=array_values(array_filter($tests,fn($t)=>$t['test_type']==='module'&&(int)$t['module_id']===(int)$m['id']));
            $testsPassed=true;foreach($m['tests'] as &$t){$t['passed']=isset($passed[$cycle][$t['id']]);$testsPassed=$testsPassed&&$t['passed'];}unset($t);
            $m['passed']=$m['all_done']&&$testsPassed;$previousPassed=$m['passed'];$out[]=$m;
        }
        return $out;
    }
    public static function lesson(int $student,int $id): ?array
    {
        $raw=DB::one('SELECT subject_id,module_id FROM content_items WHERE id=?',[$id]);if(!$raw){return null;}
        foreach(self::tree($student,(int)$raw['subject_id']) as $m){if((int)$m['id']!==(int)$raw['module_id'])continue;foreach($m['lessons'] as $l){if((int)$l['id']===$id){$l['module']=$m;return $l;}}}return null;
    }
    public static function requiredSeconds(array $lesson): int
    {
        $duration=$lesson['media_asset_id']?(int)DB::val('SELECT duration_seconds FROM media_assets WHERE id=?',[$lesson['media_asset_id']]):0;
        return max((int)setting('lesson_min_seconds',30),$duration?(int)ceil($duration*(int)$lesson['min_complete_percent']/100):0);
    }
    public static function openLesson(int $student,array $lesson): string
    {
        $clock=$_SESSION['study_clock'][$lesson['id']]??null;
        if($clock&&(int)$clock['cycle']===(int)$lesson['cycle']){return $clock['token'];}
        $nonce=bin2hex(random_bytes(16));$_SESSION['study_clock'][$lesson['id']]=['token'=>$nonce,'at'=>time(),'cycle'=>$lesson['cycle']];return $nonce;
    }
    public static function study(int $student,array $payload,string $uuid,bool $offline): array
    {
        V::uuid($uuid);$id=V::int($payload['lesson_id']??'','Lesson',1);$seconds=V::int($payload['seconds']??$payload['seconds_spent']??0,'Study time',0,14400);
        return DB::transaction(function()use($student,$payload,$uuid,$offline,$id,$seconds){
            $raw=DB::one('SELECT subject_id FROM content_items WHERE id=?',[$id]);if(!$raw||!self::enrolment($student,(int)$raw['subject_id'],true)){throw new \DomainException('This lesson is not available.');}
            $duplicate=DB::one('SELECT student_id,payload FROM activity_events WHERE client_event_uuid=?',[$uuid]);if($duplicate){if((int)$duplicate['student_id']!==$student){throw new \DomainException('Invalid event.');}return ['duplicate'=>true]+(json_decode($duplicate['payload'],true)['result']??[]);}
            $lesson=self::lesson($student,$id);if(!$lesson||!$lesson['unlocked']){throw new \DomainException('This lesson is locked.');}
            if((int)($payload['cycle_no']??0)!==$lesson['cycle']){throw new \DomainException('Study cycle changed. Reload the course and study again.');}
            if(!$offline){$clock=$_SESSION['study_clock'][$id]??null;if(!$clock||!hash_equals($clock['token'],(string)($payload['open_token']??''))){throw new \DomainException('Open the lesson again before saving.');}$seconds=min($seconds,max(0,time()-(int)$clock['at']));}
            $cycle=$lesson['cycle'];$module=(int)$lesson['module_id'];$subject=(int)$lesson['subject_id'];
            DB::run('INSERT IGNORE INTO content_progress (student_id,content_item_id,subject_id,module_id,cycle_no,first_opened_at) VALUES (?,?,?,?,?,NOW())',[$student,$id,$subject,$module,$cycle]);
            $p=DB::one('SELECT * FROM content_progress WHERE student_id=? AND content_item_id=? AND cycle_no=? FOR UPDATE',[$student,$id,$cycle]);
            $watched=(int)$p['watch_seconds']+($p['completed_at']?0:$seconds);$required=self::requiredSeconds($lesson);$complete=$p['completed_at']||(!empty($payload['complete'])&&$watched>=$required);
            $percent=$complete?100:min(99,round($watched/max(1,$required)*100,2));$position=V::int($payload['position']??0,'Position',0,864000);
            DB::run('UPDATE content_progress SET watch_seconds=?,last_position_seconds=?,completion_percent=?,primary_language_id=?,last_opened_at=NOW(),completed_at=IF(?,COALESCE(completed_at,NOW()),completed_at) WHERE id=?',[$watched,$position,$percent,self::languageId(),$complete?1:0,$p['id']]);
            $result=['lesson_id'=>$id,'cycle_no'=>$cycle,'percent'=>$percent,'completed'=>(bool)$complete,'seconds'=>$watched];
            DB::run("INSERT INTO activity_events (client_event_uuid,student_id,subject_id,module_id,content_item_id,event_type,target_type,target_id,language_id,value_seconds,payload,is_offline,occurred_at) VALUES (?,?,?,?,?,'study','web',?,?,?,?,?,NOW())",[$uuid,$student,$subject,$module,$id,$id,self::languageId(),$p['completed_at']?0:$seconds,json_encode(['result'=>$result],JSON_THROW_ON_ERROR),$offline?1:0]);
            $aggregate=DB::one("SELECT COUNT(c.id) AS total,COALESCE(SUM(cp.completed_at IS NOT NULL),0) AS done,COALESCE(SUM(cp.completion_percent),0) AS percent,COALESCE(SUM(cp.watch_seconds),0) AS seconds FROM content_items c LEFT JOIN content_progress cp ON cp.content_item_id=c.id AND cp.student_id=? AND cp.cycle_no=? WHERE c.module_id=? AND c.status='published'",[$student,$cycle,$module]);
            $mpPercent=$aggregate['total']?(float)$aggregate['percent']/(int)$aggregate['total']:0;
            DB::run('INSERT INTO module_progress (student_id,module_id,subject_id,cycle_no,time_spent_seconds,items_completed,items_total,completion_percent,status,started_at,completed_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),IF(?,NOW(),NULL)) ON DUPLICATE KEY UPDATE time_spent_seconds=VALUES(time_spent_seconds),items_completed=VALUES(items_completed),items_total=VALUES(items_total),completion_percent=VALUES(completion_percent),status=VALUES(status),completed_at=IF(VALUES(completed_at) IS NOT NULL,COALESCE(completed_at,VALUES(completed_at)),completed_at)',[$student,$module,$subject,$cycle,$aggregate['seconds'],$aggregate['done'],$aggregate['total'],$mpPercent,$mpPercent>=100?'completed':'in_progress',$mpPercent>=100?1:0]);
            if(!$offline){$_SESSION['study_clock'][$id]['at']=time();}return $result;
        });
    }
    public static function testState(int $student,int $id): ?array
    {
        $t=DB::one("SELECT t.*,COALESCE(tr.title,t.title) AS localized_title FROM tests t LEFT JOIN test_translations tr ON tr.test_id=t.id AND tr.language_id=(SELECT id FROM languages WHERE code=?) WHERE t.id=? AND t.status='published'",[TranslationService::currentCode(),$id]);
        if(!$t||!($enrol=self::enrolment($student,(int)$t['subject_id']))){return null;}
        $week=(int)($t['week_no']??1);$tree=self::tree($student,(int)$t['subject_id']);$module=null;
        if($t['module_id']){foreach($tree as $m){if((int)$m['id']===(int)$t['module_id']){$module=$m;$week=(int)$m['week_no'];break;}}if(!$module){return null;}}
        $state=DB::one('SELECT * FROM student_week_state WHERE student_id=? AND subject_id=? AND week_no=?',[$student,$t['subject_id'],$week]);$cycle=(int)($state['cycle_no']??1);
        $attempts=DB::one("SELECT COUNT(*) AS total,MAX(CASE WHEN result='pass' AND cycle_no=? THEN 1 ELSE 0 END) AS passed,MAX(CASE WHEN result<>'pending' THEN submitted_at ELSE NULL END) AS last_submit FROM test_attempts WHERE student_id=? AND test_id=?",[$cycle,$student,$id]);
        $reason=$enrol['status']==='completed'?'This course is completed. You can review your lessons and results.':null;
        if(!$reason&&($week>(int)$enrol['current_week_no']||($module&&!$module['unlocked']))){$reason='This test is locked.';}
        elseif($t['test_type']!=='practice'&&$attempts['passed']){$reason='You have already passed this test.';}
        elseif($t['available_from']&&strtotime($t['available_from'])>time()){$reason='This test is not open yet.';}
        elseif($t['available_to']&&strtotime($t['available_to'])<time()){$reason='This test has closed.';}
        $pending=DB::one("SELECT id FROM test_attempts WHERE student_id=? AND test_id=? AND cycle_no=? AND result='pending' ORDER BY id DESC LIMIT 1",[$student,$id,$cycle]);
        if(!$reason&&!$pending&&$t['max_attempts']!==null&&(int)$attempts['total']>=(int)$t['max_attempts']){$reason='Maximum attempts reached.';}
        if(!$reason&&!$pending&&$attempts['last_submit']&&strtotime($attempts['last_submit'])+(int)$t['retake_wait_hours']*3600>time()){$reason='Please wait before taking this test again.';}
        $relevant=$module?[$module]:array_values(array_filter($tree,fn($m)=>(int)$m['week_no']===$week));$required=0;$done=0;
        foreach($relevant as $m){foreach($m['lessons'] as $l){if($l['is_mandatory']){$required++;$done+=$l['completed']?1:0;}}}
        $study=$required?$done/$required*100:100;$needed=$t['test_type']==='module'?100:(int)$t['min_study_percent'];
        if(!$reason&&$study<$needed){$reason='Complete the required lessons before taking this test.';}
        return $t+['cycle'=>$cycle,'resolved_week'=>$week,'available'=>$reason===null,'reason'=>$reason,'passed'=>(bool)$attempts['passed'],'pending_id'=>$pending['id']??null];
    }
    public static function beginTest(int $student,int $test): int
    {
        return DB::transaction(function()use($student,$test){
            $subject=DB::val('SELECT subject_id FROM tests WHERE id=?',[$test]);if(!$subject||!self::enrolment($student,(int)$subject,true)){throw new \DomainException('This test is not available.');}
            DB::one('SELECT id FROM tests WHERE id=? FOR UPDATE',[$test]);
            $t=self::testState($student,$test);if(!$t){throw new \DomainException('This test is not available.');}if(!$t['available']){throw new \DomainException($t['reason']);}if($t['pending_id']){return (int)$t['pending_id'];}
            $questions=DB::all("SELECT q.id,q.question_type,COALESCE(tq.marks,q.default_marks) AS marks FROM test_questions tq JOIN questions q ON q.id=tq.question_id WHERE tq.test_id=? AND q.status='published' ORDER BY tq.sort_order,tq.id FOR UPDATE",[$test]);
            if(!$questions){throw new \DomainException('This test has no questions.');}
            $snapshot=[];foreach($questions as $q){if(!in_array($q['question_type'],['mcq_single','true_false'],true)){throw new \DomainException('This question type needs instructor review.');}$q['options']=DB::all('SELECT id,is_correct FROM question_options WHERE question_id=? ORDER BY option_order,id',[$q['id']]);if(!$q['options']||count(array_filter($q['options'],fn($o)=>$o['is_correct']))!==1){throw new \DomainException('This test needs instructor review.');}$snapshot[]=$q;}
            if($t['shuffle_questions']){shuffle($snapshot);}foreach($snapshot as &$q){if($t['shuffle_options']){shuffle($q['options']);}}unset($q);
            $uuid=uuid_v4();$marks=array_sum(array_column($snapshot,'marks'));
            $id=DB::insert("INSERT INTO test_attempts (client_uuid,test_id,test_version,student_id,subject_id,week_no,cycle_no,attempt_no,language_id,started_at,total_marks,result) VALUES (?,?,?,?,?,?,?,(SELECT COUNT(*)+1 FROM test_attempts a WHERE a.student_id=? AND a.test_id=?),?,NOW(),?,'pending')",[$uuid,$test,$t['version'],$student,$subject,$t['resolved_week'],$t['cycle'],$student,$test,self::languageId(),$marks]);
            $snapshotData=['questions'=>$snapshot,'duration'=>(int)$t['duration_minutes'],'pass_percent'=>(float)$t['pass_percent'],'pass_rule'=>$t['pass_rule'],'test_type'=>$t['test_type']];
            DB::run("INSERT INTO activity_events (client_event_uuid,student_id,subject_id,module_id,event_type,target_type,target_id,language_id,payload,occurred_at) VALUES (?,?,?,?,'test_start','attempt',?,?,?,NOW())",[$uuid,$student,$subject,$t['module_id'],$id,self::languageId(),json_encode($snapshotData,JSON_THROW_ON_ERROR)]);
            return $id;
        });
    }
    public static function attempt(int $student,int $id): ?array
    {
        $a=DB::one("SELECT a.*,COALESCE(tr.title,t.title) AS title FROM test_attempts a JOIN tests t ON t.id=a.test_id LEFT JOIN test_translations tr ON tr.test_id=t.id AND tr.language_id=(SELECT id FROM languages WHERE code=?) WHERE a.id=? AND a.student_id=?",[TranslationService::currentCode(),$id,$student]);if(!$a||($a['result']==='pending'&&!self::enrolment($student,(int)$a['subject_id']))){return null;}
        $event=DB::val("SELECT payload FROM activity_events WHERE student_id=? AND event_type='test_start' AND target_type='attempt' AND target_id=?",[$student,$id]);$a['snapshot']=$event?json_decode($event,true):null;return $a;
    }
    public static function takingQuestions(array $attempt): array
    {
        $lang=TranslationService::currentCode();$out=[];
        foreach($attempt['snapshot']['questions']??[] as $snapshot){$id=(int)$snapshot['id'];$q=DB::one("SELECT COALESCE(t.question_text,en.question_text) AS text FROM questions q LEFT JOIN question_translations t ON t.question_id=q.id AND t.language_id=(SELECT id FROM languages WHERE code=?) LEFT JOIN question_translations en ON en.question_id=q.id AND en.language_id=(SELECT id FROM languages WHERE code='en') WHERE q.id=?",[$lang,$id]);$item=['id'=>$id,'text'=>$q['text']??'','marks'=>$snapshot['marks'],'options'=>[]];
            foreach($snapshot['options'] as $o){$text=DB::val("SELECT COALESCE(t.option_text,en.option_text) FROM question_options o LEFT JOIN question_option_translations t ON t.option_id=o.id AND t.language_id=(SELECT id FROM languages WHERE code=?) LEFT JOIN question_option_translations en ON en.option_id=o.id AND en.language_id=(SELECT id FROM languages WHERE code='en') WHERE o.id=?",[$lang,$o['id']]);$item['options'][]=['id'=>(int)$o['id'],'text'=>(string)$text];}$out[]=$item;
        }return $out;
    }
    public static function submitTest(int $student,int $id,array $answers,bool $offline=false): array
    {
        return DB::transaction(function()use($student,$id,$answers,$offline){
            $raw=DB::one('SELECT subject_id FROM test_attempts WHERE id=? AND student_id=?',[$id,$student]);if(!$raw||!self::enrolment($student,(int)$raw['subject_id'],true)){throw new \DomainException('This attempt is not available.');}
            DB::one('SELECT id FROM test_attempts WHERE id=? FOR UPDATE',[$id]);$a=self::attempt($student,$id);if($a['result']!=='pending'){return ['attempt_id'=>$id,'percent'=>(float)$a['percentage'],'passed'=>$a['result']==='pass'];}
            $t=self::testState($student,(int)$a['test_id']);if(!$t||$t['cycle']!==(int)$a['cycle_no']||!$t['available']){throw new \DomainException($t['reason']??'This attempt is not available.');}
            $snapshot=$a['snapshot'];if(!$snapshot){throw new \DomainException('This attempt cannot be resumed.');}$duration=(int)$snapshot['duration'];$elapsed=max(0,time()-strtotime($a['started_at']));$expired=$duration>0&&$elapsed>$duration*60+5;
            if($expired){$answers=[];}$score=0.;$total=0.;$detail=[];
            foreach($snapshot['questions'] as $q){$total+=(float)$q['marks'];$given=$answers[$q['id']]??null;$given=is_scalar($given)?(int)$given:null;$valid=false;$correct=false;foreach($q['options'] as $option){if($given===(int)$option['id']){$valid=true;$correct=(bool)$option['is_correct'];break;}}$marks=$correct?(float)$q['marks']:0.;$score+=$marks;$detail[]=[$q['id'],$valid?json_encode([$given]):null,$correct?1:0,$marks];}
            if($total<=0){throw new \DomainException('This test has no questions.');}$percent=round($score/$total*100,2);$pass=!$expired&&($snapshot['pass_rule']==='gt'?$percent>(float)$snapshot['pass_percent']:$percent>=(float)$snapshot['pass_percent']);
            DB::run("UPDATE test_attempts SET submitted_at=NOW(),duration_seconds=?,total_marks=?,score=?,percentage=?,result=?,graded_by='server',is_offline=?,server_verified_at=NOW() WHERE id=?",[$elapsed,$total,$score,$percent,$pass?'pass':'fail',$offline?1:0,$id]);
            foreach($detail as [$qid,$selected,$correct,$marks]){DB::run('INSERT INTO test_attempt_answers (attempt_id,question_id,selected_option_ids,is_correct,marks_awarded,answered_at) VALUES (?,?,?,?,?,NOW())',[$id,$qid,$selected,$correct,$marks]);}
            if($snapshot['test_type']==='weekly'){
                DB::run("INSERT IGNORE INTO student_week_state (student_id,subject_id,week_no,cycle_no,status,opened_at) VALUES (?,?,?,?,'open',NOW())",[$student,$a['subject_id'],$a['week_no'],$a['cycle_no']]);
                if($pass){DB::run("UPDATE student_week_state SET status='passed',passed_at=NOW(),last_attempt_id=? WHERE student_id=? AND subject_id=? AND week_no=?",[$id,$student,$a['subject_id'],$a['week_no']]);DB::run('UPDATE student_subjects SET current_week_no=GREATEST(current_week_no,?) WHERE student_id=? AND subject_id=?',[(int)$a['week_no']+1,$student,$a['subject_id']]);DB::run("INSERT IGNORE INTO student_week_state (student_id,subject_id,week_no,status,opened_at) VALUES (?,?,?,'open',NOW())",[$student,$a['subject_id'],(int)$a['week_no']+1]);}
                else{DB::run("UPDATE student_week_state SET cycle_no=cycle_no+1,status='open',last_attempt_id=? WHERE student_id=? AND subject_id=? AND week_no=?",[$id,$student,$a['subject_id'],$a['week_no']]);}
            }
            $result=['attempt_id'=>$id,'percent'=>$percent,'passed'=>$pass,'expired'=>$expired];
            DB::run("INSERT INTO activity_events (client_event_uuid,student_id,subject_id,module_id,event_type,target_type,target_id,language_id,payload,is_offline,occurred_at) VALUES (?,?,?,?,'test_submit','attempt',?,?,?,?,NOW())",[uuid_v4(),$student,$a['subject_id'],$t['module_id'],$id,self::languageId(),json_encode($result,JSON_THROW_ON_ERROR),$offline?1:0]);
            DB::run("INSERT INTO notifications (user_id,type,title,body,data) VALUES (?,'test',?,?,?)",[$student,$pass?'Test passed':'Test not passed',$snapshot['test_type']==='weekly'?($pass?'The next week is now available.':'Review this week and study again before retrying.'):'Your test result is ready.',json_encode(['attempt_id'=>$id,'percent'=>$percent])]);return $result;
        });
    }
}
