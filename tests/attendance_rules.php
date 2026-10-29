<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/autoload.php';
use App\Core\{Database as DB,Auth};use App\Services\{AttendanceService as A,ManagementService as M};
date_default_timezone_set('Asia/Kolkata');$pdo=DB::pdo();$pdo->beginTransaction();$passed=0;$imagePath=APP_ROOT.'/tmp/qa-selfie.jpg';
function checkAttendance(bool $ok,string $label):void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;$passed++;}
function asAttendance(int $id):array{$_SESSION=['uid'=>$id,'last_activity'=>time()];foreach(['loaded'=>false,'user'=>null] as $k=>$v){(new ReflectionProperty(Auth::class,$k))->setValue(null,$v);}return Auth::user();}
function rejectsAttendance(callable $fn,string $label):void{try{$fn();}catch(DomainException $e){checkAttendance(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
try{
 $make=function(string $role){return DB::insert("INSERT INTO users (uuid,role_id,full_name,username,password_hash) VALUES (?,(SELECT id FROM roles WHERE code=?),?,?,?)",[uuid_v4(),$role,'QA Attendance '.$role,'qa.attendance.'.$role,password_hash('QaTest@2026!',PASSWORD_DEFAULT)]);};
 $student=$make('student');$teacher=$make('teacher');$admin=$make('super_admin');
 foreach(['attendance_student_mode','attendance_staff_mode'] as $k)DB::run("UPDATE app_settings SET setting_value='simple' WHERE setting_key=?",[$k]);
 $u=asAttendance($student);checkAttendance(A::status()['state']==='not_checked_in'&&A::today($student)===null,'opening status never marks attendance');rejectsAttendance(fn()=>A::checkOut(),'Out before In rejected');
 A::checkIn();$row=A::today($student);checkAttendance($row['status']==='present'&&$row['checkin_mode']==='simple'&&$row['check_in_time']!==null,'explicit simple In records present and server time');
 A::checkIn();checkAttendance((int)DB::val('SELECT COUNT(*) FROM attendance_records WHERE user_id=?',[$student])===1,'repeat In has one daily record');
 DB::run("UPDATE attendance_records SET status='leave' WHERE id=?",[$row['id']]);A::checkIn();checkAttendance(A::today($student)['status']==='leave','repeat In preserves manual correction');
 DB::run('UPDATE attendance_records SET check_in_time=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE id=?',[$row['id']]);checkAttendance(A::status()['state']==='checked_in','In state offers checkout');
 $out=A::checkOut();$state=A::status();checkAttendance($state['state']==='completed'&&$state['duration_seconds']>=3600&&$state['duration_seconds']<3610,'Out completes attendance with server duration');
 $repeat=A::checkOut();checkAttendance($repeat['check_out_time']===$out['check_out_time'],'repeat Out never overwrites first checkout');A::checkIn();checkAttendance(A::today($student)['check_out_time']===$out['check_out_time'],'In cannot restart completed day');
 $t=asAttendance($teacher);rejectsAttendance(fn()=>A::checkOut(),'different user cannot check out student');A::checkIn();checkAttendance(A::today($teacher)['attendance_for']==='teacher','staff simple mode records teacher');
 $a=asAttendance($admin);A::checkIn();checkAttendance(A::today($admin)['attendance_for']==='admin','staff simple mode records admin');
 DB::run("UPDATE app_settings SET setting_value='selfie' WHERE setting_key='attendance_student_mode'");checkAttendance(A::mode($a)==='simple','student policy independent from staff');
 DB::run('DELETE FROM attendance_records WHERE user_id=?',[$student]);$u=asAttendance($student);checkAttendance(A::status()['state']==='not_checked_in'&&A::today($student)===null,'selfie mode never marks on open');
 rejectsAttendance(fn()=>A::checkIn(),'selfie cannot be bypassed with simple POST');$token=A::challenge();rejectsAttendance(fn()=>A::checkIn(null,$token),'selfie requires uploaded photo');
 $_SESSION['attendance_camera']['expires']=time()-1;rejectsAttendance(fn()=>A::checkIn(null,$token),'expired capture challenge rejected');
 $fresh=A::challenge();checkAttendance($fresh!==$token&&A::challenge()===$fresh,'expired challenge renewed while valid multi-tab challenge reused');
 $image=imagecreatetruecolor(320,240);imagefill($image,0,0,imagecolorallocate($image,20,100,120));imagejpeg($image,$imagePath);imagedestroy($image);
 $bytes=A::imageBytes($imagePath);checkAttendance(str_starts_with($bytes,"\xff\xd8")&&strlen($bytes)>0,'valid camera image canonicalized to JPEG');
 file_put_contents($imagePath,'<?php echo "invalid";');rejectsAttendance(fn()=>A::imageBytes($imagePath),'non image selfie rejected');
 checkAttendance(!A::canViewSelfie(['user_id'=>$teacher,'attendance_for'=>'teacher']),'student cannot view teacher selfie');
 checkAttendance(A::canViewSelfie(['user_id'=>$student,'attendance_for'=>'student']),'student can view own evidence');
 asAttendance($teacher);checkAttendance(!A::canViewSelfie(['user_id'=>$student,'attendance_for'=>'student']),'unassigned teacher cannot view student selfie');
 asAttendance($admin);checkAttendance(A::canViewSelfie(['user_id'=>$teacher,'attendance_for'=>'teacher']),'admin may review staff evidence');
 $old=A::today($teacher);rejectsAttendance(fn()=>M::save('attendance',(int)$old['id'],['user_id'=>$teacher,'attendance_date'=>'2026-01-02','attendance_for'=>'teacher','status'=>'present']),'correction cannot move evidence to a different day');
 asAttendance($student);DB::run("INSERT INTO attendance_records (user_id,attendance_date,attendance_for,status) VALUES (?,?,'student','leave')",[$student,date('Y-m-d')]);checkAttendance(A::status()['state']==='recorded','manual leave remains a protected recorded state');rejectsAttendance(fn()=>A::checkOut(),'manual row without In cannot fabricate duration');
 echo "$passed passed; fixtures rolled back.\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();if(is_file($imagePath))unlink($imagePath);}
