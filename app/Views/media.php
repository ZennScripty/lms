<?php
use App\Core\{Auth, Authorization, Csrf, Database as DB, Logger};
use App\Services\ManagementService as M;
if(!M::isStaff()){forbidden();}$canUpload=M::canWrite('lessons');$error=null;
if(is_post()){
    Csrf::verifyRequest();if(!$canUpload){forbidden();}
    $path=null;
    try{
        $file=$_FILES['media']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK){throw new DomainException('Upload failed. Check the PHP upload size limit.');}
        if($file['size']>50*1024*1024){throw new DomainException('File must be smaller than 50 MB.');}
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed=['image/jpeg'=>['image','jpg'],'image/png'=>['image','png'],'image/webp'=>['image','webp'],'application/pdf'=>['pdf','pdf'],'video/mp4'=>['video','mp4'],'audio/mpeg'=>['audio','mp3'],'audio/ogg'=>['audio','ogg'],'audio/x-wav'=>['audio','wav']];
        if(!isset($allowed[$mime])){throw new DomainException('Upload a JPG, PNG, WebP, PDF, MP4, MP3, OGG or WAV file.');}
        [$type,$ext]=$allowed[$mime];$uuid=uuid_v4();$relative='uploads/'.$uuid.'.'.$ext;$path=APP_ROOT.'/storage/'.$relative;
        if(!is_dir(dirname($path))&&!mkdir(dirname($path),0770,true)){throw new RuntimeException('Storage directory unavailable');}
        if(!move_uploaded_file($file['tmp_name'],$path)){throw new RuntimeException('File could not be saved');}
        $id=DB::insert('INSERT INTO media_assets (uuid,asset_type,original_name,storage_path,mime_type,size_bytes,checksum_sha256,uploaded_by) VALUES (?,?,?,?,?,?,?,?)',[$uuid,$type,mb_substr(basename($file['name']),0,255),$relative,$mime,filesize($path),hash_file('sha256',$path),Auth::user()['id']]);
        Logger::audit((int)Auth::user()['id'],'media_uploaded','media_assets',$id);flash('success','File uploaded.');redirect_self();
    }catch(Throwable $e){if($path&&is_file($path)){unlink($path);}$error=handle_exception($e);}
}
$where=Authorization::isSuperAdmin()?'1=1':'uploaded_by=?';$params=Authorization::isSuperAdmin()?[]:[Auth::user()['id']];$total=(int)DB::val("SELECT COUNT(*) FROM media_assets WHERE $where",$params);$pages=max(1,(int)ceil($total/20));$page=max(1,min($pages,(int)($_GET['page']??1)));$offset=($page-1)*20;$files=DB::all("SELECT * FROM media_assets WHERE $where ORDER BY id DESC LIMIT 20 OFFSET $offset",$params);
page_header('Media library'); ?><nav class="content-tabs"><a class="btn" href="?view=modules"><?= e(tr('Modules')) ?></a><a class="btn" href="?view=lessons"><?= e(tr('Lessons')) ?></a><a class="btn active" href="?view=media"><?= e(tr('Media library')) ?></a></nav>
<?php if($error): ?><div class="flash flash-error"><?= e(tr($error)) ?></div><?php endif; ?>
<?php if($canUpload): ?><section class="card"><h2><?= e(tr('Upload file')) ?></h2><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><label><?= e(tr('File')) ?><input name="media" type="file" accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,audio/mpeg,audio/ogg,audio/wav" required></label><p class="status-note">JPG, PNG, WebP, PDF, MP4, MP3, OGG, WAV · <?= e(tr('Maximum file size')) ?>: <?= e(ini_get('upload_max_filesize')) ?></p><button class="btn btn-primary"><?= icon('plus') ?><?= e(tr('Upload')) ?></button></form></section><?php endif; ?>
<section class="card"><div class="table-wrap"><table><thead><tr><?php foreach(['File','Type','Size','Actions'] as $h): ?><th><?= e(tr($h)) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($files as $file): ?><tr><td><?= e($file['original_name']) ?></td><td><?= e($file['asset_type']) ?></td><td><?= number_format($file['size_bytes']/1024,1) ?> KB</td><td><a class="btn btn-sm" href="media.php?id=<?= (int)$file['id'] ?>"><?= e(tr('Preview')) ?></a></td></tr><?php endforeach; ?></tbody></table></div></section><div class="pagination"><span><?= $total ?> <?= e(tr('records')) ?></span><nav><?php if($page>1): ?><a class="btn" href="?view=media&page=<?= $page-1 ?>"><?= e(tr('Previous')) ?></a><?php endif; ?><?php if($page<$pages): ?><a class="btn" href="?view=media&page=<?= $page+1 ?>"><?= e(tr('Next')) ?></a><?php endif; ?></nav></div><?php page_footer();
