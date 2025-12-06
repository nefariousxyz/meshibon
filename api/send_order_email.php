<?php
declare(strict_types=1);
header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../config/db.php';

function respond(array $arr) { echo json_encode($arr); exit; }

$order_id = $_POST['order_id'] ?? '';
$status   = $_POST['status'] ?? '';
$email    = $_POST['email'] ?? '';
$name     = $_POST['name'] ?? '';

if (!$order_id || !$status) respond(['ok'=>false,'error'=>'Missing order_id/status']);

// If client didn’t pass email/name, fall back to session
if (!$email) $email = (string)($_SESSION['user']['email'] ?? '');
if (!$name)  $name  = trim((string)(($_SESSION['user']['firstname'] ?? '') . ' ' . ($_SESSION['user']['lastname'] ?? '')));

if (!$email) respond(['ok'=>false,'error'=>'Missing user email']);

// Only send on Ready to Pickup (guard)
if (strcasecmp($status, 'Ready to Pickup') !== 0) {
  respond(['ok'=>true,'skipped'=>true,'reason'=>'Status not Ready to Pickup']);
}

$subject = "Your Order #$order_id is Ready for Pickup!";
$message = "
<html>
<body style='font-family:Segoe UI,Arial,sans-serif; color:#222;'>
  <h2 style='margin:0 0 10px;'>Hi " . htmlspecialchars($name ?: 'there', ENT_QUOTES, 'UTF-8') . ",</h2>
  <p>Your order <strong>#". htmlspecialchars((string)$order_id, ENT_QUOTES, 'UTF-8') ."</strong> is now <strong>Ready to Pickup</strong>.</p>
  <p>Please visit the counter soon to collect your order.</p>
  <hr style='border:none;border-top:1px solid #eee;margin:16px 0;'>
  <p style='font-size:12px;color:#666;margin:0;'>Thank you for ordering with us!</p>
</body>
</html>
";

$fromEmail = defined('SYSTEM_FROM_EMAIL') ? SYSTEM_FROM_EMAIL : ('no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-type: text/html; charset=UTF-8\r\n";
$headers .= "From: No-Reply <{$fromEmail}>\r\n";

$sent = @mail($email, $subject, $message, $headers);
respond(['ok' => (bool)$sent]);