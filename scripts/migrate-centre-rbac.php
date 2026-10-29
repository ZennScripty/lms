<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/autoload.php';use App\Core\Database as DB;
date_default_timezone_set(env('APP_TIMEZONE','Asia/Kolkata'));
$lock=DB::val("SELECT GET_LOCK('lms_phase1_centre_rbac',20)");if(!$lock)throw new RuntimeException('Another RBAC migration is running.');
try{
 DB::run('CREATE TABLE IF NOT EXISTS admin_centres (admin_id BIGINT UNSIGNED NOT NULL, centre_id INT UNSIGNED NOT NULL, assigned_by BIGINT UNSIGNED NULL, assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(admin_id,centre_id), KEY idx_admin_centre(centre_id), FOREIGN KEY(admin_id) REFERENCES users(id), FOREIGN KEY(centre_id) REFERENCES centres(id), FOREIGN KEY(assigned_by) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 DB::run('CREATE TABLE IF NOT EXISTS centre_subjects (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, centre_id INT UNSIGNED NOT NULL, subject_id INT UNSIGNED NOT NULL, UNIQUE KEY uq_centre_subject(centre_id,subject_id), KEY idx_cs_subject(subject_id), FOREIGN KEY(centre_id) REFERENCES centres(id), FOREIGN KEY(subject_id) REFERENCES subjects(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 $cols=array_column(DB::all('SHOW COLUMNS FROM users'),'Type','Field');
 if(!str_contains($cols['status'],"'pending'"))DB::run("ALTER TABLE users MODIFY status ENUM('active','inactive','blocked','pending','rejected') NOT NULL DEFAULT 'active'");
 if(!isset($cols['reviewed_at']))DB::run('ALTER TABLE users ADD reviewed_at DATETIME NULL, ADD reviewed_by BIGINT UNSIGNED NULL, ADD CONSTRAINT fk_user_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id)');
 $media=array_column(DB::all('SHOW COLUMNS FROM media_assets'),'Type','Field');
 if(!isset($media['centre_id'])){
  DB::run('ALTER TABLE media_assets ADD centre_id INT UNSIGNED NULL, ADD KEY idx_media_centre(centre_id), ADD CONSTRAINT fk_media_centre FOREIGN KEY(centre_id) REFERENCES centres(id)');
  DB::run('UPDATE media_assets ma JOIN users u ON u.id=ma.uploaded_by SET ma.centre_id=u.centre_id WHERE u.centre_id IS NOT NULL');
 }
 DB::transaction(function(){
  DB::run("INSERT IGNORE INTO roles (code,name) VALUES ('super_admin','Super Admin')");
  if(!DB::val("SELECT setting_value FROM app_settings WHERE setting_key='phase1_centre_rbac_migrated'")){
   DB::run("UPDATE users SET role_id=(SELECT id FROM roles WHERE code='super_admin') WHERE role_id=(SELECT id FROM roles WHERE code='admin')");
   DB::run("UPDATE roles SET name='Admin' WHERE code='admin'");
   DB::run("INSERT IGNORE INTO centre_subjects (centre_id,subject_id) SELECT DISTINCT u.centre_id,ss.subject_id FROM student_subjects ss JOIN users u ON u.id=ss.student_id WHERE u.centre_id IS NOT NULL");
   DB::run("INSERT IGNORE INTO centre_subjects (centre_id,subject_id) SELECT DISTINCT u.centre_id,ts.subject_id FROM teacher_subjects ts JOIN users u ON u.id=ts.teacher_id WHERE u.centre_id IS NOT NULL");
   DB::run("INSERT INTO app_settings (setting_key,setting_value) VALUES ('phase1_centre_rbac_migrated','1')");
  }
  foreach(['students.view','students.manage','subjects.view','subjects.manage','modules.view','modules.manage','tests.manage','attendance.mark','attendance.view','reports.view'] as $code){
   DB::run('INSERT IGNORE INTO permissions (code,description) VALUES (?,?)',[$code,$code]);
   DB::run("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code=? WHERE r.code='admin'",[$code]);
  }
 });
 echo "Phase 1 RBAC migration complete. Existing global Admin privileges preserved as Super Admin; no learning records deleted.\n";
}finally{DB::val("SELECT RELEASE_LOCK('lms_phase1_centre_rbac')");}
