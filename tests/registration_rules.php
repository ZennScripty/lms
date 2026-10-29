<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/autoload.php';
use App\Core\{Auth,Database as DB};use App\Services\{ManagementService as M,RegistrationService as R,ProfileImageService as P};
date_default_timezone_set('Asia/Kolkata');$_SERVER['REMOTE_ADDR']='192.0.2.73';$pdo=DB::pdo();$pdo->beginTransaction();$passed=0;$image=APP_ROOT.'/tmp/registration-image.png';
function regCheck(bool $ok,string $label):void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;$passed++;}
function regReject(callable $f,string $label):void{try{$f();}catch(DomainException|InvalidArgumentException $e){regCheck(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
function regAs(int $id):void{$_SESSION=['uid'=>$id,'last_activity'=>time()];foreach(['loaded'=>false,'user'=>null] as $k=>$v)(new ReflectionProperty(Auth::class,$k))->setValue(null,$v);}
try{
 $root=(int)DB::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='super_admin' AND u.status='active' AND u.deleted_at IS NULL LIMIT 1");regAs($root);
 $a=M::save('centres',null,['name'=>'QA Registration Alpha','is_active'=>1]);$b=M::save('centres',null,['name'=>'QA Registration Beta','is_active'=>1]);
 $batch=M::save('batches',null,['centre_id'=>$a,'name'=>'QA Registration Batch A','training_mode'=>'community','is_active'=>1]);$foreign=M::save('batches',null,['centre_id'=>$b,'name'=>'QA Registration Batch B','training_mode'=>'community','is_active'=>1]);
 $subjects=[];foreach(['A','B','Foreign'] as $tag){$s=M::save('subjects',null,['code'=>'QA-REG-'.$tag,'name'=>'QA Reg '.$tag,'is_active'=>1]);M::save('centre_subjects',null,['centre_id'=>$tag==='Foreign'?$b:$a,'subject_id'=>$s]);$subjects[$tag]=$s;}
 $admin=M::save('users',null,['full_name'=>'QA Registration Admin','username'=>'qa.reg.admin','role_id'=>DB::val("SELECT id FROM roles WHERE code='admin'"),'centre_id'=>$a,'centre_ids'=>[$a],'password'=>'QaTest@2026!','status'=>'active']);
 $studentData=['account_type'=>'student','full_name'=>'QA Registration Student','email'=>'qa.registration.student@example.test','password'=>'QaTest@2026!','confirmation'=>'QaTest@2026!','centre_id'=>$a,'batch_id'=>$batch];
 regCheck(array_column(R::options('student',$a),'id')==[$batch],'batch options bound to centre');regReject(fn()=>R::options('teacher',$a),'public Teacher options disabled');
 regReject(fn()=>R::submit($studentData+['subject_ids'=>[$subjects['Foreign']]]),'Student cannot smuggle Teacher subject assignment');
 $bad=$studentData;$bad['batch_id']=$foreign;regReject(fn()=>R::submit($bad),'cross-centre batch rejected');$bad=$studentData;$bad['account_type']='super_admin';regReject(fn()=>R::submit($bad),'public privileged role rejected');
 $bad=$studentData;$bad['confirmation']='wrong';regReject(fn()=>R::submit($bad),'password mismatch rejected');$bad=$studentData;$bad['password']=$bad['confirmation']='short';regReject(fn()=>R::submit($bad),'existing minimum password enforced');
 $student=R::submit($studentData+['status'=>'active','role_id'=>1,'username'=>'admin']);$row=DB::one('SELECT * FROM users WHERE id=?',[$student]);regCheck($row['status']==='pending'&&$row['batch_id']==$batch&&$row['centre_id']==$a,'registration stays pending with selected centre and batch');
 regCheck(password_verify('QaTest@2026!',$row['password_hash'])&&$row['username']!=='admin','password hashed and public username spoof ignored');
 regCheck(DB::val("SELECT code FROM roles WHERE id=?",[$row['role_id']])==='student','posted role ID cannot override account type');
 regCheck((int)DB::val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='registration' AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.user_id'))=?",[$admin,(string)$student])===1,'correct centre Admin receives registration notification');
 regReject(fn()=>R::submit($studentData),'duplicate email rejected without duplicate account');
 regAs($student);regCheck(Auth::user()===null,'pending account cannot obtain session access');regAs($admin);
 DB::run('UPDATE batches SET is_active=0 WHERE id=?',[$batch]);regReject(fn()=>M::reviewUser($student,'approve'),'approval rechecks inactive batch');DB::run('UPDATE batches SET is_active=1 WHERE id=?',[$batch]);M::reviewUser($student,'approve');regCheck(DB::val('SELECT batch_id FROM users WHERE id=?',[$student])==$batch,'approval preserves selected batch');
 regAs($student);regCheck(Auth::user()['batch_id']==$batch,'approved session exposes canonical batch membership');regAs($root);
 $teacherData=['account_type'=>'teacher','full_name'=>'QA Registration Teacher','email'=>'qa.registration.teacher@example.test','password'=>'QaTest@2026!','confirmation'=>'QaTest@2026!','centre_id'=>$a,'subject_ids'=>[$subjects['A'],$subjects['B'],$subjects['A']]];
 $bad=$teacherData;$bad['subject_ids']=[$subjects['Foreign']];regReject(fn()=>R::submit($bad),'foreign curriculum rejected');$bad=$teacherData;$bad['subject_ids']=['invalid'];regReject(fn()=>R::submit($bad),'malformed subject IDs rejected');
 regReject(fn()=>R::submit($teacherData),'public Teacher signup disabled');$teacher=M::save('users',null,['full_name'=>$teacherData['full_name'],'username'=>'qa.reg.staff','email'=>$teacherData['email'],'password'=>'QaTest@2026!','role_id'=>DB::val("SELECT id FROM roles WHERE code='teacher'"),'centre_id'=>$a,'status'=>'pending']);DB::run('UPDATE users SET registration_submitted_at=NOW() WHERE id=?',[$teacher]);foreach(array_unique($teacherData['subject_ids']) as $sid)M::save('assignments',null,['teacher_id'=>$teacher,'subject_id'=>$sid]);regCheck((int)DB::val('SELECT COUNT(*) FROM teacher_subjects WHERE teacher_id=?',[$teacher])===2,'multiple Teacher subjects saved without duplicate links');regCheck((int)DB::val('SELECT SUM(can_manage_content+can_manage_tests) FROM teacher_subjects WHERE teacher_id=?',[$teacher])===0,'signup cannot grant management permissions');
 DB::run('UPDATE subjects SET is_active=0 WHERE id=?',[$subjects['A']]);regReject(fn()=>M::reviewUser($teacher,'approve'),'Teacher approval revalidates subjects');DB::run('UPDATE subjects SET is_active=1 WHERE id=?',[$subjects['A']]);M::reviewUser($teacher,'approve');regCheck(DB::val('SELECT status FROM users WHERE id=?',[$teacher])==='active','valid Teacher approval activates existing account');
 DB::run('UPDATE centres SET is_active=0 WHERE id=?',[$b]);regReject(fn()=>R::options('student',$b),'inactive centres unavailable');
 $img=imagecreatetruecolor(800,600);imagepng($img,$image);imagedestroy($img);$bytes=P::encode($image);$size=getimagesizefromstring($bytes);regCheck($size[0]===512&&$size[1]===384&&$size[2]===IMAGETYPE_JPEG,'profile image safely decoded and bounded with aspect ratio');
 file_put_contents($image,'<?php echo "not an image";');regReject(fn()=>P::encode($image),'executable or fake image rejected');
 echo "$passed passed; registration fixtures rolled back.\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();if(is_file($image))unlink($image);}
