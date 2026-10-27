<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit;}
require __DIR__.'/../app/autoload.php';use App\Core\Database as DB;
$base='http://localhost/lms/public/';$cookie=tempnam(sys_get_temp_dir(),'tlmti-student-');$passed=0;$failed=0;
$testUsername=getenv('TEST_STUDENT_USERNAME')?:'demo.student.01';$testPassword=getenv('TEST_STUDENT_PASSWORD')?:'Demo@2026!';
function studentCheck(bool $ok,string $label):void{global $passed,$failed;if($ok){$passed++;echo "PASS $label\n";}else{$failed++;echo "FAIL $label\n";}}
function studentRequest(string $path,?array $post=null):array{global $base,$cookie;$ch=curl_init($base.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>20]);if($post!==null){curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);}$body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);if($body===false){throw new RuntimeException(curl_error($ch));}curl_close($ch);return[$code,$body];}
function studentToken(string $html):string{preg_match('/name="csrf_token" value="([^"]+)"/',$html,$m);return $m[1]??'';}
try{
 [$code,$html]=studentRequest('login.php');[$code]=studentRequest('login.php',['action'=>'login','login'=>$testUsername,'password'=>$testPassword,'csrf_token'=>studentToken($html)]);studentCheck($code===303,'student login');
 $uid=(int)DB::val('SELECT id FROM users WHERE username=?',[$testUsername]);$subject=(int)DB::val('SELECT subject_id FROM student_subjects WHERE student_id=? LIMIT 1',[$uid]);$lesson=(int)DB::val("SELECT c.id FROM content_items c JOIN modules m ON m.id=c.module_id WHERE c.subject_id=? AND c.status='published' AND m.status='published' ORDER BY m.week_no,m.sequence_no,c.sequence_no LIMIT 1",[$subject]);
 foreach(['dashboard.php','courses.php','courses.php?q=COPA','courses.php?filter=progress','courses.php?id='.$subject,'lesson.php?id='.$lesson,'tests.php','progress.php','student_detail.php','attendance.php','offline_downloads.php','sync_status.php','notifications.php','profile.php'] as $route){[$code,$html]=studentRequest($route);studentCheck($code===200&&!str_contains($html,'Something went wrong')&&!str_contains($html,'Fatal error'),$route);}
 $foreignSubject=(int)DB::val('SELECT id FROM subjects WHERE id<>? LIMIT 1',[$subject]);$foreignLesson=(int)DB::val('SELECT id FROM content_items WHERE subject_id=? LIMIT 1',[$foreignSubject]);$foreignTest=(int)DB::val('SELECT id FROM tests WHERE subject_id=? LIMIT 1',[$foreignSubject]);$foreignAttempt=(int)DB::val("SELECT id FROM test_attempts WHERE student_id<>? AND result<>'pending' LIMIT 1",[$uid]);
 foreach(['courses.php?id='.$foreignSubject,'lesson.php?id='.$foreignLesson,'tests.php?id='.$foreignTest,'tests.php?result='.$foreignAttempt,'student_detail.php?id=1','users.php','centres.php','app_settings.php','roles_permissions.php'] as $route){[$code]=studentRequest($route);studentCheck($code===403,'student access denied '.$route);}
 [$code,$html]=studentRequest('courses.php');studentCheck(!str_contains($html,'>Electrician<')&&str_contains($html,'COPA'),'course list is enrolment scoped');
 [$code,$html]=studentRequest('lesson.php?id='.$lesson);studentCheck(!str_contains($html,'is_correct'),'lesson HTML contains no answer key');
 [$code]=studentRequest('lesson.php?id='.$lesson,['action'=>'study']);studentCheck($code===419,'study requires CSRF');
 [$code]=studentRequest('attendance.php',['action'=>'mark','csrf_token'=>studentToken($html)]);studentCheck($code===403,'student cannot mark own attendance');
 [$code,$html]=studentRequest('dashboard.php');studentRequest('dashboard.php',['action'=>'set_language','language'=>'hi','csrf_token'=>studentToken($html)]);[$code,$html]=studentRequest('courses.php');studentCheck(str_contains($html,'मेरे कोर्स')&&!str_contains($html,'value="hne"'),'Hindi courses and only two languages');
 [$code,$html]=studentRequest('courses.php?id='.$subject);studentCheck(str_contains($html,'परिचय और सुरक्षा'),'Hindi lesson titles');
 studentRequest('courses.php',['action'=>'set_language','language'=>'en','csrf_token'=>studentToken($html)]);[$code,$html]=studentRequest('dashboard.php');studentCheck(str_contains($html,'lang="en"'),'English preference saved');
 $event=['event_uuid'=>uuid_v4(),'type'=>'study','payload'=>['lesson_id'=>$foreignLesson,'cycle_no'=>1,'seconds'=>30,'complete'=>1],'queued_offline'=>true];[$code,$response]=studentRequest('lesson.php?action=sync_push',['csrf_token'=>studentToken($html),'events'=>json_encode([$event])]);$json=json_decode($response,true);studentCheck($code===200&&($json['results'][0]['status']??'')==='rejected','offline sync rechecks subject access');
 echo "$passed passed, $failed failed\n";
}finally{if(is_file($cookie)){unlink($cookie);}}exit($failed?1:0);
