<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Core\{Auth,Csrf};
use App\Services\AttendanceService as A;
Auth::requireLogin();
header('Cache-Control: private,no-store');header('X-TLMTI-Cache: no');
function attendance_response(bool $duplicate=false,?string $message=null): array {
    $status=A::status();ob_start();require APP_ROOT.'/app/Views/attendance_checkin.php';$html=(string)ob_get_clean();
    return ['ok'=>true,'duplicate'=>$duplicate,'message'=>$message?tr($message):null,'attendance'=>$status,'html'=>$html];
}
if(!is_post()){
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&request_action()==='status'&&wants_json()){json_response(attendance_response());}
    http_response_code(405);header('Allow: GET, POST');exit;
}
Csrf::verifyRequest();
try{
    $action=request_action()?:'check_in';$before=A::status();
    if($action==='check_in'){A::checkIn($_FILES['selfie']??null,is_string($_POST['camera_token']??null)?$_POST['camera_token']:null);$duplicate=$before['state']!=='not_checked_in';$message=$duplicate?'Attendance is already recorded.':'Attendance checked in.';}
    elseif($action==='check_out'){A::checkOut();$duplicate=$before['state']==='completed';$message=$duplicate?'Attendance is already completed.':'Attendance checked out.';}
    else{throw new DomainException('Unknown action.');}
    if(wants_json()){json_response(attendance_response($duplicate,$message));}flash('success',$message);
}
catch(Throwable $e){$message=handle_exception($e);if(wants_json()){json_response(['ok'=>false,'error'=>tr($message)],422);}flash('error',$message);}
redirect(($_POST['return_to']??'')==='dashboard.php'?'dashboard.php':'attendance.php');
