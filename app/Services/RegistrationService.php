<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Database as DB,Validator as V,Logger};

final class RegistrationService
{
    public static function centres(): array
    {
        return DB::all('SELECT id,name FROM centres WHERE is_active=1 ORDER BY name,id');
    }
    public static function options(string $type,int $centre): array
    {
        V::inList($type,['student'],'Account type');
        if(!DB::val('SELECT id FROM centres WHERE id=? AND is_active=1',[$centre])){throw new \DomainException('Choose an active centre.');}
        return $type==='student'?DB::all('SELECT id,name FROM batches WHERE centre_id=? AND is_active=1 ORDER BY name,id',[$centre]):DB::all('SELECT s.id,s.name FROM centre_subjects cs JOIN subjects s ON s.id=cs.subject_id AND s.is_active=1 WHERE cs.centre_id=? ORDER BY s.name,s.id',[$centre]);
    }
    /** Same relationship validation at submission and approval; IDs never confer authority. */
    private static function relationships(string $type,int $centre,?int $batch,array $subjects): void
    {
        $options=$type==='teacher'?array_map('intval',array_column(DB::all('SELECT s.id FROM centre_subjects cs JOIN subjects s ON s.id=cs.subject_id AND s.is_active=1 JOIN centres c ON c.id=cs.centre_id AND c.is_active=1 WHERE cs.centre_id=?',[$centre]),'id')):array_map('intval',array_column(self::options($type,$centre),'id'));
        if(DB::pdo()->inTransaction()&&!DB::val('SELECT id FROM centres WHERE id=? AND is_active=1 FOR UPDATE',[$centre])){throw new \DomainException('Choose an active centre.');}
        if($type==='student'){
            if(!$batch||!in_array($batch,$options,true)){throw new \DomainException('Choose an active batch from the selected centre.');}
            if(DB::pdo()->inTransaction()&&!DB::val('SELECT id FROM batches WHERE id=? AND centre_id=? AND is_active=1 FOR UPDATE',[$batch,$centre])){throw new \DomainException('Choose an active batch from the selected centre.');}
            if($subjects){throw new \DomainException('Subject selection is only for Teacher registration.');}
        }else{
            if($batch){throw new \DomainException('Batch selection is only for Student registration.');}
            if(!$subjects){throw new \DomainException('Choose at least one subject from the selected centre.');}
            foreach($subjects as $id){if(!in_array($id,$options,true)){throw new \DomainException('Choose subjects from the selected centre only.');}}
            if(DB::pdo()->inTransaction()){foreach($subjects as $id){if(!DB::val('SELECT s.id FROM subjects s JOIN centre_subjects cs ON cs.subject_id=s.id WHERE s.id=? AND cs.centre_id=? AND s.is_active=1 FOR UPDATE',[$id,$centre])){throw new \DomainException('Choose subjects from the selected centre only.');}}}
        }
    }
    public static function submit(array $input,?array $file=null): int
    {
        $type=V::inList($input['account_type']??'',['student'],'Account type');
        $name=V::str($input['full_name']??'','Full name',1,120);$email=V::email($input['email']??'');if(strlen($email)>120){throw new \DomainException('Email must be at most 120 characters.');}
        $password=V::password($input['password']??'');if(strlen($password)>72){throw new \DomainException('Password is too long.');}
        if(!is_string($input['confirmation']??null)||!hash_equals($password,$input['confirmation'])){throw new \DomainException('Passwords do not match.');}
        $centre=V::int($input['centre_id']??'','Centre',1);$batch=V::optInt($input['batch_id']??null,'Batch');
        $raw=$input['subject_ids']??[];if(!is_array($raw)||count($raw)>50){throw new \DomainException('Choose up to 50 subjects.');}
        $subjects=[];foreach($raw as $id)$subjects[]=V::int($id,'Subject',1);$subjects=array_values(array_unique($subjects));
        self::relationships($type,$centre,$batch,$subjects);
        $image=null;if($file&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            if(($file['error']??null)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')){throw new \DomainException('Profile image upload failed. Choose the file again.');}
            $image=ProfileImageService::encode($file['tmp_name']);
        }
        $savedPath=null;$ip=client_ip();$lock='lms-register:'.substr(hash('sha256',$ip),0,40);
        if(!DB::val('SELECT GET_LOCK(?,5)',[$lock])){throw new \DomainException('Registration is busy. Please try again.');}
        try{
            if((int)DB::val("SELECT COUNT(*) FROM audit_logs WHERE action='registration_submitted' AND ip_address=? AND created_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)",[$ip])>=10){throw new \DomainException('Too many registrations. Please try again later.');}
            return DB::transaction(function()use($type,$name,$email,$password,$centre,$batch,$subjects,$image,&$savedPath){
                // Recheck under centre lock so a stale dropdown cannot bypass an inactivated centre.
                if(!DB::val('SELECT id FROM centres WHERE id=? AND is_active=1 FOR UPDATE',[$centre])){throw new \DomainException('Choose an active centre.');}
                self::relationships($type,$centre,$batch,$subjects);
                if(DB::val('SELECT id FROM users WHERE email=? OR username=? LIMIT 1',[$email,$email])){throw new \DomainException('This email is already registered. Sign in or contact Admin.');}
                $role=(int)DB::val('SELECT id FROM roles WHERE code=?',[$type]);if(!$role){throw new \RuntimeException('Registration role unavailable.');}
                $language=TranslationService::currentCode();
                $id=DB::insert("INSERT INTO users (uuid,role_id,centre_id,batch_id,full_name,username,email,password_hash,status,registration_submitted_at,preferred_language_id) VALUES (?,?,?,?,?,?,?,?,'pending',NOW(),(SELECT id FROM languages WHERE code=?))",[uuid_v4(),$role,$centre,$batch,$name,'reg.'.bin2hex(random_bytes(12)),$email,password_hash($password,PASSWORD_DEFAULT),$language]);
                foreach($subjects as $subject)DB::run('INSERT INTO teacher_subjects (teacher_id,subject_id,can_manage_content,can_manage_tests) VALUES (?,?,0,0)',[$id,$subject]);
                if($image!==null){$asset=ProfileImageService::save($image,$id,$centre,$savedPath);DB::run('UPDATE users SET profile_asset_id=? WHERE id=?',[$asset,$id]);}
                $recipients=DB::all("SELECT DISTINCT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status='active' AND u.deleted_at IS NULL AND (r.code='super_admin' OR r.code='admin' AND EXISTS (SELECT 1 FROM admin_centres ac WHERE ac.admin_id=u.id AND ac.centre_id=?))",[$centre]);
                foreach($recipients as $recipient)DB::run("INSERT INTO notifications (user_id,type,title,body,data) VALUES (?,'registration','Registration pending approval','A new registration needs review.',?)",[$recipient['id'],json_encode(['user_id'=>$id,'centre_id'=>$centre],JSON_THROW_ON_ERROR)]);
                Logger::audit($id,'registration_submitted','users',$id,$type);return $id;
            });
        }catch(\PDOException $e){if($savedPath&&is_file($savedPath))unlink($savedPath);if(($e->errorInfo[1]??0)===1062){throw new \DomainException('This email is already registered. Sign in or contact Admin.');}throw $e;}
        catch(\Throwable $e){if($savedPath&&is_file($savedPath))unlink($savedPath);throw $e;}
        finally{DB::val('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public static function validateApproval(array $user): void
    {
        if(empty($user['registration_submitted_at']))return;
        $type=(string)DB::val('SELECT code FROM roles WHERE id=?',[$user['role_id']]);
        V::inList($type,['student','teacher'],'Account type');
        $subjects=$type==='teacher'?array_map('intval',array_column(DB::all('SELECT subject_id FROM teacher_subjects WHERE teacher_id=?',[$user['id']]),'subject_id')):[];
        self::relationships($type,(int)$user['centre_id'],isset($user['batch_id'])?(int)$user['batch_id']:null,$subjects);
    }
}
