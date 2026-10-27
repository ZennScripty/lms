<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/autoload.php';
use App\Core\Database as DB;
$columns=array_column(DB::all('SHOW COLUMNS FROM attendance_records'),'Type','Field');
foreach(['checkin_mode'=>"ENUM('manual','simple','selfie') NOT NULL DEFAULT 'manual'",'selfie_path'=>'VARCHAR(255) NULL','selfie_sha256'=>'CHAR(64) NULL'] as $name=>$type){
 if(!isset($columns[$name])){DB::run("ALTER TABLE attendance_records ADD COLUMN `$name` $type");echo "Added $name\n";}
}
if(!str_contains($columns['attendance_for'],"'admin'")){DB::run("ALTER TABLE attendance_records MODIFY attendance_for ENUM('student','teacher','admin') NOT NULL");echo "Enabled admin staff attendance\n";}
foreach(['attendance_student_mode','attendance_staff_mode'] as $key){DB::run("INSERT IGNORE INTO app_settings (setting_key,setting_value) VALUES (?,'simple')",[$key]);}
echo "Attendance check-in migration complete. Existing records preserved.\n";
