<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$TOKEN = 'APCOA-SECRET-CHANGE-ME';
$dir   = __DIR__ . '/data';
$file  = $dir . '/state.json';

$given = $_GET['token'] ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
if ($TOKEN !== $given) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }

if (!is_dir($dir)) { mkdir($dir, 0775, true); }
if (!file_exists($file)) {
  file_put_contents($file, json_encode([
    'version'=>0,'updated_at'=>time(),
    'state'=>['users'=>[],'shifts'=>[],'rounds'=>[],'activity'=>[]]
  ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function read_state($file){
  $fp = fopen($file, 'r'); if(!$fp){ return ['version'=>0,'state'=>['users'=>[],'shifts'=>[],'rounds'=>[],'activity'=>[]]]; }
  flock($fp, LOCK_SH);
  $content = stream_get_contents($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  $json = json_decode($content, true);
  return $json ?: ['version'=>0,'state'=>['users'=>[],'shifts'=>[],'rounds'=>[],'activity'=>[]]];
}
function write_state($file, $data){
  $fp = fopen($file, 'c+'); if(!$fp){ return false; }
  flock($fp, LOCK_EX);
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  return true;
}

$data = read_state($file);

if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode($data); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $body = json_decode(file_get_contents('php://input'), true);
  $client_version = intval($body['version'] ?? -1);
  $state = $body['state'] ?? null;
  $force = !empty($body['force']);

  if (!is_array($state)) { http_response_code(400); echo json_encode(['error'=>'invalid_state']); exit; }

  $server_version = intval($data['version'] ?? 0);
  if (!$force && $client_version < $server_version) {
    http_response_code(409);
    echo json_encode(['error'=>'version_mismatch','version'=>$server_version]); exit;
  }

  $new = [
    'version' => $server_version + 1,
    'updated_at' => time(),
    'state' => [
      'users' => $state['users'] ?? [],
      'shifts' => $state['shifts'] ?? [],
      'rounds' => $state['rounds'] ?? [],
      'activity' => $state['activity'] ?? [],
    ],
  ];
  write_state($file, $new);
  echo json_encode(['ok'=>true,'version'=>$new['version']]); exit;
}

http_response_code(405); echo json_encode(['error'=>'method_not_allowed']);
