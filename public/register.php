<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Core\{Auth,Csrf,Validator as V};use App\Services\RegistrationService as R;
if(Auth::user()){redirect('dashboard.php');}
header('Cache-Control: no-store');header('X-TLMTI-Cache: no');
if(!is_post()&&request_action()==='options'){
    try{$type=V::inList($_GET['account_type']??'',['student'],'Account type');$centre=V::int($_GET['centre_id']??'','Centre',1);json_response(['ok'=>true,'options'=>R::options($type,$centre)]);}
    catch(DomainException|InvalidArgumentException $e){json_response(['ok'=>false,'error'=>tr($e->getMessage())],422);}
}
$error=null;$data=[];
if(is_post()){
    if(!$_POST&&(int)($_SERVER['CONTENT_LENGTH']??0)>0){$error='Upload is too large. Choose an image under 2 MB.';http_response_code(413);}
    else{
        Csrf::verifyRequest();$data=$_POST;unset($data['password'],$data['confirmation']);
        try{
            if(request_action()!=='register'){throw new DomainException('Unknown action.');}
            R::submit($_POST,$_FILES['profile_image']??null);$_SESSION['registration_receipt']=time();redirect('register.php?submitted=1');
        }catch(Throwable $e){$error=handle_exception($e);}
    }
}
$success=($_GET['submitted']??'')==='1'&&(int)($_SESSION['registration_receipt']??0)>time()-900;
$data['batch_id']=is_scalar($data['batch_id']??null)?$data['batch_id']:'';
$type='student';
$centre=filter_var($data['centre_id']??null,FILTER_VALIDATE_INT)?:null;$options=[];
if($centre){try{$options=R::options($type,(int)$centre);}catch(DomainException $e){$centre=null;}}
$centres=R::centres();page_header('Create account',['public'=>true]);
?>
<link rel="stylesheet" href="assets/css/registration.css?v=20261027-1">
<div class="registration-layout"><section class="card registration-card"><div class="registration-heading"><span class="brand-mark"><?= icon('book') ?></span><div><h2><?= e(tr('Join TLMTI Skills LMS')) ?></h2><p class="muted"><?= e(tr('Register as a Student. Staff accounts are created by Admin.')) ?></p></div></div>
<?php if($success): ?><div class="registration-success" role="status"><h3><?= e(tr('Registration submitted')) ?></h3><p><?= e(tr('Your account is pending Admin approval. Sign in with your email after approval.')) ?></p><a class="btn btn-primary" href="login.php"><?= e(tr('Back to sign in')) ?></a></div>
<?php else: ?>
<?php if($error): ?><div class="flash flash-error" role="alert"><?= e(tr($error)) ?></div><?php endif; ?>
<?php if(!$centres): ?><p class="flash" role="status"><?= e(tr('No active centres are available. Please contact Admin.')) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="form-grid" data-registration data-loading="<?= e(tr('Loading centre options…')) ?>" data-empty="<?= e(tr('No active options are available for this centre. Contact Admin.')) ?>" data-error="<?= e(tr('Could not load centre options. Please retry.')) ?>" data-select="<?= e(tr('Select')) ?>" data-subject-required="<?= e(tr('Choose at least one subject from the selected centre.')) ?>" data-password-mismatch="<?= e(tr('Passwords do not match.')) ?>" data-upload-error="<?= e(tr('Choose a valid image under 2 MB.')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="register"><input type="hidden" name="MAX_FILE_SIZE" value="2097152">
<label><?= e(tr('Account type')) ?><select name="account_type" data-registration-type required><option value="student"<?= selected($type,'student') ?>><?= e(tr('Student')) ?></option></select></label>
<label><?= e(tr('Full name')) ?><input name="full_name" autocomplete="name" maxlength="120" value="<?= e(is_scalar($data['full_name']??null)?(string)$data['full_name']:'') ?>" required></label>
<label class="wide"><?= e(tr('Email')) ?><input name="email" type="email" autocomplete="email" maxlength="120" value="<?= e(is_scalar($data['email']??null)?(string)$data['email']:'') ?>" required><small><?= e(tr('Use this email to sign in after approval.')) ?></small></label>
<label><?= e(tr('Password')) ?><input name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required><small><?= e(tr('Use at least 8 characters.')) ?></small></label>
<label><?= e(tr('Confirm password')) ?><input name="confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></label>
<label class="wide"><?= e(tr('Profile image optional')) ?><input name="profile_image" type="file" accept="image/jpeg,image/png,image/webp" data-registration-photo><small><?= e(tr('JPG, PNG or WebP. Maximum 2 MB.')) ?></small></label><img class="registration-photo" data-registration-preview alt="<?= e(tr('Profile image preview')) ?>" hidden>
<label class="wide"><?= e(tr('Centre')) ?><select name="centre_id" data-registration-centre required><option value=""><?= e(tr('Select')) ?></option><?php foreach($centres as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($c['id'],$centre??'') ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
<label class="wide" data-registration-batch-wrap<?= $type==='teacher'?' hidden':'' ?>><?= e(tr('Batch')) ?><select name="batch_id" data-registration-batch<?= $type==='teacher'?' disabled':' required' ?>><option value=""><?= e(tr('Select')) ?></option><?php if($type==='student'): foreach($options as $o): ?><option value="<?= (int)$o['id'] ?>"<?= selected($o['id'],$data['batch_id']??'') ?>><?= e($o['name']) ?></option><?php endforeach; endif; ?></select></label>
<fieldset class="wide" data-registration-subject-wrap<?= $type==='student'?' hidden':'' ?>><legend><?= e(tr('Subjects')) ?></legend><div class="assignment-grid" data-registration-subjects><?php if($type==='teacher'): foreach($options as $o): ?><label class="check"><input type="checkbox" name="subject_ids[]" value="<?= (int)$o['id'] ?>"<?= checked(in_array((string)$o['id'],array_map('strval',is_array($data['subject_ids']??null)?array_filter($data['subject_ids'],'is_scalar'):[]),true)) ?>><?= e($o['name']) ?></label><?php endforeach; endif; ?></div></fieldset>
<p class="wide registration-options-note muted" data-registration-status role="status"><?= e(tr(!$centre?'Choose your centre to load available batches or subjects.':(!$options?'No active options are available for this centre. Contact Admin.':''))) ?></p><button type="button" class="btn" data-registration-retry hidden><?= e(tr('Retry')) ?></button>
<noscript><p><?= e(tr('Enable JavaScript to load centre batches and subjects.')) ?></p></noscript>
<div class="form-actions"><button class="btn btn-primary" type="submit" data-registration-submit<?= !$centres?' disabled':'' ?>><?= e(tr('Submit registration')) ?></button><a class="btn" href="login.php"><?= e(tr('Back to sign in')) ?></a></div>
</form><?php endif; ?></section></div><script src="assets/js/registration.js?v=20261027-1" defer></script>
<?php page_footer();
