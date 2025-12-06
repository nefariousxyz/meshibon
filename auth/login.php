<?php
declare(strict_types=1);

/* ===== CONFIG (edit) ===== */
const RECAPTCHA_SITE_KEY     = '6Ldz09srAAAAADLjz_ak465rk-XM7K9Akr5xsz3O';
const RECAPTCHA_SECRET       = '6Ldz09srAAAAAAYvNs1Lv-9-Hpd_rvnk86VqkqDp';
const RECAPTCHA_MIN_SCORE    = 0.5;
const RECAPTCHA_LOGIN_ACTION = 'login';

/* ===== Security headers ===== */
$https = !empty($_SERVER['HTTPS']);
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; script-src 'self' https://cdn.tailwindcss.com https://www.google.com https://www.gstatic.com; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
if ($https) header("Strict-Transport-Security: max-age=15552000; includeSubDomains; preload");

/* ===== Session ===== */
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

/* ===== DB ===== */
require __DIR__ . '/../config/db.php';
if (!defined('ORG_EMAIL_DOMAIN')) define('ORG_EMAIL_DOMAIN', 'splacebpo.com');
if (!defined('MAIL_FROM'))       define('MAIL_FROM', 'no-reply@' . ORG_EMAIL_DOMAIN);
if (!defined('MAIL_FROM_NAME'))  define('MAIL_FROM_NAME', 'Meshibon Portal');

/* ===== CSRF ===== */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

/* ===== Throttling ===== */
$now = time();
$ipKey = 'rl_ip_' . ($_SERVER['REMOTE_ADDR'] ?? 'na');
$userKeyPrefix = 'rl_user_';
if (!isset($_SESSION['rate_limits'])) $_SESSION['rate_limits'] = ['ip'=>[], 'users'=>[]];
$rl = &$_SESSION['rate_limits'];
$WINDOW = 15 * 60;
foreach ($rl['ip'] as $k => $row)   if ($now - ($row['ts'] ?? 0) > $WINDOW) unset($rl['ip'][$k]);
foreach ($rl['users'] as $k => $row)if ($now - ($row['ts'] ?? 0) > $WINDOW) unset($rl['users'][$k]);
$rl['ip'][$ipKey] = $rl['ip'][$ipKey] ?? ['tries'=>0,'ts'=>$now];

/* ===== Helpers ===== */
$genericError = "Invalid login credentials.";
$error = "";
const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$YWFhYWFhYWFhYWFhYWFhYQ$wA1xY5jI9q1Ccw0U64U9nCqD1VvY1m0zK1WQ6dQK2gc';

/* Get client IP (honor reverse proxies/CDN safely) */
function client_ip(): string {
  $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
  if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
  return trim($ip);
}

/* Simple geolocation via ip-api.com (no key). Returns [city, region, country, lat, lon]. */
function geolocate_ip(string $ip): array {
  $out = ['city'=>null,'region'=>null,'country'=>null,'lat'=>null,'lon'=>null];
  if (!$ip || $ip === '127.0.0.1' || $ip === '::1') return $out;
  $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,regionName,city,lat,lon,query';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 3,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_USERAGENT => 'MeshibonPortal/1.0'
  ]);
  $raw = curl_exec($ch);
  curl_close($ch);
  if ($raw === false) return $out;
  $j = json_decode($raw, true);
  if (!is_array($j) || ($j['status'] ?? 'fail') !== 'success') return $out;
  $out['city']    = $j['city'] ?? null;
  $out['region']  = $j['regionName'] ?? null;
  $out['country'] = $j['country'] ?? null;
  $out['lat']     = isset($j['lat']) ? (float)$j['lat'] : null;
  $out['lon']     = isset($j['lon']) ? (float)$j['lon'] : null;
  return $out;
}

