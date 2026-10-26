<?php
use App\Core\Authorization;
if(!Authorization::isSuperAdmin()){forbidden();}
page_header('Language'); ?><section class="card"><h2><?= e(tr('Language')) ?></h2><p><?= e(tr('Only Hindi and English are available.')) ?></p><p class="muted"><?= e(tr('Use the language selector in the header. Your choice is saved to your account.')) ?></p></section><?php page_footer();
