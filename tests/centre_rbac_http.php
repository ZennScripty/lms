<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/autoload.php';
use App\Core\{Auth,Database as DB};use App\Services\ManagementService as M;
date_default_timezone_set('Asia/Kolkata');$base='http://localhost/lms/public/';$passed=0;$cookie='';$cookies=[];$users=[];$centres=[];$subjects=[];$files=[];$assets=[];
function httpRbac(bool $ok,string $label):void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;$passed++;}
function rbacRequest(string $path,?array $post=null):array{global $base,$cookie;$c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>20]);if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$html=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);if($html===false)throw new RuntimeException(curl_error($c));curl_close($c);return[$code,$html];}
function rbacToken(string $html):string{preg_match('/name="csrf_token" value="([^"]+)"/',$html,$m);return $m[1]??'';}
function rbacLogin(string $tag):void{global $cookie,$cookies;$cookie=$cookies[$tag]??=tempnam(sys_get_temp_dir(),'rbac-http-');[$c,$html]=rbacRequest('login.php');[$c]=rbacRequest('login.php',['action'=>'login','login'=>'qa.rbac.http.'.$tag,'password'=>'QaTest@2026!','csrf_token'=>rbacToken($html)]);httpRbac($c===303,$tag.' login');}
try{
 $root=DB::insert("INSERT INTO users (uuid,role_id,full_name,username,password_hash,preferred_language_id) VALUES (?,(SELECT id FROM roles WHERE code='super_admin'),'QA RBAC Root','qa.rbac.http.root',?,(SELECT id FROM languages WHERE code='en'))",[uuid_v4(),password_hash('QaTest@2026!',PASSWORD_DEFAULT)]);$users[]=$root;$_SESSION=['uid'=>$root,'last_activity'=>time()];
 foreach(['A','B'] as $tag)$centres[$tag]=M::save('centres',null,['name'=>'QA RBAC Centre '.$tag,'is_active'=>1]);
 $make=function(string $tag,string $role,string $centre)use(&$users,$centres){$data=['full_name'=>'QA RBAC '.$tag,'username'=>'qa.rbac.http.'.$tag,'role_id'=>DB::val('SELECT id FROM roles WHERE code=?',[$role]),'centre_id'=>$centres[$centre],'password'=>'QaTest@2026!','status'=>'active'];if($role==='admin')$data['centre_ids']=[$centres[$centre]];$id=M::save('users',null,$data);$users[]=$id;return $id;};
 $aa=$make('adminA','admin','A');$ab=$make('adminB','admin','B');$sa=$make('studentA','student','A');$sb=$make('studentB','student','B');$ta=$make('teacherA','teacher','A');$tb=$make('teacherB','teacher','B');
 foreach(['A','B','Shared'] as $tag)$subjects[$tag]=M::save('subjects',null,['code'=>'QA-RH-'.$tag,'name'=>'QA RBAC Subject '.$tag,'is_active'=>1]);
 foreach([['A','A'],['B','B'],['A','Shared'],['B','Shared']] as [$c,$s])M::save('centre_subjects',null,['centre_id'=>$centres[$c],'subject_id'=>$subjects[$s]]);
 $ba=M::save('batches',null,['name'=>'QA RBAC Batch A','centre_id'=>$centres['A'],'training_mode'=>'community','is_active'=>1]);$bb=M::save('batches',null,['name'=>'QA RBAC Batch B','centre_id'=>$centres['B'],'training_mode'=>'community','is_active'=>1]);
 foreach([[$sa,'A',$ba],[$sa,'Shared',$ba],[$sb,'B',$bb],[$sb,'Shared',$bb]] as [$student,$s,$b])M::save('enrolments',null,['student_id'=>$student,'subject_id'=>$subjects[$s],'batch_id'=>$b,'status'=>'active']);
 M::save('assignments',null,['teacher_id'=>$ta,'subject_id'=>$subjects['Shared']]);
 $module=M::save('modules',null,['subject_id'=>$subjects['B'],'code'=>'QA-RH-M','week_no'=>1,'sequence_no'=>1,'estimated_minutes'=>10,'status'=>'published','title_en'=>'QA RBAC Beta Private Module','title_hi'=>'नमूना']);
 foreach([[$ta,'A'],[$tb,'B']] as [$teacher,$c]){
  $path=APP_ROOT.'/storage/uploads/qa-rbac-'.bin2hex(random_bytes(6)).'.png';$image=imagecreatetruecolor(10,10);imagepng($image,$path);imagedestroy($image);$files[]=$path;
  $assets[$c]=DB::insert("INSERT INTO media_assets (uuid,asset_type,original_name,storage_path,mime_type,size_bytes,checksum_sha256,uploaded_by,centre_id) VALUES (?,'image',?,?,'image/png',?,?,?,?)",[uuid_v4(),'QA Private '.$c.'.png','uploads/'.basename($path),filesize($path),hash_file('sha256',$path),$teacher,$centres[$c]]);
 }
 $pending=$make('pending','student','A');DB::run("UPDATE users SET status='pending' WHERE id=?",[$pending]);
 $cookie=$cookies['pending']=tempnam(sys_get_temp_dir(),'rbac-pending-');[$c,$html]=rbacRequest('login.php');[$c]=rbacRequest('login.php',['action'=>'login','login'=>'qa.rbac.http.pending','password'=>'QaTest@2026!','csrf_token'=>rbacToken($html)]);httpRbac($c===200,'pending account cannot log in directly');DB::run("DELETE FROM audit_logs WHERE action='login_failed' AND JSON_UNQUOTE(JSON_EXTRACT(new_values,'$.details'))=?",['login:qa.rbac.http.pending']);
 rbacLogin('root');[$c,$html]=rbacRequest('users.php?edit='.$aa);httpRbac($c===200&&str_contains($html,'Admin centre access')&&str_contains($html,'QA RBAC Centre B'),'Super Admin centre assignment UI');
 foreach(['users.php','centres.php','batches.php','subjects.php?view=centre_subjects&new=1','dashboard.php','reports.php?centre_id='.$centres['B']] as $route){[$c,$html]=rbacRequest($route);httpRbac($c===200&&!str_contains($html,'Something went wrong'), 'Super Admin '.$route);}
 [$c,$csv]=rbacRequest('reports.php?export=csv');httpRbac($c===200&&str_contains($csv,'QA RBAC studentA')&&str_contains($csv,'QA RBAC studentB'),'consolidated export includes both centres');
 rbacLogin('adminA');
 foreach(['dashboard.php','centres.php','batches.php','users.php','users.php?new=1','subjects.php','subjects.php?view=assignments','enrolments.php','modules.php','modules.php?view=media','tests.php','attendance.php','reports.php'] as $route){[$c,$html]=rbacRequest($route);httpRbac($c===200&&!str_contains($html,'Something went wrong')&&!str_contains($html,'Fatal error'),'centre Admin healthy '.$route);}
 foreach(['users.php','dashboard.php','reports.php','reports.php?export=csv'] as $route){[$c,$html]=rbacRequest($route);httpRbac(!str_contains($html,'QA RBAC studentB')&&!str_contains($html,'QA RBAC teacherB'),'cross-centre people excluded '.$route);}
 [$c,$html]=rbacRequest('subjects.php');httpRbac(str_contains($html,'QA RBAC Subject A')&&str_contains($html,'QA RBAC Subject Shared')&&!str_contains($html,'QA RBAC Subject B'),'catalogue only permitted centre subjects');
 foreach(['users.php?edit='.$sb,'batches.php?edit='.$bb,'modules.php?edit='.$module] as $route){[$c]=rbacRequest($route);httpRbac($c===404,'direct foreign lookup denied '.$route);}
 foreach(['student_detail.php?id='.$sb,'reports.php?centre_id='.$centres['B'],'roles_permissions.php','app_settings.php','language_settings.php','media.php?id='.$assets['B']] as $route){[$c]=rbacRequest($route);httpRbac($c===403,'direct forbidden '.$route);}
 [$c,$image]=rbacRequest('media.php?id='.$assets['A']);httpRbac($c===200&&str_starts_with($image,"\x89PNG"),'authorized centre media bytes');
 [$c,$html]=rbacRequest('modules.php?view=media');httpRbac(str_contains($html,'QA Private A.png')&&!str_contains($html,'QA Private B.png')&&str_contains($html,'name="centre_id"'),'media library and upload centre scoped');
 [$c,$html]=rbacRequest('users.php');$token=rbacToken($html);[$c]=rbacRequest('users.php',['action'=>'delete_user','id'=>$sb,'confirm_delete'=>'1','csrf_token'=>$token]);httpRbac($c===200&&DB::val('SELECT deleted_at FROM users WHERE id=?',[$sb])===null,'forged deletion does not modify foreign account');
 [$c]=rbacRequest('users.php',['action'=>'save_record','full_name'=>'Attack','username'=>'qa.rbac.http.attack','role_id'=>DB::val("SELECT id FROM roles WHERE code='super_admin'"),'centre_id'=>$centres['A'],'password'=>'QaTest@2026!','status'=>'active','csrf_token'=>$token]);httpRbac($c===200&&!DB::val('SELECT id FROM users WHERE username=?',['qa.rbac.http.attack']),'crafted Super Admin creation denied');
 [$c]=rbacRequest('users.php',['action'=>'save_record','full_name'=>'Foreign attack','username'=>'qa.rbac.http.foreignattack','role_id'=>DB::val("SELECT id FROM roles WHERE code='student'"),'centre_id'=>$centres['B'],'password'=>'QaTest@2026!','status'=>'active','csrf_token'=>$token]);httpRbac($c===200&&!DB::val('SELECT id FROM users WHERE username=?',['qa.rbac.http.foreignattack']),'crafted cross-centre user creation denied');
 [$c]=rbacRequest('users.php',['action'=>'review_user','id'=>$pending,'decision'=>'approve','csrf_token'=>$token]);httpRbac($c===303&&DB::val('SELECT status FROM users WHERE id=?',[$pending])==='active','own-centre pending approval works');
 DB::run("UPDATE users SET status='pending' WHERE id=?",[$pending]);[$c]=rbacRequest('users.php',['action'=>'review_user','id'=>$pending,'decision'=>'reject','csrf_token'=>$token]);httpRbac($c===303&&DB::val('SELECT status FROM users WHERE id=?',[$pending])==='rejected','own-centre rejection is persisted');
 [$c]=rbacRequest('users.php',['action'=>'delete_user','id'=>$pending,'confirm_delete'=>'1','csrf_token'=>$token]);httpRbac($c===303&&DB::val('SELECT deleted_at FROM users WHERE id=?',[$pending])!==null,'confirmed scoped soft deletion works');
 rbacLogin('teacherA');[$c,$csv]=rbacRequest('reports.php?export=csv');httpRbac(!str_contains($csv,'QA RBAC studentA')&&!str_contains($csv,'QA RBAC studentB'),'subject-only Teacher cannot export Students without batch assignment');[$c]=rbacRequest('student_detail.php?id='.$sb);httpRbac($c===403,'delegated Teacher foreign detail denied');
 $cookie=$cookies['adminA'];DB::run('DELETE FROM admin_centres WHERE admin_id=?',[$aa]);[$c,$html]=rbacRequest('users.php');httpRbac($c===200&&!str_contains($html,'QA RBAC studentA')&&!str_contains($html,'QA RBAC studentB'),'live session loses data on centre revocation');
 [$c]=rbacRequest('media.php?id='.$assets['A']);httpRbac($c===403,'revocation blocks previously available centre file');
 DB::run('INSERT INTO admin_centres (admin_id,centre_id,assigned_by) VALUES (?,?,?)',[$aa,$centres['B'],$root]);[$c,$html]=rbacRequest('users.php');httpRbac(str_contains($html,'QA RBAC studentB')&&!str_contains($html,'QA RBAC studentA'),'changed centre assignment applies to existing session');
 $cookie=$cookies['root'];[$c,$html]=rbacRequest('users.php');[$c]=rbacRequest('users.php',['action'=>'delete_user','id'=>$aa,'confirm_delete'=>'1','csrf_token'=>rbacToken($html)]);httpRbac($c===303,'Super Admin soft-deletes centre Admin');
 $cookie=$cookies['adminA'];[$c]=rbacRequest('users.php');httpRbac($c===303,'deleted Admin session cannot continue');
 echo "$passed passed; HTTP fixtures cleaned.\n";
}finally{
 foreach($assets as $id)DB::run('DELETE FROM media_assets WHERE id=?',[$id]);
 foreach($subjects as $id){DB::run('DELETE FROM module_translations WHERE module_id IN (SELECT id FROM modules WHERE subject_id=?)',[$id]);DB::run('DELETE FROM modules WHERE subject_id=?',[$id]);DB::run('DELETE FROM centre_subjects WHERE subject_id=?',[$id]);}
 foreach($users as $id){DB::run('DELETE FROM admin_centres WHERE admin_id=?',[$id]);DB::run('DELETE FROM teacher_subjects WHERE teacher_id=?',[$id]);DB::run('DELETE FROM student_week_state WHERE student_id=?',[$id]);DB::run('DELETE FROM student_subjects WHERE student_id=?',[$id]);DB::run('DELETE FROM audit_logs WHERE user_id=?',[$id]);DB::run('UPDATE users SET reviewed_by=NULL,created_by=NULL WHERE id=?',[$id]);}
 foreach(array_reverse($users) as $id)DB::run('DELETE FROM users WHERE id=?',[$id]);
 foreach($centres as $id){DB::run('DELETE FROM batches WHERE centre_id=?',[$id]);DB::run('DELETE FROM centres WHERE id=?',[$id]);}
 foreach($subjects as $id)DB::run('DELETE FROM subjects WHERE id=?',[$id]);
 foreach(array_merge($cookies,$files) as $path)if(is_file($path))unlink($path);
}
