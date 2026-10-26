<?php
use App\Core\{Auth, Authorization, Database as DB, Validator as V};
use App\Services\ManagementService as M;
if(!M::isStaff()){forbidden();} Authorization::require('reports.view');
$scope=Authorization::visibleSubjectIds();$params=[];$where="ss.status IN ('active','completed') AND u.deleted_at IS NULL";
if($scope!==null){$where.=$scope?' AND ss.subject_id IN ('.DB::in($scope).')':' AND 1=0';$params=$scope;}
$studentId=isset($_GET['id'])?V::int($_GET['id'],'Student',1):null;
if($studentId && !Authorization::canAccessStudent($studentId)){forbidden();}
if($studentId){$where.=' AND u.id=?';$params[]=$studentId;}
$subject=filter_var($_GET['subject_id']??null,FILTER_VALIDATE_INT)?:null;
if($subject){$where.=' AND ss.subject_id=?';$params[]=$subject;}
$select="SELECT u.id,u.full_name,u.username,s.name AS subject,b.name AS batch,
COALESCE((SELECT SUM(mp.completion_percent) FROM module_progress mp JOIN modules m ON m.id=mp.module_id WHERE mp.student_id=u.id AND mp.subject_id=s.id AND m.status='published' AND m.deleted_at IS NULL)/NULLIF((SELECT COUNT(*) FROM modules m WHERE m.subject_id=s.id AND m.status='published' AND m.deleted_at IS NULL),0),0) AS completion,
(SELECT ROUND(AVG(ta.percentage),1) FROM test_attempts ta WHERE ta.student_id=u.id AND ta.subject_id=s.id AND ta.result<>'pending') AS test_score,
(SELECT COUNT(*) FROM attendance_records ar WHERE ar.user_id=u.id AND ar.status='present') AS present_days
FROM student_subjects ss JOIN users u ON u.id=ss.student_id JOIN subjects s ON s.id=ss.subject_id LEFT JOIN batches b ON b.id=ss.batch_id WHERE $where";
$total=(int)DB::val("SELECT COUNT(*) FROM student_subjects ss JOIN users u ON u.id=ss.student_id WHERE $where",$params);
if(($_GET['export']??'')==='csv'){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="tlmti-progress.csv"');header('Cache-Control: no-store');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array_map('tr',['Name','Username','Subject','Batch','Completion','Score','Attendance']));
    $stmt=DB::pdo()->prepare($select.' ORDER BY u.full_name,u.id,s.id');$stmt->execute($params);
    while($r=$stmt->fetch()){$cells=[$r['full_name'],$r['username'],$r['subject'],$r['batch'],round((float)$r['completion']),$r['test_score'],$r['present_days']];$cells=array_map(static function($c){$s=(string)$c;return preg_match('/^[=+@\-\t\r]/',$s)?"'".$s:$s;},$cells);fputcsv($out,$cells);}
    fclose($out);exit;
}
$page=max(1,min(max(1,(int)ceil($total/20)),(int)($_GET['page']??1)));$offset=($page-1)*20;
$rows=DB::all($select." ORDER BY u.full_name,u.id,s.id LIMIT 20 OFFSET $offset",$params);
page_header('Reports');
?>
<div class="toolbar"><p><?= e(tr('Data is calculated from saved records.')) ?></p><a class="btn btn-primary" href="?<?= e(http_build_query(array_merge($_GET,['export'=>'csv']))) ?>"><?= icon('download') ?><?= e(tr('Export CSV')) ?></a></div>
<form method="get" class="filter-bar"><?php if($studentId): ?><input type="hidden" name="id" value="<?= $studentId ?>"><?php endif; ?><label><?= e(tr('Subject')) ?><select name="subject_id"><option value=""><?= e(tr('All')) ?></option><?php foreach(M::options('subjects') as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'],$subject??'') ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></label><button class="btn"><?= e(tr('Search')) ?></button></form>
<section class="card"><div class="table-wrap"><table><thead><tr><?php foreach(['Name','Subject','Batch','Completion','Average test score','Attendance'] as $h): ?><th><?= e(tr($h)) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><a href="student_detail.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['full_name']) ?></strong></a><br><small class="muted"><?= e($r['username']) ?></small></td><td><?= e($r['subject']) ?></td><td><?= e($r['batch']??'—') ?></td><td><?= min(100,round((float)$r['completion'])) ?>%<div class="progress-track"><div class="progress-fill w<?= (int)min(100,round((float)$r['completion'])) ?>"></div></div></td><td><?= $r['test_score']===null?'—':e((string)$r['test_score']).'%' ?></td><td><?= (int)$r['present_days'] ?> <?= e(tr('present')) ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="6" class="empty-state"><?= e(tr('No results found.')) ?></td></tr><?php endif; ?></tbody></table></div></section>
<div class="pagination"><span><?= $total ?> <?= e(tr('records')) ?></span><nav><?php foreach(['Previous'=>$page-1,'Next'=>$page+1] as $label=>$n): if($n<1||$n>ceil($total/20))continue; ?><a class="btn btn-sm" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$n]))) ?>"><?= e(tr($label)) ?></a><?php endforeach; ?></nav></div>
<?php
if($studentId){
    $ap=[$studentId];$aw='a.student_id=?';
    if($scope!==null){$aw.=$scope?' AND a.subject_id IN ('.DB::in($scope).')':' AND 1=0';$ap=array_merge($ap,$scope);}
    if($subject){$aw.=' AND a.subject_id=?';$ap[]=$subject;}
    $attemptTotal=(int)DB::val("SELECT COUNT(*) FROM test_attempts a WHERE $aw",$ap);$attemptPages=max(1,(int)ceil($attemptTotal/20));$attemptPage=max(1,min($attemptPages,(int)($_GET['attempt_page']??1)));$ao=($attemptPage-1)*20;
    $attempts=DB::all("SELECT a.*,t.title FROM test_attempts a JOIN tests t ON t.id=a.test_id WHERE $aw ORDER BY a.id DESC LIMIT 20 OFFSET $ao",$ap);
    echo '<section class="card"><h2>'.e(tr('Test attempts')).'</h2><div class="table-wrap"><table><thead><tr>';foreach(['Test title','Score','Result','Submitted'] as $h){echo '<th>'.e(tr($h)).'</th>';}echo '</tr></thead><tbody>';
    foreach($attempts as $a){echo '<tr><td>'.e($a['title']).'</td><td>'.e((string)$a['score']).' / '.e((string)$a['total_marks']).' ('.e((string)$a['percentage']).'%)</td><td><span class="pill '.e($a['result']).'">'.e(tr($a['result'])).'</span></td><td>'.e(fmt_dt($a['submitted_at'])).'</td></tr>';}
    if(!$attempts){echo '<tr><td colspan="4">'.e(tr('No test attempts yet.')).'</td></tr>';}echo '</tbody></table></div><div class="pagination"><span>'.$attemptTotal.' '.e(tr('records')).'</span><nav>';
    foreach(['Previous'=>$attemptPage-1,'Next'=>$attemptPage+1] as $label=>$n){if($n<1||$n>$attemptPages)continue;echo '<a class="btn btn-sm" href="?'.e(http_build_query(array_merge($_GET,['attempt_page'=>$n]))).'">'.e(tr($label)).'</a>';}
    echo '</nav></div></section>';
}
page_footer();
