<?php
declare(strict_types=1);
session_start();

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../config/fcm.php';

header('Content-Type: application/json; charset=UTF-8');

// Protect this endpoint (call it only from your internal admin/update code)
$hdr = $_SERVER['HTTP_X_APP_KEY'] ?? '';
if (!hash_equals(FCM_APP_SECRET, $hdr)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$userId = (int)($input['user_id'] ?? 0);
$status = trim((string)($input['status'] ?? ''));
$txId   = trim((string)($input['transaction_id'] ?? ''));

if ($userId <= 0 || $status === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing fields']); exit;
}

// Option 1: topic per user (no token storage)
$topic = 'user_'.$userId;

// Optional: verify the order belongs to user, etc.
$validStatuses = ['Order Received','Preparing','Ready to Pickup'];
if (!in_array($status, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid status']); exit;
}

$title = 'Order '.$status;
$body  = $status === 'Preparing'
       ? 'Your order is being prepared.'
       : ($status === 'Ready to Pickup'
          ? 'Your order is ready. Please pick it up.'
          : 'We received your order.');

$data  = [
  'status' => $status,
  'transaction_id' => $txId,
  'user_id' => (string)$userId,
];

try {
    $resp = fcm_send_to_topic(FCM_PROJECT_ID, FCM_SA_JSON_PATH, $topic, $title, $body, $data);
    echo json_encode(['ok'=>true,'resp'=>$resp]); exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
}
