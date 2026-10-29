<?php
require __DIR__.'/../app/autoload.php';use App\Core\{Database as DB,Auth};use App\Services\ManagementService as M;
date_default_timezone_set('Asia/Kolkata');
if(($argv[1]??'')==='cleanup'){
 $ids=array_column(DB::all("SELECT id FROM users WHERE username IN ('qa.phase1.root','qa.phase1.admin','qa.phase1.studentA','qa.phase1.studentB')"),'id');
 foreach($ids as $id){DB::run('DELETE FROM admin_centres WHERE admin_id=?',[$id]);DB::run('DELETE FROM attendance_records WHERE user_id=?',[$id]);DB::run('DELETE FROM audit_logs WHERE user_id=?',[$id]);DB::run('UPDATE users SET created_by=NULL WHERE id=?',[$id]);}
 foreach(array_reverse($ids) as $id)DB::run('DELETE FROM users WHERE id=?',[$id]);DB::run("DELETE FROM centres WHERE name IN ('QA Phase1 Alpha','QA Phase1 Beta')");echo "Preview removed\n";exit;
}
$root=DB::insert("INSERT INTO users (uuid,role_id,full_name,username,password_hash,preferred_language_id) VALUES (?,(SELECT id FROM roles WHERE code='super_admin'),'QA Phase1 Super Admin','qa.phase1.root',?,(SELECT id FROM languages WHERE code='en'))",[uuid_v4(),password_hash('QaTest@2026!',PASSWORD_DEFAULT)]);$_SESSION=['uid'=>$root,'last_activity'=>time()];
$a=M::save('centres',null,['name'=>'QA Phase1 Alpha','is_active'=>1]);$b=M::save('centres',null,['name'=>'QA Phase1 Beta','is_active'=>1]);
foreach([['admin','admin',$a],['studentA','student',$a],['studentB','student',$b]] as [$tag,$role,$centre]){
 $input=['full_name'=>'QA Phase1 '.$tag,'username'=>'qa.phase1.'.$tag,'role_id'=>DB::val('SELECT id FROM roles WHERE code=?',[$role]),'centre_id'=>$centre,'password'=>'QaTest@2026!','status'=>'active'];if($role==='admin')$input['centre_ids']=[$a];M::save('users',null,$input);
}
echo "Preview setup complete\n";
