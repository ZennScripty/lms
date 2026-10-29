<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/autoload.php';
echo json_encode(\App\Services\LearningAccessPreviewService::report(),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
