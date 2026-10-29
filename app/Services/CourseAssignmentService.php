<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Auth,Authorization as A,Database as DB,Logger,Validator as V};

/** Additive assignment operations; never deletes content, enrolments or learning history. */
final class CourseAssignmentService
{
    private static function guard(bool $global=false): void
    {
        if(!Auth::user()||!A::isAdmin()||($global&&!A::isSuperAdmin())||!A::can('students.manage')){
            throw new \DomainException('Access denied.');
        }
    }
    private static function course(int $id,bool $active): void
    {
        $row=DB::one('SELECT * FROM courses WHERE id=? FOR UPDATE',[$id]);
        if(!$row||($active&&!(int)$row['is_active']))throw new \DomainException('Choose an available course.');
    }
    private static function centre(int $id,bool $active): void
    {
        $row=DB::one('SELECT * FROM centres WHERE id=? FOR UPDATE',[$id]);
        if(!$row||!A::canAccessCentre($id)||($active&&!(int)$row['is_active']))throw new \DomainException('Choose a permitted centre.');
    }
    private static function batch(int $id,bool $active): array
    {
        $row=DB::one('SELECT * FROM batches WHERE id=? FOR UPDATE',[$id]);
        if(!$row||($active&&!(int)$row['is_active']))throw new \DomainException('Choose an available batch.');
        self::centre((int)$row['centre_id'],$active);return $row;
    }
    private static function courseAvailable(int $centre,int $course): void
    {
        if(!DB::val('SELECT course_id FROM centre_courses WHERE centre_id=? AND course_id=? AND is_active=1 FOR UPDATE',[$centre,$course]))throw new \DomainException('Course is not available at this centre.');
    }
    private static function batchCourse(int $batch,int $course): void
    {
        if(!DB::val('SELECT course_id FROM batch_courses WHERE batch_id=? AND course_id=? AND is_active=1 FOR UPDATE',[$batch,$course]))throw new \DomainException('Assign the course to this batch first.');
    }
    /** Fixed internal table/column names only; repeat assignments do not overwrite assignment attribution. */
    private static function mapping(string $table,array $keys,bool $active): void
    {
        $fields=array_keys($keys);$values=array_values($keys);$values[]=(int)Auth::user()['id'];$values[]=$active?1:0;
        DB::run("INSERT INTO $table (".implode(',',$fields).",assigned_by,is_active) VALUES (".DB::in($values).") ON DUPLICATE KEY UPDATE assigned_by=IF(is_active<>VALUES(is_active),VALUES(assigned_by),assigned_by),assigned_at=IF(is_active<>VALUES(is_active),CURRENT_TIMESTAMP,assigned_at),is_active=VALUES(is_active)",$values);
        Logger::audit((int)Auth::user()['id'],'course_mapping_updated',$table,null,json_encode($keys+['is_active'=>$active],JSON_THROW_ON_ERROR));
    }
    public static function saveCourse(?int $id,string $code,string $name,?string $description=null,bool $active=true): int
    {
        self::guard(true);$code=strtoupper(V::str($code,'Code',1,40));$name=V::str($name,'Name',1,160);$description=V::optStr($description,'Description',5000);
        if(!preg_match('/^[A-Z0-9_-]+$/',$code))throw new \DomainException('Use letters, numbers, hyphens or underscores for course code.');
        return DB::transaction(function()use($id,$code,$name,$description,$active){
            if($id){self::course($id,false);DB::run('UPDATE courses SET code=?,name=?,description=?,is_active=? WHERE id=?',[$code,$name,$description,(int)$active,$id]);}
            else{$id=DB::insert('INSERT INTO courses(code,name,description,is_active,created_by) VALUES (?,?,?,?,?)',[$code,$name,$description,(int)$active,Auth::user()['id']]);}
            Logger::audit((int)Auth::user()['id'],'global_course_saved','courses',$id);return $id;
        });
    }
    public static function setCourseSubject(int $course,int $subject,bool $active=true): void
    {
        self::guard(true);DB::transaction(function()use($course,$subject,$active){
            self::course($course,$active);$s=DB::one('SELECT is_active FROM subjects WHERE id=? FOR UPDATE',[$subject]);
            if(!$s||($active&&!(int)$s['is_active']))throw new \DomainException('Choose an available subject.');
            self::mapping('course_subjects',['course_id'=>$course,'subject_id'=>$subject],$active);
        });
    }
    public static function setCentreCourse(int $centre,int $course,bool $active=true): void
    {
        self::guard(true);DB::transaction(function()use($centre,$course,$active){
            self::course($course,$active);self::centre($centre,$active);self::mapping('centre_courses',['centre_id'=>$centre,'course_id'=>$course],$active);
        });
    }
    public static function setBatchCourse(int $batch,int $course,bool $active=true): void
    {
        self::guard();DB::transaction(function()use($batch,$course,$active){
            self::course($course,$active);$b=self::batch($batch,$active);
            if($active)self::courseAvailable((int)$b['centre_id'],$course);
            self::mapping('batch_courses',['batch_id'=>$batch,'course_id'=>$course],$active);
        });
    }
    /** Complete desired set for one batch/course. Removal needs explicit confirmation. */
    public static function setBatchSubjects(int $batch,int $course,array $subjects,bool $confirmRemoval=false): void
    {
        self::guard();if(count($subjects)>200)throw new \DomainException('Choose up to 200 subjects.');
        $ids=[];foreach($subjects as $id)$ids[]=V::int($id,'Subject',1);$ids=array_values(array_unique($ids));sort($ids);
        DB::transaction(function()use($batch,$course,$ids,$confirmRemoval){
            self::course($course,(bool)$ids);$b=self::batch($batch,(bool)$ids);
            if($ids){self::courseAvailable((int)$b['centre_id'],$course);self::batchCourse($batch,$course);}
            foreach($ids as $id){
                if(!DB::val('SELECT cs.subject_id FROM course_subjects cs JOIN subjects s ON s.id=cs.subject_id AND s.is_active=1 JOIN centre_subjects available ON available.subject_id=s.id AND available.centre_id=? WHERE cs.course_id=? AND cs.subject_id=? AND cs.is_active=1 FOR UPDATE',[$b['centre_id'],$course,$id]))throw new \DomainException('Choose available subjects from this course and centre only.');
            }
            $current=array_map('intval',array_column(DB::all('SELECT subject_id FROM batch_subjects WHERE batch_id=? AND course_id=? AND is_active=1 FOR UPDATE',[$batch,$course]),'subject_id'));
            $removed=array_diff($current,$ids);if($removed&&!$confirmRemoval)throw new \DomainException('Confirm subject removal first.');
            foreach($removed as $id)self::mapping('batch_subjects',['batch_id'=>$batch,'course_id'=>$course,'subject_id'=>$id],false);
            foreach($ids as $id)self::mapping('batch_subjects',['batch_id'=>$batch,'course_id'=>$course,'subject_id'=>$id],true);
        });
    }
    public static function setTeacherAssignment(int $teacher,int $batch,int $course,int $subject,bool $active=true): void
    {
        self::guard();DB::transaction(function()use($teacher,$batch,$course,$subject,$active){
            self::course($course,$active);$b=self::batch($batch,$active);
            $u=DB::one("SELECT u.* FROM users u JOIN roles r ON r.id=u.role_id AND r.code='teacher' WHERE u.id=? AND u.deleted_at IS NULL FOR UPDATE",[$teacher]);
            if(!$u||(int)$u['centre_id']!==(int)$b['centre_id']||($active&&$u['status']!=='active'))throw new \DomainException('Choose a Teacher from this centre.');
            if($active){
                self::courseAvailable((int)$b['centre_id'],$course);self::batchCourse($batch,$course);
                if(!DB::val('SELECT bs.subject_id FROM batch_subjects bs JOIN course_subjects cs ON cs.course_id=bs.course_id AND cs.subject_id=bs.subject_id AND cs.is_active=1 JOIN subjects s ON s.id=bs.subject_id AND s.is_active=1 JOIN centre_subjects available ON available.centre_id=? AND available.subject_id=bs.subject_id JOIN teacher_subjects ts ON ts.subject_id=bs.subject_id AND ts.teacher_id=? WHERE bs.batch_id=? AND bs.course_id=? AND bs.subject_id=? AND bs.is_active=1 FOR UPDATE',[$b['centre_id'],$teacher,$batch,$course,$subject]))throw new \DomainException('Choose a batch subject explicitly assigned to this Teacher.');
            }
            self::mapping('teacher_batch_subjects',['teacher_id'=>$teacher,'batch_id'=>$batch,'course_id'=>$course,'subject_id'=>$subject],$active);
        });
    }
}
