<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
\App\Core\Auth::requireLogin();
if(!\App\Core\Authorization::isSuperAdmin()){forbidden();}
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Csrf, Database, Logger, Validator};

$user = Auth::requireLogin();
Authorization::require('settings.manage');

$defs = [
    'module_test_interval_days'  => ['Module test interval (days)', 'int', 15],
    'module_test_interval_start' => ['Interval starts at', ['first_session' => 'First valid module study session (recommended)', 'lesson_completion' => 'When all lessons are completed'], 'first_session'],
    'module_pass_percent'        => ['Module pass percent', 'int', 50],
    'module_pass_rule'           => ['Pass rule', ['gte' => 'score >= pass percent', 'gt' => 'score > pass percent'], 'gte'],
    'lesson_min_seconds'         => ['Minimum study seconds to complete a lesson', 'int', 30],
    'session_timeout_minutes'    => ['Session idle timeout (minutes)', 'int', 120],
    'login_max_attempts'         => ['Max failed logins before lockout', 'int', 5],
    'login_lockout_minutes'      => ['Login lockout window (minutes)', 'int', 15],
];

if (is_post() && request_action() === 'save_settings') {
    Csrf::verifyRequest();
    try {
        $clean = [];
        foreach ($defs as $key => [$label, $type, $default]) {
            $v = $_POST[$key] ?? $default;
            if (is_array($type)) {
                $clean[$key] = Validator::inList($v, array_keys($type), $label);
            } else {
                $max = $key === 'module_pass_percent' ? 100 : 100000;
                $clean[$key] = (string) Validator::int($v, $label, $key === 'module_test_interval_days' ? 0 : 1, $max);
            }
        }
        foreach ($clean as $k => $v) {
            Database::run('INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v]);
        }
        Logger::audit((int) $user['id'], 'settings_saved', 'app_settings');
        flash('success', 'Settings saved.');
    } catch (\Throwable $e) {
        flash('error', handle_exception($e));
    }
    redirect_self();
}

page_header(t('nav.settings', 'Settings'));
?>
<div class="card"><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
<?php foreach ($defs as $key => [$label, $type, $default]): $val = (string) setting($key, $default); ?>
    <label><?= e($label) ?>
    <?php if (is_array($type)): ?><select name="<?= e($key) ?>"><?php foreach ($type as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $val) ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <?php else: ?><input type="number" name="<?= e($key) ?>" value="<?= e($val) ?>" required><?php endif; ?></label>
<?php endforeach; ?>
<div><button class="btn btn-primary">Save settings</button></div></form></div>
<?php page_footer();
