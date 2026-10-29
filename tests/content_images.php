<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/autoload.php';use App\Services\ContentImageService as Images;
$path=APP_ROOT.'/tmp/cover-encoder-test.png';$passed=0;
function imageCheck(bool $ok,string $label): void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;$passed++;}
function imageReject(callable $fn,string $label): void {try{$fn();}catch(DomainException $e){imageCheck(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
try{
    $im=imagecreatetruecolor(1800,900);imagepng($im,$path);imagedestroy($im);$r=Images::encode($path,'cover.png');$info=getimagesizefromstring($r['bytes']);imageCheck($info[0]===1200&&$info[1]===600,'image resized with preserved aspect ratio');imageCheck($info['mime']==='image/webp'&&$r['ext']==='webp','validated raster converted to WebP');
    imageReject(fn()=>Images::encode($path,'cover.jpg'),'extension must match decoded MIME');imageReject(fn()=>Images::encode($path,'cover.php'),'executable extension denied');imageReject(fn()=>Images::encode($path,'cover.svg'),'SVG upload denied');
    file_put_contents($path,'<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>');imageReject(fn()=>Images::encode($path,'fake.png'),'SVG content cannot masquerade as raster');
    file_put_contents($path,str_repeat('x',2097153));imageReject(fn()=>Images::encode($path,'large.png'),'oversized file denied');
    $im=imagecreatetruecolor(16,16);imagepng($im,$path);imagedestroy($im);imageReject(fn()=>Images::encode($path,'small.png'),'undersized dimensions denied');
    file_put_contents($path,'');imageReject(fn()=>Images::encode($path,'empty.png'),'empty image denied');
    $html=Images::thumbnail(null,'<script>');imageCheck(str_contains($html,'cover-placeholder.svg')&&!str_contains($html,'<script>'),'placeholder and alternative text safely escaped');
    echo "$passed image checks passed\n";
}finally{if(is_file($path))unlink($path);}
