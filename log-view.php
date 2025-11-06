<?php
// admin_v2/log-view.php — عرض آمن لآخر أسطر من سجّل الوكيل (DEV)
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/agent-config.php';
session_start();

// نفس حماية لوحة الوكيل: بيئة الديف + جلسة دخول من agent-console.php
if (!ZAMZAM_AGENT_ENABLED || ($_SERVER['HTTP_HOST'] ?? '') !== ZAMZAM_ENV_HOST) {
  http_response_code(403); echo "<h3>Forbidden</h3>"; exit;
}
if (!isset($_SESSION['ok']) || $_SESSION['ok'] !== true) {
  http_response_code(401); echo "<h3>Unauthorized</h3><p>سجّل الدخول من agent-console.php أولاً.</p>"; exit;
}

$log = __DIR__ . '/agent-actions.log';
$limit = isset($_GET['n']) ? max(10, min(1000, (int)$_GET['n'])) : 200;

// دالة tail بسيطة لقراءة آخر N أسطر بدون تحميل الملف كاملًا
function tail_lines(string $file, int $lines = 200): string {
  if (!is_file($file)) return '';
  $f = fopen($file, 'rb');
  if (!$f) return '';
  $buffer = '';
  $chunkSize = 8192;
  $pos = -1;
  $lineCount = 0;
  fseek($f, 0, SEEK_END);
  $fileSize = ftell($f);
  while ($fileSize > 0 && $lineCount <= $lines) {
    $seek = max(0, $fileSize - $chunkSize);
    $len = $fileSize - $seek;
    fseek($f, $seek);
    $chunk = fread($f, $len);
    $buffer = $chunk . $buffer;
    $fileSize = $seek;
    $lineCount = substr_count($buffer, "\n");
    if ($fileSize === 0) break;
  }
  fclose($f);
  $rows = explode("\n", trim($buffer));
  $rows = array_slice($rows, -$lines);
  return implode("\n", $rows);
}

$body = tail_lines($log, $limit);
?>
<!doctype html>
<meta charset="utf-8">
<title>Zamzam Agent — Logs (last <?=$limit?> lines)</title>
<style>
body{font-family:system-ui,Segoe UI,Arial;max-width:960px;margin:24px auto;padding:0 12px;direction:rtl}
pre{background:#0b1;color:#0f0;padding:12px;overflow:auto;white-space:pre-wrap;border-radius:6px}
a{color:#0a7;text-decoration:none}
</style>

<h2>📜 سجّل الوكيل — آخر <?=$limit?> سطر</h2>
<p><a href="agent-console.php">↩ العودة للوكيل</a> — يمكنك تغيير عدد الأسطر بإضافة <code>?n=500</code> في العنوان.</p>
<pre><?=htmlspecialchars($body ?: "لا يوجد سجل بعد.")?></pre>
