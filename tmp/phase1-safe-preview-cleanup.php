<?php
require __DIR__.'/../app/autoload.php';use App\Core\Database as DB;use App\Services\ManagementService as M;
date_default_timezone_set('Asia/Kolkata');
$u=DB::one('SELECT * FROM users WHERE username=?',['qa.phase1.scopecheck']);
if($u&&$u['full_name']==='QA Scope Verification'&&password_verify('QaTest@2026!',$u['password_hash'])){
 $id=$u['id'];$dependencies=DB::val('SELECT (SELECT COUNT(*) FROM users WHERE created_by=?)+(SELECT COUNT(*) FROM attendance_records WHERE user_id=?)+(SELECT COUNT(*) FROM media_assets WHERE uploaded_by=?)',[$id,$id,$id]);
 if(!$dependencies){DB::transaction(function()use($id){DB::run('DELETE FROM admin_centres WHERE admin_id=?',[$id]);DB::run('DELETE FROM audit_logs WHERE user_id=?',[$id]);DB::run('DELETE FROM users WHERE id=?',[$id]);});echo "Disposable scope-check account removed\n";}
}
$root=DB::one('SELECT * FROM users WHERE username=?',['qa.phase1.root']);
if($root&&$root['deleted_at']===null&&password_verify('QaTest@2026!',$root['password_hash'])){
 $actor=DB::val("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='super_admin' AND u.status='active' AND u.deleted_at IS NULL AND u.username NOT LIKE 'qa.%' ORDER BY u.id LIMIT 1");
 $_SESSION=['uid'=>(int)$actor,'last_activity'=>time()];M::deleteUser((int)$root['id'],true);echo "Temporary Super Admin deactivated with history retained\n";
}
echo "Externally edited preview Admin and related records preserved\n";
