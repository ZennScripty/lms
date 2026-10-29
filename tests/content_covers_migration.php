<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/autoload.php';use App\Core\Database as DB;
function coverSnapshot(): array {$result=[];foreach(['courses','subjects','modules','content_items','users','student_subjects','content_progress','module_progress','test_attempts','certificates'] as $table){$rows=DB::all("SELECT * FROM $table ORDER BY id");foreach($rows as &$row)unset($row['cover_asset_id']);unset($row);$result[$table]=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));}return $result;}
$before=coverSnapshot();
for($i=0;$i<2;$i++){$p=proc_open([PHP_BINARY,APP_ROOT.'/scripts/migrate-content-covers.php','--apply'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,APP_ROOT);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($out.$err);if(coverSnapshot()!==$before)throw new RuntimeException('Existing values changed');echo 'PASS cover migration '.($i+1).' preserves existing values'.PHP_EOL;}
if(DB::val("SELECT setting_value FROM app_settings WHERE setting_key='batch_learning_strict_access'")==='1')throw new RuntimeException('Unexpected strict activation');echo "PASS strict access remains off\n3 migration checks passed\n";
