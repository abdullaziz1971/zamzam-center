<?php
/**
 * admin_v2/import-from-datajs.php
 * يقبل JSON من /data.js (ZAMZAM_DATA أو DATA) أو من لوحة v2 ويولّد:
 *  - admin_v2/data.json   المصدر الرئيسي للوحة
 *  - /data.js             المصدر المقروء من الموقع
 *
 * ملاحظة: لا يطلب كلمة سر لتسهيل الهجرة من الموقع. للحماية فعّل قيود الخادم.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  // مسارات
  $adminDir  = __DIR__ . DIRECTORY_SEPARATOR;
  $rootDir   = dirname(__DIR__) . DIRECTORY_SEPARATOR;
  $jsonFile  = $adminDir . 'data.json';
  $jsFile    = $rootDir . 'data.js';
  $backupDir = $adminDir . 'backups';

  if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }

  // قراءة الجسم
  $raw = file_get_contents('php://input');
  if (!$raw) throw new RuntimeException('لا يوجد جسم JSON');

  $in = json_decode($raw, true);
  if (!is_array($in)) throw new RuntimeException('JSON غير صالح');

  // قبول الحقول البديلة (ZAMZAM_DATA أو DATA)
  if (isset($in['ZAMZAM_DATA']) && is_array($in['ZAMZAM_DATA'])) $in = $in['ZAMZAM_DATA'];
  if (isset($in['DATA']) && is_array($in['DATA']))               $in = $in['DATA'];

  // تطبيع
  $norm = normalize_input($in);

  // أخذ نسخة احتياطية
  backup_if_exists($jsonFile, $backupDir);
  backup_if_exists($jsFile,   $backupDir);

  // كتابة data.json
  $ok1 = (bool)file_put_contents($jsonFile, json_encode($norm, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
  if (!$ok1) throw new RuntimeException('تعذّر كتابة data.json');

  $regen = (isset($_GET['regen']) && $_GET['regen'] == '1');
  if ($regen) {
    $js = 'window.ZAMZAM_DATA=' . json_encode($norm, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ';';
    $ok2 = (bool)file_put_contents($jsFile, $js, LOCK_EX);
    if (!$ok2) throw new RuntimeException('تعذّر كتابة data.js');
  }

  http_response_code(200);
  echo json_encode(['ok'=>true,'regen'=>$regen,'json'=>$jsonFile,'js'=>$regen?$jsFile:null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ================= وظائف مساعدة ================= */

function backup_if_exists(string $path, string $dir): void {
  if (!is_file($path)) return;
  $ts = date('Ymd_His');
  $base = basename($path);
  @copy($path, $dir . DIRECTORY_SEPARATOR . $ts . '_' . $base);
}

