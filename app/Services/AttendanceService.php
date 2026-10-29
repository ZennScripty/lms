<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{Auth,Authorization,Database as DB,Logger};

/** Daily person-based attendance; all check-in dates and times come from the server. */
final class AttendanceService
{
    public static function mode(array $user): string
    {
        $key=$user['role_slug']==='student'?'attendance_student_mode':'attendance_staff_mode';
        // Read fresh: an Admin policy change applies immediately, including a previously opened camera form.
        $mode=DB::val('SELECT setting_value FROM app_settings WHERE setting_key=?',[$key])??'simple';
        if(!in_array($mode,['simple','selfie'],true)){throw new \DomainException('Attendance settings are invalid. Contact Admin.');}
        return $mode;
    }
    public static function today(int $user): ?array
    {
        return DB::one('SELECT * FROM attendance_records WHERE user_id=? AND attendance_date=?',[$user,date('Y-m-d')]);
    }
    /** Read-only status for this account; never takes a browser supplied user or centre ID. */
    public static function status(): array
    {
        $user=Auth::requireLogin();$row=self::today((int)$user['id']);$mode=self::mode($user);
        $state=!$row?'not_checked_in':(!empty($row['check_out_time'])?'completed':(!empty($row['check_in_time'])?'checked_in':'recorded'));
        $seconds=0;
        if(!empty($row['check_in_time'])){$seconds=max(0,(!empty($row['check_out_time'])?strtotime($row['check_out_time']):time())-strtotime($row['check_in_time']));}
        return ['user_id'=>(int)$user['id'],'date'=>date('Y-m-d'),'date_label'=>date('d M Y'),'timezone'=>date_default_timezone_get(),'mode'=>$mode,'state'=>$state,
            'label'=>match($state){'not_checked_in'=>'Not Checked In','checked_in'=>'Checked In','completed'=>'Attendance Completed',default=>'Attendance recorded'},
            'check_in_time'=>$row['check_in_time']??null,'check_out_time'=>$row['check_out_time']??null,'duration_seconds'=>$seconds,
            'duration'=>sprintf('%02d:%02d:%02d',intdiv($seconds,3600),intdiv($seconds%3600,60),$seconds%60),
            'attendance_status'=>$row['status']??null,'selfie_id'=>!empty($row['selfie_path'])?(int)$row['id']:null,
            'revision'=>hash('sha256',json_encode([(int)$user['id'],date('Y-m-d'),$mode,$state,$row['updated_at']??null,$row['status']??null,$row['check_in_time']??null,$row['check_out_time']??null]))];
    }
    public static function challenge(): string
    {
        $u=Auth::requireLogin();$old=$_SESSION['attendance_camera']??[];
        if(($old['user']??0)===(int)$u['id']&&($old['date']??'')===date('Y-m-d')&&($old['expires']??0)>time()+15){return $old['token'];}
        $token=bin2hex(random_bytes(24));
        $_SESSION['attendance_camera']=['token'=>$token,'user'=>(int)$u['id'],'date'=>date('Y-m-d'),'expires'=>time()+300];
        return $token;
    }
    /** Strict decode and canonical JPEG encoding; no original filename or metadata is kept. */
    public static function imageBytes(string $path): string
    {
        if(!is_file($path)||filesize($path)>3*1024*1024||filesize($path)<32){throw new \DomainException('Selfie must be a JPEG or PNG image under 3 MB.');}
        $info=@getimagesize($path);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!$info||!in_array($mime,['image/jpeg','image/png'],true)||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)||$info[0]<160||$info[1]<120||$info[0]>4096||$info[1]>4096||$info[0]*$info[1]>12000000){throw new \DomainException('Selfie must be a valid camera image.');}
        $image=@imagecreatefromstring((string)file_get_contents($path));if(!$image){throw new \DomainException('Selfie must be a valid camera image.');}
        $width=min(1280,imagesx($image));$height=(int)round(imagesy($image)*$width/imagesx($image));
        $copy=imagecreatetruecolor($width,$height);imagecopyresampled($copy,$image,0,0,0,0,$width,$height,imagesx($image),imagesy($image));
        ob_start();imagejpeg($copy,null,85);$bytes=(string)ob_get_clean();imagedestroy($copy);imagedestroy($image);return $bytes;
    }
    public static function checkIn(?array $file=null,?string $token=null): array
    {
        $user=Auth::requireLogin();$uid=(int)$user['id'];$day=date('Y-m-d');$mode=self::mode($user);
        if(!in_array($user['role_slug'],['student','subject_teacher','super_admin','centre_admin'],true)){throw new \DomainException('Attendance is not available for this role.');}
        if($existing=self::today($uid)){return $existing;}
        $bytes=null;
        if($mode==='selfie'){
            $challenge=$_SESSION['attendance_camera']??[];
            if(!$token||!isset($challenge['token'])||!hash_equals($challenge['token'],$token)||($challenge['user']??0)!==$uid||($challenge['date']??'')!==$day||($challenge['expires']??0)<time()){throw new \DomainException('Open the camera again before checking in.');}
            if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')){throw new \DomainException('Take a selfie before checking in.');}
            $bytes=self::imageBytes($file['tmp_name']);
        }
        $savedPath=null;
        try{
            $record=DB::transaction(function()use($uid,$day,$mode,$user,$bytes,&$savedPath){
                // One lock per person prevents duplicate check-ins and races with manual corrections.
                DB::one('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);
                if($existing=self::today($uid)){return $existing;}
                $path=null;$hash=null;
                if($bytes!==null){
                    $dir=APP_ROOT.'/storage/attendance/'.date('Y/m');if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){throw new \RuntimeException('Selfie storage unavailable.');}
                    $path='attendance/'.date('Y/m').'/'.bin2hex(random_bytes(20)).'.jpg';$savedPath=APP_ROOT.'/storage/'.$path;
                    if(file_put_contents($savedPath,$bytes,LOCK_EX)!==strlen($bytes)){throw new \RuntimeException('Selfie storage unavailable.');}$hash=hash('sha256',$bytes);
                }
                $role=match($user['role_slug']){'student'=>'student','subject_teacher'=>'teacher',default=>'admin'};
                $id=DB::insert('INSERT INTO attendance_records (user_id,attendance_date,attendance_for,status,check_in_time,marked_by,checkin_mode,selfie_path,selfie_sha256) VALUES (?,?,?,\'present\',?,?,?, ?,?)',[$uid,$day,$role,date('Y-m-d H:i:s'),$uid,$mode,$path,$hash]);
                Logger::audit($uid,'attendance_checkin','attendance_records',$id,$mode);
                return DB::one('SELECT * FROM attendance_records WHERE id=?',[$id]);
            });
            unset($_SESSION['attendance_camera']);return $record;
        }catch(\Throwable $e){if($savedPath&&is_file($savedPath)){unlink($savedPath);}throw $e;}
    }
    public static function canViewSelfie(array $record): bool
    {
        $u=Auth::user();if(!$u)return false;
        if(Authorization::isSuperAdmin()||(int)$record['user_id']===(int)$u['id'])return true;
        if(Authorization::isCentreAdmin()){return Authorization::can('attendance.view')&&Authorization::canManageUser((int)$record['user_id']);}
        return Authorization::isTeacher()&&Authorization::can('attendance.view')&&$record['attendance_for']==='student'&&Authorization::canAccessStudent((int)$record['user_id']);
    }
    public static function checkOut(): array
    {
        $user=Auth::requireLogin();$uid=(int)$user['id'];$day=date('Y-m-d');
        if(!in_array($user['role_slug'],['student','subject_teacher','super_admin','centre_admin'],true)){throw new \DomainException('Attendance is not available for this role.');}
        return DB::transaction(function()use($uid,$day){
            DB::one('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);
            $row=DB::one('SELECT * FROM attendance_records WHERE user_id=? AND attendance_date=? FOR UPDATE',[$uid,$day]);
            if(!$row||!$row['check_in_time']){throw new \DomainException('Check in before checking out.');}
            // Retry-safe: duplicate requests never replace the first saved check-out time.
            if($row['check_out_time']){return $row;}
            $now=date('Y-m-d H:i:s');if($now<$row['check_in_time']){throw new \DomainException('Check-in time is in the future. Contact Admin.');}
            DB::run('UPDATE attendance_records SET check_out_time=? WHERE id=? AND check_out_time IS NULL',[$now,$row['id']]);
            Logger::audit($uid,'attendance_checkout','attendance_records',(int)$row['id']);
            return DB::one('SELECT * FROM attendance_records WHERE id=?',[$row['id']]);
        });
    }
}
