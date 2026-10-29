<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Core\{Auth,Authorization as A,Validator as V};
Auth::requireLogin();if(!A::isAdmin())forbidden();A::require('reports.view');
$analyticsCentre=V::optInt($_GET['centre_id']??null,'Centre');if($analyticsCentre&&!A::canAccessCentre($analyticsCentre))forbidden();
page_header('Progress analytics');
?>
<section class="card"><form method="get" class="filter-bar"><label><?= e(tr('Centre')) ?><select name="centre_id"><option value=""><?= e(tr('All authorized centres')) ?></option><?php foreach(\App\Services\ManagementService::options('centres') as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($c['id'],$analyticsCentre??'') ?>><?= e($c['label']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary"><?= e(tr('Apply')) ?></button></form></section>
<?php require APP_ROOT.'/app/Views/progress_charts.php';page_footer();
