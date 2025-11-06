<?php
// admin_v2/audit.php
// UTF-8
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

$root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__).'/public_html','/');
$paths = [
  'public_html' => $root,
  'admin_v2'    => dirname(__FILE__),
];
$maxFiles = 20000;
$fix = isset($_GET['fix']);
$now = date('Ymd_His');
$issues = ['html_missing_alias'=>[], 'datajs_missing'=>[], 'datajs_no_alias'=>[], 'broken_trailing_comma'=>[]];
$total = 0;

function listFiles($base) {
  $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
  foreach ($rii as $f) {
    if ($f->isFile()) yield $f->getPathname();
  }
}

function hasDataScript($html) {
  return (bool)preg_match('#<script[^>]+src=["\']/?data\.js(\?[^"\']*)?["\']#i', $html);
}
function hasAliasInline($html) {
  return (bool)preg_match('#window\.(DATA)\s*=\s*window\.ZAMZAM_DATA#', $html);
}
function checkDataJs($code) {
  $hasZZ = (bool)preg_match('#window\.ZAMZAM_DATA\s*=\s*{#', $code);
  $hasAlias = (bool)preg_match('#window\.(DATA)\s*=\s*window\.ZAMZAM_DATA#', $code);
  $badTrail = (bool)preg_match('#\]\}\],\s*};\s*$#', $code); // ]}],};
  return [$hasZZ, $hasAlias, $badTrail];
}

function safeBackupAndWrite($path, $content) {
  $backupDir = dirname(__FILE__).'/backups';
  @is_dir($backupDir) || @mkdir($backupDir, 0775, true);
  $bak = $backupDir.'/'.basename($path).'.'.date('Ymd_His').'.bak';
  @copy($path, $bak);
  return file_put_contents($path, $content) !== false ? [$bak, null] : [null, 'write_fail'];
}

$dataJsPaths = [];
foreach ($paths as $label => $base) {
  $i = 0;
  foreach (listFiles($base) as $p) {
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    if (!in_array($ext, ['html','htm','js'], true)) continue;
    $total++; if ($total > $maxFiles) break 2;
    $rel = str_replace($root.'/','', $p);

    if ($ext === 'html' || $ext === 'htm') {
      $html = @file_get_contents($p) ?: '';
      if (hasDataScript($html) && !hasAliasInline($html)) {
        $issues['html_missing_alias'][] = $rel;
      }
    } elseif ($ext === 'js') {
      if (basename($p) === 'data.js') $dataJsPaths[] = $p;
    }
  }
}

// فحص data.js
foreach (array_unique($dataJsPaths) as $p) {
  $code = @file_get_contents($p) ?: '';
  [$hasZZ, $hasAlias, $badTrail] = checkDataJs($code);
  if (!$hasZZ) $issues['datajs_missing'][] = str_replace($root.'/','',$p);
  if ($badTrail) $issues['broken_trailing_comma'][] = str_replace($root.'/','',$p);
  if (!$hasAlias) $issues['datajs_no_alias'][] = str_replace($root.'/','',$p);

  if ($fix) {
    $changed = false;
    // أصلح الذيل إذا كان ]}],};
    if ($badTrail) {
      $code = preg_replace('#\]\}\],\s*};\s*$#', '}]};', $code);
      $changed = true;
    }
    // أضف جسر التوافق إن غاب
    if (!$hasAlias && $hasZZ) {
      $code .= "\n\n// compat alias\nif(typeof window!=='undefined'&&window.ZAMZAM_DATA&&!window.DATA){window.DATA=window.ZAMZAM_DATA;}\n";
      $changed = true;
    }
    if ($changed) {
      [$bak, $err] = safeBackupAndWrite($p, $code);
      echo ($err ? "❌ فشل كتابة: $p\n" : "✅ تم إصلاح: $p | نسخة احتياطية: $bak\n");
    }
  }
}

// تقرير
echo "=== تقرير التدقيق ===\n";
echo "إجمالي الملفات المفحوصة: $total\n\n";

echo "HTML تحتاج جسر alias داخل الصفحة بعد data.js:\n";
echo $issues['html_missing_alias'] ? " - ".implode("\n - ", $issues['html_missing_alias'])."\n" : " - لا شيء\n";

echo "\nملفات data.js لا تحتوي window.ZAMZAM_DATA:\n";
echo $issues['datajs_missing'] ? " - ".implode("\n - ", $issues['datajs_missing'])."\n" : " - لا شيء\n";

echo "\nملفات data.js بذيل خاطئ (]}],;}):\n";
echo $issues['broken_trailing_comma'] ? " - ".implode("\n - ", $issues['broken_trailing_comma'])."\n" : " - لا شيء\n";

echo "\nملفات data.js بلا جسر alias داخلي:\n";
echo $issues['datajs_no_alias'] ? " - ".implode("\n - ", $issues['datajs_no_alias'])."\n" : " - لا شيء\n";

echo "\nنصيحة: إن لم تستخدم ?fix=1 أعد تشغيل الأداة مع: /admin_v2/audit.php?fix=1 لتطبيق الإصلاحات تلقائياً مع نسخ احتياطية.\n";
