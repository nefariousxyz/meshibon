<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=UTF-8');

$appId   = getenv('ONESIGNAL_APP_ID')   ?: 'a0b31e81-19b4-44bd-b4c9-843bcc1e673c';
$restKey = getenv('ONESIGNAL_REST_KEY') ?: 'os_v2_app_uczr5aizwrcl3ngjqq54yhthhqdsj2vca32e3rnzrjgkxv6dhi6o2syjbsxkimzydtl4mn7x5tc5cvqbz4u64fb6q2vra7ehvgsslbq';

$userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);
if (!$userId) { http_response_code(400); echo json_encode(['error'=>'no session user']); exit; }

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$url    = $scheme . $host . '/';

$payload = [
  'app_id'          => $appId,
  'target_channel'  => 'push',
  'include_aliases' => ['external_id' => [ (string)$userId ]],
  'headings'        => ['en' => 'Test Push'],
  'contents'        => ['en' => 'If you see this, external_id + SW are OK.'],
  'url'             => $url,
  'collapse_id'     => 'test_push_' . (string)$userId,
];

$ch = curl_init('https://api.onesignal.com/notifications');
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Key '.$restKey],
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 12,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode(['http_code'=>$code, 'error'=>$err ?: null, 'response'=>json_decode($resp, true)]);
