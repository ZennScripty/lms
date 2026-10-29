<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{Auth, Authorization, Database as DB, Validator as V};

/** Schema-specific management queries. All SQL identifiers come from this allowlist. */
final class ManagementService
{
    public static function assignStudentBatch(int $student,int $batch): void
    {
        if(!Authorization::isAdmin()||!Authorization::can('students.manage'))throw new \DomainException('Access denied.');
        DB::transaction(function()use($student,$batch){
            $u=DB::one("SELECT u.* FROM users u JOIN roles r ON r.id=u.role_id AND r.code='student' WHERE u.id=? AND u.deleted_at IS NULL FOR UPDATE",[$student]);
            $b=DB::one('SELECT * FROM batches WHERE id=? AND is_active=1 FOR UPDATE',[$batch]);
            if(!$u||!$b||!Authorization::canManageUser($student)||!Authorization::canAccessCentre((int)$b['centre_id'])||(int)$u['centre_id']!==(int)$b['centre_id'])throw new \DomainException('Student batch must belong to the selected centre.');
            DB::run('UPDATE users SET batch_id=? WHERE id=?',[$batch,$student]);
            \App\Core\Logger::audit((int)Auth::user()['id'],'student_batch_assigned','users',$student,'batch_id='.$batch);
        });
    }
    public static function definitions(): array
    {
        return [
            'centres'=>['title'=>'Centres','table'=>'centres','search'=>['name','district'],'columns'=>['name'=>'Name','district'=>'District','is_active'=>'Active'],'fields'=>[
                'name'=>['Name','text',120], 'district'=>['District','optional',80], 'address'=>['Address','textarea',2000], 'is_active'=>['Active','bool']]],
            'batches'=>['title'=>'Batches','table'=>'batches','search'=>['name'],'columns'=>['name'=>'Name','centre_id'=>'Centre','training_mode'=>'Training mode','start_date'=>'Start date','is_active'=>'Active'],'fields'=>[
                'name'=>['Name','text',120], 'centre_id'=>['Centre','ref','centres'], 'training_mode'=>['Training mode','enum',['residential','community']], 'start_date'=>['Start date','date'], 'end_date'=>['End date','date'], 'is_active'=>['Active','bool']]],
            'users'=>['title'=>'Users','table'=>'users','search'=>['full_name','username','email'],'columns'=>['full_name'=>'Full name','username'=>'Username','role_id'=>'Role','centre_id'=>'Centre','status'=>'Status'],'fields'=>[
                'full_name'=>['Full name','text',120], 'username'=>['Username','text',60], 'email'=>['Email','email'], 'phone'=>['Phone','optional',20], 'role_id'=>['Role','ref','roles'], 'centre_id'=>['Centre','ref_optional','centres'], 'batch_id'=>['Student batch','ref_optional','batches'], 'password'=>['Password','password'], 'status'=>['Status','enum',['active','inactive','blocked','pending','rejected']]]],
            'students'=>['title'=>'Students','table'=>'users','search'=>['full_name','username','email'],'columns'=>['full_name'=>'Full name','username'=>'Username','centre_id'=>'Centre','status'=>'Status'],'fields'=>[]],
            'subjects'=>['title'=>'Subjects','table'=>'subjects','search'=>['name','code'],'columns'=>['code'=>'Code','name'=>'Name','is_active'=>'Active'],'fields'=>[
                'code'=>['Code','text',30], 'name'=>['Name','text',120], 'description'=>['Description','textarea',2000], 'is_active'=>['Active','bool']]],
            'assignments'=>['title'=>'Teacher assignments','table'=>'teacher_subjects','search'=>[],'columns'=>['teacher_id'=>'Teacher','subject_id'=>'Subject','can_manage_content'=>'Can manage content','can_manage_tests'=>'Can manage tests'],'fields'=>[
                'teacher_id'=>['Teacher','ref','teachers'], 'subject_id'=>['Subject','ref','subjects'], 'can_manage_content'=>['Can manage content','bool'], 'can_manage_tests'=>['Can manage tests','bool']]],
            'centre_subjects'=>['title'=>'Centre subjects','table'=>'centre_subjects','search'=>[],'columns'=>['centre_id'=>'Centre','subject_id'=>'Subject'],'fields'=>['centre_id'=>['Centre','ref','centres'],'subject_id'=>['Subject','ref','subjects']]],
            'enrolments'=>['title'=>'Enrolments','table'=>'student_subjects','search'=>[],'columns'=>['student_id'=>'Student','subject_id'=>'Subject','batch_id'=>'Batch','status'=>'Status'],'fields'=>[
                'student_id'=>['Student','ref','students'], 'subject_id'=>['Subject','ref','subjects'], 'batch_id'=>['Batch','ref_optional','batches'], 'status'=>['Status','enum',['active','completed','dropped']]]],
            'modules'=>['title'=>'Modules','table'=>'modules','search'=>['code'],'columns'=>['display_title'=>'Title','subject_id'=>'Subject','week_no'=>'Week','sequence_no'=>'Sequence','status'=>'Status'],'fields'=>[
                'subject_id'=>['Subject','ref','subjects'], 'code'=>['Code','optional',40], 'week_no'=>['Week','int',1,52], 'sequence_no'=>['Sequence','int',1,65535], 'estimated_minutes'=>['Estimated minutes','int',1,65535], 'status'=>['Status','enum',['draft','published','archived']], 'title_en'=>['Title (English)','text',200], 'title_hi'=>['Title (Hindi)','text',200], 'description_en'=>['Description (English)','textarea',10000], 'description_hi'=>['Description (Hindi)','textarea',10000]]],
            'lessons'=>['title'=>'Lessons','table'=>'content_items','search'=>[],'columns'=>['display_title'=>'Title','module_id'=>'Module','item_type'=>'Item type','sequence_no'=>'Sequence','status'=>'Status'],'fields'=>[
                'module_id'=>['Module','ref','modules'], 'item_type'=>['Item type','enum',['text','video','audio','pdf','image','checklist','step_guide']], 'sequence_no'=>['Sequence','int',1,65535], 'status'=>['Status','enum',['draft','published','archived']], 'is_mandatory'=>['Mandatory','bool'], 'is_downloadable'=>['Downloadable','bool'], 'min_complete_percent'=>['Minimum completion %','int',1,100], 'title_en'=>['Title (English)','text',200], 'title_hi'=>['Title (Hindi)','text',200], 'body_en'=>['Text (English)','textarea',100000], 'body_hi'=>['Text (Hindi)','textarea',100000], 'media_asset_id'=>['Media file','ref_optional','media_assets']]],
            'questions'=>['title'=>'Question bank','table'=>'questions','search'=>[],'columns'=>['display_title'=>'Question','subject_id'=>'Subject','difficulty'=>'Difficulty','default_marks'=>'Marks','status'=>'Status'],'fields'=>[
                'subject_id'=>['Subject','ref','subjects'], 'module_id'=>['Module','ref_optional','modules'], 'difficulty'=>['Difficulty','enum',['easy','medium','hard']], 'default_marks'=>['Marks','int',1,100], 'status'=>['Status','enum',['draft','published','archived']], 'question_en'=>['Question (English)','text',5000], 'question_hi'=>['Question (Hindi)','text',5000],
                'option_a_en'=>['Option A (English)','text',500], 'option_a_hi'=>['Option A (Hindi)','text',500], 'option_b_en'=>['Option B (English)','text',500], 'option_b_hi'=>['Option B (Hindi)','text',500], 'option_c_en'=>['Option C (English)','text',500], 'option_c_hi'=>['Option C (Hindi)','text',500], 'option_d_en'=>['Option D (English)','text',500], 'option_d_hi'=>['Option D (Hindi)','text',500], 'correct_option'=>['Correct option','enum',['A','B','C','D']]]],
            'tests'=>['title'=>'Tests','table'=>'tests','search'=>['title'],'columns'=>['title'=>'Test title','subject_id'=>'Subject','test_type'=>'Test type','pass_percent'=>'Pass %','status'=>'Status'],'fields'=>[
                'title'=>['Test title','text',200], 'title_hi'=>['Test title (Hindi)','optional',200], 'subject_id'=>['Subject','ref','subjects'], 'test_type'=>['Test type','enum',['weekly','module','practice']], 'week_no'=>['Week','int',1,52], 'module_id'=>['Module','ref_optional','modules'], 'duration_minutes'=>['Duration (minutes)','int',0,1440], 'pass_percent'=>['Pass %','int',1,100], 'pass_rule'=>['Pass rule','enum',['gte','gt']], 'max_attempts'=>['Maximum attempts','int_optional',1,255], 'retake_wait_hours'=>['Retake wait (hours)','int',0,65535], 'min_study_percent'=>['Minimum completion %','int',0,100], 'status'=>['Status','enum',['draft','published','archived']]]],
            'attendance'=>['title'=>'Attendance','table'=>'attendance_records','search'=>[],'columns'=>['user_id'=>'User','attendance_date'=>'Date','attendance_for'=>'Attendance for','status'=>'Status','check_in_time'=>'Check-in time','check_out_time'=>'Check-out time','checkin_mode'=>'Check-in method','remarks'=>'Remarks'],'fields'=>[
                'user_id'=>['User','ref','attendance_users'], 'attendance_date'=>['Date','required_date'], 'attendance_for'=>['Attendance for','enum',['student','teacher','admin']], 'status'=>['Status','enum',['present','absent','late','half_day','leave','holiday']], 'remarks'=>['Remarks','optional',500]]],
        ];
    }

