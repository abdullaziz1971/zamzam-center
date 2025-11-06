<?php
// admin_v2/migrate_from_site.php
// استيراد شركة "البتراء" فقط من /data.js إلى admin_v2/data.json مع دمج ذكي
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$root     = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/');
$adminDir = dirname(__FILE__);
$dataJson = $adminDir . '/data.json';
$backups  = $adminDir . '/backups';
$dataJs   = $root . '/data.js';

@mkdir($backups, 0775, true);

// 1) اقرأ JSON اللوحة الحالي
$admin = is_file($dataJson) ? json_decode(file_get_contents($dataJson), true) : null;
if (!is_array($admin)) $admin = ['companies'=>[], 'featuredOffers'=>['active'=>false,'title'=>'','items'=>[]], 'mergedOffers'=>['active'=>false,'title'=>'','items'=>[]]];

// 2) استخرج JSON من data.js (إزالة "window.ZAMZAM_DATA =")
$raw = @file_get_contents($dataJs);
if ($raw === false || trim($raw) === '') { http_response_code(404); echo json_encode(['error'=>'لم أجد data.js']); exit; }
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
if (preg_match('/window\.ZAMZAM_DATA\s*=\s*(\{.*\});?\s*$/s', $raw, $m)) {
  $jsonText = $m[1];
} else if (preg_match('/(?:const|let|var)\s+ZAMZAM_DATA\s*=\s*(\{.*\});?\s*$/s', $raw, $m)) {
  $jsonText = $m[1];
} else {
  http_response_code(400); echo json_encode(['error'=>'لم أستطع استخراج JSON من data.js']); exit;
}

$site = json_decode($jsonText, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_array($site)) { http_response_code(400); echo json_encode(['error'=>'JSON غير صالح في data.js']); exit; }

// 3) ابحث عن شركة البتراء في JSON الموقع
$srcCompanies = $site['companies'] ?? [];
$petra = null;
foreach ($srcCompanies as $c) {
  if (
    (isset($c['id']) && strtolower((string)$c['id']) === 'petra') ||
    (isset($c['name']) && mb_strpos($c['name'], 'البتراء') !== false)
  ) { $petra = $c; break; }
}
if (!$petra) { http_response_code(404); echo json_encode(['error'=>'لم أجد شركة البتراء في data.js']); exit; }

// 4) دمج البتراء داخل admin_v2/data.json (استبدال منتجات الشركة فقط، دون مسّ الباقي)
$adminCompanies = $admin['companies'] ?? [];
$found = false;
foreach ($adminCompanies as &$c) {
  if (
    (isset($c['id']) && strtolower((string)$c['id']) === 'petra') ||
    (isset($c['name']) && mb_strpos($c['name'], 'البتراء') !== false)
  ) {
    $c['id'] = $c['id'] ?? 'petra';
    $c['name'] = $c['name'] ?? 'شركة البتراء';
    $c['active'] = true;
    $c['products'] = array_values($petra['products'] ?? []);
    $found = true;
    break;
  }
}
unset($c);

if (!$found) {
  $adminCompanies[] = [
    'id' => 'petra',
    'name' => 'شركة البتراء',
    'active' => true,
    'displayOrder' => 10,
    'products' => array_values($petra['products'] ?? [])
  ];
}

$admin['companies'] = $adminCompanies;

// 5) نسخ احتياطي + حفظ JSON + توليد data.js
$now = date('Y-m-d\TH-i-s');
@copy($dataJson, $backups . "/data.before-petra.$now.json");
file_put_contents($dataJson, json_encode($admin, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

$js = "window.ZAMZAM_DATA = " . json_encode($admin, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ";";
@copy($dataJs, $backups . "/data.before-petra.$now.js");
file_put_contents($dataJs, $js);

echo json_encode(['ok'=>true,'imported'=>'petra','products'=>count($petra['products'] ?? [])]);
