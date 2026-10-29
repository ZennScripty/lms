<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Authorization as A,Database as DB};
final class LearningAccessPreviewService
{
    public static function report(): array
    {
        if(PHP_SAPI!=='cli'&&!A::isSuperAdmin())throw new \DomainException('Access denied.');
        $source=AssignmentAccessService::studentPairsSql(true);
        $report=[
            'strict_access_enabled'=>AssignmentAccessService::enabled(),
            'missing_batch'=>(int)DB::val("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id AND r.code='student' WHERE u.status='active' AND u.deleted_at IS NULL AND u.batch_id IS NULL"),
            'effective_batch_subject_pairs'=>(int)DB::val("SELECT COUNT(*) FROM $source effective"),
            'legacy_pairs_losing_access'=>(int)DB::val("SELECT COUNT(*) FROM student_subjects legacy JOIN users u ON u.id=legacy.student_id AND u.status='active' AND u.deleted_at IS NULL WHERE legacy.status IN ('active','completed') AND NOT EXISTS(SELECT 1 FROM $source e WHERE e.student_id=legacy.student_id AND e.subject_id=legacy.subject_id)"),
            'new_pairs_gaining_access'=>(int)DB::val("SELECT COUNT(*) FROM $source e WHERE NOT EXISTS(SELECT 1 FROM student_subjects legacy WHERE legacy.student_id=e.student_id AND legacy.subject_id=e.subject_id AND legacy.status IN ('active','completed'))"),
            'student_memberships'=>DB::all("SELECT u.id AS student_id,u.centre_id,u.batch_id FROM users u JOIN roles r ON r.id=u.role_id AND r.code='student' WHERE u.status='active' AND u.deleted_at IS NULL ORDER BY u.id"),
            'batch_subject_assignments'=>DB::all('SELECT batch_id,course_id,subject_id,is_active FROM batch_subjects ORDER BY batch_id,course_id,subject_id'),
        ];
        $report['review_hash']=hash('sha256',json_encode($report,JSON_THROW_ON_ERROR));return $report;
    }
}
