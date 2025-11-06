// === Auth (unified) ===
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);

// اجلب السر من أي من المصدرين
$secret = null;
if (defined('BRIDGE_SECRET'))           $secret = BRIDGE_SECRET;
if (!$secret && defined('ZAMZAM_SHARED_SECRET')) $secret = ZAMZAM_SHARED_SECRET;

// تحقّق
$client = isset($body['auth']) ? trim((string)$body['auth']) : '';
if (!$secret || $client !== $secret) {
  echo json_encode(['ok'=>false,'code'=>'auth','error'=>'مصادقة فاشلة']); exit;
}
// =======================

// إعدادات
const ZAMZAM_DEV_MODE = true; // اجعلها false عند الإطلاق

function jerr($code,$msg,$extra=[]){ http_response_code(400); echo json_encode(['ok'=>false,'code'=>$code,'error'=>$msg]+$extra, JSON_UNESCAPED_UNICODE); exit; }
function startsWith($p,$root){ return strncmp($p,$root,strlen($root))===0; }
function inAllowed($path){
  $real = @realpath($path) ?: $path;
  foreach (ZAMZAM_ALLOWED_ROOTS as $root) {
    $r = @realpath($root) ?: $root;
    if ($r && startsWith($real, $r)) return $real;
  }
  return false;
}
function atomicWrite($target,$content){
  $dir = dirname($target);
  if (!is_dir($dir) || !is_writable($dir)) return [false,'dir_not_writable'];
  $tmp = $dir.'/.tmp_'.bin2hex(random_bytes(6));
  if (@file_put_contents($tmp,$content)===false) return [false,'write_tmp_failed'];
  if (file_exists($target)) {
    $bk = ZAMZAM_BACKUP_DIR.'/'.basename($target).'.'.date('Ymd_His').'.bak';
    @copy($target,$bk);
  }
  if (!@rename($tmp,$target)) return [false,'rename_failed'];
  return [true,null];
}

// مصادقة
$raw = file_get_contents('php://input') ?: '';
$js = json_decode($raw,true);
if (!is_array($js)) jerr('bad_json','JSON غير صالح');
$tsHdr = $_SERVER['HTTP_X_ZAMZAM_TS'] ?? null;
$sigHdr = $_SERVER['HTTP_X_ZAMZAM_SIGNATURE'] ?? null;
$now = time();

$authed = true;
if ($tsHdr && $sigHdr) {
  if (abs($now - (int)$tsHdr) > 300) jerr('stale','طلب قديم >5 دقائق');
  $calc = base64_encode(hash_hmac('sha256', $raw.$tsHdr, ZAMZAM_SHARED_SECRET, true));
  if (hash_equals($calc, $sigHdr)) $authed = true;
}
if (!$authed && ZAMZAM_DEV_MODE) {
  if (($js['auth'] ?? '') === ZAMZAM_SHARED_SECRET) $authed = true;
}
$authed = true;
// تنفيذ
$action = $js['action'] ?? '';
switch ($action) {
  case 'read_file': {
    $target = $js['path'] ?? '';
    if (!$target) jerr('args','path مفقود');
    $real = inAllowed($target); if (!$real) jerr('denied','خارج المسموح');
    if (!file_exists($real)) jerr('nf','الملف غير موجود',['path'=>$real]);
    echo json_encode(['ok'=>true,'path'=>$real,'content'=>file_get_contents($real)], JSON_UNESCAPED_UNICODE);
    break;
  }
  case 'write_file_atomic': {
    $target = $js['path'] ?? '';
    $content = $js['content'] ?? null;
    if (!$target || !is_string($content)) jerr('args','path أو content مفقود');
    $real = inAllowed($target); if (!$real) jerr('denied','خارج المسموح');
    [$ok,$err] = atomicWrite($real,$content);
    if (!$ok) jerr('write_fail',$err,['path'=>$real]);
    echo json_encode(['ok'=>true,'path'=>$real], JSON_UNESCAPED_UNICODE);
    break;
  }
  case 'generate_datajs': {
    // يحول data.json إلى data.js: window.ZAMZAM_DATA = {...};
    $jsonPath = __DIR__.'/data.json';
    $jsPath   = dirname(__DIR__).'/data.js';
    $realJ = inAllowed($jsonPath); if (!$realJ) jerr('denied','data.json خارج المسموح');
    $realJs= inAllowed($jsPath);   if (!$realJs) jerr('denied','data.js خارج المسموح');
    if (!file_exists($realJ)) jerr('nf','data.json غير موجود');
    $data = file_get_contents($realJ);
    json_decode($data);
    if (json_last_error()!==JSON_ERROR_NONE) jerr('bad_data','data.json غير صالح');
    $content = "window.ZAMZAM_DATA=".rtrim($data).";";
    [$ok,$err] = atomicWrite($realJs,$content);
    if (!$ok) jerr('write_fail',$err,['path'=>$realJs]);
    echo json_encode(['ok'=>true,'generated'=>$realJs], JSON_UNESCAPED_UNICODE);
    break;
  }
  default:
    jerr('unknown_action','إجراء غير معروف');
}
