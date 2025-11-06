<?php
// ======== CONFIG ========
const MCP_BASE           = 'https://dev-zamzam-center.com/zamzam_mcp_server'; // مسار خادم MCP الحالي
const BRIDGE_SECRET      = 'ZZ-Bridge-2025-Prod'; // السر الداخلي للـ MCP (X-Bridge-Secret)
const OAUTH_CLIENT_ID    = 'zamzam_client';       // عرّفه في GPT لاحقاً
const OAUTH_CLIENT_SECRET= 'super-strong-secret'; // احفظه في GPT سراً
const SIGNING_SECRET     = 'change-me-signing-key-256bit'; // لتوقيع التوكنات
const TOKEN_TTL_SECONDS  = 3600;                  // صلاحية التوكن 1 ساعة
// =========================

header('Content-Type: application/json; charset=utf-8');

function json_out($code, $arr){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function hmac_b64($data){ return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, SIGNING_SECRET, true)),'+/','-_'),'='); }
function b64($x){ return rtrim(strtr(base64_encode($x),'+/','-_'),'='); }
function now(){ return time(); }

// ----- إصدار توكن OAuth (Client Credentials) -----
function issue_token(){
  if (($_POST['grant_type'] ?? '') !== 'client_credentials') json_out(400,['error'=>'unsupported_grant_type']);
  $id  = $_POST['client_id'] ?? '';
  $sec = $_POST['client_secret'] ?? '';
  if ($id !== OAUTH_CLIENT_ID || $sec !== OAUTH_CLIENT_SECRET) json_out(401,['error'=>'invalid_client']);
  $exp = now() + TOKEN_TTL_SECONDS;
  $payload = ['iss'=>'zamzam-oauth','sub'=>$id,'scope'=>'mcp','exp'=>$exp,'iat'=>now()];
  $header  = ['alg'=>'HS256','typ'=>'JWT'];
  $jwt = b64(json_encode($header)).'.'.b64(json_encode($payload));
  $sig = hmac_b64($jwt);
  $token = $jwt.'.'.$sig;
  json_out(200,[
    'access_token'=>$token,
    'token_type'=>'Bearer',
    'expires_in'=>TOKEN_TTL_SECONDS,
    'scope'=>'mcp'
  ]);
}

// ----- التحقق من Bearer -----
function require_bearer(){
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+(.+)/i',$h,$m)) json_out(401,['error'=>'invalid_token']);
  $tok = $m[1];
  $parts = explode('.',$tok);
  if (count($parts)!==3) json_out(401,['error'=>'malformed']);
  [$h64,$p64,$sig] = $parts;
  $check = hmac_b64("$h64.$p64");
  if (!hash_equals($check,$sig)) json_out(401,['error'=>'bad_signature']);
  $payload = json_decode(base64_decode(strtr($p64,'-_','+/')),true);
  if (!$payload || ($payload['exp'] ?? 0) < now()) json_out(401,['error'=>'expired']);
  return $payload;
}

// ----- Proxy مساعد -----
function proxy($method,$path,$query='',$body=null,$ctype=null){
  $url = MCP_BASE.$path.($query?("?".$query):"");
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array_filter([
    'X-Bridge-Secret: '.BRIDGE_SECRET,
    $ctype ? 'Content-Type: '.$ctype : null
  ]));
  if ($body!==null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  curl_setopt($ch, CURLOPT_HEADER, true);
  $resp = curl_exec($ch);
  if ($resp===false){ json_out(502,['error'=>'upstream_error','detail'=>curl_error($ch)]); }
  $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $headers = substr($resp,0,$header_size);
  $payload = substr($resp,$header_size);
  curl_close($ch);
  // نُمرر الحالة والبيانات كما هي من MCP
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo $payload; exit;
}

// ====== Router بسيط ======
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// OAuth Token endpoint: POST /oauth_bridge.php/token
if (preg_match('#/oauth_bridge\.php/?token$#',$uri)){
  if ($method!=='POST') json_out(405,['error'=>'method_not_allowed']);
  issue_token();
}

// Proxy endpoints (تبدأ بـ /oauth_bridge.php/bridge/...)
if (preg_match('#/oauth_bridge\.php/bridge(/.*)$#',$uri,$m)){
  require_bearer();
  $sub = $m[1]; // مثل: /mcp_fetch.php
  // دعم العمليات القياسية:
  if ($method==='GET'){
    $q = $_SERVER['QUERY_STRING'] ?? '';
    proxy('GET',$sub,$q,null,null);
  } else {
    $raw = file_get_contents('php://input');
    $ctype = $_SERVER['CONTENT_TYPE'] ?? 'application/json';
    $q = $_SERVER['QUERY_STRING'] ?? '';
    proxy($method,$sub,$q,$raw,$ctype);
  }
}

// Root helper
if (preg_match('#/oauth_bridge\.php$#',$uri)){
  json_out(200,[
    'ok'=>true,
    'endpoints'=>[
      'token'=>'POST /oauth_bridge.php/token (grant_type=client_credentials, client_id, client_secret)',
      'bridge'=>'/oauth_bridge.php/bridge/*  (Bearer token) → proxied to '.MCP_BASE
    ]
  ]);
}

json_out(404,['error'=>'not_found']);
