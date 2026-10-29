<?php
declare(strict_types=1);
use App\Core\{Auth,Authorization,Csrf,Validator};
use App\Services\{StudentService as S,SyncService};
if(Authorization::isStudent()){
    $accessService=\App\Services\AssignmentAccessService::class;
    header('X-TLMTI-Access: '.hash('sha256',json_encode([$accessService::forStudent((int)Auth::user()['id']),Auth::user()['batch_id'],$accessService::forStudent((int)Auth::user()['id'])?$accessService::studentSubjectIds((int)Auth::user()['id']):[]])));
    if(current_page()==='attendance.php'){header('X-TLMTI-Cache: no');}
    Authorization::require('learn.access');$student=(int)Auth::user()['id'];$page=current_page();
    $views=['dashboard.php'=>'dashboard','courses.php'=>'courses','subjects.php'=>'courses','modules.php'=>'courses','lesson.php'=>'lesson','tests.php'=>'tests','progress.php'=>'progress','student_detail.php'=>'progress','attendance.php'=>'attendance','offline_downloads.php'=>'downloads','sync_status.php'=>'sync'];
    if(!isset($views[$page])){
        if(in_array($page,['profile.php','notifications.php'],true)){return;}
        forbidden();
    }
    if($page==='student_detail.php'&&isset($_GET['id'])&&(int)$_GET['id']!==$student){forbidden();}
    if(is_post()){
        Csrf::verifyRequest();$action=request_action();
        if($action==='sync_push'&&in_array($page,['lesson.php','tests.php'],true)){
            try{
                $results=[];foreach(SyncService::parseEvents() as $event){$uuid='';try{$uuid=Validator::uuid($event['event_uuid']??null);$payload=is_array($event['payload']??null)?$event['payload']:[];
                    if($page==='lesson.php'&&($event['type']??'')==='study'){$result=S::study($student,$payload,$uuid,!empty($event['queued_offline']));}
                    elseif($page==='tests.php'&&($event['type']??'')==='test_submit'){$result=S::submitTest($student,Validator::int($payload['attempt_id']??'','Attempt',1),is_array($payload['answers']??null)?$payload['answers']:[],!empty($event['queued_offline']));}
                    else{throw new DomainException('Unsupported event.');}$results[]=['event_uuid'=>$uuid,'status'=>!empty($result['duplicate'])?'duplicate':'accepted','data'=>$result];
                }catch(DomainException|InvalidArgumentException $e){$results[]=['event_uuid'=>$uuid,'status'=>'rejected','message'=>tr($e->getMessage())];}}
                json_response(['ok'=>true,'results'=>$results]);
            }catch(Throwable $e){json_response(['ok'=>false,'error'=>tr(handle_exception($e))],400);}
        }
        if($page==='lesson.php'&&$action==='study'){
            try{$result=S::study($student,$_POST,Validator::uuid($_POST['event_uuid']??null),false);json_response(['ok'=>true,'data'=>$result]);}catch(Throwable $e){json_response(['ok'=>false,'error'=>tr(handle_exception($e))],400);}
        }
        if($page!=='tests.php'){forbidden();}
    }
    header('X-TLMTI-User: '.$student);header('X-TLMTI-Role: student');
    $studentView=$views[$page];require APP_ROOT.'/app/Views/student_'.$studentView.'.php';exit;
}
