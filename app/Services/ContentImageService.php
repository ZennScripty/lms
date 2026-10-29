<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Auth,Authorization as A,Database as DB,Validator as V};

/** Optional covers are separate from language-specific lesson media and private profile photos. */
final class ContentImageService
{
    private const TABLES=['courses'=>'courses','subjects'=>'subjects','modules'=>'modules','lessons'=>'content_items'];
    public static function ready(): bool
    {
        return DB::val("SELECT setting_value FROM app_settings WHERE setting_key='content_covers_schema_version'")==='1';
    }
    public static function encode(string $path,string $name): array
    {
        if(!is_file($path)||filesize($path)>2097152||filesize($path)===0)throw new \DomainException('Choose an image under 2 MB.');
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);$info=@getimagesize($path);
        $types=['image/jpeg'=>['jpg','jpeg'],'image/png'=>['png'],'image/webp'=>['webp']];
        if(!isset($types[$mime])||!in_array($ext,$types[$mime],true)||!$info||$info['mime']!==$mime||min($info[0],$info[1])<32||max($info[0],$info[1])>4096||$info[0]*$info[1]>12000000)throw new \DomainException('Use a valid JPG, PNG or WebP image, 32–4096 pixels.');
        $source=@imagecreatefromstring((string)file_get_contents($path));if(!$source)throw new \DomainException('Image could not be decoded.');
        $scale=min(1,1200/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));$dest=imagecreatetruecolor($w,$h);
        imagefill($dest,0,0,imagecolorallocate($dest,255,255,255));imagecopyresampled($dest,$source,0,0,0,0,$w,$h,$info[0],$info[1]);
        $webp=function_exists('imagewebp');ob_start();$ok=$webp?imagewebp($dest,null,82):imagejpeg($dest,null,85);$bytes=(string)ob_get_clean();imagedestroy($source);imagedestroy($dest);
        if(!$ok||!$bytes)throw new \DomainException('Image conversion failed.');
        return ['bytes'=>$bytes,'mime'=>$webp?'image/webp':'image/jpeg','ext'=>$webp?'webp':'jpg'];
    }
    public static function save(string $kind,?int $id,array $input,?array $file=null): int
    {
        if(!isset(self::TABLES[$kind]))throw new \DomainException('Unknown image owner.');
        if(in_array($kind,['courses','subjects'],true)&&!A::isSuperAdmin())throw new \DomainException('Only Super Admin can edit global definitions.');
        if($kind!=='courses'){
            $old=$id?ManagementService::find($kind,$id):null;
            if($id&&!$old||!ManagementService::canWrite($kind,isset($old['subject_id'])?(int)$old['subject_id']:null))throw new \DomainException('Access denied.');
        }
        $remove=($input['remove_cover']??'')==='1';$upload=$file&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;$encoded=null;
        if($remove&&$upload)throw new \DomainException('Choose replacement or removal, not both.');
        if(($upload||$remove)&&!self::ready())throw new \DomainException('Cover image migration is awaiting approval.');
        if($upload){if(($file['error']??null)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new \DomainException('Image upload failed.');$encoded=self::encode($file['tmp_name'],(string)($file['name']??''));}
        $path=null;
        try{return DB::transaction(function()use($kind,$id,$input,$encoded,$remove,&$path){
            if($kind==='courses'){$saved=CourseAssignmentService::saveCourse($id,V::str($input['code']??'','Code',1,40),V::str($input['name']??'','Name',1,160),V::optStr($input['description']??'','Description',5000),isset($input['is_active']));}
            else{$saved=ManagementService::save($kind,$id,$input);}
            if($kind==='subjects'){
                // Old clients may preserve existing links, but cannot create an orphan subject.
                $courseIds=$input['course_ids']??($id?array_column(DB::all('SELECT course_id FROM course_subjects WHERE subject_id=? AND is_active=1',[$id]),'course_id'):[]);
                if(!is_array($courseIds))throw new \DomainException('Invalid courses.');
                CourseAssignmentService::setSubjectCourses($saved,$courseIds,($input['confirm_course_removal']??'')==='1');
            }
            if($encoded||$remove){
                $asset=null;if($encoded){$uuid=uuid_v4();$relative='uploads/cover-'.$uuid.'.'.$encoded['ext'];$path=APP_ROOT.'/storage/'.$relative;
                    if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true)&&!is_dir(dirname($path)))throw new \RuntimeException('Cover storage unavailable.');
                    if(@file_put_contents($path,$encoded['bytes'],LOCK_EX)!==strlen($encoded['bytes']))throw new \RuntimeException('Cover storage unavailable.');
                    $asset=DB::insert("INSERT INTO media_assets(uuid,asset_type,original_name,storage_path,mime_type,size_bytes,checksum_sha256,uploaded_by) VALUES (?,'image',?,?,?,?,?,?)",[$uuid,'cover.'.$encoded['ext'],$relative,$encoded['mime'],strlen($encoded['bytes']),hash('sha256',$encoded['bytes']),Auth::user()['id']]);
                }
                DB::run('UPDATE '.self::TABLES[$kind].' SET cover_asset_id=? WHERE id=?',[$asset,$saved]);
                // Retain the old immutable asset; shared references/history are never deleted here.
            }
            return $saved;
        });}catch(\Throwable $e){if($path&&is_file($path))unlink($path);throw $e;}
    }
    public static function thumbnail(?int $asset,string $title='',bool $compact=false): string
    {
        $url=$asset?'media.php?id='.$asset:'assets/icons/cover-placeholder.svg';
        return '<img class="content-cover'.($compact?' content-cover-small':'').'" src="'.e($url).'" alt="'.e($title).'" loading="lazy" width="640" height="360" data-cover-fallback>';
    }
    public static function canRead(int $asset): bool
    {
        if(!self::ready()||!Auth::user())return false;if(A::isSuperAdmin())return true;
        foreach(['subjects'=>'SELECT id AS subject_id FROM subjects WHERE cover_asset_id=?','modules'=>"SELECT subject_id FROM modules WHERE cover_asset_id=? AND status='published' AND deleted_at IS NULL",'lessons'=>"SELECT c.subject_id FROM content_items c JOIN modules m ON m.id=c.module_id WHERE c.cover_asset_id=? AND c.status='published' AND m.status='published' AND m.deleted_at IS NULL"] as $kind=>$query){
            foreach(DB::all($query,[$asset]) as $owner){$subject=(int)$owner['subject_id'];if(A::isStudent()?StudentService::enrolment((int)Auth::user()['id'],$subject)!==null:A::canAccessSubject($subject))return true;}
        }
        foreach(DB::all('SELECT c.id FROM courses c WHERE c.cover_asset_id=? AND c.is_active=1',[$asset]) as $course){
            if(A::isCentreAdmin()){$p=[];$scope=A::centreWhere('cc.centre_id',$p);$p[]=$course['id'];if(DB::val("SELECT cc.course_id FROM centre_courses cc WHERE $scope AND cc.course_id=? AND cc.is_active=1",$p))return true;}
            elseif(A::isTeacher()){foreach(AssignmentAccessService::teacherAssignments((int)Auth::user()['id']) as $t)if((int)$t['course_id']===(int)$course['id'])return true;}
            elseif(A::isStudent()){
                foreach(DB::all('SELECT subject_id FROM course_subjects WHERE course_id=? AND is_active=1',[$course['id']]) as $s)if(StudentService::enrolment((int)Auth::user()['id'],(int)$s['subject_id'])){
                    if(!AssignmentAccessService::forStudent((int)Auth::user()['id'])||DB::val('SELECT bc.course_id FROM batch_courses bc JOIN users u ON u.batch_id=bc.batch_id WHERE u.id=? AND bc.course_id=? AND bc.is_active=1',[Auth::user()['id'],$course['id']]))return true;
                }
            }
        }
        return false;
    }
}