function normalize_input(array $in): array {
  $out = $in;

  // metadata
  if (!isset($out['metadata']) || !is_array($out['metadata'])) $out['metadata'] = [];
  $out['metadata']['siteName']        = $out['metadata']['siteName']        ?? 'مركز ينابيع زمزم التجاري';
  $out['metadata']['whatsappNumber']  = $out['metadata']['whatsappNumber']  ?? '';
  $out['metadata']['lastUpdate']      = date('c');
  $out['metadata']['version']         = '2.0';

  // mergedOffers
  if (!isset($out['mergedOffers']) || !is_array($out['mergedOffers'])) $out['mergedOffers'] = [];
  $mo = &$out['mergedOffers'];
  $mo['active']       = isset($mo['active']) ? (bool)$mo['active'] : true;
  $mo['title']        = $mo['title']        ?? '💥 عروض الدمج المميزة 🔥';
  $mo['expiryLogic']  = $mo['expiryLogic']  ?? 'saturday_tuesday';

  // strong → items
  if (isset($mo['strong']) && is_array($mo['strong']) && isset($mo['strong']['items']) && is_array($mo['strong']['items'])) {
    $s = [];
    foreach ($mo['strong']['items'] as $i => $it) {
      $left  = trim(implode(' ', array_filter([ $it['pairA_title']??'', $it['pairA_unit']??'', $it['pairA_packaging']??'' ])));
      $right = trim(implode(' ', array_filter([ $it['pairB_title']??'', $it['pairB_unit']??'', $it['pairB_packaging']??'' ])));
      $title = $it['title'] ?? (($left && $right)? $left.' + '.$right : ($left ?: $right));
      $price = isset($it['price']) ? (float)$it['price'] : 0.0;
      if ($title) $s[] = ['id'=>$it['id'] ?? ('m_'.($i+1)), 'title'=>$title, 'price'=>$price];
    }
    $mo['items'] = $mo['items'] ?? $s;
  } elseif (isset($mo['items']) && is_array($mo['items'])) {
    // ok
  } else {
    $mo['items'] = [];
  }

  // free (احتفاظ فقط)
  if (!isset($mo['free']) || !is_array($mo['free'])) $mo['free'] = ['active'=>false,'title'=>'الدمج الحر','items'=>[]];

  // featuredOffers
  if (!isset($out['featuredOffers']) || !is_array($out['featuredOffers'])) $out['featuredOffers'] = [];
  $fo = &$out['featuredOffers'];
  $fo['active']      = isset($fo['active']) ? (bool)$fo['active'] : true;
  $fo['title']       = $fo['title'] ?? '✨ عروض الأيام الثلاث المميزة!';
  $fo['expiryLogic'] = $fo['expiryLogic'] ?? 'saturday_tuesday';
  $fo['items']       = array_values(array_map(function($it, $idx){
    $desc = $it['description'] ?? ( ($it['packaging'] ?? '') ?: ($it['unit'] ?? '—') );
    return [
      'id'              => $it['id'] ?? ('featured_'.($idx+1)),
      'title'           => $it['title'] ?? '',
      'description'     => $desc ?: '—',
      'originalPrice'   => isset($it['originalPrice'])   ? (float)$it['originalPrice']   : 0.0,
      'discountedPrice' => isset($it['discountedPrice']) ? (float)$it['discountedPrice'] : 0.0,
      'unit'            => $it['unit'] ?? '',
      'packaging'       => $it['packaging'] ?? '',
      'image'           => $it['image'] ?? ''
    ];
  }, is_array($fo['items']??null) ? $fo['items'] : [], array_keys($fo['items']??[])));

  // الشركات: قبول شكل قديم (products منفصلة) أو الشكل الجديد
  if (!isset($out['companies']) || !is_array($out['companies'])) {
    $out['companies'] = [];
  }
  // تنظيف المنتجات
  foreach ($out['companies'] as &$c) {
    $c['active']       = isset($c['active']) ? (bool)$c['active'] : true;
    $c['displayOrder'] = isset($c['displayOrder']) ? (int)$c['displayOrder'] : 0;
    $prods = is_array($c['products'] ?? null) ? $c['products'] : [];
    $clean = [];
    foreach ($prods as $i => $p) {
      $base = [
        'id'          => $p['id'] ?? ($c['id'].'_'.($i+1)),
        'title'       => $p['title'] ?? '',
        'description' => $p['description'] ?? ($p['note'] ?? '—'),
        'packaging'   => $p['packaging'] ?? '',
        'notes'       => $p['notes'] ?? '',
        'category'    => $p['category'] ?? null,
        'image'       => $p['image'] ?? ''
      ];
      if (!empty($p['hasVariants']) && is_array($p['variants'] ?? null)) {
        $clean[] = array_merge($base, [
          'hasVariants' => true,
          'variants'    => array_values(array_map(function($v){
            return [
              'label' => (string)($v['label'] ?? ''),
              'price' => isset($v['price']) ? (float)$v['price'] : 0.0
            ];
          }, $p['variants']))
        ]);
      } else {
        // price: أخذ prices.carton أو p.price
        $price = null;
        if (isset($p['prices']['carton'])) $price = (float)$p['prices']['carton'];
        elseif (isset($p['price']))        $price = (float)$p['price'];
        $clean[] = array_merge($base, [
          'hasVariants' => false,
          'price'       => $price
        ]);
      }
    }
    $c['products'] = $clean;
  }
  unset($c);

  return $out;
}
