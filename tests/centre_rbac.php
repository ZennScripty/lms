<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/autoload.php';
use App\Core\{Auth,Authorization as A,Database as DB};use App\Services\{ManagementService as M,AttendanceService as Attendance};
date_default_timezone_set('Asia/Kolkata');$pdo=DB::pdo();$pdo->beginTransaction();$passed=0;
function rbacAs(int $id):void{$_SESSION=['uid'=>$id,'last_activity'=>time()];foreach(['loaded'=>false,'user'=>null] as $k=>$v)(new ReflectionProperty(Auth::class,$k))->setValue(null,$v);}
function rbacCheck(bool $ok,string $label):void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;$passed++;}
function rbacReject(callable $f,string $label):void{try{$f();}catch(DomainException|InvalidArgumentException $e){rbacCheck(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
try{
 $root=(int)DB::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='super_admin' AND u.status='active' AND u.deleted_at IS NULL LIMIT 1");rbacAs($root);
 $ca=M::save('centres',null,['name'=>'QA RBAC Alpha','is_active'=>1]);$cb=M::save('centres',null,['name'=>'QA RBAC Beta','is_active'=>1]);
 $make=function(string $tag,string $role,int $centre,array $extra=[]){return M::save('users',null,$extra+['full_name'=>'QA RBAC '.$tag,'username'=>'qa.rbac.'.$tag,'role_id'=>DB::val('SELECT id FROM roles WHERE code=?',[$role]),'centre_id'=>$centre,'password'=>'QaTest@2026!','status'=>'active']);};
 $adminA=$make('adminA','admin',$ca,['centre_ids'=>[$ca]]);$adminB=$make('adminB','admin',$cb,['centre_ids'=>[$cb]]);
 $studentA=$make('studentA','student',$ca);$studentB=$make('studentB','student',$cb);$teacherA=$make('teacherA','teacher',$ca);$teacherB=$make('teacherB','teacher',$cb);
 $sa=M::save('subjects',null,['code'=>'QA-RBAC-A','name'=>'QA Alpha subject','is_active'=>1]);$sb=M::save('subjects',null,['code'=>'QA-RBAC-B','name'=>'QA Beta subject','is_active'=>1]);$shared=M::save('subjects',null,['code'=>'QA-RBAC-S','name'=>'QA Shared subject','is_active'=>1]);
 foreach([[$ca,$sa],[$cb,$sb],[$ca,$shared],[$cb,$shared]] as [$centre,$subject])M::save('centre_subjects',null,['centre_id'=>$centre,'subject_id'=>$subject]);
 $batchA=M::save('batches',null,['centre_id'=>$ca,'name'=>'QA Alpha batch','training_mode'=>'community','is_active'=>1]);$batchB=M::save('batches',null,['centre_id'=>$cb,'name'=>'QA Beta batch','training_mode'=>'community','is_active'=>1]);
 foreach([[$studentA,$sa,$batchA],[$studentB,$sb,$batchB],[$studentB,$shared,$batchB],[$studentA,$shared,$batchA]] as [$student,$subject,$batch])M::save('enrolments',null,['student_id'=>$student,'subject_id'=>$subject,'batch_id'=>$batch,'status'=>'active']);
 $module=M::save('modules',null,['subject_id'=>$sb,'code'=>'QA-B-M','week_no'=>1,'sequence_no'=>1,'estimated_minutes'=>10,'status'=>'published','title_en'=>'QA Beta module','title_hi'=>'नमूना']);
 rbacAs($adminA);rbacCheck(A::isCentreAdmin()&&!A::isSuperAdmin(),'distinct centre Admin identity');rbacCheck(A::centreIds()===[$ca],'Admin only assigned active centres');
 rbacCheck(A::can('students.manage')&&!A::can('roles.manage')&&!A::can('settings.manage'),'centre Admin cannot inherit global role/settings privileges');
 foreach(['students','teachers','centres','batches','subjects'] as $kind){$ids=array_map('intval',array_column(M::options($kind),'id'));$foreign=match($kind){'students'=>$studentB,'teachers'=>$teacherB,'centres'=>$cb,'batches'=>$batchB,default=>$sb};rbacCheck(!in_array($foreign,$ids,true),'scoped dropdown '.$kind);}
 rbacCheck(M::find('users',$studentA)!==null&&M::find('users',$studentB)===null,'user lookup isolates centres');rbacCheck(M::find('users',$root)===null,'centre Admin cannot target Super Admin');
 rbacCheck(M::find('modules',$module)===null,'foreign curriculum lookup denied');rbacCheck(!A::canAccessStudent($studentB),'foreign student detail authorization denied');
 rbacCheck(A::canAccessSubject($shared)&&!M::canWrite('modules',$shared),'shared curriculum readable without cross-centre writes');
 rbacReject(fn()=>M::save('users',$studentB,['full_name'=>'Attack']),'foreign user mutation rejected');
 rbacReject(fn()=>M::save('users',null,['full_name'=>'Attack','username'=>'qa.rbac.attack','role_id'=>DB::val("SELECT id FROM roles WHERE code='super_admin'"),'centre_id'=>$ca,'password'=>'QaTest@2026!','status'=>'active']),'Super Admin promotion rejected server side');
 rbacReject(fn()=>M::save('enrolments',null,['student_id'=>$studentA,'subject_id'=>$sb,'batch_id'=>$batchA,'status'=>'active']),'foreign subject enrolment rejected');
 rbacReject(fn()=>M::save('enrolments',null,['student_id'=>$studentA,'subject_id'=>$sa,'batch_id'=>$batchB,'status'=>'active']),'foreign batch rejected');
 rbacReject(fn()=>M::save('assignments',null,['teacher_id'=>$teacherA,'subject_id'=>$shared,'can_manage_content'=>1]),'centre Admin cannot grant shared global write powers');
 rbacAs($root);M::save('assignments',null,['teacher_id'=>$teacherA,'subject_id'=>$shared]);rbacAs($teacherA);rbacCheck(!A::canAccessStudent($studentB)&&!in_array($studentB,array_map('intval',array_column(M::options('students'),'id')),true),'shared subject does not bypass Teacher centre isolation');rbacAs($adminA);
 rbacCheck(!M::canWrite('subjects'),'Centre Admin cannot define global subjects');rbacAs($root);$new=M::save('subjects',null,['code'=>'QA-RBAC-NEW','name'=>'QA Global new','is_active'=>1]);M::save('centre_subjects',null,['centre_id'=>$ca,'subject_id'=>$new]);rbacAs($adminA);rbacCheck(A::canAccessSubject($new),'Super Admin can make global subject available to centre');
 $pending=M::save('users',null,['full_name'=>'QA Pending','username'=>'qa.rbac.pending','role_id'=>DB::val("SELECT id FROM roles WHERE code='student'"),'centre_id'=>$ca,'password'=>'QaTest@2026!','status'=>'pending']);
 M::reviewUser($pending,'approve');$review=DB::one('SELECT * FROM users WHERE id=?',[$pending]);rbacCheck($review['status']==='active'&&$review['reviewed_by']==$adminA&&$review['reviewed_at']!==null,'scoped pending approval audited with actor/time');rbacReject(fn()=>M::reviewUser($pending,'approve'),'duplicate approval rejected');
 rbacReject(fn()=>M::deleteUser($studentB,true),'cross-centre account deletion denied');M::deleteUser($pending,true);rbacCheck(DB::val('SELECT deleted_at FROM users WHERE id=?',[$pending])!==null,'authorized deletion is soft and retains identity');
 rbacCheck(!Attendance::canViewSelfie(['user_id'=>$studentB,'attendance_for'=>'student']),'foreign attendance evidence denied');
 DB::run('DELETE FROM admin_centres WHERE admin_id=?',[$adminA]);rbacCheck(!A::canAccessStudent($studentA)&&!M::options('subjects'),'revocation fails closed immediately');
 DB::run('INSERT INTO admin_centres (admin_id,centre_id) VALUES (?,?)',[$adminA,$ca]);DB::run('UPDATE centres SET is_active=0 WHERE id=?',[$ca]);rbacCheck(!M::options('students'),'inactive centre denies Admin access');DB::run('UPDATE centres SET is_active=1 WHERE id=?',[$ca]);
 rbacAs($root);$data=['full_name'=>'QA RBAC adminA','username'=>'qa.rbac.adminA','role_id'=>DB::val("SELECT id FROM roles WHERE code='admin'"),'centre_id'=>$ca,'password'=>'','status'=>'active','centre_ids'=>[$ca,$cb]];M::save('users',$adminA,$data);
 rbacAs($adminA);rbacCheck(count(A::centreIds())===2&&M::find('users',$studentB)!==null,'Super Admin can assign multiple centres');
 rbacAs($root);$data['status']='inactive';M::save('users',$adminA,$data);rbacAs($adminA);rbacCheck(Auth::user()===null,'deactivated Admin loses authenticated access');rbacAs($root);$data['status']='active';M::save('users',$adminA,$data);rbacAs($adminA);rbacCheck(Auth::user()!==null&&count(A::centreIds())===2,'Super Admin can reactivate Admin without losing centre links');
 rbacAs($root);rbacReject(fn()=>M::deleteUser($root,true),'self deletion blocked');rbacCheck(A::canAccessStudent($studentB)&&M::find('modules',$module)!==null,'Super Admin retains global access');
 // Simulate an actor whose role was revoked mid-request; the last active root still cannot be deleted.
 $other=$make('lastroot','super_admin',$ca);DB::run("UPDATE users SET status='inactive' WHERE role_id=(SELECT id FROM roles WHERE code='super_admin') AND id<>?",[$other]);rbacReject(fn()=>M::deleteUser($other,true),'last active Super Admin deletion blocked under transaction lock');
 echo "$passed passed; all records and role changes rolled back.\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();}
