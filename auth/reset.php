<?php
declare(strict_types=1);

/* ===== CONFIG ===== */
const RECAPTCHA_SITE_KEY     = '6LfOxbcrAAAAABr_XYPFnJ5336vvDPuRhgc0AaXa';
const RECAPTCHA_SECRET       = '6LfOxbcrAAAAAL7rVFgcih3JN77adSwPBb_TgN1s';
const RECAPTCHA_MIN_SCORE    = 0.5;
const RECAPTCHA_RESET_ACTION = 'reset';

$https = !empty($_SERVER['HTTPS']);
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; script-src 'self' https://cdn.tailwindcss.com https://www.google.com https://www.gstatic.com; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
if ($https) header("Strict-Transport-Security: max-age=15552000; includeSubDomains; preload");

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => $https,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => 1,
    'use_only_cookies'=> 1,
    'sid_length'      => 48,
  ]);
}

require __DIR__ . '/../config/db.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8');

function bad_token(): void {
  http_response_code(400);
  echo "<!doctype html><meta charset='utf-8'><body style='background:#0b0b0c;color:#eee;font-family:system-ui;padding:2rem'>Invalid or expired reset link.</body>";
  exit;
}
function verify_recaptcha_v3(string $token, string $expectedAction): bool {
  if ($token === '' || RECAPTCHA_SECRET === 'RECAPTCHA_SECRET_KEY_HERE') return false;
  $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
      'secret'   => RECAPTCHA_SECRET,
      'response' => $token,
      'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]),
    CURLOPT_TIMEOUT => 10,
  ]);
  $raw = curl_exec($ch); curl_close($ch);
  if ($raw === false) return false;
  $resp = json_decode($raw, true);
  if (empty($resp['success'])) return false;
  if (!empty($resp['hostname']) && $resp['hostname'] !== ($_SERVER['HTTP_HOST'] ?? '')) return false;
  if (!empty($resp['action'])   && $resp['action']   !== $expectedAction) return false;
  return ((float)($resp['score'] ?? 0)) >= RECAPTCHA_MIN_SCORE;
}

/* Parse token from URL or POST */
$tokenParam = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($tokenParam === '' || strpos($tokenParam, ':') === false) bad_token();
[$selector, $verifierB64] = explode(':', $tokenParam, 2);
$selector = preg_replace('/[^a-f0-9]/i', '', $selector);
$verifier = base64_decode(strtr($verifierB64, '-_', '+/'), true);
if ($selector === '' || $verifier === false || strlen($verifier) < 32) bad_token();

$state = ['ok'=>false,'user_id'=>null,'err'=>null];

/* On GET: verify token still valid */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $stmt = $pdo->prepare("SELECT user_id, token_hash, expires_at, used_at FROM password_resets WHERE selector = ? LIMIT 1");
  $stmt->execute([$selector]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) < time()) bad_token();
  if (!hash_equals($row['token_hash'], hash('sha256', $verifier, true))) bad_token();
  $state = ['ok'=>true,'user_id'=>(int)$row['user_id'],'err'=>null];
}

/* On POST: verify token again, then require employee_id + email + new password */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) bad_token();
  $recToken = (string)($_POST['recaptcha_token'] ?? '');
  if (!verify_recaptcha_v3($recToken, RECAPTCHA_RESET_ACTION)) bad_token();

  $stmt = $pdo->prepare("SELECT user_id, token_hash, expires_at, used_at FROM password_resets WHERE selector = ? LIMIT 1");
  $stmt->execute([$selector]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) < time()) bad_token();
  if (!hash_equals($row['token_hash'], hash('sha256', $verifier, true))) bad_token();
  $userId = (int)$row['user_id'];

  $employeeId = trim((string)($_POST['employee_id'] ?? ''));
  $email      = trim((string)($_POST['email'] ?? ''));
  $p1         = (string)($_POST['password'] ?? '');
  $p2         = (string)($_POST['confirm'] ?? '');
  $validPolicy= strlen($p1) >= 10 && preg_match('/[A-Za-z]/',$p1) && preg_match('/\d/',$p1);

  // Check that employee_id + email match the token's user
  $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND employee_id = ? AND email = ? LIMIT 1");
  $stmt->execute([$userId, $employeeId, $email]);
  $match = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$match || !$validPolicy || $p1 === '' || $p1 !== $p2) {
    $state = ['ok'=>true,'user_id'=>$userId,'err'=>'Please confirm the correct Employee ID & Email, and ensure the new password meets policy and matches confirmation.'];
  } else {
    $hash = password_hash($p1, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $userId]);

    // consume token and purge others
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE selector = ?")->execute([$selector]);
    $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL")->execute([$userId]);

    if (function_exists('session_regenerate_id')) session_regenerate_id(true);
    $_SESSION['user'] = ['id'=>$userId,'login_at'=>date('c')];
    header('Location: ../dashboard.php'); exit;
  }
}
?>
<!doctype html>
<html lang="en" class="dark">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reset password — Meshibon Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body class="min-h-screen bg-gray-900 text-gray-100 flex items-center justify-center p-6">
  <main class="w-full max-w-md p-6 bg-gray-800 rounded-xl shadow-lg">
    <h2 class="text-lg font-semibold text-orange-500 mb-4">Reset password</h2>

    <?php if (!empty($state['err'])): ?>
      <div class="mb-4 p-3 bg-red-600/20 border border-red-500/40 rounded text-red-200 text-sm">
        <?= htmlspecialchars($state['err'], ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form id="reset-form" method="POST" class="space-y-4" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="token" value="<?= htmlspecialchars($tokenParam, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" id="recaptcha_token" name="recaptcha_token" value="">

      <div>
        <label class="block text-sm mb-1">Employee ID</label>
        <input name="employee_id" required class="w-full p-2 rounded bg-gray-700 border border-gray-600" placeholder="e.g., EMP12345">
      </div>
      <div>
        <label class="block text-sm mb-1">Email</label>
        <input name="email" type="email" required class="w-full p-2 rounded bg-gray-700 border border-gray-600" placeholder="you@company.com">
      </div>
      <div>
        <label class="block text-sm mb-1">New password</label>
        <input name="password" type="password" required class="w-full p-2 rounded bg-gray-700 border border-gray-600" placeholder="At least 10 chars, letters & digits">
      </div>
      <div>
        <label class="block text-sm mb-1">Confirm password</label>
        <input name="confirm" type="password" required class="w-full p-2 rounded bg-gray-700 border border-gray-600" placeholder="Repeat password">
      </div>

      <button id="btn-submit" type="submit" class="w-full py-2 bg-orange-600 hover:bg-orange-500 rounded text-white">
        Update password
      </button>
    </form>
  </main>

<script>
const SITE_KEY = "<?= htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8') ?>";
const ACTION   = "<?= htmlspecialchars(RECAPTCHA_RESET_ACTION, ENT_QUOTES, 'UTF-8') ?>";
const form = document.getElementById('reset-form');
const tok  = document.getElementById('recaptcha_token');
const btn  = document.getElementById('btn-submit');
form.addEventListener('submit', function(e){
  if (tok.value) return;
  e.preventDefault(); btn.disabled = true;
  if (!window.grecaptcha || !grecaptcha.execute) { form.submit(); return; }
  grecaptcha.ready(function(){
    grecaptcha.execute(SITE_KEY, {action: ACTION}).then(function(token){
      tok.value = token || ""; form.submit();
    }).catch(function(){ form.submit(); });
  });
});
</script>
</body>
</html>
