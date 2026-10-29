<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;

/** Registration profile evidence uses the existing private local media storage. */
final class ProfileImageService
{
    public static function encode(string $path): string
    {
        $size=is_file($path)?filesize($path):0;
        if(!$size||$size>2*1024*1024){throw new \DomainException('Profile image must be under 2 MB.');}
        $info=@getimagesize($path);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!$info||!in_array($mime,['image/jpeg','image/png','image/webp'],true)||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)||$info[0]<32||$info[1]<32||$info[0]>4096||$info[1]>4096||$info[0]*$info[1]>12000000){throw new \DomainException('Choose a valid JPG, PNG or WebP profile image between 32 and 4096 pixels.');}
        $source=@imagecreatefromstring((string)file_get_contents($path));if(!$source){throw new \DomainException('Profile image could not be read.');}
        $scale=min(1,512/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));
        $image=imagecreatetruecolor($w,$h);imagefill($image,0,0,imagecolorallocate($image,255,255,255));imagecopyresampled($image,$source,0,0,0,0,$w,$h,$info[0],$info[1]);
        ob_start();imagejpeg($image,null,85);$bytes=(string)ob_get_clean();imagedestroy($source);imagedestroy($image);
        if(!$bytes){throw new \RuntimeException('Profile image could not be saved.');}return $bytes;
    }
    public static function save(string $bytes,int $user,int $centre,?string &$savedPath): int
    {
        $uuid=uuid_v4();$relative='uploads/profile-'.$uuid.'.jpg';$savedPath=APP_ROOT.'/storage/'.$relative;
        $dir=dirname($savedPath);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){throw new \RuntimeException('Profile image storage unavailable.');}
        if(file_put_contents($savedPath,$bytes,LOCK_EX)!==strlen($bytes)){throw new \RuntimeException('Profile image could not be saved.');}
        return DB::insert("INSERT INTO media_assets (uuid,asset_type,original_name,storage_path,mime_type,size_bytes,checksum_sha256,uploaded_by,centre_id) VALUES (?,'image','profile.jpg',?,'image/jpeg',?,?,?,?)",[$uuid,$relative,strlen($bytes),hash('sha256',$bytes),$user,$centre]);
    }
}
