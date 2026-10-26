<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class LearningRepository
{
    /** @param array<int,int>|null $ids null = all */
    public static function subjects(?array $ids = null, bool $publishedOnly = false): array
    {
        $sql = 'SELECT * FROM subjects WHERE 1=1';
        $p = [];
        if ($ids !== null) {
            if (!$ids) {
                return [];
            }
            $sql .= ' AND id IN (' . Database::in($ids) . ')';
            $p = $ids;
        }
        if ($publishedOnly) {
            $sql .= " AND status = 'published'";
        }
        return Database::all($sql . ' ORDER BY name', $p);
    }

    public static function subject(int $id): ?array
    {
        return Database::one('SELECT * FROM subjects WHERE id = ?', [$id]);
    }

    public static function saveSubject(?int $id, string $name, ?string $desc, string $status): int
    {
        if ($id) {
            Database::run('UPDATE subjects SET name = ?, description = ?, status = ? WHERE id = ?', [$name, $desc, $status, $id]);
            return $id;
        }
        return Database::insert('INSERT INTO subjects (name, description, status, created_at) VALUES (?,?,?,NOW())', [$name, $desc, $status]);
    }

    public static function deleteSubject(int $id): void
    {
        Database::run('DELETE FROM subjects WHERE id = ?', [$id]);
    }

    public static function teacherIds(int $subjectId): array
    {
        return array_map('intval', array_column(Database::all('SELECT teacher_id FROM teacher_subjects WHERE subject_id = ?', [$subjectId]), 'teacher_id'));
    }

    public static function setTeachers(int $subjectId, array $teacherIds): void
    {
        Database::transaction(function () use ($subjectId, $teacherIds) {
            Database::run('DELETE FROM teacher_subjects WHERE subject_id = ?', [$subjectId]);
            foreach ($teacherIds as $tid) {
                Database::run('INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id) VALUES (?,?)', [$tid, $subjectId]);
            }
        });
    }

    public static function modules(int $subjectId, bool $publishedOnly = false): array
    {
        return Database::all(
            'SELECT * FROM modules WHERE subject_id = ?' . ($publishedOnly ? " AND status = 'published'" : '') . ' ORDER BY sort_order, id',
            [$subjectId]
        );
    }

    public static function module(int $id): ?array
    {
        return Database::one('SELECT * FROM modules WHERE id = ?', [$id]);
    }

    public static function saveModule(?int $id, int $subjectId, string $title, ?string $desc, int $sort, string $status): int
    {
        if ($id) {
            Database::run('UPDATE modules SET title = ?, description = ?, sort_order = ?, status = ? WHERE id = ? AND subject_id = ?', [$title, $desc, $sort, $status, $id, $subjectId]);
            return $id;
        }
        return Database::insert('INSERT INTO modules (subject_id, title, description, sort_order, status, created_at) VALUES (?,?,?,?,?,NOW())', [$subjectId, $title, $desc, $sort, $status]);
    }

    public static function deleteModule(int $id): void
    {
        Database::run('DELETE FROM modules WHERE id = ?', [$id]);
    }

    public static function lessons(int $moduleId, bool $publishedOnly = false): array
    {
        return Database::all(
            'SELECT * FROM content_items WHERE module_id = ?' . ($publishedOnly ? " AND status = 'published'" : '') . ' ORDER BY sort_order, id',
            [$moduleId]
        );
    }

    /** @return array<int,array<int,array<string,mixed>>> lessons grouped by module id (published only) */
    public static function lessonsForModules(array $moduleIds): array
    {
        if (!$moduleIds) {
            return [];
        }
        $out = [];
        foreach (Database::all("SELECT * FROM content_items WHERE status = 'published' AND module_id IN (" . Database::in($moduleIds) . ') ORDER BY sort_order, id', $moduleIds) as $r) {
            $out[(int) $r['module_id']][] = $r;
        }
        return $out;
    }

    public static function lesson(int $id): ?array
    {
        return Database::one('SELECT c.*, m.subject_id FROM content_items c JOIN modules m ON m.id = c.module_id WHERE c.id = ?', [$id]);
    }

    public static function saveLesson(?int $id, int $moduleId, string $title, ?string $body, int $sort, string $status): int
    {
        if ($id) {
            Database::run('UPDATE content_items SET title = ?, body = ?, sort_order = ?, status = ? WHERE id = ? AND module_id = ?', [$title, $body, $sort, $status, $id, $moduleId]);
            return $id;
        }
        return Database::insert("INSERT INTO content_items (module_id, title, body, content_type, sort_order, status, created_at) VALUES (?,?,?,'lesson',?,?,NOW())", [$moduleId, $title, $body, $sort, $status]);
    }

    public static function deleteLesson(int $id): void
    {
        Database::run('DELETE FROM content_items WHERE id = ?', [$id]);
    }

    /** @return array<string,array<string,mixed>> keyed by language code */
    public static function moduleTranslations(int $moduleId): array
    {
        $out = [];
        foreach (Database::all('SELECT * FROM module_translations WHERE module_id = ?', [$moduleId]) as $r) {
            $out[$r['language_code']] = $r;
        }
        return $out;
    }

    public static function saveModuleTranslation(int $moduleId, string $lang, string $title, ?string $desc): void
    {
        if ($title === '') {
            Database::run('DELETE FROM module_translations WHERE module_id = ? AND language_code = ?', [$moduleId, $lang]);
            return;
        }
        Database::run(
            'INSERT INTO module_translations (module_id, language_code, title, description) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)',
            [$moduleId, $lang, $title, $desc]
        );
    }

    public static function lessonTranslations(int $lessonId): array
    {
        $out = [];
        foreach (Database::all('SELECT * FROM content_item_translations WHERE content_item_id = ?', [$lessonId]) as $r) {
            $out[$r['language_code']] = $r;
        }
        return $out;
    }

    public static function saveLessonTranslation(int $lessonId, string $lang, string $title, ?string $body): void
    {
        if ($title === '') {
            Database::run('DELETE FROM content_item_translations WHERE content_item_id = ? AND language_code = ?', [$lessonId, $lang]);
            return;
        }
        Database::run(
            'INSERT INTO content_item_translations (content_item_id, language_code, title, body) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body)',
            [$lessonId, $lang, $title, $body]
        );
    }
}
