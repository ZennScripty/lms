<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/autoload.php';use App\Core\Database as DB;
if(!DB::val("SELECT setting_value FROM app_settings WHERE setting_key='phase1_centre_rbac_migrated'")){throw new RuntimeException('Run the Phase 1 centre RBAC migration first.');}
if(!DB::val("SELECT GET_LOCK('lms_phase3_registration',20)")){throw new RuntimeException('Registration migration is already running.');}
try{
 $columns=array_column(DB::all('SHOW COLUMNS FROM users'),'Type','Field');
 if(!isset($columns['batch_id']))DB::run('ALTER TABLE users ADD batch_id INT UNSIGNED NULL, ADD KEY idx_users_batch(batch_id), ADD CONSTRAINT fk_users_batch FOREIGN KEY(batch_id) REFERENCES batches(id)');
 if(!isset($columns['profile_asset_id']))DB::run('ALTER TABLE users ADD profile_asset_id BIGINT UNSIGNED NULL, ADD KEY idx_users_profile_asset(profile_asset_id), ADD CONSTRAINT fk_users_profile_asset FOREIGN KEY(profile_asset_id) REFERENCES media_assets(id)');
 if(!isset($columns['registration_submitted_at']))DB::run('ALTER TABLE users ADD registration_submitted_at DATETIME NULL');
 // Existing enrolment/batch history is not rewritten or guessed into a single membership.
 DB::run("INSERT IGNORE INTO app_settings (setting_key,setting_value) VALUES ('phase3_registration_migrated','1')");
 echo "Registration migration complete. Existing accounts and enrolments preserved.\n";
}finally{DB::val("SELECT RELEASE_LOCK('lms_phase3_registration')");}
