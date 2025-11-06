<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
header('Content-Type: application/json; charset=utf-8');

function jerr($c,$m,$x=[]){http_response_code(400);echo json_encode(['ok'=>false,'code'=>$c,'error'=>$m]+$x,JSON_UNESCAPED_UNICODE);exit;}
function atomic_restore(string $from, string $to): array{
  $dir = dirname($to);
  if (!is_dir($dir) || !is_writable($dir)) return [false,'target_not_writable'];
  $tmp = $dir.'/.tmp_restore_'.bin2hex(random_bytes(6));
  if (!@copy($from,$tmp)) return [false,'copy_tmp_failed'];
  if (file_exists($to)) {
    @copy($to, ZAMZAM_BACKUP_DIR.'/'.basename($to).'.'.date('Ymd_His').'.pre-rollback.bak');
  }
  if (!@rename($tmp,$to)) return [false,'rename_failed'];
  return [true,null];
}

$secret = $_POST['secret'] ?? '';
$file   = $_POST['file'] ?? '';
if ($secret !== ZAMZAM_SHARED_SECRET) jerr('auth','مفتاح خاطئ');
if (!$file) jerr('args','اسم النسخة مفقود');

$backup = rtrim(ZAMZAM_BACKUP_DIR,'/').'/'.$file;
if (!is_file($backup)) jerr('nf','النسخة غير موجودة');

if (!preg_match('/^(.*)\.(\d{8}_\d{6})\.bak$/', basename($backup), $m)) jerr('bad','تنسيق اسم غير متوقع');
$orig = $m[1];

// نحدد الهدف المسموح فقط
$target = null;
if ($orig === 'data.json') $target = __DIR__.'/data.json';
elseif ($orig === 'data.js') $target = dirname(__DIR__).'/data.js';
else jerr('denied','ملف غير مسموح بالاسترجاع');

[$ok,$err] = atomic_restore($backup, $target);
if (!$ok) jerr('restore_fail',$err, ['target'=>$target]);

echo json_encode(['ok'=>true,'restored_from'=>basename($backup),'to'=>$target], JSON_UNESCAPED_UNICODE);
