<?php
declare(strict_types=1);
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../config/db.php';

function respond(array $arr) { echo json_encode($arr); exit; }

$appId   = defined('ONESIGNAL_APP_ID')   ? ONESIGNAL_APP_ID   : '';
$restKey = defined('ONESIGNAL_REST_KEY') ? ONESIGNAL_REST_KEY : '';
if (!$appId || !$restKey) respond(['ok'=>false,'error'=>'Missing OneSignal credentials']);

$order_id        = $_POST['order_id'] ?? '';
$status          = $_POST['status'] ?? '';
$external_id_in  = $_POST['external_id'] ?? null;     // optional
$subscription_id = $_POST['subscription_id'] ?? null; // optional

if (!$external_id_in) {
  $external_id_in = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);
}
if (!$order_id || !$status) respond(['ok'=>false,'error'=>'Missing order_id/status']);

$title = 'Order Update';
$body  = "Your order #$order_id is now: $status";

// Prefer targeting the specific device if we have it
$target = [];
if ($subscription_id) {
  $target['include_subscription_ids'] = [ (string)$subscription_id ];
} elseif ($external_id_in) {
  $target['include_external_user_ids'] = [ (string)$external_id_in ];
  // Nudge OneSignal to use push for external ids
  $target['channel_for_external_user_ids'] = 'push';
} else {
  respond(['ok'=>false,'error'=>'No target: need subscription_id or external_id']);
}

// unique topic prevents silent dedupe/collapse on some browsers
$topic = 'order-' . preg_replace('~[^a-zA-Z0-9_-]~','', (string)$order_id) . '-' . time();

$payload = array_merge([
  'app_id'   => $appId,
  'headings' => ['en' => $title],
  'contents' => ['en' => $body],
  'ttl'      => 900,
  'web_push_topic' => $topic,
], $target);

$ch = curl_init('https://api.onesignal.com/notifications');
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => [
    'Content-Type: application/json; charset=utf-8',
    'Authorization: Basic ' . $restKey,
  ],
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 20,
]);
$res  = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
  error_log('[OneSignal] cURL error: ' . $err);
  respond(['ok'=>false,'error'=>'Transport error','detail'=>$err]);
}

$data = json_decode($res, true);
if ($http >= 200 && $http < 300 && !empty($data['id'])) {
  respond(['ok'=>true,'id'=>$data['id'],'http'=>$http,'payload'=>$payload]);
}

// Log enough to see rejections/mis-targeting
error_log('[OneSignal] API error http=' . $http . ' resp=' . $res . ' payload=' . json_encode($payload));
respond(['ok'=>false,'error'=>'API error','http'=>$http,'response'=>$data,'payload'=>$payload]);