/* Send login notification email (HTML + text fallback) */
function send_login_email(string $toEmail, string $displayUser, string $ip, array $loc): bool {
  if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

  // Build location string
  $parts = array_filter([$loc['city'] ?? null, $loc['region'] ?? null, $loc['country'] ?? null]);
  $locationStr = $parts ? implode(', ', $parts) : 'Unknown location';
  $mapLink = ($loc['lat'] && $loc['lon']) ? 'https://maps.google.com/?q=' . $loc['lat'] . ',' . $loc['lon'] : null;

  // Timestamp in Asia/Manila for user clarity
  $dtManila = new DateTime('now', new DateTimeZone('Asia/Manila'));
  $whenPH = $dtManila->format('Y-m-d H:i:s T');

  $subject = 'Login alert for your Meshibon account';
  $from = MAIL_FROM;
  $fromName = MAIL_FROM_NAME;

  $html  = '<div style="font-family:Segoe UI,Roboto,Arial,sans-serif;color:#222">';
  $html .= '<h2 style="margin:0 0 12px">New sign-in to your account</h2>';
  $html .= '<p style="margin:0 0 10px">Hi ' . htmlspecialchars($displayUser) . ',</p>';
  $html .= '<p style="margin:0 0 10px">Your account was just signed in.</p>';
  $html .= '<ul style="margin:10px 0 14px; padding-left:18px">';
  $html .= '<li><b>Time (PH):</b> ' . htmlspecialchars($whenPH) . '</li>';
  $html .= '<li><b>IP Address:</b> ' . htmlspecialchars($ip) . '</li>';
  $html .= '<li><b>Approx. Location:</b> ' . htmlspecialchars($locationStr) . '</li>';
  if ($mapLink) $html .= '<li><a href="'.htmlspecialchars($mapLink).'" target="_blank" rel="noopener">View on map</a></li>';
  $html .= '</ul>';
  $html .= '<p style="margin:0 0 10px">If this was you, you can ignore this message. ';
  $html .= 'If you didn’t sign in, please change your password immediately.</p>';
  $html .= '<p style="margin-top:16px;font-size:12px;color:#666">This location is approximate and based on IP.</p>';
  $html .= '</div>';

  $text  = "New sign-in to your account\n\n";
  $text .= "Time (PH): $whenPH\n";
  $text .= "IP Address: $ip\n";
  $text .= "Approx. Location: $locationStr\n";
  if ($mapLink) $text .= "Map: $mapLink\n";
  $text .= "\nIf this wasn't you, change your password.\n";

  // Basic multipart/alternative via mail()
  $boundary = '=_mb_' . bin2hex(random_bytes(12));
  $headers  = [];
  $headers[] = 'From: ' . sprintf('"%s" <%s>', addslashes($fromName), $from);
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: multipart/alternative; boundary="'.$boundary.'"';
  $body  = "--$boundary\r\n";
  $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
  $body .= $text . "\r\n";
  $body .= "--$boundary\r\n";
  $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
  $body .= $html . "\r\n";
  $body .= "--$boundary--";

  // Suppress warnings; email failure should not block login
  return @mail($toEmail, $subject, $body, implode("\r\n", $headers));
}

function table_has_column(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k = $table.'|'.$col;
  if (array_key_exists($k, $cache)) return $cache[$k];
  try {
    $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$col]);
    $cache[$k] = ($s->rowCount() > 0);
  } catch (Throwable $e) {
    $cache[$k] = false;
  }
  return $cache[$k];
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

