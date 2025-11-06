<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/agent-config.php';

// منع التشغيل خارج الديف
if (!ZAMZAM_AGENT_ENABLED || ($_SERVER['HTTP_HOST'] ?? '') !== ZAMZAM_ENV_HOST) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'agent disabled or wrong host']);
  exit;
}

// قراءة الجسم
$raw = file_get_contents('php://input') ?: '';
if ($raw === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'empty body']);
  exit;
}
$req = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid JSON: '.json_last_error_msg()]);
  exit;
}

// تحقق التوقيع البسيط: HMAC-SHA256 على الجسم الخام بمفتاح مشترك
// --- توقيع HMAC صحيح: نُخرج signature من الجسم قبل الحساب ---
$clientSig = $req['signature'] ?? '';

// ابنِ payload للتوقيع بدون حقل signature لضمان تطابق الجانبين
$payloadForSig = $req;
unset($payloadForSig['signature']);

// طبّع JSON بنفس الطريقة على الجانبين
$normalized = json_encode($payloadForSig, JSON_UNESCAPED_UNICODE);

// احسب HMAC على الـnormalized (بدون signature)
$calcSig = base64_encode(hash_hmac('sha256', $normalized, ZAMZAM_SHARED_SECRET, true));

if (!$clientSig || !hash_equals($calcSig, $clientSig)) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'bad signature']);
  exit;
}
// --- Rate Limit (آمن وخفيف) — ضعه بعد التوقيع مباشرة ---
$__rate_file = __DIR__ . '/agent-rate-limit.json';
$__ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$__now  = time();
$__win  = 60;   // نافذة زمنية: 60 ثانية
$__max  = 5;    // أقصى 5 أوامر لكل IP في الدقيقة

$__data = [];
if (is_file($__rate_file)) {
  $rawRL = @file_get_contents($__rate_file);
  if ($rawRL !== false) {
    $tmp = @json_decode($rawRL, true);
    if (is_array($tmp)) { $__data = $tmp; }
  }
}

// نظّف القديم داخل النافذة
if (!empty($__data)) {
  foreach ($__data as $k => $arr) {
    if (!is_array($arr)) { $__data[$k] = []; continue; }
    $__new = [];
    foreach ($arr as $t) { if ($__now - (int)$t < $__win) { $__new[] = (int)$t; } }
    $__data[$k] = $__new;
  }
}

if (!isset($__data[$__ip])) { $__data[$__ip] = []; }
if (count($__data[$__ip]) >= $__max) {
  http_response_code(429);
  echo json_encode(['ok'=>false,'error'=>'too many requests']);
  exit;
}

$__data[$__ip][] = $__now;
// كتابة محمية بسيطة (بدون أقفال ثقيلة لتفادي أخطاء الاستضافة المشتركة)
@file_put_contents($__rate_file, json_encode($__data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));


// وضع تجريبي أم تنفيذ
$dry = (bool)($req['dry_run'] ?? true);

// قائمة الأوامر
$ops = $req['ops'] ?? null;
if (!is_array($ops) || count($ops) === 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'no ops']);
  exit;
}

// أدوات مساعدة
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
if ($docRoot === '') { // احتياط على بعض الاستضافات
  $docRoot = dirname(__DIR__) . '/public_html';
}
$rootDir = dirname($docRoot); // مجلد الحساب الذي يحوي public_html و admin_v2
$backupDir = $rootDir . ZAMZAM_BACKUP_DIR;
@mkdir($backupDir, 0775, true);

// تحقّق أن المسار ضمن الجذور المسموحة
function isPathAllowed(string $abs, array $allowedRoots, string $rootDir): bool {
  $abs = str_replace('\\','/',$abs);
  if (!str_starts_with($abs, $rootDir)) return false;
  $rel = substr($abs, strlen($rootDir)); // يبدأ بـ /...
  foreach ($allowedRoots as $ar) {
    if (str_starts_with($rel, $ar)) return true;
  }
  return false;
}

// نسخ احتياطي آمن
function backupFile(string $abs, string $backupDir): ?string {
  if (!is_file($abs)) return null;
  $ts = date('Y-m-d_His');
  $base = basename($abs);
  $dest = $backupDir . '/' . $base . '.' . $ts . '.bak';
  @copy($abs, $dest);
  return $dest;
}

