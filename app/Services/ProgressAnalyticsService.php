<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Authorization as A,Database as DB};

final class ProgressAnalyticsService
{
    public static function summary(?int $centre=null): array
    {
        if(!A::isAdmin()||!A::can('reports.view'))forbidden();
        if($centre&&!A::canAccessCentre($centre))forbidden();
        $p=[];$where=A::centreWhere('u.centre_id',$p);
        if($centre){$where.=' AND u.centre_id=?';$p[]=$centre;}
        $source=AssignmentAccessService::studentPairsSql();
        $base="SELECT DISTINCT u.id AS student_id,u.centre_id,u.batch_id,ss.subject_id,
            COALESCE((SELECT SUM(LEAST(100,GREATEST(0,mp.completion_percent))) FROM module_progress mp JOIN modules m ON m.id=mp.module_id AND m.subject_id=ss.subject_id AND m.status='published' AND m.deleted_at IS NULL LEFT JOIN student_week_state ws ON ws.student_id=mp.student_id AND ws.subject_id=mp.subject_id AND ws.week_no=m.week_no WHERE mp.student_id=u.id AND mp.subject_id=ss.subject_id AND mp.cycle_no=COALESCE(ws.cycle_no,1))/NULLIF((SELECT COUNT(*) FROM modules m WHERE m.subject_id=ss.subject_id AND m.status='published' AND m.deleted_at IS NULL),0),0) AS completion
            FROM $source ss JOIN users u ON u.id=ss.student_id JOIN roles r ON r.id=u.role_id AND r.code='student' WHERE $where AND u.deleted_at IS NULL AND ss.status IN ('active','completed')";
        $groups=[];
        foreach(['centres'=>['centre_id','centres'],'batches'=>['batch_id','batches'],'subjects'=>['subject_id','subjects']] as $key=>[$column,$table]){
            $groups[$key]=DB::all("SELECT g.$column AS id,COALESCE(n.name,?) AS name,COUNT(DISTINCT g.student_id) AS students,ROUND(AVG(LEAST(100,g.completion)),1) AS progress FROM ($base) g LEFT JOIN $table n ON n.id=g.$column GROUP BY g.$column,n.name ORDER BY progress DESC,name",array_merge([tr('No batch selected')],$p));
        }
        $overall=DB::one("SELECT COUNT(DISTINCT student_id) AS students,COUNT(*) AS memberships,ROUND(AVG(LEAST(100,completion)),1) AS progress FROM ($base) g",$p);
        $trend=DB::all("SELECT DATE_FORMAT(t.submitted_at,'%Y-%m') AS month,ROUND(AVG(t.percentage),1) AS score,COUNT(*) AS attempts FROM test_attempts t JOIN users u ON u.id=t.student_id WHERE $where AND u.deleted_at IS NULL AND t.result<>'pending' AND t.submitted_at>=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 5 MONTH),'%Y-%m-01') GROUP BY month ORDER BY month",$p);
        return $groups+['overall'=>$overall,'trend'=>$trend];
    }
}
