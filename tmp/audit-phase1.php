<?php
require __DIR__.'/../app/autoload.php';use App\Core\Database as DB;
foreach(DB::all('SHOW TABLES') as $table){$name=array_values($table)[0];echo $name.': '.implode(', ',array_map(fn($c)=>$c['Field'].' '.$c['Type'],DB::all('SHOW COLUMNS FROM `'.$name.'`')))."\n";}
echo "ROLES ".json_encode(DB::all('SELECT r.id,r.code,r.name,COUNT(u.id) AS accounts FROM roles r LEFT JOIN users u ON u.role_id=r.id AND u.deleted_at IS NULL GROUP BY r.id'))."\n";
echo "FK ".json_encode(DB::all('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'))."\n";
