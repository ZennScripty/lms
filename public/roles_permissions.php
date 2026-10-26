<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Csrf, Database, Logger, Validator};

$user = Auth::requireLogin();
Authorization::require('roles.manage');
$catalog = require APP_ROOT . '/app/Config/permissions.php';

if (is_post()) {
    Csrf::verifyRequest();
    try {
        $action = request_action();
        if ($action === 'register_permissions') {
            foreach ($catalog['permissions'] as $code => $desc) {
                Database::run('INSERT INTO permissions (code, description) VALUES (?,?) ON DUPLICATE KEY UPDATE description = VALUES(description)', [$code, $desc]);
            }
            flash('success', 'Permissions registered.');
        } elseif ($action === 'apply_defaults') {
            foreach ($catalog['defaults'] as $slug => $codes) {
                $rid = Database::val('SELECT id FROM roles WHERE slug = ?', [$slug]);
                if (!$rid) {
                    continue;
                }
                foreach ($codes as $code) {
                    Database::run('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?', [$rid, $code]);
                }
            }
            flash('success', 'Default role permissions applied (existing grants kept).');
        } elseif ($action === 'create_role') {
            $name = Validator::str($_POST['name'] ?? '', 'Role name', 2, 60);
            $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
            if ($slug === '' || $slug === 'super_admin') {
                throw new InvalidArgumentException('Invalid role name.');
            }
            Database::run('INSERT INTO roles (name, slug) VALUES (?,?)', [$name, $slug]);
            flash('success', 'Role created.');
        } elseif ($action === 'save_role_permissions') {
            $rid = Validator::int($_POST['role_id'] ?? '', 'Role', 1);
            $role = Database::one('SELECT id, slug FROM roles WHERE id = ?', [$rid]);
            if (!$role || $role['slug'] === 'super_admin') {
                throw new DomainException('This role cannot be edited.');
            }
            $valid = array_column(Database::all('SELECT id FROM permissions'), 'id');
            $ids = array_values(array_intersect(Validator::intList($_POST['permission_ids'] ?? []), array_map('intval', $valid)));
            Database::transaction(static function () use ($rid, $ids) {
                Database::run('DELETE FROM role_permissions WHERE role_id = ?', [$rid]);
                foreach ($ids as $pid) {
                    Database::run('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)', [$rid, $pid]);
                }
            });
            Logger::audit((int) $user['id'], 'role_permissions_saved', 'role', $rid);
            flash('success', 'Permissions saved.');
        }
    } catch (\Throwable $e) {
        flash('error', handle_exception($e));
    }
    redirect_self();
}

$roles = Database::all('SELECT * FROM roles ORDER BY id');
$perms = Database::all('SELECT * FROM permissions ORDER BY code');
$granted = [];
foreach (Database::all('SELECT role_id, permission_id FROM role_permissions') as $r) {
    $granted[(int) $r['role_id']][(int) $r['permission_id']] = true;
}
page_header(t('nav.roles', 'Roles & permissions'));
?>
<div class="card">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="register_permissions"><button class="btn">Register default permissions</button></form>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="apply_defaults"><button class="btn">Apply default role grants</button></form>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_role"><input name="name" placeholder="New role name" maxlength="60" required><button class="btn">Add role</button></form>
</div>
<?php foreach ($roles as $r): ?>
<div class="card"><h2><?= e($r['name']) ?> <small class="muted"><?= e($r['slug']) ?></small></h2>
<?php if ($r['slug'] === 'super_admin'): ?><p class="muted">Super Admin always has every permission.</p><?php else: ?>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_role_permissions"><input type="hidden" name="role_id" value="<?= (int) $r['id'] ?>">
    <?php foreach ($perms as $p): ?>
        <label class="check"><input type="checkbox" name="permission_ids[]" value="<?= (int) $p['id'] ?>"<?= checked(isset($granted[(int) $r['id']][(int) $p['id']])) ?>> <code><?= e($p['code']) ?></code> <small class="muted"><?= e($p['description']) ?></small></label>
    <?php endforeach; ?>
    <div><button class="btn btn-primary">Save</button></div>
</form><?php endif; ?></div>
<?php endforeach;
page_footer();
