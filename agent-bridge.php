<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/admin_v2/agent-config.php';
if (!defined('BRIDGE_SECRET')) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'CONFIG_MISSING']); exit;
}

$given = $_GET['secret'] ?? ($_SERVER['HTTP_X_BRIDGE_SECRET'] ?? '');
if ($given !== BRIDGE_SECRET) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized','hint'=>'use ?secret=123456 or header X-Bridge-Secret']); exit;
}

if (isset($_GET['ping'])) { echo json_encode(['ok'=>true,'ping'=>'OK']); exit; }
echo json_encode(['ok'=>true,'msg'=>'bridge ready']);
