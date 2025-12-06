<?php
session_start();
header('Content-Type: application/json');
require '../config/db.php';

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user']['id'];

$stmt = $pdo->prepare("
    SELECT transaction_id, status 
    FROM reservations 
    WHERE user_id = ? AND status != 'Picked Up' 
    ORDER BY reserved_at DESC 
    LIMIT 1
");
$stmt->execute([$userId]);
$pendingOrder = $stmt->fetch(PDO::FETCH_ASSOC);

if ($pendingOrder) {
    echo json_encode(['success' => true, 'order' => $pendingOrder]);
} else {
    echo json_encode(['success' => false, 'message' => 'No pending order']);
}