<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Core\{Auth,Database as DB,Validator as V};
use App\Services\AttendanceService as A;
Auth::requireLogin();
$record=DB::one('SELECT * FROM attendance_records WHERE id=?',[V::int($_GET['id']??'','Attendance',1)]);
if(!$record||!A::canViewSelfie($record)){forbidden();}
if(!$record['selfie_path']){not_found();}
$root=realpath(APP_ROOT.'/storage/attendance');$path=realpath(APP_ROOT.'/storage/'.$record['selfie_path']);
if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)){not_found();}
header('Content-Type: image/jpeg');header('Cache-Control: private,no-store');header('X-TLMTI-Cache: no');header('Content-Length: '.filesize($path));session_write_close();readfile($path);
