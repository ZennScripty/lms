<?php
require __DIR__.'/../app/autoload.php';use App\Core\Database as DB;
foreach(['student','teacher'] as $role){
 $username='qa.ux.'.$role;
 if(($argv[1]??'')==='cleanup'){
  $id=DB::val('SELECT id FROM users WHERE username=?',[$username]);if(!$id)continue;
  DB::run('DELETE FROM attendance_records WHERE user_id=?',[$id]);DB::run('DELETE FROM audit_logs WHERE user_id=?',[$id]);DB::run('DELETE FROM users WHERE id=?',[$id]);
 }else{
  DB::run("INSERT INTO users (uuid,role_id,full_name,username,password_hash,preferred_language_id) VALUES (?,(SELECT id FROM roles WHERE code=?),?,?,?,(SELECT id FROM languages WHERE code='en'))",[uuid_v4(),$role,'QA '.ucfirst($role).' Review',$username,password_hash('QaTest@2026!',PASSWORD_DEFAULT)]);
 }
}
echo "QA fixture action complete\n";
