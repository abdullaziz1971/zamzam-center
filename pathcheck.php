<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/** يفحص ثلاث طبقات مجلدات: userRoot > domainRoot > public_html */
function statP($p){$e=$p&&file_exists($p);return[
 'in'=>$p,'real'=>$e?@realpath($p):null,'exists'=>$e,'dir'=>$e?is_dir($p):false,
 'perm'=>$e?substr(sprintf('%o',@fileperms($p)),-4):null,'w'=>$e?is_writable($p):false
];}
function ls($d,$n=30){
 if(!is_dir($d))return null;$a=@scandir($d)?:[];$o=[];
 foreach($a as $x){if($x==='.'||$x==='..')continue;$p=rtrim($d,'/').'/'.$x;
  $o[]=['name'=>$x,'dir'=>is_dir($p),'w'=>is_writable($p)];
  if(count($o)>=$n)break;}
 return ['dir'=>$d,'sample'=>$o];
}

$doc = rtrim($_SERVER['DOCUMENT_ROOT']??'', '/');      // …/public_html
$domainRoot = dirname($doc);                            // …/domains/DOMAIN
$userRoot   = dirname($domainRoot);                     // …/home/USER
$maybeDup   = strpos($doc,'/public_html/public_html')!==false;

$cand = [
 'public_html'         => $doc,
 'domainRoot'          => $domainRoot,
 'userRoot'            => $userRoot,
 'admin_in_public'     => $doc.'/admin_v2',
 'admin_at_domainRoot' => $domainRoot.'/admin_v2',
 'admin_at_userRoot'   => $userRoot.'/admin_v2',
 'data_js'             => $doc.'/data.js',
 'data_json_in_public' => $doc.'/admin_v2/data.json',
 'data_json_at_domain' => $domainRoot.'/admin_v2/data.json',
 'data_json_at_user'   => $userRoot.'/admin_v2/data.json',
 'backups_in_public'   => $doc.'/admin_v2/backups',
 'backups_at_domain'   => $domainRoot.'/admin_v2/backups',
 'backups_at_user'     => $userRoot.'/admin_v2/backups',
];

$stats=[]; foreach($cand as $k=>$p){ $stats[$k]=statP($p); }
$dirs = [
 'ls_public_html' => ls($doc),
 'ls_domainRoot'  => ls($domainRoot),
 'ls_userRoot'    => ls($userRoot),
 'ls_admin_in_public'     => ls($cand['admin_in_public']),
 'ls_admin_at_domainRoot' => ls($cand['admin_at_domainRoot']),
 'ls_admin_at_userRoot'   => ls($cand['admin_at_userRoot']),
];

$write = [];
foreach (['admin_in_public','admin_at_domainRoot','admin_at_userRoot'] as $k){
  $d = $cand[$k]; $f = $d.'/.__probe_'.time().'.txt';
  $ok=false;$err=null;
  if(is_dir($d)){ $ok=@file_put_contents($f,'probe')!==false; if(!$ok){$err=error_get_last()['message']??'unknown';} else {@unlink($f);} }
  else{$err='dir_missing';}
  $write[$k]=['dir'=>$d,'ok'=>$ok,'err'=>$err];
}

$layout='unknown';
if($stats['admin_in_public']['exists'])     $layout='admin_inside_public_html';
elseif($stats['admin_at_domainRoot']['exists']) $layout='admin_at_domain_root';
elseif($stats['admin_at_userRoot']['exists'])   $layout='admin_at_user_root';

$rec=[];
if($layout==='admin_inside_public_html'){
  $rec=['ALLOWED_ROOTS'=>[$doc,$cand['admin_in_public']],'BACKUPS'=>$cand['backups_in_public'],'DATA_JS'=>$cand['data_js'],'DATA_JSON'=>$cand['data_json_in_public'],'note'=>'admin_v2 داخل public_html'];
}elseif($layout==='admin_at_domain_root'){
  $rec=['ALLOWED_ROOTS'=>[$doc,$cand['admin_at_domainRoot']],'BACKUPS'=>$cand['backups_at_domain'],'DATA_JS'=>$cand['data_js'],'DATA_JSON'=>$cand['data_json_at_domain'],'note'=>'admin_v2 شقيق public_html'];
}elseif($layout==='admin_at_user_root'){
  $rec=['ALLOWED_ROOTS'=>[$doc,$cand['admin_at_userRoot']],'BACKUPS'=>$cand['backups_at_user'],'DATA_JS'=>$cand['data_js'],'DATA_JSON'=>$cand['data_json_at_user'],'note'=>'admin_v2 في user root'];
}

echo json_encode([
 'meta'=>[
   'time'=>date('Y-m-d H:i:s'),'server'=>$_SERVER['SERVER_NAME']??'',
   'php'=>PHP_VERSION,'sapi'=>php_sapi_name(),
   'document_root_raw'=>$_SERVER['DOCUMENT_ROOT']??null,
   'maybe_dup_public_html'=>$maybeDup
 ],
 'stats'=>$stats,'dir_samples'=>$dirs,'write_tests'=>$write,
 'layout_detected'=>$layout,'config_recommendation'=>$rec
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
