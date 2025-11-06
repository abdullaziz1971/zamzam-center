<?php
// admin_v2/agent-console.php — النسخة المحمية + شريط وضع التشغيل + تأكيد قبل التنفيذ
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/agent-config.php';

// حماية الوصول: فعّال فقط على dev
if (!ZAMZAM_AGENT_ENABLED || ($_SERVER['HTTP_HOST'] ?? '') !== ZAMZAM_ENV_HOST) {
  http_response_code(403);
  echo "<h3>Forbidden: wrong host or agent disabled</h3>";
  exit;
}

// كلمة المرور (يمكن تغييرها هنا)
$PASS = 'abd5526117';

// جلسة الدخول
session_start();

// دعم الخروج عبر GET: ?logout=1
if (isset($_GET['logout'])) {
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000,
      $params["path"] ?? '/', $params["domain"] ?? '',
      $params["secure"] ?? false, $params["httponly"] ?? true
    );
  }
  session_destroy();
  header('Location: ?'); exit;
}

// شاشة كلمة المرور
if (!isset($_SESSION['ok'])) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass']) && hash_equals($PASS, $_POST['pass'])) {
    $_SESSION['ok'] = true;
    header('Location: ?'); exit;
  }
  echo '<!doctype html><meta charset="utf-8"><title>Zamzam Agent Console (DEV)</title>
  <style>body{font-family:system-ui,sans-serif;max-width:720px;margin:40px auto}input,textarea,select{width:100%;padding:8px;margin:6px 0}</style>
  <h2>🔐 Zamzam Agent Console — DEV</h2>
  <form method="post"><input type="password" name="pass" placeholder="Password"><button type="submit">Enter</button></form>';
  exit;
}

// helper: POST JSON إلى الوكيل التنفيذي
function post_json(string $url, array $payload): array {
  $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
  $opts = [
    'http' => [
      'method'  => 'POST',
      'header'  => "Content-Type: application/json; charset=utf-8\r\n",
      'content' => $raw,
      'timeout' => 20
    ]
  ];
  $ctx = stream_context_create($opts);
  $res = @file_get_contents($url, false, $ctx);
  $code = 0;
  if (isset($http_response_header)) {
    foreach ($http_response_header as $h) {
      if (preg_match('~^HTTP/.*\s(\d{3})~', $h, $m)) { $code = (int)$m[1]; break; }
    }
  }
  return ['code'=>$code, 'raw'=>$res, 'payload'=>$raw];
}

$out = null; $err = null;

// معالجة النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do'])) {
  $dry      = isset($_POST['dry_run']) ? true : false;
  $action   = $_POST['action'] ?? 'write';
  $path     = trim($_POST['path'] ?? '');
  $content  = $_POST['content'] ?? '';
  $mode     = $_POST['mode'] ?? '0644';

  if ($path === '') {
    $err = 'الرجاء إدخال المسار';
  } else {
    $op = ['action'=>$action, 'path'=>$path];
    if ($action === 'write')      $op['content_base64'] = base64_encode($content);
    elseif ($action === 'chmod')  $op['mode'] = $mode;

    $body = ['dry_run'=>$dry, 'ops'=>[$op]];

    // توقيع داخلي آمن (HMAC على الجسم بدون حقل signature)
    $rawForSig = json_encode($body, JSON_UNESCAPED_UNICODE);
    $sig  = base64_encode(hash_hmac('sha256', $rawForSig, ZAMZAM_SHARED_SECRET, true));
    $body['signature'] = $sig;

    $out = post_json('https://' . ZAMZAM_ENV_HOST . '/admin_v2/agent-update.php', $body);
  }
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Zamzam Agent Console — DEV</title>
<style>
body{font-family:system-ui,Segoe UI,Arial;max-width:900px;margin:32px auto;padding:0 12px;direction:rtl}
label{display:block;margin-top:10px;font-weight:600}
textarea{height:180px}
pre{background:#111;color:#0f0;padding:12px;overflow:auto;white-space:pre-wrap;border-radius:6px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
small{color:#666}
.btns{margin-top:12px}
a{color:#0a7;text-decoration:none}
.banner{margin:8px 0;padding:8px;border-radius:6px}
</style>

<h2>🛠️ Zamzam Agent Console — <span style="color:#0a7">DEV</span></h2>
<p><a href="?logout=1">🚪 خروج</a> — <a href="log-view.php" target="_blank">📜 عرض السجل</a></p>

<form method="post">
  <div class="row">
    <div>
      <label>العملية</label>
      <select name="action">
        <option value="write">write (إنشاء/استبدال ملف)</option>
        <option value="delete">delete (حذف ملف)</option>
        <option value="chmod">chmod (تغيير صلاحيات)</option>
      </select>
    </div>
    <div>
      <label>Dry-Run (تجربة فقط)</label>
      <input id="dryRun" type="checkbox" name="dry_run" checked>
      <small>أزل العلامة للتنفيذ الفعلي</small>
    </div>
  </div>

  <div id="modeBanner" class="banner" style="background:#eef;">
    وضع التشغيل: <b>تجربة فقط (لا يكتب على الملفات)</b>
  </div>

  <label>المسار (مثال):</label>
  <input name="path" placeholder="/public_html/test-agent.txt">

  <label>المحتوى (عند write):</label>
  <textarea name="content" placeholder="hello from zamzam agent <?=htmlspecialchars(date('c'))?>"></textarea>

  <label>وضع الصلاحيات (عند chmod):</label>
  <input name="mode" value="0644">

  <div class="btns">
    <button type="submit" name="do" value="1">تنفيذ الأمر</button>
  </div>
</form>

<?php if($err): ?>
  <p style="color:#c00"><?=$err?></p>
<?php endif; ?>

<?php if($out): ?>
  <h3>🔎 النتيجة</h3>
  <pre><?=htmlspecialchars("HTTP ".$out['code']."\n".$out['raw'])?></pre>
<?php endif; ?>

<script>
  (function(){
    var dry = document.getElementById('dryRun');
    var banner = document.getElementById('modeBanner');
    function refreshBanner(){
      if(dry.checked){
        banner.style.background = '#eef';
        banner.innerHTML = 'وضع التشغيل: <b>تجربة فقط (لا يكتب على الملفات)</b>';
      } else {
        banner.style.background = '#fee';
        banner.innerHTML = 'وضع التشغيل: <b>تنفيذ فعلي (سيكتب على الملفات)</b>';
      }
    }
    dry.addEventListener('change', refreshBanner);
    refreshBanner();

    // تأكيد قبل الإرسال إن كان تنفيذ فعلي
    var form = document.querySelector('form[method="post"]');
    form.addEventListener('submit', function(e){
      var isDo = (e.submitter && e.submitter.name === 'do');
      if(isDo && !dry.checked){
        if(!confirm('تنبيه: سيتم تنفيذ التعديل فعليًا على الملفات. هل أنت متأكد؟')) {
          e.preventDefault();
        }
      }
    });
  })();
</script>
