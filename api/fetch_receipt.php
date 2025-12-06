<?php
session_start();
require '../config/db.php';

// --- Authorization Check ---
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    die("Access denied. Please log in.");
}

$transactionId = $_GET['tx'] ?? null;
if (!$transactionId) {
    http_response_code(400);
    die("Missing transaction ID.");
}

// --- Fetch Order & User Info ---
$stmt = $pdo->prepare("
    SELECT r.transaction_id, r.payment_type, r.reserved_at, u.id AS user_id, u.firstname, u.lastname, u.unique_id
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    WHERE r.transaction_id = ?
    LIMIT 1
");
$stmt->execute([$transactionId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    die("Invalid transaction ID.");
}

// --- Security check to allow admins OR the order owner to view ---
$isOwner = ($_SESSION['user']['id'] === $order['user_id']);
$isAdmin = in_array($_SESSION['user']['role'], ['admin', 'cashier']);

if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    die("Access denied. You do not have permission to view this receipt.");
}

// Fetch items for the order
$itemStmt = $pdo->prepare("
    SELECT p.name, r.quantity, p.price
    FROM reservations r
    JOIN products p ON r.product_id = p.id
    WHERE r.transaction_id = ?
");
$itemStmt->execute([$transactionId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total
$total = array_reduce($items, fn($sum, $item) => $sum + $item['price'] * $item['quantity'], 0);

// --- Output HTML Fragment for the Modal ---
?>
<div class="print-content text-center mb-6">
    <h1 class="text-2xl font-bold text-orange-400">Order Receipt</h1>
    <p class="text-sm text-neutral-400 mt-1">Order #: <span class="font-mono"><?= htmlspecialchars($order['transaction_id']) ?></span></p>
    <p class="text-sm text-neutral-500"><?= date('F j, Y • h:i A', strtotime($order['reserved_at'])) ?></p>
</div>

<div class="mb-6 space-y-2 text-sm">
    <div><span class="font-semibold text-neutral-400">Customer:</span> <span class="text-neutral-200"><?= htmlspecialchars($order['firstname'] . ' ' . $order['lastname']) ?></span></div>
    <div><span class="font-semibold text-neutral-400">User ID:</span> <span class="text-neutral-200"><?= htmlspecialchars($order['unique_id']) ?></span></div>
    <div><span class="font-semibold text-neutral-400">Payment:</span> <span class="text-neutral-200"><?= ucfirst($order['payment_type']) ?></span></div>
</div>

<div class="border-t border-white/10 pt-4 mb-4">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-neutral-400 border-b border-white/10">
                <th class="text-left py-2 font-semibold">Item</th>
                <th class="text-center py-2 font-semibold">Qty</th>
                <th class="text-right py-2 font-semibold">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr class="border-b border-white/5 last:border-none text-neutral-200">
                    <td class="py-2"><?= htmlspecialchars($item['name']) ?></td>
                    <td class="text-center py-2"><?= $item['quantity'] ?></td>
                    <td class="text-right py-2 font-mono">₱<?= number_format($item['price'] * $item['quantity'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="text-right text-xl font-bold text-green-400 mt-2">
    Total: ₱<?= number_format($total, 2) ?>
</div>