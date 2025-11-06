<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
header('Content-Type: application/json; charset=utf-8');

function jerr($c,$m,$x=[]){http_response_code(400);echo json_encode(['ok'=>false,'code'=>$c,'error'=>$m]+$x,JSON_UNESCAPED_UNICODE);exit;}
function awrite($target,$content){
  $dir=dirname($target); if(!is_dir($dir)||!is_writable($dir)) return [false,'dir_not_writable',$dir];
  $tmp=$dir.'/.tmp_'.bin2hex(random_bytes(6));
  if(@file_put_contents($tmp,$content)===false) return [false,'tmp_write_failed',$tmp];
  if(file_exists($target)){ @copy($target, ZAMZAM_BACKUP_DIR.'/'.basename($target).'.'.date('Ymd_His').'.bak'); }
  if(!@rename($tmp,$target)) return [false,'rename_failed',$target];
  return [true,null,$target];
}
function pick_json_from_live(string $liveDir): array {
  $cands = [
    $liveDir.'/data.json',
    $liveDir.'/offers.json',
    $liveDir.'/site.json',
    $liveDir.'/db.json',
  ];
  foreach ($cands as $p) if (is_file($p)) return ['type'=>'json','path'=>$p];

  $dj = $liveDir.'/data.js';
  if (is_file($dj)) return ['type'=>'js','path'=>$dj];

  // أكبر JSON حديث داخل _admin_live كحل أخير
  $best = null; $bestSize = 0;
  foreach (glob($liveDir.'/*.json') ?: [] as $p) {
    $sz = @filesize($p) ?: 0;
    if ($sz > $bestSize) { $best = $p; $bestSize = $sz; }
  }
  if ($best) return ['type'=>'json','path'=>$best];

  return ['type'=>null,'path'=>null];
}

$secret = $_POST['secret'] ?? $_GET['secret'] ?? '';
if ($secret !== ZAMZAM_SHARED_SECRET) jerr('auth','مفتاح خاطئ');

$LIVE_DIR = dirname(__DIR__).'/_admin_live'; // /public_html/_admin_live
if (!is_dir($LIVE_DIR)) jerr('nf','مجلد _admin_live غير موجود',['dir'=>$LIVE_DIR]);

$pick = pick_json_from_live($LIVE_DIR);
if (!$pick['path']) jerr('nf','لا توجد بيانات في _admin_live');

$raw = @file_get_contents($pick['path']);
if ($raw===false) jerr('read_fail','تعذّر قراءة المصدر',['src'=>$pick['path']]);

if ($pick['type']==='js') {
  // توقع: window.ZAMZAM_DATA=...;
  if (!preg_match('/window\\s*\\.\\s*ZAMZAM_DATA\\s*=\\s*(\\{.*\\});?\\s*$/s', $raw, $m)) {
    jerr('parse_fail','data.js غير متوقّع');
  }
  $raw = $m[1];
}

$data = json_decode($raw,true);
if (!is_array($data)) jerr('bad_json','JSON غير صالح من المصدر',['src'=>$pick['path']]);

$jsonPath = __DIR__.'/data.json';
$jsPath   = dirname(__DIR__).'/data.js';

// اكتب data.json
[$ok1,$e1,$p1]=awrite($jsonPath, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
if(!$ok1) jerr('write_json_fail','فشل حفظ data.json',['detail'=>$e1,'path'=>$p1]);

// ولّد data.js
$js = 'window.ZAMZAM_DATA='.rtrim(json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).';';
[$ok2,$e2,$p2]=awrite($jsPath,$js);
if(!$ok2) jerr('write_js_fail','فشل توليد data.js',['detail'=>$e2,'path'=>$p2]);

// إحصاءات
$counts=['companies'=>0,'products'=>0,'featuredOffers'=>0];
if(isset($data['companies'])&&is_array($data['companies'])){
  $counts['companies']=count($data['companies']);
  foreach($data['companies'] as $c){ if(isset($c['products'])&&is_array($c['products'])) $counts['products']+=count($c['products']); }
}
if(isset($data['featuredOffers'])&&is_array($data['featuredOffers'])) $counts['featuredOffers']=count($data['featuredOffers']);

echo json_encode([
  'ok'=>true,
  'source'=>$pick,
  'written'=>['json'=>$jsonPath,'js'=>$jsPath],
  'counts'=>$counts,
  'ts'=>date('c'),
], JSON_UNESCAPED_UNICODE);
