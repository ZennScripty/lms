<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/autoload.php';use App\Core\Database as DB;
$id=DB::insert("INSERT INTO users (uuid,role_id,full_name,username,password_hash,preferred_language_id) VALUES (?,(SELECT id FROM roles WHERE code='student'),'QA HTTP student','qa.student.http',?,(SELECT id FROM languages WHERE code='en'))",[uuid_v4(),password_hash('QaTest@2026!',PASSWORD_DEFAULT)]);
try{
 $subject=(int)DB::val("SELECT id FROM subjects WHERE code='COPA'");DB::run('INSERT INTO student_subjects (student_id,subject_id) VALUES (?,?)',[$id,$subject]);DB::run("INSERT INTO student_week_state (student_id,subject_id,week_no,status) VALUES (?,?,1,'open')",[$id,$subject]);
 putenv('TEST_STUDENT_USERNAME=qa.student.http');putenv('TEST_STUDENT_PASSWORD=QaTest@2026!');
 passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/student_http.php'),$code);
}finally{
 DB::run('DELETE FROM attendance_records WHERE user_id=?',[$id]);DB::run('DELETE FROM audit_logs WHERE user_id=?',[$id]);DB::run('DELETE FROM student_week_state WHERE student_id=?',[$id]);DB::run('DELETE FROM student_subjects WHERE student_id=?',[$id]);DB::run('DELETE FROM users WHERE id=?',[$id]);
}
exit($code);
