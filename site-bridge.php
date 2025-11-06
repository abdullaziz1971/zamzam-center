<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
header('Content-Type: application/json; charset=utf-8');

$secret = $_GET['secret'] ?? '';
$op = $_GET['op'] ?? 'pull';
if ($secret !== ZAMZAM_SHARED_SECRET) { http_response_code(401); echo json_encode(['ok'=>false,'err'=>'auth']); exit; }

function js_to_json(string $js): array {
  if (!preg_match('/window\s*\.\s*ZAMZAM_DATA\s*=\s*(\{.*\});?\s*$/s', $js, $m)) return [false,null];
  $json = $m[1];
  $arr = json_decode($json,true);
  if (!is_array($arr)) return [false,null];
  return [true,$arr];
}
function atomic_write(string $p,string $c): bool {
  $d=dirname($p); if(!is_dir($d)||!is_writable($d)) return false;
  $t=$d.'/.tmp_'.bin2hex(random_bytes(6));
  if(@file_put_contents($t,$c)===false) return false;
  if(file_exists($p)){ @copy($p, $d.'/backups/'.basename($p).'.'.date('Ymd_His').'.bak'); }
  return @rename($t,$p);
}

$siteJs = dirname(__DIR__).'/data.js';          // /public_html/data.js
$panelJson = __DIR__.'/data.json';              // /admin_v2/data.json

if ($op==='pull') {
  $raw = @file_get_contents($siteJs);
  if ($raw===false) { echo json_encode(['ok'=>false,'err'=>'read_site_js']); exit; }
  [$ok,$arr] = js_to_json($raw);
  if (!$ok) { echo json_encode(['ok'=>false,'err'=>'parse_js']); exit; }
  $json = json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if (!atomic_write($panelJson,$json)) { echo json_encode(['ok'=>false,'err'=>'write_panel_json']); exit; }
  echo json_encode(['ok'=>true,'op'=>'pull','written'=>$panelJson,'counts'=>[
    'companies'=>is_array($arr['companies']??null)?count($arr['companies']):0,
    'featured'=>is_array($arr['featuredOffers']??null)?count($arr['featuredOffers']):0
  ]]);
  exit;
}

if ($op==='push') {
  $json = @file_get_contents($panelJson);
  if ($json===false) { echo json_encode(['ok'=>false,'err'=>'read_panel_json']); exit; }
  json_decode($json);
  if (json_last_error()!==JSON_ERROR_NONE) { echo json_encode(['ok'=>false,'err'=>'bad_json']); exit; }
  $js = 'window.ZAMZAM_DATA='.rtrim($json).';';
  if (!atomic_write($siteJs,$js)) { echo json_encode(['ok'=>false,'err'=>'write_site_js']); exit; }
  echo json_encode(['ok'=>true,'op'=>'push','written'=>$siteJs]);
  exit;
}

echo json_encode(['ok'=>false,'err'=>'bad_op']);
