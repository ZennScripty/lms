<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\{Auth, Csrf};

if (Auth::user()) {
    redirect('dashboard.php');
}

$error = null;
if (is_post() && request_action() === 'login') {
    Csrf::verifyRequest();
    try {
        if (Auth::attempt((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            redirect('dashboard.php');
        }
        $error = 'Invalid username or password.';
    } catch (\DomainException $e) {
        $error = $e->getMessage();
    }
}

page_header('Login', ['public' => true]);
?>
<div class="auth-layout"><section class="auth-art"><span class="brand-mark"><?= icon('book') ?></span><h2><?= e(tr('Build skills. Create possibilities.')) ?></h2><p><?= e(tr('Your learning journey starts here.')) ?></p></section><section class="login-card"><h2><?= e(tr('Welcome to TLMTI')) ?></h2><p><?= e(tr('Sign in to continue your learning journey.')) ?></p>
<?php if ($error): ?><div class="flash flash-error" role="alert"><?= e(tr($error)) ?></div><?php endif; ?>
<form method="post" autocomplete="on"><?= csrf_field() ?><input type="hidden" name="action" value="login"><label><?= e(tr('Username or email')) ?><input name="login" required autofocus maxlength="190" autocomplete="username"></label><label><?= e(tr('Password')) ?><input type="password" name="password" required maxlength="200" autocomplete="current-password"></label><button class="btn btn-primary"><?= e(tr('Sign in')) ?> <?= icon('arrow') ?></button></form><p class="registration-login-link"><a href="register.php"><?= e(tr('New Student? Create account')) ?></a></p></section></div>
<?php page_footer();
