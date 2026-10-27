<?php
declare(strict_types=1);
use App\Core\{Auth, Authorization};
use App\Services\ManagementService as M;

Auth::requireLogin();
if (M::isStaff()) {
    $page=current_page();
    if($page==='dashboard.php'){require APP_ROOT.'/app/Views/staff_dashboard.php';exit;}
    if($page==='lesson.php'){require APP_ROOT.'/app/Views/staff_lesson.php';exit;}
    $sections=['centres.php'=>'centres','batches.php'=>'batches','users.php'=>'users','students.php'=>'students','subjects.php'=>'subjects','enrolments.php'=>'enrolments','modules.php'=>'modules','tests.php'=>'tests','attendance.php'=>'attendance'];
    if(isset($sections[$page])){
        $section=$sections[$page];$views=match($page){'modules.php'=>['modules','lessons','media'],'subjects.php'=>Authorization::isSuperAdmin()?['subjects','assignments']:['subjects'],'users.php'=>['users','enrolments'],'tests.php'=>['tests','questions'],default=>[$section]};
        $candidate=$_GET['view']??$section;
        if(!is_string($candidate)||!in_array($candidate,$views,true)){not_found();}
        $section=$candidate;
        require APP_ROOT.'/app/Views/'.($section==='media'?'media.php':'management.php');exit;
    }
    if(in_array($page,['reports.php','student_detail.php','sync_status.php','app_settings.php','roles_permissions.php','language_settings.php'],true)){
        require APP_ROOT.'/app/Views/staff_'.match($page){'reports.php','student_detail.php'=>'reports','sync_status.php'=>'logs','app_settings.php'=>'settings','roles_permissions.php'=>'roles',default=>'languages'}.'.php';exit;
    }
}

require_once APP_ROOT . '/app/Support/student_dispatch.php';
