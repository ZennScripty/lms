<?php
declare(strict_types=1);

/**
 * Permission catalogue. The "Register default permissions" button on
 * roles_permissions.php inserts any missing codes (INSERT IGNORE) at runtime.
 */
return [
    'permissions' => [
        'students.view'     => 'View students in own scope',
        'students.manage'   => 'Create / edit students and assign subjects',
        'subjects.view'     => 'View subjects',
        'subjects.manage'   => 'Create / edit subjects and assign teachers',
        'modules.view'      => 'View modules and lessons',
        'modules.manage'    => 'Create / edit modules and lessons',
        'tests.manage'      => 'Create / edit tests and questions',
        'attendance.mark'   => 'Create attendance sessions and mark attendance',
        'attendance.view'   => 'View attendance of students in scope',
        'reports.view'      => 'View and export reports',
        'roles.manage'      => 'Manage roles and permissions',
        'languages.manage'  => 'Manage languages and UI translations',
        'settings.manage'   => 'Manage application settings',
        'learn.access'      => 'Access own learning, tests and attendance',
    ],
    'defaults' => [
        'subject_teacher' => [
            'students.view', 'subjects.view', 'modules.view', 'modules.manage',
            'tests.manage', 'attendance.mark', 'attendance.view', 'reports.view',
        ],
        'student' => ['learn.access'],
    ],
];
