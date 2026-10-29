<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/autoload.php';use App\Core\Database as DB;
$tables=['courses','subjects','content_items'];$preview=[];
foreach($tables as $table){$cols=array_column(DB::all("SHOW COLUMNS FROM $table"),'Field');$preview[$table]=['rows'=>(int)DB::val("SELECT COUNT(*) FROM $table"),'action'=>in_array('cover_asset_id',$cols,true)?'already present':'ADD nullable BIGINT UNSIGNED cover_asset_id, index and RESTRICT foreign key to media_assets(id)'];}
if(!in_array('--apply',$argv,true)){echo json_encode(['changes'=>$preview,'modules'=>'reuse existing cover_asset_id','backfill'=>false,'strict_access_activation'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;exit;}
if(!DB::val("SELECT setting_value FROM app_settings WHERE setting_key='global_courses_schema_version'"))throw new RuntimeException('Global course schema required.');
if(!DB::val("SELECT GET_LOCK('lms_content_covers',20)"))throw new RuntimeException('Migration busy.');
try{
    foreach($tables as $table){
        $cols=array_column(DB::all("SHOW COLUMNS FROM $table"),'Type','Field');
        if(!isset($cols['cover_asset_id']))DB::run("ALTER TABLE $table ADD cover_asset_id BIGINT UNSIGNED NULL, ADD KEY idx_{$table}_cover(cover_asset_id), ADD CONSTRAINT fk_{$table}_cover FOREIGN KEY(cover_asset_id) REFERENCES media_assets(id)");
        $fk=DB::all("SELECT REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='cover_asset_id' AND REFERENCED_TABLE_NAME IS NOT NULL",[$table]);
        if(count($fk)!==1||$fk[0]['REFERENCED_TABLE_NAME']!=='media_assets'||$fk[0]['REFERENCED_COLUMN_NAME']!=='id')throw new RuntimeException('Unexpected cover schema: '.$table);
    }
    DB::run("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES ('content_covers_schema_version','1')");
    echo "Cover references ready. No records backfilled, no learning history changed, strict access not activated.\n";
}finally{DB::val("SELECT RELEASE_LOCK('lms_content_covers')");}
