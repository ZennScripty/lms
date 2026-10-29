<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/autoload.php';
use App\Core\Database as DB;
$passed=0;
function gmCheck(bool $ok,string $label): void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;$passed++;}
function gmRun(string $arg): string {
    $process=proc_open([PHP_BINARY,APP_ROOT.'/scripts/migrate-global-courses.php',$arg],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,APP_ROOT);
    if(!is_resource($process))throw new RuntimeException('Migration process unavailable.');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
    if($code!==0)throw new RuntimeException($out.$err);return $out;
}
function gmSnapshot(array $tables): array {
    $out=[];foreach($tables as $table){$row=DB::one("CHECKSUM TABLE $table");if($row['Checksum']===null)throw new RuntimeException('Checksum unavailable for '.$table);$out[$table]=[(int)DB::val("SELECT COUNT(*) FROM $table"),(string)$row['Checksum']];}return $out;
}
$legacy=['users','subjects','centres','batches','admin_centres','centre_subjects','teacher_subjects','student_subjects','student_week_state','modules','content_items','questions','tests','content_progress','module_progress','test_attempts','test_attempt_answers','certificates','activity_events','audit_logs'];
$before=gmSnapshot($legacy);$preview=json_decode(gmRun('--preview'),true,512,JSON_THROW_ON_ERROR);
gmCheck($preview['automatic_backfill']===false&&isset($preview['student_batch_candidates_review_required']),'preview explicitly requires mapping review');gmCheck(gmSnapshot($legacy)===$before,'preview preserves legacy table checksums');
gmRun('--apply');gmCheck(gmSnapshot($legacy)===$before,'migration preserves all 20 legacy table counts and checksums');
$new=['courses','course_subjects','centre_courses','batch_courses','batch_subjects','teacher_batch_subjects'];$after=gmSnapshot($new);gmRun('--apply');gmCheck(gmSnapshot($new)===$after&&gmSnapshot($legacy)===$before,'repeat migration preserves new and historical data');
gmCheck(DB::val("SELECT setting_value FROM app_settings WHERE setting_key='global_courses_schema_version'")==='1','schema marker recorded');
$refs=[
    'course_subjects'=>['courses:course_id','subjects:subject_id','users:assigned_by'],
    'centre_courses'=>['centres:centre_id','courses:course_id','users:assigned_by'],
    'batch_courses'=>['batches:batch_id','courses:course_id','users:assigned_by'],
    'batch_subjects'=>['batch_courses:batch_id,course_id','course_subjects:course_id,subject_id','users:assigned_by'],
    'teacher_batch_subjects'=>['batch_subjects:batch_id,course_id,subject_id','users:assigned_by','users:teacher_id'],
];
foreach($refs as $table=>$expected){
    $rows=DB::all("SELECT REFERENCED_TABLE_NAME AS parent,GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS cols FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL GROUP BY CONSTRAINT_NAME,REFERENCED_TABLE_NAME",[$table]);
    $actual=array_map(fn($r)=>$r['parent'].':'.$r['cols'],$rows);sort($actual);sort($expected);gmCheck($actual===$expected,$table.' foreign keys including composite relationships');
}
$placeholders=DB::in($new);gmCheck((int)DB::val("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ($placeholders) AND DELETE_RULE='CASCADE'",$new)===0,'new relationships never cascade deletion');
echo "$passed passed; additive migration verified. No legacy assignment backfill executed.\n";
