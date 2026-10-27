<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/autoload.php';

use App\Core\Database as DB;

date_default_timezone_set('Asia/Kolkata');
$base = 'http://localhost/lms/public/';
$passed = 0;
$cookies = [];
$cookie = '';
$users = [];
$subjects = [];
$paths = [];
$original = DB::all("SELECT setting_key,setting_value,updated_by FROM app_settings WHERE setting_key IN ('attendance_student_mode','attendance_staff_mode')");
$photo = APP_ROOT . '/tmp/attendance-http.jpg';
$bad = APP_ROOT . '/tmp/attendance-http.txt';
function assertDaily(bool $ok, string $label): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
    $passed++;
}
function dailyRequest(string $path, ?array $post = null, bool $multipart = false, bool $json = false): array
{
    global $base, $cookie;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $multipart ? $post : http_build_query($post)]);
    }
    if ($json) curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) throw new RuntimeException(curl_error($ch));
    curl_close($ch);
    return [$code, $body];
}
function dailyToken(string $html, string $name = 'csrf_token'): string
{
    preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}
function loginDaily(string $role): string
{
    global $cookie, $cookies;
    $cookie = $cookies[$role] ??= tempnam(sys_get_temp_dir(), 'lms-daily-');
    [$c, $html] = dailyRequest('login.php');
    [$c] = dailyRequest('login.php', ['action' => 'login', 'login' => 'qa.daily.' . $role, 'password' => 'QaTest@2026!', 'csrf_token' => dailyToken($html)]);
    assertDaily($c === 303, $role . ' HTTP login');
    return $cookie;
}
try {
    foreach (['admin' => 'admin', 'teacher' => 'teacher', 'foreign' => 'teacher', 'student' => 'student'] as $tag => $role) {
        $users[$tag] = DB::insert("INSERT INTO users (uuid,role_id,full_name,username,password_hash,preferred_language_id) VALUES (?,(SELECT id FROM roles WHERE code=?),?,?,?,(SELECT id FROM languages WHERE code='en'))", [uuid_v4(), $role, 'QA Daily ' . $tag, 'qa.daily.' . $tag, password_hash('QaTest@2026!', PASSWORD_DEFAULT)]);
    }
    for ($i = 1; $i <= 3; $i++) $subjects[] = DB::insert('INSERT INTO subjects (code,name) VALUES (?,?)', ['QA-DAILY-' . $i, 'QA Daily Subject ' . $i]);
    $adminCookie = loginDaily('admin');
    [$c, $html] = dailyRequest('app_settings.php');
    $adminToken = dailyToken($html);
    [$c] = dailyRequest('app_settings.php', ['action' => 'attendance_settings', 'attendance_student_mode' => 'selfie', 'attendance_staff_mode' => 'simple', 'csrf_token' => $adminToken]);
    assertDaily($c === 303, 'admin saves independent attendance modes');
    foreach (['assignments' => ['teacher_id' => $users['teacher'], 'can_manage_content' => 1, 'can_manage_tests' => 1], 'enrolments' => ['student_id' => $users['student'], 'status' => 'active']] as $view => $fields) {
        $route = $view === 'assignments' ? 'subjects.php?view=assignments' : 'enrolments.php';
        [$c, $html] = dailyRequest($route);
        assertDaily(str_contains($html, 'Assign multiple subjects'), 'bulk subjects UI ' . $view);
        [$c] = dailyRequest($route, $fields + ['action' => 'assign_subjects', 'subject_ids' => array_slice($subjects, 0, 2), 'csrf_token' => dailyToken($html)]);
        assertDaily($c === 303, 'bulk subjects saved over HTTP ' . $view);
    }
    $studentCookie = loginDaily('student');
    [$c, $html] = dailyRequest('courses.php');
    assertDaily($c === 200 && str_contains($html, 'QA Daily Subject 1') && str_contains($html, 'QA Daily Subject 2') && !str_contains($html, 'QA Daily Subject 3'), 'student sees both subjects and no foreign course');
    [$c, $dashboard] = dailyRequest('dashboard.php');
    assertDaily($c === 200 && str_contains($dashboard, 'data-daily-attendance') && str_contains($dashboard, 'data-attendance-state="not_checked_in"') && str_contains($dashboard, 'data-attendance-camera'), 'student dashboard contains inline selfie In card');
    assertDaily(!str_contains($dashboard, 'class="page-title"') && str_contains($dashboard, 'class="sr-only">Dashboard'), 'large dashboard title removed with accessible heading retained');
    [$c, $status] = dailyRequest('attendance_checkin.php?action=status&user_id=' . $users['teacher'], null, false, true);
    $status = json_decode($status, true);
    assertDaily($c === 200 && $status['attendance']['user_id'] === $users['student'] && $status['attendance']['state'] === 'not_checked_in', 'status endpoint ignores foreign user id and does not check in');
    [$c] = dailyRequest('attendance_checkin.php', ['action' => 'check_out', 'csrf_token' => dailyToken($dashboard)], false, true);
    assertDaily($c === 422, 'student cannot check out before In');
    [$c, $html] = dailyRequest('attendance.php');
    $token = dailyToken($html);
    $camera = dailyToken($html, 'camera_token');
    assertDaily($c === 200 && $camera !== '' && !DB::val('SELECT id FROM attendance_records WHERE user_id=?', [$users['student']]), 'selfie page opens without automatic attendance');
    [$c] = dailyRequest('attendance_checkin.php', ['camera_token' => $camera], false, true);
    assertDaily($c === 419, 'check-in rejects missing CSRF');
    [$c] = dailyRequest('attendance_checkin.php', ['csrf_token' => $token, 'camera_token' => $camera], false, true);
    assertDaily($c === 422, 'selfie policy rejects missing image');
    file_put_contents($bad, 'this is not a camera image');
    [$c] = dailyRequest('attendance_checkin.php', ['csrf_token' => $token, 'camera_token' => $camera, 'selfie' => new CURLFile($bad, 'image/jpeg', 'selfie.jpg')], true, true);
    assertDaily($c === 422, 'fake image upload rejected');
    $img = imagecreatetruecolor(320, 240);
    imagefill($img, 0, 0, imagecolorallocate($img, 23, 101, 122));
    imagejpeg($img, $photo);
    imagedestroy($img);
    [$c, $json] = dailyRequest('attendance_checkin.php', ['csrf_token' => $token, 'camera_token' => $camera, 'attendance_date' => '2000-01-01', 'selfie' => new CURLFile($photo, 'image/jpeg', 'selfie.jpg')], true, true);
    $row = DB::one('SELECT * FROM attendance_records WHERE user_id=?', [$users['student']]);
    if (!empty($row['selfie_path'])) $paths[] = APP_ROOT . '/storage/' . $row['selfie_path'];
    assertDaily($c === 200 && ($row['checkin_mode'] ?? '') === 'selfie' && $row['attendance_date'] === date('Y-m-d') && $row['status'] === 'present', 'selfie upload records server date and present');
    $inResult = json_decode($json, true);
    assertDaily($inResult['attendance']['state'] === 'checked_in' && str_contains($inResult['html'], 'value="check_out"'), 'In response returns updated Out card without navigation');
    assertDaily(is_file(end($paths)) && hash_file('sha256', end($paths)) === $row['selfie_sha256'], 'private canonical selfie saved with checksum');
    [$c] = dailyRequest('attendance_checkin.php', ['csrf_token' => $token, 'camera_token' => $camera], false, true);
    assertDaily($c === 200 && (int)DB::val('SELECT COUNT(*) FROM attendance_records WHERE user_id=?', [$users['student']]) === 1, 'selfie retry returns same daily record');
    [$c, $image] = dailyRequest('attendance_selfie.php?id=' . $row['id']);
    assertDaily($c === 200 && str_starts_with($image, "\xff\xd8"), 'student may view own selfie');
    [$c, $denied] = dailyRequest('app_settings.php', ['action' => 'attendance_settings', 'attendance_student_mode' => 'simple', 'attendance_staff_mode' => 'simple', 'csrf_token' => $token]);
    if ($c !== 403) {
        echo substr(strip_tags($denied), 0, 700) . PHP_EOL;
    }
    assertDaily($c === 403, 'student cannot change attendance policy (HTTP ' . $c . ')');
    $teacherCookie = loginDaily('teacher');
    [$c, $html] = dailyRequest('subjects.php');
    assertDaily(str_contains($html, 'QA Daily Subject 1') && str_contains($html, 'QA Daily Subject 2') && !str_contains($html, 'QA Daily Subject 3'), 'teacher sees both assigned subjects only');
    [$c, $teacherDashboard] = dailyRequest('dashboard.php');
    assertDaily($c === 200 && str_contains($teacherDashboard, 'data-attendance-state="not_checked_in"') && !str_contains($teacherDashboard, 'class="page-title"'), 'staff dashboard starts not checked in with compact layout');
    [$c, $response] = dailyRequest('attendance_checkin.php', ['action' => 'check_in', 'csrf_token' => dailyToken($teacherDashboard), 'user_id' => $users['student'], 'centre_id' => 2147483647, 'check_in_time' => '2000-01-01 00:00:00'], false, true);
    $response = json_decode($response, true);
    $teacherRow = DB::one('SELECT * FROM attendance_records WHERE user_id=?', [$users['teacher']]);
    assertDaily($c === 200 && $response['attendance']['state'] === 'checked_in' && $teacherRow['attendance_for'] === 'teacher' && substr($teacherRow['check_in_time'], 0, 10) === date('Y-m-d'), 'staff simple In uses authenticated user and server timestamp');
    [$c, $response] = dailyRequest('attendance_checkin.php', ['action' => 'check_in', 'csrf_token' => dailyToken($teacherDashboard)], false, true);
    assertDaily($c === 200 && json_decode($response, true)['duplicate'] === true && (int)DB::val('SELECT COUNT(*) FROM attendance_records WHERE user_id=?', [$users['teacher']]) === 1, 'repeated staff In is an idempotent no-op');
    [$c] = dailyRequest('attendance_checkin.php', ['action' => 'check_out'], false, true);
    assertDaily($c === 419, 'checkout requires CSRF');
    [$c, $response] = dailyRequest('attendance_checkin.php', ['action' => 'check_out', 'csrf_token' => dailyToken($teacherDashboard), 'user_id' => $users['student'], 'check_out_time' => '2099-01-01'], false, true);
    $response = json_decode($response, true);
    assertDaily($c === 200 && $response['attendance']['state'] === 'completed' && !str_contains($response['html'], 'data-attendance-action'), 'staff Out response completes card and removes action buttons');
    $firstOut = DB::val('SELECT check_out_time FROM attendance_records WHERE id=?', [$teacherRow['id']]);
    [$c, $response] = dailyRequest('attendance_checkin.php', ['action' => 'check_out', 'csrf_token' => dailyToken($teacherDashboard)], false, true);
    assertDaily($c === 200 && json_decode($response, true)['duplicate'] === true && DB::val('SELECT check_out_time FROM attendance_records WHERE id=?', [$teacherRow['id']]) === $firstOut, 'repeated Out preserves first server checkout');
    assertDaily(DB::val('SELECT check_out_time FROM attendance_records WHERE id=?', [$row['id']]) === null, 'staff forged user id never checks out student');
    [$c] = dailyRequest('attendance_selfie.php?id=' . $row['id']);
    assertDaily($c === 200, 'assigned teacher may review student selfie');
    [$c, $html] = dailyRequest('attendance.php?edit=' . $row['id']);
    [$c] = dailyRequest('attendance.php?edit=' . $row['id'], ['action' => 'save_record', 'id' => $row['id'], 'user_id' => $users['student'], 'attendance_date' => $row['attendance_date'], 'attendance_for' => 'student', 'status' => 'late', 'remarks' => 'QA correction', 'csrf_token' => dailyToken($html)]);
    assertDaily($c === 303 && DB::val('SELECT status FROM attendance_records WHERE id=?', [$row['id']]) === 'late', 'teacher manages assigned student attendance');
    $after = DB::one('SELECT * FROM attendance_records WHERE id=?', [$row['id']]);
    assertDaily($after['selfie_path'] === $row['selfie_path'] && $after['check_in_time'] === $row['check_in_time'], 'teacher correction preserves selfie and check-in time');
    loginDaily('foreign');
    [$c] = dailyRequest('attendance_selfie.php?id=' . $row['id']);
    assertDaily($c === 403, 'unassigned teacher selfie access denied');
    [$c] = dailyRequest('attendance.php?edit=' . $row['id']);
    assertDaily($c === 404, 'unassigned teacher attendance edit denied');
    $cookie = $adminCookie;
    [$c] = dailyRequest('attendance_selfie.php?id=' . $row['id']);
    assertDaily($c === 200, 'admin may review attendance evidence');
    [$c, $html] = dailyRequest('app_settings.php');
    [$c] = dailyRequest('app_settings.php', ['action' => 'attendance_settings', 'attendance_student_mode' => 'bypass', 'attendance_staff_mode' => 'simple', 'csrf_token' => dailyToken($html)]);
    assertDaily($c === 200 && DB::val("SELECT setting_value FROM app_settings WHERE setting_key='attendance_student_mode'") === 'selfie', 'invalid policy rejected without partial save');
    $cookie = $studentCookie;
    [$c, $html] = dailyRequest('attendance.php');
    assertDaily(str_contains($html, 'Checked In') && str_contains($html, 'Late'), 'student sees corrected daily status');
    $cookie = $adminCookie;
    [$c, $html] = dailyRequest('app_settings.php');
    [$c] = dailyRequest('app_settings.php', ['action' => 'attendance_settings', 'attendance_student_mode' => 'selfie', 'attendance_staff_mode' => 'selfie', 'csrf_token' => dailyToken($html)]);
    assertDaily($c === 303, 'admin enables staff selfie independently');
    DB::run('DELETE FROM attendance_records WHERE user_id=?', [$users['foreign']]);
    $cookie = $cookies['foreign'];
    [$c, $html] = dailyRequest('attendance.php');
    assertDaily($c === 200 && dailyToken($html, 'camera_token') !== '' && !DB::val('SELECT id FROM attendance_records WHERE user_id=?', [$users['foreign']]), 'staff selfie mode requires capture without auto present');
    [$c] = dailyRequest('attendance_checkin.php', ['csrf_token' => dailyToken($html), 'camera_token' => dailyToken($html, 'camera_token'), 'user_id' => $users['student'], 'selfie' => new CURLFile($photo, 'image/jpeg', 'selfie.jpg')], true, true);
    $staffRow = DB::one('SELECT * FROM attendance_records WHERE user_id=?', [$users['foreign']]);
    if (!empty($staffRow['selfie_path'])) $paths[] = APP_ROOT . '/storage/' . $staffRow['selfie_path'];
    assertDaily($c === 200 && $staffRow['checkin_mode'] === 'selfie' && $staffRow['attendance_for'] === 'teacher', 'staff selfie checks in authenticated teacher and ignores posted user');
    [$c] = dailyRequest('attendance_selfie.php?id=' . $staffRow['id']);
    assertDaily($c === 200, 'teacher can view own staff selfie');
    [$c, $html] = dailyRequest('attendance.php');
    [$c] = dailyRequest('app_settings.php', ['action' => 'attendance_settings', 'attendance_student_mode' => 'simple', 'attendance_staff_mode' => 'simple', 'csrf_token' => dailyToken($html)]);
    assertDaily($c === 403, 'teacher cannot change Admin attendance settings');
    $cookie = $studentCookie;
    [$c] = dailyRequest('attendance_selfie.php?id=' . $staffRow['id']);
    assertDaily($c === 403, 'student cannot view staff selfie');
    [$c, $dashboard] = dailyRequest('dashboard.php');
    [$c, $response] = dailyRequest('attendance_checkin.php', ['action' => 'check_out', 'csrf_token' => dailyToken($dashboard), 'user_id' => $users['foreign']], false, true);
    $response = json_decode($response, true);
    assertDaily($c === 200 && $response['attendance']['state'] === 'completed' && $response['attendance']['check_in_time'] && $response['attendance']['check_out_time'] && is_int($response['attendance']['duration_seconds']), 'student Out returns both timestamps and working duration');
    assertDaily(DB::val('SELECT check_out_time FROM attendance_records WHERE id=?', [$staffRow['id']]) === null, 'student cannot check out another staff account');
    [$c, $dashboard] = dailyRequest('dashboard.php');
    assertDaily(str_contains($dashboard, 'data-attendance-state="completed"') && !str_contains($dashboard, 'data-attendance-action'), 'completed student dashboard has no In or Out action');
    $cookie = $cookies['anonymous'] = tempnam(sys_get_temp_dir(), 'lms-daily-anon-');
    [$c] = dailyRequest('attendance_checkin.php?action=status', null, false, true);
    assertDaily($c === 401, 'unauthenticated attendance status rejected');
    echo "$passed passed; HTTP fixtures cleaned up.\n";
} finally {
    foreach ($original as $r) DB::run('UPDATE app_settings SET setting_value=?,updated_by=? WHERE setting_key=?', [$r['setting_value'], $r['updated_by'], $r['setting_key']]);
    foreach ($users as $id) {
        DB::run('DELETE FROM attendance_records WHERE user_id=?', [$id]);
        DB::run('DELETE FROM audit_logs WHERE user_id=?', [$id]);
        DB::run('DELETE FROM student_week_state WHERE student_id=?', [$id]);
        DB::run('DELETE FROM student_subjects WHERE student_id=?', [$id]);
        DB::run('DELETE FROM teacher_subjects WHERE teacher_id=?', [$id]);
    }
    foreach (array_reverse($users) as $id) DB::run('DELETE FROM users WHERE id=?', [$id]);
    foreach ($subjects as $id) DB::run('DELETE FROM subjects WHERE id=?', [$id]);
    foreach (array_merge($cookies, $paths, [$photo, $bad]) as $file) if (is_file($file)) unlink($file);
}
