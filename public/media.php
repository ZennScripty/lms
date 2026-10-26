<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
use App\Core\{Auth,Authorization,Database as DB,Validator as V};
$u=Auth::requireLogin();$id=V::int($_GET['id']??'','Media',1);$asset=DB::one('SELECT * FROM media_assets WHERE id=?',[$id]);if(!$asset){not_found();}
$allowed=Authorization::isSuperAdmin()||(int)$asset['uploaded_by']===(int)$u['id'];
if(!$allowed){$query="SELECT COUNT(*) FROM content_item_translations t JOIN content_items c ON c.id=t.content_item_id JOIN modules m ON m.id=c.module_id WHERE t.media_asset_id=?";$p=[$id];
    if(Authorization::isTeacher()){$query.=' AND c.subject_id IN (SELECT subject_id FROM teacher_subjects WHERE teacher_id=?)';$p[]=$u['id'];}
    else{$query.=" AND c.status='published' AND m.status='published' AND c.subject_id IN (SELECT subject_id FROM student_subjects WHERE student_id=? AND status='active' AND current_week_no>=m.week_no)";$p[]=$u['id'];}
    $allowed=(int)DB::val($query,$p)>0;
}
if(!$allowed){forbidden();}
$root=realpath(APP_ROOT.'/storage/uploads');$path=realpath(APP_ROOT.'/storage/'.$asset['storage_path']);if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)){not_found();}
$size=filesize($path);$start=0;$end=$size-1;
header('Content-Type: '.$asset['mime_type']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private,no-store');header('Accept-Ranges: bytes');header('Content-Disposition: inline; filename="'.preg_replace('/[^a-zA-Z0-9._-]/','_',$asset['original_name']).'"');
if(isset($_SERVER['HTTP_RANGE'])){
    if(!preg_match('/^bytes=(\d*)-(\d*)$/',$_SERVER['HTTP_RANGE'],$m)||($m[1]===''&&$m[2]==='')){http_response_code(416);header("Content-Range: bytes */$size");exit;}
    if($m[1]===''){$start=max(0,$size-(int)$m[2]);}else{$start=(int)$m[1];$end=$m[2]===''?$end:min($end,(int)$m[2]);}
    if($start>$end||$start>=$size){http_response_code(416);header("Content-Range: bytes */$size");exit;}
    http_response_code(206);header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: '.($end-$start+1));session_write_close();$handle=fopen($path,'rb');fseek($handle,$start);$remaining=$end-$start+1;while($remaining>0&&!feof($handle)){$chunk=fread($handle,min(65536,$remaining));echo $chunk;$remaining-=strlen($chunk);}fclose($handle);
