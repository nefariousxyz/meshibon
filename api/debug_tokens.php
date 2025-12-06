<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']['id'])) {
  http_response_code(401); echo json_encode(['ok'=>false,'error'=>'login']); exit;
}
$uid = (int)$_SESSION['user']['id'];

try {
  $stmt = $pdo->prepare("SELECT user_id, platform, LEFT(token, 20) AS token_prefix, LENGTH(token) AS len, updated_at FROM fcm_tokens WHERE user_id=? ORDER BY updated_at DESC");
  $stmt->execute([$uid]);
  echo json_encode(['ok'=>true,'rows'=>$stmt->fetchAll()]);
} catch (Throwable $e) {
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db','msg'=>$e->getMessage()]);
}
