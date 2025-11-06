<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
header('Content-Type: application/json; charset=utf-8');

function okDir($p){ return is_dir($p) && is_writable($p); }
function okFileW($p){ return file_exists($p) ? is_writable($p) : is_writable(dirname($p)); }

$out = [
  'ok' => true,
  'host' => ZAMZAM_ENV_HOST,
  'allowed_roots' => ZAMZAM_ALLOWED_ROOTS,
  'backup_dir' => ZAMZAM_BACKUP_DIR,
  'agent_enabled' => ZAMZAM_AGENT_ENABLED,
  'ts' => date('c'),
  'checks' => [],
];

if (!ZAMZAM_AGENT_ENABLED) { $out['ok']=false; $out['error']='agent_disabled'; }

$checks = [];
foreach (ZAMZAM_ALLOWED_ROOTS as $r) {
  $checks[] = ['root'=>$r, 'exists'=>is_dir($r), 'writable'=>okDir($r)];
  if (!okDir($r)) { $out['ok']=false; $out['error']='root_not_writable'; }
}
$checks[] = ['backup_dir'=>ZAMZAM_BACKUP_DIR, 'exists'=>is_dir(ZAMZAM_BACKUP_DIR), 'writable'=>okDir(ZAMZAM_BACKUP_DIR)];
if (!okDir(ZAMZAM_BACKUP_DIR)) { $out['ok']=false; $out['error']='backup_not_writable'; }

$out['checks'] = $checks;
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
