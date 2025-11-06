<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') throw new RuntimeException('Empty body');

  $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

  // تحقق كلمة السر
  if (($data['password'] ?? '') !== 'zamzam2025') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'BAD_PASSWORD'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  unset($data['password']);

  // مسارات
  $adminDir  = __DIR__;                    // /public_html/admin_v2
  $rootDir   = dirname($adminDir);         // /public_html
  $backupDir = $adminDir . '/backups';
  if (!is_dir($backupDir)) @mkdir($backupDir, 0775, true);

  $ts = date('Ymd_His');

  // نسخ احتياطي قبل الكتابة
  @copy($adminDir . '/data.json', $backupDir . "/data.json.$ts.bak");
  @copy($rootDir  . '/data.js',   $backupDir . "/data.js.$ts.bak");

  // 1) كتابة /admin_v2/data.json كاملًا (شكل التحرير + شكل الموقع)
  $jsonAdmin = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($jsonAdmin === false) throw new RuntimeException('JSON encode admin failed');
  atomic_write($adminDir . '/data.json', $jsonAdmin);

  // 2) توليد /data.js لما يستهلكه الموقع (شكل الموقع فقط)
  $sitePayload = [
    'metadata'       => $data['metadata']       ?? new stdClass(),
    'mergedOffers'   => $data['mergedOffers']   ?? ['active'=>false,'title'=>'','items'=>[]],
    'featuredOffers' => $data['featuredOffers'] ?? ['active'=>false,'title'=>'','items'=>[]],
    'companies'      => $data['companies']      ?? [],
  ];
  $js = "window.ZAMZAM_DATA=" . json_encode($sitePayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
      . ";\nif(typeof window!=='undefined'&&window.ZAMZAM_DATA&&!window.DATA){window.DATA=window.ZAMZAM_DATA;}\n"
      . "/* build_ts:$ts */\n";
  atomic_write($rootDir . '/data.js', $js);

  echo json_encode(['ok'=>true,'message'=>'saved','regen'=>true], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function atomic_write(string $path, string $content): void {
  $tmp = $path . '.tmp';
  $bytes = @file_put_contents($tmp, $content, LOCK_EX);
  if ($bytes === false) throw new RuntimeException('WRITE_TMP_FAILED: '.$path);
  if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('RENAME_FAILED: '.$path); }
  @chmod($path, 0664);
}
