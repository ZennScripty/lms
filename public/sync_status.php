<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Database};

$user = Auth::requireLogin();
$logs = Database::all('SELECT event_uuid, status, message, created_at FROM sync_logs WHERE user_id = ? ORDER BY id DESC LIMIT 25', [(int) $user['id']]);

page_header(t('nav.sync', 'Sync status'));
?>
<div class="card" data-sync-panel>
    <div class="grid">
        <div class="stat"><div class="num" data-count="pending">0</div>Pending</div>
        <div class="stat"><div class="num" data-count="synced">0</div>Synced</div>
        <div class="stat"><div class="num" data-count="failed">0</div>Failed</div>
        <div class="stat"><div class="num" data-count="rejected">0</div>Rejected</div>
    </div>
    <p>Last sync: <strong data-last-sync>never</strong></p>
    <p class="muted" data-storage-info></p>
    <p class="muted" data-sync-message></p>
    <button class="btn btn-primary" data-sync-now type="button">Sync now</button>
    <button class="btn" data-sync-retry type="button">Retry failed</button>
    <button class="btn" data-sync-clear type="button">Clear synced history</button>
</div>
<div class="card"><h2>On this device</h2><table><thead><tr><th>Type</th><th>Created</th><th>Status</th><th>Detail</th></tr></thead><tbody data-sync-list></tbody></table></div>
<div class="card"><h2>Recent server log</h2><table><thead><tr><th>When</th><th>Status</th><th>Message</th></tr></thead><tbody>
<?php foreach ($logs as $l): ?><tr><td><?= e(fmt_dt($l['created_at'])) ?></td><td><?= e($l['status']) ?></td><td><?= e($l['message']) ?></td></tr><?php endforeach; ?>
<?php if (!$logs): ?><tr><td colspan="3" class="muted">No sync activity.</td></tr><?php endif; ?></tbody></table></div>
<?php page_footer();