/* ===== Login Handler (AJAX + normal) ===== */
function handle_login_request(PDO $pdo, array &$rl, string $ipKey, string $userKeyPrefix): array {
  global $now, $genericError;

  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    return ['ok' => false, 'error' => $genericError];
  }

  $identifier = trim((string)($_POST['identifier'] ?? '')); // username or email
  $password = (string)($_POST['password'] ?? '');
  $recaptchaToken = (string)($_POST['recaptcha_token'] ?? '');

  if ($identifier === '' || $password === '') {
    return ['ok' => false, 'error' => "Please fill in all fields."];
  }

  // If looks like email, enforce org domain.
  $looksLikeEmail = str_contains($identifier, '@');
  if ($looksLikeEmail) {
    $lower = strtolower($identifier);
    $parts = explode('@', $lower);
    $domain = $parts[1] ?? '';
    if ($domain !== ORG_EMAIL_DOMAIN) {
      return ['ok' => false, 'error' => $genericError];
    }
  }

  $ipRow = $rl['ip'][$ipKey];
  if ($ipRow['tries'] >= 20) {
    return ['ok' => false, 'error' => $genericError];
  }

  if (!verify_recaptcha_v3($recaptchaToken, RECAPTCHA_LOGIN_ACTION)) {
    $rl['ip'][$ipKey] = ['tries'=>$ipRow['tries']+1, 'ts'=>$now];
    usleep(random_int(250000, 500000));
    return ['ok' => false, 'error' => $genericError];
  }

  $uKey = $userKeyPrefix . strtolower($identifier);
  $uRow = $rl['users'][$uKey] ?? ['tries'=>0,'ts'=>$now];
  if ($uRow['tries'] >= 10) {
    return ['ok' => false, 'error' => $genericError];
  }

  // Column checks
  $hasAccountStatus = table_has_column($pdo, 'users', 'account_status'); // enum: active|banned|inactive
  $hasTinyStatus    = table_has_column($pdo, 'users', 'status');         // tinyint 1/0

  $cols = "id, username, email, password, role";
  if ($hasAccountStatus) $cols .= ", account_status";
  if ($hasTinyStatus)    $cols .= ", status";

  // Query
  if ($looksLikeEmail) {
    $stmt = $pdo->prepare("SELECT $cols FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([strtolower($identifier)]);
  } else {
    $possibleEmail = function_exists('canonical_org_email')
      ? canonical_org_email($identifier)
      : (strtolower($identifier) . '@' . ORG_EMAIL_DOMAIN);
    $stmt = $pdo->prepare("SELECT $cols FROM users WHERE username = ? OR email = ? LIMIT 1");
    $stmt->execute([$identifier, $possibleEmail]);
  }

  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  $hashToCheck = $user['password'] ?? DUMMY_HASH;
  $ok = password_verify($password, (string)$hashToCheck);

  if (!($user && $ok)) {
    $rl['ip'][$ipKey]   = ['tries'=>$ipRow['tries']+1, 'ts'=>$now];
    $rl['users'][$uKey] = ['tries'=>($uRow['tries']??0)+1, 'ts'=>$now];
    usleep(random_int(250000, 500000));
    return ['ok' => false, 'error' => $genericError];
  }

  // Status gate
  $isActive = true; $statusMsg = null;
  if ($hasAccountStatus) {
    $acc = strtolower((string)($user['account_status'] ?? 'active'));
    if ($acc === 'banned')   { $isActive = false; $statusMsg = "Your account is banned."; }
    if ($acc === 'inactive') { $isActive = false; $statusMsg = "Your account is inactive/disabled."; }
  } elseif ($hasTinyStatus) {
    $isActive = ((int)($user['status'] ?? 1) === 1);
    if (!$isActive) $statusMsg = "Your account is inactive/disabled.";
  }
  if (!$isActive) {
    return ['ok' => false, 'error' => $statusMsg ?? $genericError];
  }

  // Rehash if needed
  if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
  }

  if (function_exists('session_regenerate_id')) session_regenerate_id(true);
  $_SESSION['user'] = [
    'id'       => (int)$user['id'],
    'username' => (string)$user['username'],
    'email'    => strtolower((string)($user['email'] ?? '')),
    'role'     => $user['role'] ?? null,
    'login_at' => date('c'),
  ];
  $rl['ip'][$ipKey] = ['tries'=>0,'ts'=>$now];
  unset($rl['users'][$uKey]);

  /* ===== NEW: Email login notification ===== */
  $ip      = client_ip();
  $loc     = geolocate_ip($ip); // best-effort
  $toEmail = $_SESSION['user']['email'] ?: (isset($user['email']) ? strtolower((string)$user['email']) : null);

  if ($toEmail && str_ends_with($toEmail, '@' . ORG_EMAIL_DOMAIN)) {
    // Fire and forget; do not block user if it fails
    try { send_login_email($toEmail, $user['username'] ?: $toEmail, $ip, $loc); } catch (\Throwable $e) { /* ignore */ }
  }

  return ['ok' => true, 'redirect' => '../dashboard.php', 'user' => [
    'username' => (string)$user['username'],
    'email'    => strtolower((string)($user['email'] ?? '')),
  ]];
}

