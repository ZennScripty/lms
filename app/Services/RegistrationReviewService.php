<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Authorization as A, Database as DB, Validator as V};

final class RegistrationReviewService
{
    public static function guard(): void
    {
        if(!A::isAdmin()){throw new \DomainException('Access denied.');}
        ManagementService::checkSection('users');
    }
    private static function scope(array &$params): string
    {
        return "u.deleted_at IS NULL AND r.code IN ('student','teacher') AND (u.registration_submitted_at IS NOT NULL OR u.status IN ('pending','rejected')) AND ".A::centreWhere('u.centre_id',$params);
    }
    public static function find(int $id): ?array
    {
        self::guard();$p=[];$where=self::scope($p);$p[]=$id;
        return DB::one("SELECT u.*,r.code AS account_type,c.name AS centre_name,b.name AS batch_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN centres c ON c.id=u.centre_id LEFT JOIN batches b ON b.id=u.batch_id AND b.centre_id=u.centre_id WHERE $where AND u.id=?",$p);
    }
    public static function listing(array $input): array
    {
        self::guard();$status=V::inList($input['status']??'pending',['pending','active','rejected','all'],'Status');$type=V::inList($input['type']??'all',['all','student','teacher'],'Account type');
        $centre=V::optInt($input['centre_id']??null,'Centre');$q=V::optStr($input['q']??null,'Search',120)??'';
        $p=[];$where=self::scope($p);
        if($status!=='all'){$where.=' AND u.status=?';$p[]=$status;}
        if($type!=='all'){$where.=' AND r.code=?';$p[]=$type;}
        if($centre){$where.=' AND u.centre_id=?';$p[]=$centre;}
        if($q!==''){$where.=' AND (u.full_name LIKE ? OR u.email LIKE ?)';$p[]='%'.$q.'%';$p[]='%'.$q.'%';}
        $joins=' FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN centres c ON c.id=u.centre_id LEFT JOIN batches b ON b.id=u.batch_id AND b.centre_id=u.centre_id';
        $total=(int)DB::val("SELECT COUNT(*) $joins WHERE $where",$p);$pages=max(1,(int)ceil($total/20));$page=max(1,min($pages,V::int($input['page']??1,'Page',1)));$offset=($page-1)*20;
        $rows=DB::all("SELECT u.*,r.code AS account_type,c.name AS centre_name,b.name AS batch_name $joins WHERE $where ORDER BY COALESCE(u.registration_submitted_at,u.created_at) DESC,u.id DESC LIMIT 20 OFFSET $offset",$p);
        return compact('rows','total','pages','page','status','type','centre','q');
    }
    public static function act(int $id,string $decision,bool $confirmed=false): void
    {
        self::guard();
        // Lock eligibility and centre ownership in the same transaction as existing review actions.
        DB::transaction(function()use($id,$decision,$confirmed){
            DB::one('SELECT id FROM users WHERE id=? FOR UPDATE',[$id]);
            if(!self::find($id)){throw new \DomainException('Record not found.');}
            if($decision==='delete'){ManagementService::deleteUser($id,$confirmed);}
            else{ManagementService::reviewUser($id,$decision);}
        });
    }
}
