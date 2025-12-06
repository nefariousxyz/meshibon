<?php
// /api/order_status.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';

// Make PDO throw exceptions so we can handle them
try {
    if ($pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { /* ignore if already set */ }

$orderId = trim((string)($_GET['order_id'] ?? ''));
if ($orderId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'order_id required']);
    exit;
}

/** Optional debug (safe): requires env token */
$debugEnabled = false;
try {
    $debugTokenEnv = getenv('MESHIBON_STATUS_DEBUG_TOKEN');
    if ($debugTokenEnv && isset($_GET['debug'], $_GET['token']) && $_GET['debug'] === '1' && hash_equals($debugTokenEnv, (string)$_GET['token'])) {
        $debugEnabled = true;
    }
} catch (Throwable $e) {}

/** Helpers */
function tableColumns(PDO $pdo, string $table): array {
    try {
        $cols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`','``',$table) . '`');
        foreach ($stmt as $row) {
            if (!empty($row['Field'])) $cols[strtolower($row['Field'])] = true;
        }
        return $cols;
    } catch (Throwable $e) { return []; }
}
function pickColumn(array $cols, array $candidates, $default = null): ?string {
    foreach ($candidates as $c) if (isset($cols[strtolower($c)])) return $c;
    return $default;
}
function bestOrderBy(array $cols): string {
    $col = pickColumn($cols, ['updated_at','reserved_at','created_at','id']);
    return $col ? (' ORDER BY `'.$col.'` DESC ') : '';
}
function pickReadyAt(array $row): ?string {
    foreach (['ready_at','status_ready_at','status_changed_at','updated_at','reserved_at','created_at'] as $c) {
        if (!empty($row[$c])) return (string)$row[$c];
    }
    return null;
}
/** Canonicalize status to the 3 pushable values used by the UI */
function normalizeStatus(string $s): string {
    $t = trim($s);
    // map common variants
    if (strcasecmp($t, 'Order Received') === 0) return 'Order Received';
    if (strcasecmp($t, 'Preparing') === 0) return 'Preparing';
    // variants for "Ready to Pickup"
    $rtp = ['Ready to Pickup','Ready for Pickup','Ready for pick up','Ready to pick up','Ready'];
    foreach ($rtp as $v) if (strcasecmp($t, $v) === 0) return 'Ready to Pickup';
    return $t; // fall back to original if it’s something else
}

try {
    $table = 'reservations';
    $cols  = tableColumns($pdo, $table);

    $statusCol = pickColumn($cols, ['status','order_status','reservation_status','state']);
    if (!$statusCol) throw new RuntimeException('No status-like column found in table "'.$table.'"');

    $baseSelect = 'SELECT * FROM `'.$table.'` WHERE ';
    $orderBy    = bestOrderBy($cols) . ' LIMIT 1';

    $row = null;

    // 1) Try by transaction_id (string)
    if (pickColumn($cols, ['transaction_id'])) {
        $sqlTx = $baseSelect . '`transaction_id` = :id' . $orderBy;
        $stmt  = $pdo->prepare($sqlTx);
        $stmt->execute([':id' => $orderId]);
        $row = $stmt->fetch();
    }

    // 2) Fallback: numeric id
    if (!$row && ctype_digit($orderId) && pickColumn($cols, ['id'])) {
        $sqlId = $baseSelect . '`id` = :nid LIMIT 1';
        $stmt  = $pdo->prepare($sqlId);
        $stmt->execute([':nid' => (int)$orderId]);
        $row = $stmt->fetch();
    }

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'order not found']);
        exit;
    }

    $statusRaw = (string)($row[$statusCol] ?? '');
    if ($statusRaw === '') {
        throw new RuntimeException('Row found but status column "'.$statusCol.'" is empty');
    }

    $status    = normalizeStatus($statusRaw);
    $readyAt   = pickReadyAt($row);
    $updatedAt = $row[pickColumn($cols, ['updated_at','reserved_at','created_at']) ?? ''] ?? null;

    // NEW: include order_id (prefer transaction_id, else numeric id)
    $responseOrderId = $row[pickColumn($cols, ['transaction_id']) ?? ''] ?? null;
    if (!$responseOrderId && isset($row['id'])) $responseOrderId = (string)$row['id'];

    echo json_encode([
        'order_id'   => $responseOrderId,   // <-- added
        'status'     => $status,            // <-- normalized to UI’s 3 keys when applicable
        'ready_at'   => $readyAt,
        'updated_at' => $updatedAt,
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log('[order_status] '.$e->getMessage());
    http_response_code(500);
    $payload = ['error' => 'server error'];
    if ($debugEnabled) $payload['debug'] = $e->getMessage();
    echo json_encode($payload);
}
