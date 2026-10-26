<?php
use App\Core\{Auth, Authorization, Csrf, Database as DB, Validator as V, Logger};
if(!Authorization::isSuperAdmin()){forbidden();}
$fields=['default_pass_percent'=>['Default pass %',50,1,100],'lesson_min_seconds'=>['Minimum study seconds',30,1,3600],'session_timeout_minutes'=>['Session timeout (minutes)',120,5,1440],'login_max_attempts'=>['Login attempt limit',5,3,20],'login_lockout_minutes'=>['Login lockout (minutes)',15,1,1440]];
$error=null;
if(is_post()){
    Csrf::verifyRequest();try{$clean=[];foreach($fields as $key=>[$label,$default,$min,$max]){$clean[$key]=V::int($_POST[$key]??'',$label,$min,$max);}DB::transaction(function()use($clean){foreach($clean as $k=>$v){DB::run('INSERT INTO app_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)',[$k,$v,Auth::user()['id']]);}});Logger::audit((int)Auth::user()['id'],'settings_saved','app_settings');flash('success','Settings saved.');redirect_self();}catch(Throwable $e){$error=handle_exception($e);}
}
page_header('Settings');if($error){echo '<div class="flash flash-error">'.e(tr($error)).'</div>';}
?><section class="card"><h2><?= e(tr('Security & access')) ?></h2><form method="post" class="form-grid"><?= csrf_field() ?><?php foreach($fields as $key=>[$label,$default,$min,$max]): ?><label><?= e(tr($label)) ?><input type="number" name="<?= e($key) ?>" min="<?= $min ?>" max="<?= $max ?>" value="<?= e((string)setting($key,$default)) ?>" required></label><?php endforeach; ?><div class="form-actions"><button class="btn btn-primary"><?= e(tr('Save settings')) ?></button></div></form></section><section class="card"><h2><?= e(tr('Language')) ?></h2><p class="muted"><?= e(tr('Only Hindi and English are available.')) ?></p><a class="btn" href="language_settings.php"><?= e(tr('Language')) ?></a></section><?php page_footer();
