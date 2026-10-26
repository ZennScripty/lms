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
        return array_map('intval', array_column(
            Database::all('SELECT subject_id FROM teacher_subjects WHERE teacher_id = ?', [$teacherId]),
            'subject_id'
        ));
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
        if ($u['role_slug'] === 'subject_teacher') {
            return self::teacherSubjectIds((int) $u['id']);
        }
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
            case 'super_admin':
                return Database::val(
                    "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.code = 'student'",
                    [$studentId]
                ) > 0;
            case 'subject_teacher':
                return Database::val(
                    'SELECT COUNT(*) FROM student_subjects ss JOIN teacher_subjects ts ON ts.subject_id = ss.subject_id
                     WHERE ss.student_id = ? AND ts.teacher_id = ?',
                    [$studentId, (int) $u['id']]
                ) > 0;
            case 'student':
                return (int) $u['id'] === $studentId;
        }
        return false;
    }
}
