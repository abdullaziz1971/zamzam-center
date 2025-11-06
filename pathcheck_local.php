<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/** يفحص موضع الملف الحالي ويستنتج الطبقات الثلاث */
function statP($p){$e=$p&&file_exists($p);return[
 'in'=>$p,'real'=>$e?@realpath($p):null,'exists'=>$e,'dir'=>$e?is_dir($p):false,
 'perm'=>$e?substr(sprintf('%o',@fileperms($p)),-4):null,'w'=>$e?is_writable($p):false
];}
function up($p,$n){for($i=0;$i<$n;$i++){$p=dirname($p);}return $p;}

$here      = __DIR__;
$maybePH   = preg_match('#/public_html(/|$)#',$here)===1;
$public    = $maybePH ? preg_replace('#^(.*?/public_html)(/.*)?$#','$1',$here) : null;
$domain    = $public ? dirname($public) : up($here,1);
$user      = up($domain,1);

$cand = [
 'here_admin_v2'   => $here,
 'public_html'     => $public,
 'domainRoot'      => $domain,
 'userRoot'        => $user,
 'data_js'         => $public ? $public.'/data.js' : null,
 'data_json_here'  => $here.'/data.json',
 'backups_here'    => $here.'/backups',
];

$stats=[]; foreach($cand as $k=>$p){ $stats[$k]=statP($p); }

$rec = [
 'ALLOWED_ROOTS' => array_values(array_filter([$public,$here])),
 'BACKUPS'       => $cand['backups_here'],
 'DATA_JS'       => $cand['data_js'],
 'DATA_JSON'     => $cand['data_json_here'],
 'note'          => $maybePH ? 'admin_v2 تحت public_html' : 'admin_v2 خارج public_html',
];

echo json_encode([
 'meta'=>['time'=>date('Y-m-d H:i:s'),'server'=>$_SERVER['SERVER_NAME']??'','php'=>PHP_VERSION],
 'stats'=>$stats,'config_recommendation'=>$rec
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
