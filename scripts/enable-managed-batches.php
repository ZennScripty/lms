<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/autoload.php';use App\Core\Database as DB;
$preview=['managed_batch_students'=>(int)DB::val("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id AND r.code='student' WHERE u.status='active' AND u.deleted_at IS NULL AND EXISTS(SELECT 1 FROM batch_subjects managed WHERE managed.batch_id=u.batch_id)"),'students_without_batch_preserved'=>(int)DB::val("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id AND r.code='student' WHERE u.status='active' AND u.deleted_at IS NULL AND u.batch_id IS NULL"),'global_strict_activation'=>false,'automatic_mapping_backfill'=>false];
if(!in_array('--apply',$argv,true)){echo json_encode($preview,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;exit;}
if(!DB::val("SELECT setting_value FROM app_settings WHERE setting_key='global_courses_schema_version'"))throw new RuntimeException('Global course schema required.');
DB::transaction(function(){DB::run("INSERT INTO app_settings(setting_key,setting_value) VALUES ('batch_learning_managed_rollout','1') ON DUPLICATE KEY UPDATE setting_value='1'");});
echo "Configured-batch rollout enabled. Legacy unmanaged batches remain compatible; global strict mode unchanged.\n";
