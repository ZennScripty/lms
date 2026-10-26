<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit;}
require __DIR__.'/../app/autoload.php';
use App\Core\Database as DB;
$base='http://localhost/lms/public/';$passed=0;$failed=0;
function check(bool $ok,string $label):void{global $passed,$failed;if($ok){$passed++;echo "PASS $label\n";}else{$failed++;echo "FAIL $label\n";}}
function request(string $path,?array $post=null):array{
    global $base,$cookie;
    $ch=curl_init($base.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);if($post!==null){curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);} $body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$redirect=curl_getinfo($ch,CURLINFO_REDIRECT_URL);if($body===false){throw new RuntimeException(curl_error($ch));}curl_close($ch);return [$code,$body,$redirect];
}
function token(string $html):string{preg_match('/name="csrf_token" value="([^"]+)"/',$html,$m);return $m[1]??'';}
function login(string $username,string $password):void{[$code,$body]=request('login.php');[$code]=request('login.php',['action'=>'login','login'=>$username,'password'=>$password,'csrf_token'=>token($body)]);check($code===303,"$username login");}
function healthy(string $route):void{[$code,$body]=request($route);check($code===200&&!str_contains($body,'Something went wrong')&&!str_contains($body,'Fatal error'),$route);}
$cookie=tempnam(sys_get_temp_dir(),'tlmti-test-');
try{
    $password=getenv('TEST_ADMIN_PASSWORD');if(!$password){throw new RuntimeException('Set TEST_ADMIN_PASSWORD for admin integration tests.');}
    login('admin',$password);
    foreach(['dashboard.php','centres.php','centres.php?new=1','batches.php?new=1','users.php','users.php?new=1','subjects.php','subjects.php?view=assignments&new=1','enrolments.php?new=1','modules.php','modules.php?new=1','modules.php?view=lessons&new=1','modules.php?view=media','tests.php','tests.php?new=1','tests.php?view=questions&new=1','attendance.php','reports.php','roles_permissions.php','app_settings.php','sync_status.php','language_settings.php','profile.php','notifications.php'] as $route){healthy($route);}
    [$code,$html]=request('subjects.php');check(str_contains($html,'COPA'),'subject list uses actual DB');
    [$code]=request('subjects.php',['action'=>'save_record','code'=>'ATTACK']);check($code===419,'admin POST rejects missing CSRF (status '.$code.')');
    [$code,$html]=request('users.php?per_page=10');check(substr_count($html,'<tr>')===11,'SQL pagination returns ten users');
    [$code,$html]=request('users.php?q=demo.teacher.copa');check(substr_count($html,'<tr>')===2&&str_contains($html,'demo.teacher.copa'),'SQL search filters records');
    [$code,$html]=request('dashboard.php');request('dashboard.php',['action'=>'set_language','language'=>'hi','csrf_token'=>token($html)]);[$code,$html]=request('dashboard.php');check(str_contains($html,'lang="hi"')&&str_contains($html,'डैशबोर्ड')&&!str_contains($html,'value="hne"'),'Hindi UI with only two language options');
    request('dashboard.php',['action'=>'set_language','language'=>'en','csrf_token'=>token($html)]);[$code,$html]=request('dashboard.php');check(str_contains($html,'lang="en"')&&str_contains($html,'Total teachers'),'English preference applies on reload');
    [$code,$csv]=request('reports.php?export=csv');check($code===200&&str_contains($csv,'Demo '),'CSV export contains scoped DB rows');
    $lesson=(int)DB::val("SELECT c.id FROM content_items c JOIN subjects s ON s.id=c.subject_id WHERE s.code='COPA' ORDER BY c.id LIMIT 1");healthy('lesson.php?id='.$lesson);
    $png=tempnam(sys_get_temp_dir(),'tlmti-png-');file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    $filename='QA-'.bin2hex(random_bytes(5)).'.png';$assetId=null;$storedPath=null;
    try{
        [$code,$html]=request('modules.php?view=media');$ch=curl_init($base.'modules.php?view=media');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['csrf_token'=>token($html),'media'=>new CURLFile($png,'image/png',$filename)]]);$body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);check($code===303,'local media upload');
        $asset=DB::one('SELECT * FROM media_assets WHERE original_name=?',[$filename]);$assetId=$asset['id']??null;$storedPath=$asset['storage_path']??null;check($asset&&hash_file('sha256',$png)===$asset['checksum_sha256'],'media checksum saved');
        if($assetId){[$code,$body]=request('media.php?id='.$assetId);check($code===200&&hash('sha256',$body)===$asset['checksum_sha256'],'authenticated media bytes');
            $ch=curl_init($base.'media.php?id='.$assetId);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_RANGE=>'0-7']);$bytes=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);check($code===206&&strlen($bytes)===8,'media byte range supports playback');
            $ch=curl_init('http://localhost/lms/storage/'.$storedPath);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);check($code===403,'direct storage access blocked');
        }
    }finally{unlink($png);if($assetId){DB::run('DELETE FROM media_assets WHERE id=? AND original_name=?',[$assetId,$filename]);if($storedPath&&str_starts_with($storedPath,'uploads/')){@unlink(APP_ROOT.'/storage/'.$storedPath);}}}
    request('dashboard.php',['action'=>'logout','csrf_token'=>token($html)]);unlink($cookie);$cookie=tempnam(sys_get_temp_dir(),'tlmti-test-');
    login('demo.teacher.copa','Demo@2026!');
    foreach(['dashboard.php','subjects.php','students.php','modules.php','modules.php?new=1','modules.php?view=lessons','tests.php','tests.php?view=questions','attendance.php','reports.php','profile.php'] as $route){healthy($route);}
    [$code,$html]=request('subjects.php');check(str_contains($html,'COPA')&&!str_contains($html,'>Electrician<'),'teacher sees only assigned subject');
    [$code,$html]=request('students.php');check(str_contains($html,'demo.student.01')&&!str_contains($html,'demo.student.02'),'teacher sees own students only');
    foreach(['users.php','centres.php','enrolments.php','app_settings.php','roles_permissions.php','subjects.php?view=assignments'] as $route){[$code]=request($route);check(in_array($code,[403,404],true),'teacher denied '.$route);}
    $foreign=(int)DB::val("SELECT id FROM modules WHERE subject_id=(SELECT id FROM subjects WHERE code='ELEC') LIMIT 1");[$code]=request('modules.php?edit='.$foreign);check($code===404,'foreign module edit is blocked');
    $foreignStudent=(int)DB::val("SELECT id FROM users WHERE username='demo.student.02'");[$code]=request('student_detail.php?id='.$foreignStudent);check($code===403,'foreign student detail is blocked');
    [$code,$csv]=request('reports.php?export=csv');check($code===200&&str_contains($csv,'COPA')&&!str_contains($csv,'Electrician'),'teacher CSV excludes other subjects');
    [$code,$html]=request('modules.php');[$code]=request('modules.php',['action'=>'save_record','id'=>$foreign,'csrf_token'=>token($html)]);check($code===200,'foreign record mutation rejected without server error');
    $teacherId=(int)DB::val("SELECT id FROM users WHERE username='demo.teacher.copa'");$assignment=DB::one('SELECT * FROM teacher_subjects WHERE teacher_id=? ORDER BY id LIMIT 1',[$teacherId]);
    try{DB::run('UPDATE teacher_subjects SET can_manage_content=0 WHERE id=?',[$assignment['id']]);[$code,$html]=request('modules.php');[$code]=request('modules.php',['action'=>'save_record','csrf_token'=>token($html)]);check($code===403,'revoked content permission blocks a stale form POST');}
    finally{DB::run('UPDATE teacher_subjects SET can_manage_content=? WHERE id=?',[$assignment['can_manage_content'],$assignment['id']]);}
    [$code,$body]=request('modules.php?view=lessons');check(!str_contains($body,'<option value="hne"'),'teacher language excludes Chhattisgarhi');
}finally{if(is_file($cookie)){unlink($cookie);}}
echo "$passed passed, $failed failed\n";exit($failed?1:0);
