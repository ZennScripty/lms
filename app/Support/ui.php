<?php
declare(strict_types=1);

/** Interface text only. User-authored names and lesson text are never machine-translated. */
function tr(string $text): string
{
    static $dictionary;
    $dictionary ??= require APP_ROOT . '/app/Config/ui_hi.php';
    if (\App\Services\TranslationService::currentCode() !== 'hi') { $labels=['in_progress'=>'In progress','not_started'=>'Not started','active'=>'Active','inactive'=>'Inactive','blocked'=>'Blocked','draft'=>'Draft','published'=>'Published','archived'=>'Archived','pass'=>'Passed','fail'=>'Not passed','pending'=>'Pending','student'=>'Student','teacher'=>'Teacher','admin'=>'Admin','module'=>'Module','weekly'=>'Weekly','practice'=>'Practice','text'=>'Text','video'=>'Video','audio'=>'Audio','image'=>'Image','restudy'=>'Study again','present'=>'Present','absent'=>'Absent','late'=>'Late','leave'=>'Leave','half_day'=>'Half day','holiday'=>'Holiday'];return $labels[$text]??$text; }
    if (isset($dictionary[$text])) { return $dictionary[$text]; }
    if (preg_match('/^(.+) must be between (\d+) and (\d+) characters\.$/',$text,$m)) { return ($dictionary[$m[1]] ?? $m[1]) . ' ' . $m[2] . ' से ' . $m[3] . ' अक्षरों के बीच होना चाहिए।'; }
    if (preg_match('/^(.+) (must be a whole number|is out of range|has an invalid value|is not a valid email address)\.$/',$text,$m)) { return ($dictionary[$m[1]] ?? $m[1]) . ': मान्य जानकारी दर्ज करें।'; }
    if ($text === 'Password must be at least 8 characters.') { return 'पासवर्ड कम से कम 8 अक्षरों का होना चाहिए।'; }
    return $text;
}

function icon(string $name, string $class = ''): string
{
    $paths = [
        'home'=>'M3 10 12 3l9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z',
        'book'=>'M12 5v16M3 3l9 2 9-2v16l-9 2-9-2Z',
        'users'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M13 3a4 4 0 0 1 0 8M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
        'building'=>'M4 21V3h12v18M2 21h20M16 9h4v12M8 7h4M8 11h4M8 15h4M9 21v-3h2v3',
        'test'=>'M9 4h6v4H9zM8 6H5v15h14V6h-3M8 12l1 1 2-2m2 1h3M8 17l1 1 2-2m2 1h3',
        'chart'=>'M4 3v18h17M8 16v-5m5 5V7m5 9V4',
        'settings'=>'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm0-6 2 3 3-1 1 3 3 2-2 3 2 3-3 2-1 3-3-1-2 3-2-3-3 1-1-3-3-2 2-3-2-3 3-2 1-3 3 1Z',
        'bell'=>'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4',
        'download'=>'M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4',
        'search'=>'M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
        'arrow'=>'M5 12h14m-6-6 6 6-6 6',
        'check'=>'m5 12 4 4L19 6',
        'clock'=>'M12 7v5l3 2M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z',
        'logout'=>'M9 5H3v14h6m5-14 7 7-7 7m-7-7h14',
        'menu'=>'M3 6h18M3 12h18M3 18h18',
        'plus'=>'M12 5v14M5 12h14',
        'globe'=>'M2 12h20M12 2c6 6 6 14 0 20-6-6-6-14 0-20Zm10 10a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z',
        'lock'=>'M6 10h12v11H6zM8 10V6a4 4 0 0 1 8 0v4',
        'play'=>'m9 5 11 7-11 7Z',
        'user'=>'M20 21v-2a7 7 0 0 0-14 0v2M17 7A4 4 0 1 1 9 7a4 4 0 0 1 8 0Z',
        'bolt'=>'m13 2-9 12h7l-1 8 10-13h-7Z',
    ];
    return '<svg class="icon ' . e($class) . '" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . ($paths[$name] ?? $paths['book']) . '"/></svg>';
}

