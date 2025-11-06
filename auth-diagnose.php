<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
header('Content-Type: application/json; charset=utf-8');

$in = $_GET['secret'] ?? '';

function b64($s){ return base64_encode(hash('sha256',$s,true)); }
function pathinfo_cfg(){
  $p = __DIR__.'/config.php';
  return [
    'config_path_input'=>$p,
    'config_path_real'=>@realpath($p),
    'config_mtime'=>@filemtime($p),
    'php_sapi'=>php_sapi_name(),
    'php'=>PHP_VERSION,
  ];
}

echo json_encode([
  'ok'=>true,
  'cfg'=>pathinfo_cfg(),
  'expected_secret_len'=>strlen(ZAMZAM_SHARED_SECRET),
  'got_secret_len'=>strlen($in),
  'expected_sha256_b64'=>b64(ZAMZAM_SHARED_SECRET),
  'got_sha256_b64'=>b64($in),
  'equal'=>hash_equals(ZAMZAM_SHARED_SECRET, $in),
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