/* ===== Handle AJAX POST (JSON) ===== */
$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST')
  && (
       (isset($_POST['ajax']) && $_POST['ajax'] === '1')
       || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
     );

if ($isAjax) {
  $result = handle_login_request($pdo, $rl, $ipKey, $userKeyPrefix);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($result);
  exit;
}

/* ===== Handle normal POST (non-AJAX fallback) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $result = handle_login_request($pdo, $rl, $ipKey, $userKeyPrefix);
  if (!empty($result['ok'])) {
    header('Location: ' . ($result['redirect'] ?? '../dashboard.php')); exit;
  }
  $error = $result['error'] ?? "Something went wrong.";
}

/* ===== Pre-render vars ===== */
$prefillUser = htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8');
$csrf = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
$alreadySignedIn = !empty($_SESSION['user']);
$displayName = htmlspecialchars($_SESSION['user']['email'] ?? $_SESSION['user']['username'] ?? 'your account', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title>Login — Meshibon Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <meta name="color-scheme" content="light" />
  <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8') ?>"></script>
  <style>
    html, body { height:auto; min-height:100%; overflow-y:auto; -webkit-overflow-scrolling:touch; }
    body { overflow-x:hidden; }
    .min-h-svh { min-height: 100svh; }
    .min-h-dvh { min-height: 100dvh; }
    @supports (-webkit-touch-callout: none) { .min-h-fill { min-height: -webkit-fill-available; } }
    .pb-safe { padding-bottom: max(16px, env(safe-area-inset-bottom)); }
    .bg-grid {
      background-image:
        radial-gradient(transparent 1px, rgba(0,0,0,0.03) 1px),
        radial-gradient(transparent 1px, rgba(0,0,0,0.02) 1px);
      background-size: 18px 18px, 36px 36px;
      background-position: 0 0, 9px 9px;
    }
    .toast { transform: translateY(-120%); transition: transform .45s ease, box-shadow .3s ease; }
    .toast.show { transform: translateY(0); }
    .toast-progress { width: 0%; height: 3px; transition: width linear; }
    .spin { animation: spin 1s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>

<body class="min-h-dvh min-h-svh min-h-fill bg-white text-gray-800 relative">
  <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10">
    <div class="bg-grid absolute inset-0"></div>
    <div class="absolute -top-28 -right-28 h-[22rem] w-[22rem] rounded-full opacity-30 blur-3xl"
         style="background:radial-gradient(60% 60% at 50% 50%, rgba(234,88,12,.15), transparent 70%)"></div>
    <div class="absolute -bottom-32 -left-24 h-[22rem] w-[22rem] rounded-full opacity-30 blur-3xl"
         style="background:radial-gradient(60% 60% at 50% 50%, rgba(249,115,22,.12), transparent 70%)"></div>
  </div>

  <!-- Top Toast Bar -->
  <div id="toast" class="toast fixed top-0 left-0 right-0 z-50 mx-auto w-full max-w-xl">
    <div class="mx-4 mt-3 overflow-hidden rounded-2xl border border-gray-200 bg-white/95 shadow-lg backdrop-blur">
      <div class="flex items-center gap-3 px-4 py-3">
        <div id="toast-icon" class="h-5 w-5 text-orange-600">
          <svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <circle cx="12" cy="12" r="9" stroke-width="2" stroke-linecap="round" stroke-dasharray="56" stroke-dashoffset="40"></circle>
          </svg>
        </div>
        <div class="min-w-0">
          <p id="toast-title" class="truncate text-sm font-medium text-gray-900">Working…</p>
          <p id="toast-msg" class="truncate text-xs text-gray-500">Please wait</p>
        </div>
      </div>
      <div class="toast-progress bg-orange-600" id="toast-progress"></div>
    </div>
  </div>

  <main class="relative z-10 min-h-dvh flex items-center justify-center px-4 pb-safe">
    <div class="w-full max-w-md">
      <div class="flex flex-col items-center gap-3 mb-8">
        <img src="logo.png" alt="Meshibon Logo" class="h-16 w-auto drop-shadow-sm">
      </div>

      <?php if ($alreadySignedIn): ?>
        <section class="rounded-3xl border border-gray-200 bg-white p-8 shadow-lg text-center">
          <h2 class="text-lg font-semibold text-orange-600 mb-2">You're already signed in</h2>
          <p class="text-sm text-gray-600">Redirecting you to the dashboard…</p>
        </section>
        <script>
          window.addEventListener('DOMContentLoaded', () => {
            showToast("Welcome back!", "You're signed in as <?= $displayName ?>", 900);
            startProgress(900);
            setTimeout(() => { window.location.href = "../dashboard.php"; }, 900);
          });
        </script>
      <?php else: ?>

      <section class="rounded-3xl border border-gray-200 bg-white p-8 shadow-lg">
        <header class="mb-6">
          <h2 class="text-lg font-semibold text-orange-600">Welcome back</h2>
          <p class="text-sm text-gray-500">Sign in with your username or your @<?= htmlspecialchars(ORG_EMAIL_DOMAIN, ENT_QUOTES, 'UTF-8') ?> email</p>
        </header>

        <?php if (!empty($error)): ?>
        <div class="mb-4 rounded-xl border border-red-500/20 bg-red-50 px-3 py-2.5 text-sm text-red-700">
          <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <form id="login-form" method="POST" class="space-y-5" novalidate autocomplete="off">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" id="recaptcha_token" name="recaptcha_token" value="">
          <input type="hidden" name="ajax" value="1">

          <div class="space-y-2">
            <label for="identifier" class="text-sm text-gray-700">Username or Email</label>
            <input id="identifier" type="text" name="identifier" value="<?= $prefillUser ?>"
                   placeholder="your.username or your.username@<?= htmlspecialchars(ORG_EMAIL_DOMAIN, ENT_QUOTES, 'UTF-8') ?>"
                   class="w-full rounded-xl border border-gray-300 bg-white px-3 py-3 text-sm outline-none
                          focus:border-orange-600 focus:ring-2 focus:ring-orange-600/30 placeholder:text-gray-400 text-gray-900"
                   required autocomplete="username">
          </div>

          <div class="space-y-2">
            <label for="password" class="text-sm text-gray-700">Password</label>
            <input id="password" type="password" name="password" placeholder="••••••••"
                   class="w-full rounded-xl border border-gray-300 bg-white px-3 py-3 text-sm outline-none
                          focus:border-orange-600 focus:ring-2 focus:ring-orange-600/30 placeholder:text-gray-400 text-gray-900"
                   required autocomplete="current-password">
          </div>

          <div class="flex items-center justify-between pt-1">
            <span class="text-xs text-gray-400">Only @<?= htmlspecialchars(ORG_EMAIL_DOMAIN, ENT_QUOTES, 'UTF-8') ?> emails are allowed</span>
            <a href="forgot.php" class="text-sm text-orange-600 hover:text-orange-700">Forgot password?</a>
          </div>

          <button id="btn-submit" type="submit"
                  class="mt-2 w-full rounded-xl bg-orange-600 hover:bg-orange-500 active:scale-[.99]
                         px-4 py-3 text-sm font-medium text-white transition shadow-md hover:shadow-lg
                         focus:outline-none focus:ring-2 focus:ring-orange-600/40">
            <span class="inline-flex items-center gap-2">
              <svg id="btn-spin" class="hidden h-4 w-4 spin text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <circle cx="12" cy="12" r="9" stroke-width="2" stroke-linecap="round" stroke-dasharray="56" stroke-dashoffset="40"></circle>
              </svg>
              Sign in
            </span>
          </button>
        </form>
      </section>

      <p class="mt-6 text-center text-xs text-gray-500">© <?= date('Y') ?> Meshi Bon — Meshibon Portal</p>

      <?php endif; ?>
    </div>
  </main>

  <script>
  const SITE_KEY = "<?= htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8') ?>";
  const ACTION   = "<?= htmlspecialchars(RECAPTCHA_LOGIN_ACTION, ENT_QUOTES, 'UTF-8') ?>";

  const toastEl = document.getElementById('toast');
  const toastTitle = document.getElementById('toast-title');
  const toastMsg = document.getElementById('toast-msg');
  const toastProgress = document.getElementById('toast-progress');
  const btn = document.getElementById('btn-submit');
  const btnSpin = document.getElementById('btn-spin');

  function showToast(title, msg, duration = 1500) {
    toastTitle.textContent = title || 'Working…';
    toastMsg.textContent = msg || '';
    toastEl.classList.add('show');
    startProgress(duration);
  }
  function hideToast() {
    toastEl.classList.remove('show');
    toastProgress.style.transitionDuration = '0ms';
    toastProgress.style.width = '0%';
  }
  function startProgress(duration) {
    toastProgress.style.transitionDuration = duration + 'ms';
    requestAnimationFrame(() => { toastProgress.style.width = '100%'; });
  }

  // Always get a new v3 token per attempt (tokens are single-use & short-lived)
  function getFreshRecaptchaToken() {
    if (!window.grecaptcha || typeof grecaptcha.execute !== 'function') return Promise.resolve('');
    return new Promise((resolve) => {
      try {
        grecaptcha.ready(() => {
          grecaptcha.execute(SITE_KEY, { action: ACTION })
            .then(token => resolve(token || ''))
            .catch(() => resolve(''));
        });
      } catch (e) {
        resolve('');
      }
    });
  }

  const form = document.getElementById('login-form');
  if (form) {
    const tok = document.getElementById('recaptcha_token');

    form.addEventListener('submit', async function(e){
      e.preventDefault();
      btn.disabled = true; btnSpin?.classList.remove('hidden');
      showToast('Signing you in…', 'Securing your session');

      try {
        // IMPORTANT: always fetch a fresh token right before sending
        tok.value = await getFreshRecaptchaToken();

        const formData = new FormData(form);
        const res = await fetch(location.href, {
          method: 'POST',
          headers: {'X-Requested-With': 'XMLHttpRequest'},
          body: formData
        });

        let data = null;
        try { data = await res.json(); } catch (_) { /* non-JSON fallback */ }

        if (data && data.ok) {
          showToast('Welcome back!', 'Redirecting to your dashboard…', 1000);
          setTimeout(() => { window.location.href = data.redirect || '../dashboard.php'; }, 1000);
        } else {
          hideToast();
          const msg = (data && data.error) ? data.error : 'Something went wrong.';
          showToast('Sign-in failed', msg, 1600);
          setTimeout(() => { hideToast(); }, 1700);
          btn.disabled = false; btnSpin?.classList.add('hidden');
        }
      } catch (err) {
        hideToast();
        showToast('Network error', 'Please try again', 1500);
        setTimeout(() => { hideToast(); }, 1600);
        btn.disabled = false; btnSpin?.classList.add('hidden');
      } finally {
        // Clear token to avoid accidental reuse on subsequent attempts
        tok.value = '';
      }
    });
  }
</script>
</body>
</html>
