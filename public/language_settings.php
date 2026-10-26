<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Csrf, Database, Validator};

Auth::requireLogin();
Authorization::require('languages.manage');

if (is_post()) {
    Csrf::verifyRequest();
    try {
        $action = request_action();
        if ($action === 'save_languages') {
            $codes = array_column(Database::all('SELECT code FROM languages'), 'code');
            $default = Validator::inList($_POST['default'] ?? '', $codes, 'Default language');
            $enabled = array_intersect($codes, (array) ($_POST['enabled'] ?? []));
            if (!in_array($default, $enabled, true)) {
                throw new DomainException('The default language must be enabled.');
            }
            Database::transaction(static function () use ($codes, $default, $enabled) {
                foreach ($codes as $c) {
                    $fb = (string) ($_POST['fallback'][$c] ?? '');
                    $fb = in_array($fb, $codes, true) && $fb !== $c ? $fb : null;
                    Database::run('UPDATE languages SET is_enabled = ?, is_default = ?, fallback_code = ?, tts_locale = ?, sort_order = ? WHERE code = ?',
                        [in_array($c, $enabled, true) ? 1 : 0, $c === $default ? 1 : 0, $fb, Validator::optStr($_POST['tts'][$c] ?? '', 'TTS locale', 20), Validator::int($_POST['sort'][$c] ?? '0', 'Order', 0, 1000), $c]);
                }
            });
            flash('success', 'Languages saved.');
        } elseif ($action === 'add_language') {
            $code = Validator::str($_POST['code'] ?? '', 'Code', 2, 10);
            if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,4})?$/', $code)) {
                throw new InvalidArgumentException('Language code looks invalid (e.g. hi, en, hi-IN).');
            }
            Database::run('INSERT INTO languages (code, name, is_enabled, is_default, tts_locale, sort_order) VALUES (?,?,0,0,?,99)',
                [$code, Validator::str($_POST['name'] ?? '', 'Name', 2, 60), Validator::optStr($_POST['tts_locale'] ?? '', 'TTS locale', 20)]);
            flash('success', 'Language added (disabled).');
        } elseif ($action === 'save_ui_translation') {
            $lang = Validator::str($_POST['language_code'] ?? '', 'Language', 2, 10);
            if (!Database::val('SELECT COUNT(*) FROM languages WHERE code = ?', [$lang])) {
                throw new DomainException('Unknown language.');
            }
            $key = Validator::str($_POST['translation_key'] ?? '', 'Key', 1, 120);
            if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
                throw new InvalidArgumentException('Key may contain letters, digits, dot, dash, underscore.');
            }
            Database::run('INSERT INTO ui_translations (language_code, translation_key, translation_value) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE translation_value = VALUES(translation_value)', [$lang, $key, Validator::str($_POST['translation_value'] ?? '', 'Value', 1, 1000)]);
            flash('success', 'Translation saved.');
        } elseif ($action === 'delete_ui_translation') {
            Database::run('DELETE FROM ui_translations WHERE id = ?', [Validator::int($_POST['id'] ?? '', 'Id', 1)]);
            flash('success', 'Translation deleted.');
        }
    } catch (\Throwable $e) {
        flash('error', handle_exception($e));
    }
    redirect_self();
}

$langs = Database::all('SELECT * FROM languages ORDER BY sort_order, name');
$view = (string) ($_GET['ui'] ?? ($langs[0]['code'] ?? 'en'));
$ui = Database::all('SELECT * FROM ui_translations WHERE language_code = ? ORDER BY translation_key LIMIT 500', [$view]);
page_header(t('nav.languages', 'Languages'));
?>
<div class="card"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_languages">
<table><thead><tr><th>Code</th><th>Name</th><th>Enabled</th><th>Default</th><th>Fallback</th><th>TTS locale</th><th>Order</th></tr></thead><tbody>
<?php foreach ($langs as $l): $c = $l['code']; ?>
    <tr><td><?= e($c) ?></td><td><?= e($l['name']) ?></td>
    <td><input type="checkbox" name="enabled[]" value="<?= e($c) ?>"<?= checked((int) $l['is_enabled'] === 1) ?>></td>
    <td><input type="radio" name="default" value="<?= e($c) ?>"<?= checked((int) $l['is_default'] === 1) ?>></td>
    <td><select name="fallback[<?= e($c) ?>]"><option value="">—</option><?php foreach ($langs as $o): if ($o['code'] === $c) continue; ?><option value="<?= e($o['code']) ?>"<?= selected($o['code'], $l['fallback_code']) ?>><?= e($o['code']) ?></option><?php endforeach; ?></select></td>
    <td><input name="tts[<?= e($c) ?>]" value="<?= e($l['tts_locale']) ?>" maxlength="20" size="8"></td>
    <td><input type="number" name="sort[<?= e($c) ?>]" value="<?= (int) $l['sort_order'] ?>" min="0" max="1000" class="narrow-input"></td></tr>
<?php endforeach; ?></tbody></table><button class="btn btn-primary">Save languages</button></form>
<form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_language">
    <input name="code" placeholder="code (hi)" required maxlength="10"><input name="name" placeholder="Name" required maxlength="60"><input name="tts_locale" placeholder="TTS locale (hi-IN)" maxlength="20"><button class="btn">Add language</button></form></div>

<div class="card"><h2>UI translations</h2>
<form method="get" class="inline-form"><select name="ui" data-autosubmit><?php foreach ($langs as $l): ?><option value="<?= e($l['code']) ?>"<?= selected($l['code'], $view) ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></form>
<table><thead><tr><th>Key</th><th>Value</th><th></th></tr></thead><tbody>
<?php foreach ($ui as $r): ?><tr><td><code><?= e($r['translation_key']) ?></code></td><td><?= e($r['translation_value']) ?></td><td>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_ui_translation"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-danger" data-confirm="Delete?">×</button></form></td></tr>
<?php endforeach; ?></tbody></table>
<form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_ui_translation"><input type="hidden" name="language_code" value="<?= e($view) ?>">
    <input name="translation_key" placeholder="nav.dashboard" required maxlength="120"><input name="translation_value" placeholder="Value" required maxlength="1000"><button class="btn">Save</button></form></div>
<?php page_footer();