    public static function isStaff(): bool { return Authorization::isAdmin() || Authorization::isTeacher(); }
    /** Add subjects without replacing existing assignments, permissions or learning history. */
    public static function assignSubjects(string $section,array $input): array
    {
        if(!Authorization::isAdmin()||!in_array($section,['assignments','enrolments'],true)){forbidden();}
        $raw=$input['subject_ids']??[];
        if(!is_array($raw)||count($raw)>200){throw new \DomainException('Choose between 1 and 200 subjects.');}
        $subjects=[];foreach($raw as $value){$subjects[]=V::int($value,'Subject',1);}
        $subjects=array_values(array_unique($subjects));
        if(!$subjects){throw new \DomainException('Choose at least one subject.');}
        $person=$section==='assignments'?'teacher_id':'student_id';
        $personId=V::int($input[$person]??'','User',1);
        if($section==='enrolments'&&AssignmentAccessService::forStudent($personId))throw new \DomainException('Assign the Student batch instead of individual subjects.');
        return DB::transaction(function()use($section,$input,$subjects,$person,$personId){
            // Serialize concurrent bulk requests for this person; each subject remains independent.
            DB::one('SELECT id FROM users WHERE id=? FOR UPDATE',[$personId]);
            $created=0;$existing=0;$table=self::definitions()[$section]['table'];
            foreach($subjects as $subject){
                if(!DB::val('SELECT id FROM subjects WHERE id=? AND is_active=1',[$subject])){throw new \DomainException('Choose active subjects only.');}
                if(DB::val("SELECT id FROM $table WHERE $person=? AND subject_id=?",[$personId,$subject])){$existing++;continue;}
                self::save($section,null,array_merge($input,[$person=>$personId,'subject_id'=>$subject]));$created++;
            }
            return ['created'=>$created,'existing'=>$existing];
        });
    }
    public static function checkSection(string $section): void
    {
        Auth::requireLogin();
        if(Authorization::isCentreAdmin()){
            if($section==='centre_subjects'){forbidden();}
            $permission=match($section){'users','batches','enrolments'=>'students.manage','centres'=>'subjects.view','assignments','subjects'=>'subjects.view','students'=>'students.view','attendance'=>'attendance.view','modules','lessons'=>'modules.view',default=>'tests.manage'};
            Authorization::require($permission);return;
        }
        if (!self::isStaff() || (!Authorization::isSuperAdmin() && !in_array($section,['subjects','students','modules','lessons','questions','tests','attendance'],true))) { forbidden(); }
        if (!Authorization::isSuperAdmin()) { $permission=match($section){'students'=>'students.view','subjects'=>'subjects.view','modules','lessons'=>'modules.view','attendance'=>'attendance.view',default=>'tests.manage'}; Authorization::require($permission); }
    }
    public static function scope(string $section, array &$params): string
    {
        $enrolmentSource=AssignmentAccessService::studentPairsSql();
        $where = in_array($section,['users','students','modules'],true) ? 'x.deleted_at IS NULL' : '1=1';
        if ($section === 'students') { $where .= " AND x.role_id IN (SELECT id FROM roles WHERE code='student')"; }
        if(Authorization::isCentreAdmin()){
            if(in_array($section,['users','students'],true)){$where.=" AND x.role_id IN (SELECT id FROM roles WHERE code IN ('student','teacher')) AND ".Authorization::centreWhere('x.centre_id',$params);}
            elseif($section==='centres'){$where.=' AND '.Authorization::centreWhere('x.id',$params);}
            elseif($section==='batches'){$where.=' AND '.Authorization::centreWhere('x.centre_id',$params);}
            elseif($section==='attendance'){$cp=[];$cw=Authorization::centreWhere('au.centre_id',$cp);$where.=" AND x.user_id IN (SELECT au.id FROM users au JOIN roles ar ON ar.id=au.role_id WHERE au.deleted_at IS NULL AND ar.code IN ('student','teacher') AND $cw)";$params=array_merge($params,$cp);}
            else{
                $ids=Authorization::visibleSubjectIds();$column=$section==='subjects'?'x.id':'x.subject_id';$where.=$ids?' AND '.$column.' IN ('.DB::in($ids).')':' AND 1=0';$params=array_merge($params,$ids??[]);
                if(in_array($section,['assignments','enrolments'],true)){$column=$section==='assignments'?'x.teacher_id':'x.student_id';$cp=[];$cw=Authorization::centreWhere('cu.centre_id',$cp);$where.=" AND $column IN (SELECT cu.id FROM users cu WHERE cu.deleted_at IS NULL AND $cw)";$params=array_merge($params,$cp);}
            }
            return $where;
        }
        if (!Authorization::isSuperAdmin()) {
            $uid = (int)Auth::user()['id'];
            if ($section === 'subjects') { $where .= ' AND x.id IN (SELECT subject_id FROM teacher_subjects WHERE teacher_id=?)'; }
            elseif ($section === 'students' || $section === 'attendance') {
                $column = $section === 'students' ? 'x.id' : 'x.user_id';
                $where .= " AND $column IN (SELECT ss.student_id FROM $enrolmentSource ss JOIN teacher_subjects ts ON ts.subject_id=ss.subject_id JOIN users su ON su.id=ss.student_id WHERE ts.teacher_id=? AND ss.status='active' AND su.centre_id <=> ?)";
                if ($section === 'attendance') { $where .= " AND x.attendance_for='student'"; }
            } else { $where .= ' AND x.subject_id IN (SELECT subject_id FROM teacher_subjects WHERE teacher_id=?)'; }
            if(!in_array($section,['students','attendance'],true)){$strictIds=AssignmentAccessService::teacherSubjectIds($uid);$col=$section==='subjects'?'x.id':'x.subject_id';$where.=$strictIds?' AND '.$col.' IN ('.DB::in($strictIds).')':' AND 1=0';}
            $params[] = $uid;
            if(isset($strictIds))$params=array_merge($params,$strictIds);
            if(in_array($section,['students','attendance'],true)){$params[]=Auth::user()['centre_id'];}
            if(in_array($section,['students','attendance'],true)){$alias=$section==='students'?'x':'checked_user';$tp=[];$tw=AssignmentAccessService::teacherStudentFilter($alias,null,$tp);$where.=$section==='students'?' AND '.$tw:' AND x.user_id IN (SELECT checked_user.id FROM users checked_user WHERE '.$tw.')';$params=array_merge($params,$tp);}

        }
        return $where;
    }
    public static function canWrite(string $section, ?int $subjectId = null): bool
    {
        if (Authorization::isSuperAdmin()) { return true; }
        if(Authorization::isCentreAdmin()){
            if(in_array($section,['centres','centre_subjects','students','subjects'],true))return false;
            $permission=match($section){'users','batches','enrolments'=>'students.manage','assignments','subjects'=>'subjects.manage','attendance'=>'attendance.mark','modules','lessons'=>'modules.manage',default=>'tests.manage'};
            return Authorization::can($permission)&&($subjectId===null||in_array($section,['assignments','enrolments'],true)||Authorization::canWriteSubject($subjectId));
        }
        if (!Authorization::isTeacher() || in_array($section,['subjects','students'],true)) { return false; }
        if ($section === 'attendance') { return Authorization::can('attendance.mark'); }
        if (!Authorization::can(in_array($section,['modules','lessons'],true) ? 'modules.manage' : 'tests.manage')) { return false; }
        if($subjectId!==null&&!Authorization::canAccessSubject($subjectId))return false;
        if(!AssignmentAccessService::teacherSubjectIds((int)Auth::user()['id']))return false;
        $flag = in_array($section,['modules','lessons'],true) ? 'can_manage_content' : 'can_manage_tests';
        $sql = "SELECT COUNT(*) FROM teacher_subjects WHERE teacher_id=? AND $flag=1";
        $args = [(int)Auth::user()['id']];
        $allowed=AssignmentAccessService::teacherSubjectIds((int)Auth::user()['id']);
        $sql.=' AND subject_id IN ('.DB::in($allowed).')';$args=array_merge($args,$allowed);
        if ($subjectId !== null) { $sql .= ' AND subject_id=?'; $args[]=$subjectId; }
        return (int)DB::val($sql,$args)>0;
    }
    public static function options(string $kind): array
    {
        $lang = TranslationService::currentCode();$enrolmentSource=AssignmentAccessService::studentPairsSql();
        $queries = [
            'centres'=>'SELECT id,name AS label FROM centres ORDER BY name', 'batches'=>'SELECT id,name AS label FROM batches ORDER BY name',
            'roles'=>'SELECT id,name AS label FROM roles ORDER BY id',
            'teachers'=>"SELECT u.id,u.full_name AS label FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='teacher' AND u.deleted_at IS NULL ORDER BY u.full_name",
            'students'=>"SELECT u.id,u.full_name AS label FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='student' AND u.deleted_at IS NULL ORDER BY u.full_name",
            'subjects'=>'SELECT x.id,x.name AS label,x.is_active FROM subjects x WHERE ',
            'modules'=>"SELECT x.id,COALESCE((SELECT title FROM module_translations mt JOIN languages l ON l.id=mt.language_id WHERE mt.module_id=x.id AND l.code='$lang' LIMIT 1), x.code, CONCAT('#',x.id)) AS label FROM modules x WHERE ",
            'media_assets'=>'SELECT id,original_name AS label FROM media_assets WHERE NOT EXISTS (SELECT 1 FROM users pu WHERE pu.profile_asset_id=media_assets.id) AND ' . (Authorization::isSuperAdmin() ? '1=1' : 'uploaded_by=' . (int)Auth::user()['id']) . ' ORDER BY id DESC',
            'attendance_users'=>"SELECT u.id,CONCAT(u.full_name,' · ',r.name) AS label FROM users u JOIN roles r ON r.id=u.role_id WHERE u.deleted_at IS NULL AND r.code IN ('teacher','student','admin','super_admin') ORDER BY u.full_name",
        ];
        if (!isset($queries[$kind])) { throw new \LogicException('Unknown option source'); }
        $params=[];
        if (in_array($kind,['subjects','modules'],true)) { return DB::all($queries[$kind] . self::scope($kind,$params) . ' ORDER BY x.id', $params); }
        if(Authorization::isCentreAdmin()){
            if(in_array($kind,['centres','batches'],true)){$p=[];$w=self::scope($kind,$p);return DB::all("SELECT x.id,x.name AS label FROM $kind x WHERE $w ORDER BY x.name",$p);}
            if($kind==='roles')return DB::all("SELECT id,name AS label FROM roles WHERE code IN ('teacher','student') ORDER BY id");
            if(in_array($kind,['teachers','students','attendance_users'],true)){$p=[];$w=Authorization::centreWhere('u.centre_id',$p);$roles=match($kind){'teachers'=>"'teacher'",'students'=>"'student'",default=>"'teacher','student'"};return DB::all("SELECT u.id,u.full_name AS label FROM users u JOIN roles r ON r.id=u.role_id WHERE u.deleted_at IS NULL AND r.code IN ($roles) AND $w ORDER BY u.full_name",$p);}
            if($kind==='media_assets'){$p=[];$w=Authorization::mediaWhere('ma',$p);return DB::all("SELECT ma.id,ma.original_name AS label FROM media_assets ma WHERE $w AND NOT EXISTS (SELECT 1 FROM users pu WHERE pu.profile_asset_id=ma.id) ORDER BY ma.id DESC",$p);}
        }
        if (!Authorization::isAdmin() && in_array($kind,['students','attendance_users'],true)) {
            $tp=[];$tw=AssignmentAccessService::teacherStudentFilter('u',null,$tp);
            return DB::all("SELECT DISTINCT u.id,u.full_name AS label FROM users u JOIN $enrolmentSource ss ON ss.student_id=u.id JOIN teacher_subjects ts ON ts.subject_id=ss.subject_id WHERE ts.teacher_id=? AND ss.status='active' AND u.deleted_at IS NULL AND u.centre_id <=> ? AND $tw ORDER BY u.full_name",array_merge([Auth::user()['id'],Auth::user()['centre_id']],$tp));
        }
        return DB::all($queries[$kind]);
    }
    public static function translatedSelect(string $section): string
    {
        $lang = TranslationService::currentCode();
        $map=['modules'=>['module_translations','module_id','title'], 'lessons'=>['content_item_translations','content_item_id','title'], 'questions'=>['question_translations','question_id','question_text']];
        if (!isset($map[$section])) { return 'x.*'; }
        [$table,$fk,$column]=$map[$section];
        return "x.*,COALESCE((SELECT t.$column FROM $table t JOIN languages l ON l.id=t.language_id WHERE t.$fk=x.id AND l.code='$lang' LIMIT 1),(SELECT t.$column FROM $table t JOIN languages l ON l.id=t.language_id WHERE t.$fk=x.id AND l.code='en' LIMIT 1),CONCAT('#',x.id)) AS display_title";
    }
    public static function find(string $section,int $id): ?array
    {
        $defs=self::definitions();$table=$defs[$section]['table'];$p=[];$where=self::scope($section,$p);$p[]=$id;
        return DB::one('SELECT '.self::translatedSelect($section)." FROM $table x WHERE $where AND x.id=?",$p);
    }
    public static function hydrate(string $section,array $row): array
    {
        $map=['tests'=>['test_translations','test_id',['title'=>'title']], 'modules'=>['module_translations','module_id',['title'=>'title','description'=>'description']], 'lessons'=>['content_item_translations','content_item_id',['title'=>'title','body_text'=>'body']], 'questions'=>['question_translations','question_id',['question_text'=>'question']]];
        if (isset($map[$section])) {
            [$table,$fk,$fields]=$map[$section];
            foreach(DB::all("SELECT t.*,l.code FROM $table t JOIN languages l ON l.id=t.language_id WHERE t.$fk=? AND l.code IN ('en','hi')",[$row['id']]) as $t) {
                foreach($fields as $db=>$form) {$row[$form.'_'.$t['code']]=$t[$db];}
                if($section==='lessons'){$row['media_asset_id']=$t['media_asset_id'];}
            }
        }
        if($section==='questions') {
            foreach(DB::all("SELECT o.*,t.option_text,l.code FROM question_options o JOIN question_option_translations t ON t.option_id=o.id JOIN languages l ON l.id=t.language_id WHERE o.question_id=? ORDER BY o.option_order",[$row['id']]) as $o) {
                $letter=chr(96+(int)$o['option_order']);$row['option_'.$letter.'_'.$o['code']]=$o['option_text'];if($o['is_correct']){$row['correct_option']=strtoupper($letter);}
            }
        }
        return $row;
    }
    public static function save(string $section,?int $id,array $input): int
    {
        self::checkSection($section);
        $def=self::definitions()[$section];$old=$id?self::find($section,$id):null;
        if($section==='users'&&!array_key_exists('batch_id',$input))$input['batch_id']=$old['batch_id']??'';
        if($id && !$old){throw new \DomainException('Record not found.');}
        if(!self::canWrite($section,isset($old['subject_id'])?(int)$old['subject_id']:null)){forbidden();}
        if(!$id&&in_array($section,['tests','questions'],true)){
            $subject=V::int($input['subject_id']??'','Subject',1);$s=self::find('subjects',$subject);if(!$s)throw new \DomainException('Subject not found.');$module=V::optInt($input['module_id']??null,'Module');$titles=['en'=>$s['name'],'hi'=>$s['name']];
            if($module){$m=self::find('modules',$module);if(!$m||(int)$m['subject_id']!==$subject)throw new \DomainException('The module must belong to the selected subject.');foreach(DB::all("SELECT l.code,t.title FROM module_translations t JOIN languages l ON l.id=t.language_id WHERE t.module_id=? AND l.code IN ('en','hi')",[$module]) as $mt)$titles[$mt['code']].=' — '.$mt['title'];}
            foreach(['en'=>$section==='tests'?'title':'question_en','hi'=>$section==='tests'?'title_hi':'question_hi'] as $lang=>$field){if(!isset($input[$field])||is_string($input[$field])&&trim($input[$field])==='')$input[$field]=$titles[$lang];}
        }
        $data=[];$extra=[];
        foreach($def['fields'] as $key=>$field) {
            [$label,$type]=$field;$value=$input[$key]??'';
            $valid=match($type) {
                'text'=>V::str($value,$label,1,$field[2]), 'optional','textarea'=>V::optStr($value,$label,$field[2]),
                'bool'=>isset($input[$key])?1:0, 'enum'=>V::inList($value,$field[2],$label),
                'int'=>V::int($value,$label,$field[2],$field[3]), 'int_optional'=>$value===''?null:V::int($value,$label,$field[2],$field[3]),
                'date'=>$value===''?null:V::date($value,$label), 'required_date'=>V::date($value,$label),
                'email'=>$value===''?null:V::email($value), 'password'=>$value===''&&$id?null:V::password($value),
                'ref','ref_optional'=>$value===''&&$type==='ref_optional'?null:V::int($value,$label,1),
                default=>throw new \LogicException('Unknown field type')
            };
            if(str_starts_with($type,'ref') && $valid!==null && !in_array($valid,array_map('intval',array_column(self::options($field[2]),'id')),true)){throw new \DomainException('Invalid assignment.');}
            if(preg_match('/_(en|hi)$/',$key)||in_array($key,['password','media_asset_id','correct_option'],true)){$extra[$key]=$valid;}else{$data[$key]=$valid;}
        }
        $uid=(int)Auth::user()['id'];
        if(Authorization::isCentreAdmin()&&!Authorization::centreIds()){throw new \DomainException('No active centres assigned. Contact Super Admin.');}
        if(Authorization::isCentreAdmin()&&$section==='users'){
            $code=DB::val('SELECT code FROM roles WHERE id=?',[$data['role_id']]);
            if(!in_array($code,['student','teacher'],true)||!$data['centre_id']||!Authorization::canAccessCentre((int)$data['centre_id'])){throw new \DomainException('Choose a permitted centre and Student or Teacher role.');}
        }
        $adminCentres=[];
        if($section==='users') {
            $code=DB::val('SELECT code FROM roles WHERE id=?',[$data['role_id']]);
            if($code!=='student')$data['batch_id']=null;
            if(!empty($data['batch_id'])){
                $b=DB::one('SELECT centre_id,is_active FROM batches WHERE id=?',[$data['batch_id']]);
                if($code!=='student'||!$b||(int)$b['centre_id']!==(int)$data['centre_id']||(!$b['is_active']&&($old['batch_id']??null)!=$data['batch_id']))throw new \DomainException('Student batch must belong to the selected centre.');
            }
            if($id===$uid && ($data['status']!=='active' || (int)$data['role_id']!==(int)Auth::user()['role_id'])){throw new \DomainException('You cannot disable or demote your own account.');}
            $code=DB::val('SELECT code FROM roles WHERE id=?',[$data['role_id']]);
            if($code==='admin'){
                if(!Authorization::isSuperAdmin()){forbidden();}
                $raw=$input['centre_ids']??(!empty($input['centres_submitted'])?[]:($old?array_column(DB::all('SELECT centre_id FROM admin_centres WHERE admin_id=?',[$id]),'centre_id'):[]));
                if(!is_array($raw)||count($raw)>200){throw new \DomainException('Choose permitted Admin centres.');}
                foreach($raw as $centre){$centre=V::int($centre,'Centre',1);$active=DB::val('SELECT is_active FROM centres WHERE id=?',[$centre]);if($active===null||($data['status']==='active'&&!(int)$active)){throw new \DomainException('Choose active Admin centres.');}$adminCentres[]=$centre;}
                $adminCentres=array_values(array_unique($adminCentres));if(!$adminCentres&&$data['status']==='active'){throw new \DomainException('Assign at least one active centre to an Admin.');}$data['centre_id']=$adminCentres[0]??null;
            }
            if($old&&!in_array(DB::val('SELECT code FROM roles WHERE id=?',[$old['role_id']]),['admin','super_admin'],true)&&$old['centre_id']!=$data['centre_id']&&DB::val('SELECT (SELECT COUNT(*) FROM student_subjects WHERE student_id=?)+(SELECT COUNT(*) FROM teacher_subjects WHERE teacher_id=?)+(SELECT COUNT(*) FROM attendance_records WHERE user_id=?)',[$id,$id,$id])){throw new \DomainException('Cannot move a user with assignments or attendance history.');}
            if($extra['password']!==null){$data['password_hash']=password_hash($extra['password'],PASSWORD_DEFAULT);}
            if(!$id){$data['uuid']=uuid_v4();$data['created_by']=$uid;$data['preferred_language_id']=DB::val("SELECT id FROM languages WHERE code='en'");}
        }
        if($section==='assignments'){
            $centre=DB::val('SELECT centre_id FROM users WHERE id=?',[$data['teacher_id']]);
            if(Authorization::isCentreAdmin()&&(!DB::val('SELECT id FROM centre_subjects WHERE subject_id=? AND centre_id=?',[$data['subject_id'],$centre])||(($data['can_manage_content']||$data['can_manage_tests'])&&!Authorization::canWriteSubject((int)$data['subject_id'])))){throw new \DomainException('Subject scope does not permit these Teacher management rights.');}
        }
        if($section==='enrolments'&&Authorization::isCentreAdmin()){
            $centre=DB::val('SELECT centre_id FROM users WHERE id=?',[$data['student_id']]);
            if(!DB::val('SELECT id FROM centre_subjects WHERE subject_id=? AND centre_id=?',[$data['subject_id'],$centre])){throw new \DomainException('Subject is not assigned to this student centre.');}
        }
        if($section==='centre_subjects'&&$old&&($old['centre_id']!=$data['centre_id']||$old['subject_id']!=$data['subject_id'])){throw new \DomainException('Existing centre subject links are retained. Add a new link instead.');}
        if($section==='batches'&&$data['start_date']&&$data['end_date']&&$data['end_date']<$data['start_date']){throw new \DomainException('End date must be after start date.');}
        if($section==='lessons'){$module=self::find('modules',(int)$data['module_id']);$data['subject_id']=$module['subject_id'];
            $asset=$extra['media_asset_id']; if ($asset) { $assetType=DB::val('SELECT asset_type FROM media_assets WHERE id=?',[$asset]); if(in_array($data['item_type'],['video','audio','pdf','image'],true)&&$assetType!==$data['item_type']){throw new \DomainException('Media type must match the lesson type.');} }
            if($data['status']==='published' && in_array($data['item_type'],['video','audio','pdf','image'],true) && !$asset){throw new \DomainException('Attach media before publishing this lesson.');}}
        if(isset($data['subject_id'])&&!self::canWrite($section,(int)$data['subject_id'])){forbidden();}
        if(in_array($section,['questions','tests'],true)&&!empty($data['module_id'])) {
            $module=self::find('modules',(int)$data['module_id']);
            if(!$module||(int)$module['subject_id']!==(int)$data['subject_id']){throw new \DomainException('The module must belong to the selected subject.');}
        }
        if($section==='attendance') {
            $role=DB::val('SELECT r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$data['user_id']]);
            if($role==='super_admin'){$role='admin';}
            if($role!==$data['attendance_for']||(!Authorization::isAdmin()&&$role!=='student')){throw new \DomainException('Attendance type does not match this user.');}
            $duplicate=DB::val('SELECT id FROM attendance_records WHERE user_id=? AND attendance_date=? AND id<>?',[$data['user_id'],$data['attendance_date'],$id??0]);
            if($duplicate){throw new \DomainException('Attendance already exists for this user and date. Edit that record.');}
            if($old&&((int)$old['user_id']!==(int)$data['user_id']||$old['attendance_date']!==$data['attendance_date']||$old['attendance_for']!==$data['attendance_for'])){throw new \DomainException('Attendance owner and date cannot be changed. Correct the status or remarks.');}
            $data['marked_by']=$uid;
        }
        if($section==='enrolments'&&AssignmentAccessService::forStudent((int)$data['student_id']))throw new \DomainException('Assign the Student batch instead of individual subjects.');
        if($section==='enrolments'&&!empty($data['batch_id'])) {
            $centre=DB::val('SELECT centre_id FROM users WHERE id=?',[$data['student_id']]);$batchCentre=DB::val('SELECT centre_id FROM batches WHERE id=?',[$data['batch_id']]);
            if($centre!==null && (int)$centre!==(int)$batchCentre){throw new \DomainException('Student and batch must belong to the same centre.');}
        }
        if(in_array($section,['questions','tests'],true)&&$id) {
            $attempts=$section==='tests'?DB::val('SELECT COUNT(*) FROM test_attempts WHERE test_id=?',[$id]):DB::val('SELECT COUNT(*) FROM test_attempts a JOIN test_questions tq ON tq.test_id=a.test_id WHERE tq.question_id=?',[$id]);
            if($attempts){throw new \DomainException('This record has attempts. Create a new version instead of changing historical results.');}
        }
        if($section==='tests'&&$data['test_type']==='module'&&empty($data['module_id'])){throw new \DomainException('Choose a module for a module test.');}
        $questionIds=V::intList($input['question_ids']??[]);
        if($section==='tests') {
            foreach($questionIds as $qid){$q=self::find('questions',$qid);if(!$q||(int)$q['subject_id']!==(int)$data['subject_id']||$q['status']!=='published'||(!empty($data['module_id'])&&!empty($q['module_id'])&&(int)$data['module_id']!==(int)$q['module_id'])){throw new \DomainException('Choose published questions from the same subject and module.');}}
            if($data['status']==='published'&&!$questionIds){throw new \DomainException('Published tests need at least one question.');}
        }
        return DB::transaction(function()use($section,$def,$id,$data,$extra,$uid,$questionIds,$old,$adminCentres){
            if($section==='batches'&&$old&&(int)$old['centre_id']!==(int)$data['centre_id']){
                DB::one('SELECT id FROM batches WHERE id=? FOR UPDATE',[$id]);
                if(DB::val('SELECT (SELECT COUNT(*) FROM users WHERE batch_id=?)+(SELECT COUNT(*) FROM student_subjects WHERE batch_id=?)+(SELECT COUNT(*) FROM batch_courses WHERE batch_id=?)',[$id,$id,$id]))throw new \DomainException('Cannot move a batch with members or assignments to another centre.');
            }
            if($section==='users'&&!empty($data['batch_id'])){
                $b=DB::one('SELECT centre_id FROM batches WHERE id=? FOR UPDATE',[$data['batch_id']]);if(!$b||(int)$b['centre_id']!==(int)$data['centre_id'])throw new \DomainException('Student batch must belong to the selected centre.');
            }
            if($section==='users'){
                $super=(int)DB::val("SELECT id FROM roles WHERE code='super_admin'");DB::one('SELECT id FROM roles WHERE id=? FOR UPDATE',[$super]);
                if($id){$locked=DB::one('SELECT * FROM users WHERE id=? FOR UPDATE',[$id]);self::guardSuper($locked,$data['status'],(int)$data['role_id']);}
                if($old&&$old['status']==='pending'&&in_array($data['status'],['active','rejected'],true)){
                    if($data['status']==='active'){RegistrationService::validateApproval(array_merge($locked,$data));}
                    $data['reviewed_at']=date('Y-m-d H:i:s');$data['reviewed_by']=$uid;
                }
            }
            if($section==='attendance'){
                DB::one('SELECT id FROM users WHERE id=? FOR UPDATE',[$data['user_id']]);
                if(DB::val('SELECT id FROM attendance_records WHERE user_id=? AND attendance_date=? AND id<>?',[$data['user_id'],$data['attendance_date'],$id??0])){throw new \DomainException('Attendance already exists for this user and date. Edit that record.');}
            }
            if($id&&in_array($section,['tests','questions'],true)){
                DB::one('SELECT id FROM '.$def['table'].' WHERE id=? FOR UPDATE',[$id]);
                $used=$section==='tests'?DB::val('SELECT COUNT(*) FROM test_attempts WHERE test_id=?',[$id]):DB::val('SELECT COUNT(*) FROM test_attempts a JOIN test_questions tq ON tq.test_id=a.test_id WHERE tq.question_id=?',[$id]);
                if($used){throw new \DomainException('This record has attempts. Create a new version instead of changing historical results.');}
            }
            if(!$id && in_array($section,['subjects','modules','lessons','questions','tests'],true)){$data['created_by']=$uid;}
            if(!$id && $section==='assignments'){$data['assigned_by']=$uid;}
            if(!$id && $section==='enrolments'){$data['enrolled_by']=$uid;}
            $table=$def['table'];$keys=array_keys($data);
            if($id){DB::run("UPDATE $table SET ".implode(',',array_map(fn($k)=>"`$k`=?",$keys)).' WHERE id=?',array_merge(array_values($data),[$id]));}
            else{$id=DB::insert("INSERT INTO $table (`".implode('`,`',$keys).'`) VALUES ('.DB::in($keys).')',array_values($data));}
            if($section==='users'&&Authorization::isSuperAdmin()){
                DB::run('DELETE FROM admin_centres WHERE admin_id=?',[$id]);
                foreach($adminCentres as $centre){DB::run('INSERT INTO admin_centres (admin_id,centre_id,assigned_by) VALUES (?,?,?)',[$id,$centre,$uid]);}
            }
            if($section==='subjects'&&!$old&&Authorization::isCentreAdmin()){foreach(Authorization::centreIds() as $centre){DB::run('INSERT INTO centre_subjects (centre_id,subject_id) VALUES (?,?)',[$centre,$id]);}}
            foreach(['en','hi'] as $lang) {
                $lid=(int)DB::val('SELECT id FROM languages WHERE code=?',[$lang]);
                if($section==='tests'){ $title=$lang==='en'?$data['title']:($extra['title_hi']??null);if($title){DB::run('INSERT INTO test_translations (test_id,language_id,title) VALUES (?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title)',[$id,$lid,$title]);}elseif($lang==='hi'){DB::run('DELETE FROM test_translations WHERE test_id=? AND language_id=?',[$id,$lid]);} }
                if($section==='modules'){DB::run('INSERT INTO module_translations (module_id,language_id,title,description) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description)',[$id,$lid,$extra['title_'.$lang],$extra['description_'.$lang]]);}
                if($section==='lessons'){DB::run('INSERT INTO content_item_translations (content_item_id,language_id,title,body_text,media_asset_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),body_text=VALUES(body_text),media_asset_id=VALUES(media_asset_id)',[$id,$lid,$extra['title_'.$lang],$extra['body_'.$lang],$extra['media_asset_id']]);}
                if($section==='questions'){DB::run('INSERT INTO question_translations (question_id,language_id,question_text) VALUES (?,?,?) ON DUPLICATE KEY UPDATE question_text=VALUES(question_text)',[$id,$lid,$extra['question_'.$lang]]);}
            }
            if($section==='questions') {
                foreach(['a','b','c','d'] as $i=>$letter) {
                    $oid=DB::val('SELECT id FROM question_options WHERE question_id=? AND option_order=?',[$id,$i+1]);$correct=$extra['correct_option']===strtoupper($letter)?1:0;
                    if($oid){DB::run('UPDATE question_options SET is_correct=? WHERE id=?',[$correct,$oid]);}else{$oid=DB::insert('INSERT INTO question_options (question_id,option_order,is_correct) VALUES (?,?,?)',[$id,$i+1,$correct]);}
                    foreach(['en','hi'] as $lang){DB::run('INSERT INTO question_option_translations (option_id,language_id,option_text) VALUES (?,(SELECT id FROM languages WHERE code=?),?) ON DUPLICATE KEY UPDATE option_text=VALUES(option_text)',[$oid,$lang,$extra['option_'.$letter.'_'.$lang]]);}
                }
            }
            if($section==='tests') {
                DB::run('DELETE FROM test_questions WHERE test_id=?',[$id]);$total=0;
                foreach($questionIds as $i=>$qid){$marks=DB::val('SELECT default_marks FROM questions WHERE id=?',[$qid]);$total+=(float)$marks;DB::run('INSERT INTO test_questions (test_id,question_id,sort_order,marks) VALUES (?,?,?,?)',[$id,$qid,$i+1,$marks]);}
                DB::run('UPDATE tests SET total_marks=? WHERE id=?',[$total,$id]);
            }
            if($section==='enrolments'){DB::run("INSERT IGNORE INTO student_week_state (student_id,subject_id,week_no,status,opened_at) VALUES (?,?,1,'open',NOW())",[$data['student_id'],$data['subject_id']]);}
            \App\Core\Logger::audit($uid,'record_saved',$table,$id);
            return $id;
        });
    }
    private static function guardSuper(array $user,string $status,int $role): void
    {
        $super=(int)DB::val("SELECT id FROM roles WHERE code='super_admin'");
        if((int)$user['role_id']===$super&&($status!=='active'||$role!==$super)&&(int)DB::val("SELECT COUNT(*) FROM users WHERE role_id=? AND status='active' AND deleted_at IS NULL",[$super])<=1){throw new \DomainException('Keep at least one active Super Admin.');}
    }
    public static function deleteUser(int $id,bool $confirmed): void
    {
        self::checkSection('users');if(!self::canWrite('users')||!Authorization::canManageUser($id)){throw new \DomainException('Record not found.');}
        if(!$confirmed){throw new \DomainException('Confirm account deletion first.');}
        if($id===(int)Auth::user()['id']){throw new \DomainException('You cannot delete your own account.');}
        DB::transaction(function()use($id){
            DB::one("SELECT id FROM roles WHERE code='super_admin' FOR UPDATE");$row=DB::one('SELECT * FROM users WHERE id=? FOR UPDATE',[$id]);
            if(!Authorization::canManageUser($id)){throw new \DomainException('Record not found.');}self::guardSuper($row,'inactive',(int)$row['role_id']);
            DB::run("UPDATE users SET status='inactive',deleted_at=NOW() WHERE id=? AND deleted_at IS NULL",[$id]);
            \App\Core\Logger::audit((int)Auth::user()['id'],'account_deleted','users',$id,'Soft deletion; history retained');
        });
    }
    public static function reviewUser(int $id,string $decision): void
    {
        self::checkSection('users');if(!self::canWrite('users')||!Authorization::canManageUser($id)){throw new \DomainException('Record not found.');}
        if(!in_array($decision,['approve','reject'],true)){throw new \DomainException('Invalid review decision.');}
        DB::transaction(function()use($id,$decision){$row=DB::one('SELECT * FROM users WHERE id=? FOR UPDATE',[$id]);
            if(!Authorization::canManageUser($id)||$row['status']!=='pending'){throw new \DomainException('Only pending accounts can be reviewed once.');}
            if($decision==='approve'){RegistrationService::validateApproval($row);}
            DB::run('UPDATE users SET status=?,reviewed_at=NOW(),reviewed_by=? WHERE id=?',[$decision==='approve'?'active':'rejected',Auth::user()['id'],$id]);
            \App\Core\Logger::audit((int)Auth::user()['id'],'registration_'.$decision,'users',$id);
        });
    }
}