function app_navigation(): array
{
    if (\App\Core\Authorization::isSuperAdmin()) {
        return [
            ['dashboard.php','Dashboard','home'], ['centres.php','Centres','building'],
            ['batches.php','Batches','users'], ['users.php','Users','users'],['registrations.php','Registration approvals','check'],
            ['courses.php','Global courses','book'], ['subjects.php','Subjects','book'],['teacher_assignments.php','Teacher subject grants','users'], ['enrolments.php','Student batches','users'],['course_assignments.php','Course assignments','users'],
            ['modules.php','Modules','book'],['lessons.php','Lessons','book'],['media_library.php','Media library','download'],['question_bank.php','Question bank','test'], ['tests.php','Tests','test'],
            ['attendance.php','Attendance','check'], ['progress_analytics.php','Progress analytics','chart'], ['reports.php','Reports','chart'],
            ['roles_permissions.php','Roles & permissions','lock'], ['app_settings.php','Settings','settings'],
            ['sync_status.php','System logs','clock'],
        ];
    }
    if (\App\Core\Authorization::isStudent()) {
        return [['dashboard.php','Dashboard','home'],['courses.php','My Courses','book'],['tests.php','Tests','test'],['offline_downloads.php','Downloads','download'],['progress.php','My Progress','chart'],['attendance.php','My Attendance','check'],['notifications.php','Notifications','bell'],['profile.php','Profile','user']];
    }
    if(\App\Core\Authorization::isCentreAdmin()){
        $nav=[['dashboard.php','Dashboard','home'],['centres.php','Centres','building'],['batches.php','Batches','users'],['users.php','Users','users'],['registrations.php','Registration approvals','check'],['courses.php','Global courses','book'],['course_assignments.php','Course assignments','users'],['subjects.php','Subjects','book'],['teacher_assignments.php','Teacher subject grants','users'],['progress_analytics.php','Progress analytics','chart'],['enrolments.php','Student batches','users'],['modules.php','Modules','book'],['lessons.php','Lessons','book'],['media_library.php','Media library','download'],['question_bank.php','Question bank','test'],['tests.php','Tests','test'],['attendance.php','Attendance','check'],['reports.php','Reports','chart'],['profile.php','Profile','user']];
        return $nav;
    }
    $nav = [['dashboard.php','Dashboard','home'],['course_assignments.php','My teaching assignments','users'],['subjects.php','My Subjects','book'],['modules.php','Modules','book'],['lessons.php','Lessons','book'],['media_library.php','Media library','download'],['question_bank.php','Question bank','test'],['students.php','Students','users'],['tests.php','Tests','test'],['attendance.php','Attendance','check'],['reports.php','Reports','chart'],['profile.php','Profile','user']];
    $permissions = ['subjects.php'=>'subjects.view','modules.php'=>'modules.view','students.php'=>'students.view','tests.php'=>'tests.manage','attendance.php'=>'attendance.view','reports.php'=>'reports.view'];
    return array_values(array_filter($nav, static fn($item) => !isset($permissions[$item[0]]) || \App\Core\Authorization::can($permissions[$item[0]])));
}

