<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository
{
    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT u.id, u.name, u.email, u.username, u.status, u.batch_id, u.role_id, r.slug AS role_slug, b.name AS batch_name
             FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN batches b ON b.id = u.batch_id WHERE u.id = ?',
            [$id]
        );
    }

    public static function roleIdBySlug(string $slug): ?int
    {
        $v = Database::val('SELECT id FROM roles WHERE slug = ?', [$slug]);
        return $v === null ? null : (int) $v;
    }

    /** @param array<int,int>|null $subjectIds null = no restriction */
    public static function students(?string $search, ?array $subjectIds, int $limit = 300): array
    {
        $sql = "SELECT u.id, u.name, u.email, u.username, u.status, b.name AS batch_name
                FROM users u JOIN roles r ON r.id = u.role_id AND r.slug = 'student'
                LEFT JOIN batches b ON b.id = u.batch_id WHERE 1=1";
        $p = [];
        if ($subjectIds !== null) {
            if (!$subjectIds) {
                return [];
            }
            $sql .= ' AND EXISTS (SELECT 1 FROM student_subjects ss WHERE ss.student_id = u.id AND ss.subject_id IN (' . Database::in($subjectIds) . '))';
            $p = array_merge($p, $subjectIds);
        }
        if ($search !== null && $search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        $sql .= ' ORDER BY u.name LIMIT ' . (int) $limit;
        return Database::all($sql, $p);
    }

    public static function createStudent(array $d): int
    {
        $role = self::roleIdBySlug('student');
        if ($role === null) {
            throw new \DomainException('The "student" role does not exist. Create it first.');
        }
        return Database::insert(
            "INSERT INTO users (role_id, batch_id, name, email, username, password_hash, status, created_at) VALUES (?,?,?,?,?,?, 'active', NOW())",
            [$role, $d['batch_id'], $d['name'], $d['email'], $d['username'], password_hash($d['password'], PASSWORD_DEFAULT)]
        );
    }

    public static function updateStudent(int $id, array $d): void
    {
        Database::run('UPDATE users SET name = ?, email = ?, username = ?, batch_id = ? WHERE id = ?',
            [$d['name'], $d['email'], $d['username'], $d['batch_id'], $id]);
        if (!empty($d['password'])) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($d['password'], PASSWORD_DEFAULT), $id]);
        }
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::run('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
    }

    /** @return array<int,int> */
    public static function subjectIdsOf(int $studentId): array
    {
        return array_map('intval', array_column(Database::all('SELECT subject_id FROM student_subjects WHERE student_id = ?', [$studentId]), 'subject_id'));
    }

    /** Replaces a student's subjects, limited to $scope when not null (so scoped users cannot touch other subjects). */
    public static function setSubjects(int $studentId, array $subjectIds, ?array $scope): void
    {
        Database::transaction(function () use ($studentId, $subjectIds, $scope) {
            if ($scope !== null) {
                $subjectIds = array_values(array_intersect($subjectIds, $scope));
                if ($scope) {
                    Database::run('DELETE FROM student_subjects WHERE student_id = ? AND subject_id IN (' . Database::in($scope) . ')', array_merge([$studentId], $scope));
                }
            } else {
                Database::run('DELETE FROM student_subjects WHERE student_id = ?', [$studentId]);
            }
            foreach ($subjectIds as $sid) {
                Database::run('INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (?,?)', [$studentId, $sid]);
            }
        });
    }

    public static function teachers(): array
    {
        return Database::all("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'subject_teacher' AND u.status = 'active' ORDER BY u.name");
    }

    public static function batches(): array
    {
        return Database::all('SELECT id, name FROM batches ORDER BY name');
    }
}
