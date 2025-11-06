<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
header('Content-Type: application/json; charset=utf-8');

function jerr($c,$m,$x=[]){http_response_code(400);echo json_encode(['ok'=>false,'code'=>$c,'error'=>$m]+$x,JSON_UNESCAPED_UNICODE);exit;}
$secret = $_POST['secret'] ?? '';
if ($secret !== ZAMZAM_SHARED_SECRET) jerr('auth','مفتاح خاطئ');

$dir = ZAMZAM_BACKUP_DIR;
if (!is_dir($dir)) jerr('no_dir','مجلد النسخ غير موجود');

$files = array_values(array_filter(scandir($dir) ?: [], fn($n)=>$n!=='.'&&$n!=='..'));
$items = [];
foreach ($files as $name){
  $path = rtrim($dir,'/').'/'.$name;
  if (!is_file($path)) continue;
  // نمط: original.Ymd_His.bak
  if (!preg_match('/^(.*)\.(\d{8}_\d{6})\.bak$/', $name, $m)) continue;
  $orig = $m[1];
  $target = null;
  if ($orig === 'data.json') $target = __DIR__.'/data.json';
  elseif ($orig === 'data.js') $target = dirname(__DIR__).'/data.js';
  else $target = null; // امنع غير المعروف
  $items[] = [
    'name'=>$name,
    'mtime'=>@filemtime($path)?:0,
    'mtime_h'=>date('Y-m-d H:i:s', @filemtime($path)?:time()),
    'size'=>@filesize($path)?:0,
    'size_h'=>number_format((@filesize($path)?:0)/1024,2).' KB',
    'target_path'=>$target,
  ];
}
usort($items, fn($a,$b)=>$b['mtime']<=>$a['mtime']);

echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
