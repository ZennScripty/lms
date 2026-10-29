<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Auth,Authorization as A,Database as DB};

/** New assignment model only. Existing routes keep their current access logic until Phase F. */
final class AssignmentAccessService
{
    /** Activation is an explicit deployment decision after legacy mapping review. Defaults OFF. */
    public static function enabled(): bool
    {
        return DB::val("SELECT setting_value FROM app_settings WHERE setting_key='batch_learning_strict_access'")==='1';
    }
    public static function managedRolloutEnabled(): bool
    {
        return DB::val("SELECT setting_value FROM app_settings WHERE setting_key='batch_learning_managed_rollout'")==='1';
    }
    public static function forStudent(int $student): bool
    {
        if(self::enabled())return true;
        // A batch becomes managed after its first subject configuration; inactive rows keep that boundary.
        return self::managedRolloutEnabled()&&(bool)DB::val('SELECT u.id FROM users u WHERE u.id=? AND EXISTS(SELECT 1 FROM batch_subjects managed WHERE managed.batch_id=u.batch_id)',[$student]);
    }
    private static function mayReadUser(int $id): bool
    {
        return (int)(Auth::user()['id']??0)===$id||A::isAdmin()&&A::canManageUser($id)||A::isTeacher()&&(bool)DB::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id AND r.code='student' JOIN teacher_batch_subjects t ON t.batch_id=u.batch_id AND t.teacher_id=? AND t.is_active=1 WHERE u.id=? AND u.centre_id=? AND u.deleted_at IS NULL",[Auth::user()['id'],$id,Auth::user()['centre_id']]);
    }
    /** All parents must remain active; stale enrolments and disconnected mappings grant no access. */
    private static function batchJoins(): string
    {
        return " JOIN batches b ON b.id=bs.batch_id AND b.is_active=1
            JOIN centres c ON c.id=b.centre_id AND c.is_active=1
            JOIN batch_courses bc ON bc.batch_id=bs.batch_id AND bc.course_id=bs.course_id AND bc.is_active=1
            JOIN courses co ON co.id=bs.course_id AND co.is_active=1
            JOIN centre_courses cc ON cc.centre_id=c.id AND cc.course_id=co.id AND cc.is_active=1
            JOIN course_subjects cs ON cs.course_id=co.id AND cs.subject_id=bs.subject_id AND cs.is_active=1
            JOIN centre_subjects available ON available.centre_id=c.id AND available.subject_id=bs.subject_id
            JOIN subjects s ON s.id=bs.subject_id AND s.is_active=1";
    }
    public static function studentSubjectIds(int $student): array
    {
        if(!self::mayReadUser($student))return [];
        $p=[$student];$teacher='';if(A::isTeacher()){$teacher=' AND EXISTS(SELECT 1 FROM teacher_batch_subjects ta JOIN teacher_subjects grant_row ON grant_row.teacher_id=ta.teacher_id AND grant_row.subject_id=ta.subject_id WHERE ta.teacher_id=? AND ta.batch_id=bs.batch_id AND ta.course_id=bs.course_id AND ta.subject_id=bs.subject_id AND ta.is_active=1)';$p[]=Auth::user()['id'];}
        return array_map('intval',array_column(DB::all("SELECT DISTINCT bs.subject_id FROM batch_subjects bs".self::batchJoins()." JOIN users u ON u.batch_id=b.id AND u.centre_id=c.id JOIN roles r ON r.id=u.role_id AND r.code='student' WHERE u.id=? AND u.status='active' AND u.deleted_at IS NULL AND bs.is_active=1$teacher ORDER BY bs.subject_id",$p),'subject_id'));
    }
    public static function subjectFilter(string $column,int $student,array &$params): string
    {
        if(!self::forStudent($student))return '1=1';$ids=self::studentSubjectIds($student);if(!$ids)return '1=0';$params=array_merge($params,$ids);return $column.' IN ('.DB::in($ids).')';
    }
    /** SQL source for staff metrics; callers must still apply their centre/user scope. */
    public static function studentPairsSql(bool $previewStrict=false): string
    {
        if(!self::enabled()&&!$previewStrict&&!self::managedRolloutEnabled())return 'student_subjects';
        $strict="(SELECT DISTINCT member.id AS student_id,bs.subject_id,member.batch_id,CASE WHEN legacy.status='completed' THEN 'completed' ELSE 'active' END AS status,COALESCE(legacy.enrolled_at,member.created_at) AS enrolled_at,COALESCE(legacy.current_week_no,1) AS current_week_no FROM batch_subjects bs".self::batchJoins()." JOIN users member ON member.batch_id=b.id AND member.centre_id=c.id AND member.status='active' AND member.deleted_at IS NULL JOIN roles mr ON mr.id=member.role_id AND mr.code='student' LEFT JOIN student_subjects legacy ON legacy.student_id=member.id AND legacy.subject_id=bs.subject_id WHERE bs.is_active=1)";
        if(self::enabled()||$previewStrict)return $strict;
        return "(SELECT e.* FROM $strict e UNION ALL SELECT old.student_id,old.subject_id,old.batch_id,old.status,old.enrolled_at,old.current_week_no FROM student_subjects old JOIN users old_member ON old_member.id=old.student_id WHERE NOT EXISTS(SELECT 1 FROM batch_subjects managed WHERE managed.batch_id=old_member.batch_id))";
    }
    public static function teacherStudentFilter(string $userAlias,?string $subjectColumn,array &$params): string
    {
        if(!A::isTeacher())return '1=1';$parts=[];
        foreach(self::teacherAssignments((int)Auth::user()['id']) as $t){$part="$userAlias.batch_id=? AND $userAlias.centre_id=?";$params[]=$t['batch_id'];$params[]=Auth::user()['centre_id'];if($subjectColumn){$part.=" AND $subjectColumn=?";$params[]=$t['subject_id'];}$parts[]='('.$part.')';}
        return $parts?'('.implode(' OR ',$parts).')':'1=0';
    }
    public static function teacherAssignments(int $teacher): array
    {
        if(!self::mayReadUser($teacher))return [];
        return DB::all("SELECT DISTINCT t.batch_id,t.course_id,t.subject_id,ts.can_manage_content,ts.can_manage_tests FROM teacher_batch_subjects t
            JOIN batch_subjects bs ON bs.batch_id=t.batch_id AND bs.course_id=t.course_id AND bs.subject_id=t.subject_id".self::batchJoins()."
            JOIN users u ON u.id=t.teacher_id AND u.centre_id=c.id
            JOIN roles r ON r.id=u.role_id AND r.code='teacher'
            JOIN teacher_subjects ts ON ts.teacher_id=u.id AND ts.subject_id=t.subject_id
            WHERE u.id=? AND u.status='active' AND u.deleted_at IS NULL AND t.is_active=1 AND bs.is_active=1
            ORDER BY t.batch_id,t.course_id,t.subject_id",[$teacher]);
    }
    public static function teacherSubjectIds(int $teacher): array
    {
        return array_values(array_unique(array_map('intval',array_column(self::teacherAssignments($teacher),'subject_id'))));
    }
    public static function canStudy(int $student,int $subject): bool
    {
        return in_array($subject,self::studentSubjectIds($student),true);
    }
    public static function canTeach(int $teacher,int $batch,int $course,int $subject): bool
    {
        foreach(self::teacherAssignments($teacher) as $row){if((int)$row['batch_id']===$batch&&(int)$row['course_id']===$course&&(int)$row['subject_id']===$subject)return true;}
        return false;
    }
}
