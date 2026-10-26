<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bootstrap.php';
use App\Core\{Auth,Csrf,Database as DB,Validator as V,Logger};
$u=Auth::requireLogin();$error=null;
if(is_post()){
    Csrf::verifyRequest();try{
        if(request_action()==='save_profile'){$name=V::str($_POST['full_name']??'','Full name',1,120);$phone=V::optStr($_POST['phone']??'','Phone',20);DB::run('UPDATE users SET full_name=?,phone=? WHERE id=?',[$name,$phone,$u['id']]);flash('success','Profile saved.');}
        elseif(request_action()==='change_password'){$hash=DB::val('SELECT password_hash FROM users WHERE id=?',[$u['id']]);if(!password_verify((string)($_POST['current_password']??''),$hash)){throw new DomainException('Current password is incorrect.');}$password=V::password($_POST['password']??'');if(!hash_equals($password,(string)($_POST['confirmation']??''))){throw new DomainException('Passwords do not match.');}DB::run('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);session_regenerate_id(true);flash('success','Password updated.');}
        else{throw new DomainException('Unknown action.');}
        Logger::audit((int)$u['id'],request_action(),'user',(int)$u['id']);redirect_self();
    }catch(Throwable $e){$error=handle_exception($e);}
}
$profile=DB::one('SELECT * FROM users WHERE id=?',[$u['id']]);page_header('Profile'); ?>
<?php if($error): ?><div class="flash flash-error"><?= e(tr($error)) ?></div><?php endif; ?>
<section class="card"><h2><?= e(tr('Profile')) ?></h2><p class="muted"><?= e(tr('Keep your account details up to date.')) ?></p><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile"><label><?= e(tr('Full name')) ?><input name="full_name" value="<?= e($profile['full_name']) ?>" required maxlength="120"></label><label><?= e(tr('Phone')) ?><input name="phone" value="<?= e($profile['phone']??'') ?>" maxlength="20"></label><div class="form-actions"><button class="btn btn-primary"><?= e(tr('Save')) ?></button></div></form></section>
<section class="card"><h2><?= e(tr('Change password')) ?></h2><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="change_password"><label><?= e(tr('Current password')) ?><input type="password" name="current_password" required autocomplete="current-password"></label><label><?= e(tr('New password')) ?><input type="password" name="password" minlength="8" maxlength="200" required autocomplete="new-password"></label><label><?= e(tr('Confirm password')) ?><input type="password" name="confirmation" minlength="8" maxlength="200" required autocomplete="new-password"></label><div class="form-actions"><button class="btn btn-primary"><?= e(tr('Change password')) ?></button></div></form></section><?php page_footer();
