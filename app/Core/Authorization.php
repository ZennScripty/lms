<?php
declare(strict_types=1);

namespace App\Core;

final class Authorization
{
    /** @var array<int,array<int,string>> */
    private static array $permCache = [];

    public static function isSuperAdmin(): bool
    {
        $u = Auth::user();
        return $u !== null && $u['role_slug'] === 'super_admin';
    }

    public static function isTeacher(): bool
    {
        $u = Auth::user();
        return $u !== null && $u['role_slug'] === 'subject_teacher';
    }
    public static function isCentreAdmin(): bool { return (Auth::user()['role_slug']??'')==='centre_admin'; }
    public static function isAdmin(): bool { return self::isSuperAdmin()||self::isCentreAdmin(); }
    /** null means all centres; empty list always means no access. */
    public static function centreIds(): ?array
    {
        if(self::isSuperAdmin())return null;
        $u=Auth::user();if(!$u)return [];
        if(self::isCentreAdmin())return array_map('intval',array_column(Database::all('SELECT ac.centre_id FROM admin_centres ac JOIN centres c ON c.id=ac.centre_id AND c.is_active=1 WHERE ac.admin_id=?',[$u['id']]),'centre_id'));
        return !empty($u['centre_id'])?[(int)$u['centre_id']]:[];
    }
    public static function centreWhere(string $column,array &$params): string
    {
        $ids=self::centreIds();if($ids===null)return '1=1';if(!$ids)return '1=0';
        $params=array_merge($params,$ids);return $column.' IN ('.Database::in($ids).')';
    }
    public static function canAccessCentre(int $id): bool
    {
        $ids=self::centreIds();return $ids===null||in_array($id,$ids,true);
    }
    public static function canManageUser(int $id): bool
    {
        $row=Database::one('SELECT u.centre_id,r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.deleted_at IS NULL',[$id]);
        return $row&&(self::isSuperAdmin()||self::isCentreAdmin()&&in_array($row['code'],['student','teacher'],true)&&self::canAccessCentre((int)$row['centre_id']));
    }
    /** Shared curriculum is readable by permitted centres, but writes cannot affect unassigned centres. */
    public static function canWriteSubject(int $id): bool
    {
        if(self::isSuperAdmin())return true;
        if(!self::isCentreAdmin())return self::canAccessSubject($id);
        $ids=self::centreIds();if(!$ids||!self::canAccessSubject($id))return false;
        return !(bool)Database::val('SELECT id FROM centre_subjects WHERE subject_id=? AND centre_id NOT IN ('.Database::in($ids).') LIMIT 1',array_merge([$id],$ids));
    }
    public static function mediaWhere(string $alias,array &$params): string
    {
        if(self::isSuperAdmin())return '1=1';
        if(!self::isCentreAdmin()){$params[]=Auth::user()['id'];return "$alias.uploaded_by=?";}
        if(!self::centreIds())return '1=0';
        $own=self::centreWhere("$alias.centre_id",$params);$ids=self::visibleSubjectIds();
        $linked=$ids?'mc.subject_id IN ('.Database::in($ids).')':'1=0';$params=array_merge($params,$ids??[]);
        return "($own OR EXISTS (SELECT 1 FROM content_item_translations mt JOIN content_items mc ON mc.id=mt.content_item_id WHERE mt.media_asset_id=$alias.id AND $linked))";
    }

    public static function isStudent(): bool
    {
        $u = Auth::user();
        return $u !== null && $u['role_slug'] === 'student';
    }

    public static function can(string $permission): bool
    {
        $u = Auth::user();
        if (!$u) {
            return false;
        }
        if ($u['role_slug'] === 'super_admin') {
            return true;
        }
        if($u['role_slug']==='centre_admin'&&!in_array($permission,['students.view','students.manage','subjects.view','subjects.manage','modules.view','modules.manage','tests.manage','attendance.mark','attendance.view','reports.view','report.export'],true)){return false;}
        $rid = (int) $u['role_id'];
        if (!isset(self::$permCache[$rid])) {
            $rows = Database::all(
                'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
                [$rid]
            );
            self::$permCache[$rid] = array_column($rows, 'code');
        }
        return in_array($permission, self::$permCache[$rid], true);
    }

    public static function require(string $permission): void
    {
        if (!self::can($permission)) {
            forbidden();
        }
    }

    /** @return array<int,int> */
    public static function teacherSubjectIds(int $teacherId): array
    {
        return \App\Services\AssignmentAccessService::teacherSubjectIds($teacherId);
    }

    /** null = unrestricted (super admin); array = allowed subject ids (teacher). Students: their enrolled subjects. */
    public static function visibleSubjectIds(): ?array
    {
        $u = Auth::user();
        if (!$u) {
            return [];
        }
        if ($u['role_slug'] === 'super_admin') {
            return null;
        }
        if($u['role_slug']==='centre_admin'){
            $p=[];$w=self::centreWhere('cs.centre_id',$p);
            return array_map('intval',array_column(Database::all("SELECT DISTINCT cs.subject_id FROM centre_subjects cs WHERE $w",$p),'subject_id'));
        }
        if ($u['role_slug'] === 'subject_teacher') {
            return self::teacherSubjectIds((int) $u['id']);
        }
        if(\App\Services\AssignmentAccessService::forStudent((int)$u['id']))return \App\Services\AssignmentAccessService::studentSubjectIds((int)$u['id']);
        return array_map('intval', array_column(
            Database::all('SELECT subject_id FROM student_subjects WHERE student_id = ?', [(int) $u['id']]),
            'subject_id'
        ));
    }

    public static function canAccessSubject(int $subjectId): bool
    {
        $ids = self::visibleSubjectIds();
        return $ids === null || in_array($subjectId, $ids, true);
    }

    /** Server-side ownership/assignment check. Never trust IDs from the browser. */
    public static function canAccessStudent(int $studentId): bool
    {
        $u = Auth::user();
        if (!$u) {
            return false;
        }
        switch ($u['role_slug']) {
            case 'centre_admin':
                return (bool)Database::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND r.code='student' AND u.deleted_at IS NULL",[$studentId])&&self::canManageUser($studentId);
            case 'super_admin':
                return Database::val(
                    "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.code = 'student'",
                    [$studentId]
                ) > 0;
            case 'subject_teacher':
                return (bool)\App\Services\AssignmentAccessService::studentSubjectIds($studentId);
            case 'student':
                return (int) $u['id'] === $studentId;
        }
        return false;
    }
}