// كتابة ذرّية
function atomicWrite(string $abs, string $content): bool {
  $tmp = $abs . '.tmp';
  if (file_put_contents($tmp, $content) === false) return false;
  if (!@rename($tmp, $abs)) {
    @unlink($abs);
    return @rename($tmp, $abs);
  }
  return true;
}

// تنفيذ الأوامر
$results = [];
foreach ($ops as $i => $op) {
  $action = $op['action'] ?? '';
  $relPath = $op['path'] ?? '';
  if ($relPath === '' || !is_string($relPath)) {
    $results[] = ['i'=>$i,'ok'=>false,'error'=>'missing path'];
    continue;
  }

  // تحويل لمسار مطلق داخل حساب الاستضافة
  $abs = realpath($rootDir) . '/' . ltrim($relPath, '/');
  // في حال المجلدات غير موجودة بالكامل
  $dir = dirname($abs);
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

  if (!isPathAllowed($abs, ZAMZAM_ALLOWED_ROOTS, $rootDir)) {
    $results[] = ['i'=>$i,'ok'=>false,'path'=>$relPath,'error'=>'path not allowed'];
    continue;
  }

  // تنفيذ حسب نوع العملية
  if ($action === 'write') {
    $b64 = $op['content_base64'] ?? '';
    if ($b64 === '') { $results[] = ['i'=>$i,'ok'=>false,'path'=>$relPath,'error'=>'missing content']; continue; }
    $content = base64_decode($b64, true);
    if ($content === false) { $results[] = ['i'=>$i,'ok'=>false,'path'=>$relPath,'error'=>'bad base64']; continue; }

    $backup = is_file($abs) ? backupFile($abs, $backupDir) : null;
    $ok = $dry ? true : atomicWrite($abs, $content);
    $results[] = ['i'=>$i,'ok'=>$ok,'path'=>$relPath,'backup'=>$backup];

  } elseif ($action === 'delete') {
    $backup = is_file($abs) ? backupFile($abs, $backupDir) : null;
    $ok = $dry ? true : @unlink($abs);
    $results[] = ['i'=>$i,'ok'=>$ok,'path'=>$relPath,'backup'=>$backup];

  } elseif ($action === 'chmod') {
    $mode = intval($op['mode'] ?? 0644, 8);
    $ok = $dry ? true : @chmod($abs, $mode);
    $results[] = ['i'=>$i,'ok'=>$ok,'path'=>$relPath,'mode'=>$mode];

  } else {
    $results[] = ['i'=>$i,'ok'=>false,'path'=>$relPath,'error'=>'unknown action'];
  }
}

// تنظيف النسخ القديمة
$bakList = glob($backupDir . '/*.bak') ?: [];
if (count($bakList) > ZAMZAM_MAX_BACKUPS) {
  sort($bakList);
  $toDel = count($bakList) - ZAMZAM_MAX_BACKUPS;
  for ($k=0; $k<$toDel; $k++) { @unlink($bakList[$k]); }
}
// --- Audit Log ---
$logFile = $ADMIN_DIR . '/agent-actions.log';
$entry = date('Y-m-d H:i:s') . " " . $_SERVER['REMOTE_ADDR'] .
         " " . ($_SERVER['HTTP_USER_AGENT'] ?? '') .
         " payload=" . substr(json_encode($req, JSON_UNESCAPED_UNICODE),0,5000) . "\n";
@file_put_contents($logFile, $entry, FILE_APPEND);
// --- Rate Limit بسيط لكل IP ---
$limitFile = __DIR__ . '/agent-rate-limit.json';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$now = time();
$window = 60;    // مدة النافذة الزمنية بالثواني
$maxOps = 5;     // الحد الأقصى للأوامر في الدقيقة

$data = @json_decode(@file_get_contents($limitFile), true) ?: [];
// إزالة السجلات القديمة
foreach ($data as $k => $arr) {
  $data[$k] = array_filter($arr, fn($t) => $now - $t < $window);
}
if (!isset($data[$ip])) $data[$ip] = [];
// تحقق من العدد
if (count($data[$ip]) >= $maxOps) {
  http_response_code(429);
  echo json_encode(['ok'=>false,'error'=>'too many requests']);
  exit;
}
// أضف الوقت الحالي واحفظ
$data[$ip][] = $now;
@file_put_contents($limitFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));


echo json_encode([
  'ok'=>true,
  'dry_run'=>$dry,
  'env'=>ZAMZAM_ENV_HOST,
  'results'=>$results
  ], JSON_UNESCAPED_UNICODE);
