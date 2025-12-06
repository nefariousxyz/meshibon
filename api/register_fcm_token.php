<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$appSecretHeader = 'X-APP-KEY';
$appSecretValue  = 'KAPSOFKAPSOFKAPOSKFPOASKFPOAKS12041920491029401924019204'; // same as MyFirebaseMessagingService

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }

$rawToken = trim((string)($_POST['token'] ?? ''));
if ($rawToken === '' || strlen($rawToken) < 20) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad token']); exit; }

$userId = 0;
$isApp  = false;

/** Path A: native app posts with header + user_id */
if (!empty($_SERVER['HTTP_' . str_replace('-', '_', strtoupper($appSecretHeader))])
    && hash_equals($appSecretValue, $_SERVER['HTTP_' . str_replace('-', '_', strtoupper($appSecretHeader))])) {
    $isApp = true;
    $userId = (int)($_POST['user_id'] ?? 0);
}

/** Path B: browser posts with session + CSRF */
if (!$isApp) {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'csrf']); exit;
    }
    $userId = (int)($_SESSION['user']['id'] ?? 0);
}

if ($userId <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'user']); exit; }

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("
        INSERT INTO fcm_tokens (user_id, token, platform, updated_at)
        VALUES (:uid, :tok, 'android', NOW())
        ON DUPLICATE KEY UPDATE token=VALUES(token), updated_at=VALUES(updated_at)
    ");
    $stmt->execute([':uid'=>$userId, ':tok'=>$rawToken]);

    // Optional: also store the latest token on users table for convenience
    try {
        $pdo->prepare("UPDATE users SET last_fcm_token = :tok WHERE id = :uid")->execute([':tok'=>$rawToken, ':uid'=>$userId]);
    } catch(Throwable $e) {}

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db','msg'=>$e->getMessage()]);
}