function page_header(string $title, array $opts = []): void
{
    $u = \App\Core\Auth::user();
    $public = !empty($opts['public']) || !$u;
    $compact=!$public&&(in_array(current_page(),['dashboard.php','attendance.php'],true)||\App\Core\Authorization::isStudent()||\App\Core\Authorization::isTeacher());
    $lang = \App\Services\TranslationService::currentCode();
    header('Cache-Control: private, no-store');
    echo '<!doctype html><html lang="' . e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#17657A"><meta name="color-scheme" content="light"><title>' . e(tr($title)) . ' · TLMTI Skills LMS</title><link rel="icon" href="assets/icons/icon.svg"><link rel="stylesheet" href="assets/css/app.css?v=20261027-2"><link rel="stylesheet" href="assets/css/catalogue.css?v=20261029-2"></head>';
    echo '<body class="' . ($public ? 'auth-page' : 'app-page') . '" data-user-id="' . e($u ? (string)$u['id'] : '') . '" data-role="' . e($u ? $u['role_slug'] : '') . '" data-csrf="' . e(csrf_token()) . '"><div id="net-banner" class="net-banner" hidden>' . e(tr('You are offline. Connect to sync your progress.')) . '</div>';
    if (!$public) {
        $nav = app_navigation();
        echo '<aside class="sidebar" id="main-navigation"><button class="icon-btn sidebar-close" data-menu-close aria-label="'.e(tr('Close menu')).'">×</button><a class="brand" href="dashboard.php"><span class="brand-mark">' . icon('book') . '</span><span>TLMTI <small>Skills LMS</small></span></a><div class="workspace-label">' . e(tr(\App\Core\Authorization::isAdmin() ? 'ADMIN WORKSPACE' : (\App\Core\Authorization::isStudent() ? 'MY LEARNING' : 'TEACHING WORKSPACE'))) . '</div><nav aria-label="' . e(tr('Main navigation')) . '">';
        foreach ($nav as [$href,$label,$ico]) {
            echo '<a class="nav-item' . (current_page() === $href ? ' active' : '') . '" href="' . e($href) . '"' . (current_page() === $href ? ' aria-current="page"' : '') . '>' . icon($ico) . '<span>' . e(tr($label)) . '</span></a>';
        }
        echo '</nav><div class="sidebar-footer"><div class="avatar">' . e(mb_substr($u['name'],0,1)) . '</div><div><strong>' . e($u['name']) . '</strong><small>' . e(tr($u['role_name'])) . '</small></div></div></aside><div class="app-main"><header class="app-topbar"><button class="icon-btn mobile-menu" data-menu-toggle aria-controls="main-navigation" aria-expanded="false" aria-label="' . e(tr('Open menu')) . '">' . icon('menu') . '</button><span class="breadcrumb">' . e(tr($title)) . '</span><div class="topbar-actions">';
    } else {
        echo '<header class="auth-topbar"><a class="brand" href="login.php"><span class="brand-mark">' . icon('book') . '</span>TLMTI Skills LMS</a><div class="topbar-actions">';
    }
    echo '<form method="post" class="language-form">' . csrf_field() . '<input type="hidden" name="action" value="set_language"><label class="sr-only" for="ui-language">' . e(tr('Language')) . '</label>' . icon('globe') . '<select name="language" id="ui-language" data-autosubmit><option value="en"' . selected('en',$lang) . '>English</option><option value="hi"' . selected('hi',$lang) . '>हिन्दी</option></select><noscript><button class="btn">' . e(tr('Apply')) . '</button></noscript></form>';
    if (!$public) {
        echo '<a class="icon-btn" href="notifications.php" aria-label="' . e(tr('Notifications')) . '">' . icon('bell') . '</a><span class="topbar-divider"></span><a href="profile.php" class="profile-link"><span class="avatar">' . e(mb_substr($u['name'],0,1)) . '</span><span><strong>' . e($u['name']) . '</strong><small>' . e(tr($u['role_name'])) . '</small></span></a><form method="post" class="inline" data-logout>' . csrf_field() . '<input type="hidden" name="action" value="logout"><button class="icon-btn" aria-label="' . e(tr('Logout')) . '" title="' . e(tr('Logout')) . '">' . icon('logout') . '</button></form>';
    }
    echo '</div></header><main class="container'.($compact?' compact-page':'').'">';
    if($compact){echo '<h1 class="sr-only">'.e(tr($title)).'</h1>';}
    else{echo '<div class="page-title"><div><p class="eyebrow">TLMTI SKILLS LMS</p><h1>'.e(tr($title)).'</h1></div>';if(!$public){echo '<span class="live-tag"><span></span>'.e(tr('Learning for a better tomorrow')).'</span>';}echo '</div>';}
    echo flash_render();
    if(!$public&&!in_array(current_page(),['dashboard.php','attendance.php'],true)&&\App\Services\AttendanceService::mode($u)==='selfie'&&!\App\Services\AttendanceService::today((int)$u['id'])){
        echo '<div class="flash" role="status">'.e(tr('Your selfie attendance is pending today.')).' <a href="dashboard.php#daily-attendance-title">'.e(tr('Today attendance')).'</a></div>';
    }
}

function page_footer(): void
{
    if(\App\Core\Auth::user()&&!in_array(current_page(),['dashboard.php','attendance.php'],true)){echo '<p class="attendance-shortcut"><a class="btn" href="dashboard.php#daily-attendance-title">'.icon('check').e(tr('Today attendance')).'</a></p>';}
    echo '<script src="assets/js/media-duration.js?v=20261029-1" defer></script><script src="assets/js/management-forms.js?v=20261028-1" defer></script><script src="assets/js/catalogue.js?v=20261028-1" defer></script>';
    echo '<footer class="page-footer"><span>TLMTI Skills LMS</span><span>' . e(tr('Learn. Practise. Grow.')) . '</span></footer></main>';
    if (\App\Core\Auth::user()) {
        echo '</div><nav class="bottom-nav" aria-label="' . e(tr('Mobile navigation')) . '">';
        foreach (array_slice(app_navigation(),0,5) as [$href,$label,$ico]) {
            echo '<a href="' . e($href) . '" class="' . (current_page() === $href ? 'active' : '') . '">' . icon($ico) . '<span>' . e(tr($label)) . '</span></a>';
        }
        echo '</nav>';
    }
    echo '<script src="assets/js/offline-db.js" defer></script><script src="assets/js/sync-client.js" defer></script><script src="assets/js/tts.js" defer></script><script src="assets/js/app-ui.js?v=20261026-6" defer></script><script src="assets/js/student-ui.js?v=20261026-2" defer></script><script src="assets/js/attendance.js?v=20261027-2" defer></script></body></html>';
}
