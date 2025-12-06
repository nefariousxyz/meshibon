<?php
declare(strict_types=1);

/* ===== CONFIG ===== */
const RECAPTCHA_SITE_KEY       = '6LfOxbcrAAAAABr_XYPFnJ5336vvDPuRhgc0AaXa';
const RECAPTCHA_SECRET         = '6LfOxbcrAAAAAL7rVFgcih3JN77adSwPBb_TgN1s';
const RECAPTCHA_MIN_SCORE      = 0.5;
const RECAPTCHA_FORGOT_ACTION  = 'forgot';
const RESET_EXP_MINUTES        = 15; // link valid window

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

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $employeeId = trim((string)($_POST['employee_id'] ?? ''));
    $email      = trim((string)($_POST['email'] ?? ''));
    $tok        = (string)($_POST['recaptcha_token'] ?? '');

    if ($employeeId !== '' && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && verify_recaptcha_v3($tok, RECAPTCHA_FORGOT_ACTION)) {
      // Find user by BOTH employee_id AND email (no enumeration)
      $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE employee_id = ? AND email = ? LIMIT 1");
      $stmt->execute([$employeeId, $email]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($user) {
        // Build selector/verifier
        $selector  = bin2hex(random_bytes(8));        // 16 chars
        $verifier  = random_bytes(32);
        $tokenHash = hash('sha256', $verifier, true); // 32 bytes
        $expires   = (new DateTimeImmutable("+".RESET_EXP_MINUTES." minutes"))->format('Y-m-d H:i:s');

        // Clear old tokens for this user & expired tokens
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? OR expires_at < NOW()")->execute([$user['id']]);

        $pdo->prepare("INSERT INTO password_resets (user_id, selector, token_hash, expires_at, requested_ip, requested_ua)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$user['id'], $selector, $tokenHash, $expires, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);

        // Token to user: selector:verifierB64url
        $token    = $selector . ':' . rtrim(strtr(base64_encode($verifier), '+/', '-_'), '=');

        // Build base path dynamically so it works on both main domain and subdomain
        $scheme   = $https ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'];
        $basePath = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/'); // e.g. '/scan/auth' OR '/auth'
        $resetUrl = $scheme . '://' . $host . $basePath . '/reset.php?token=' . urlencode($token);

        // Send email (PHPMailer if installed else mail())
        if (!empty($user['email'])) {
          $subj = "Password reset for Meshibon Portal";
          $msg  = "We received a request to reset your password for employee ID {$employeeId}.\n\n"
                . "Reset link (valid ".RESET_EXP_MINUTES." minutes):\n{$resetUrl}\n\n"
                . "If you didn't request this, simply ignore this email.";
          $hdrs = "Content-Type: text/plain; charset=UTF-8\r\n";
          if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
              $m->setFrom('no-reply@'.$host, 'Meshibon Portal');
              $m->addAddress($user['email']);
              $m->Subject = $subj;
              $m->Body    = $msg;
              $m->send();
            } catch (\Throwable $e) { @mail($user['email'], $subj, $msg, $hdrs); }
          } else {
            @mail($user['email'], $subj, $msg, $hdrs);
          }
        }
      }
    }
  }
  // Always generic
  $sent = true;
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Forgot password — Meshibon Portal</title>

  <!-- Tailwind config to match login.php -->
  <script>
    window.tailwind = window.tailwind || {};
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            brand: { 500: '#f97316', 600: '#ea580c', 700: '#c2410c' },
            ink:   { 900: '#0b0b0c', 800: '#111113', 700: '#17181a' }
          },
          boxShadow: {
            elevate: '0 20px 70px rgba(0,0,0,0.6)',
            glow: '0 0 0 3px rgba(249,115,22,0.35)'
          }
        }
      }
    }
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <meta name="color-scheme" content="dark" />

  <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8') ?>"></script>

  <style>
    .theme-fade { transition: all .25s ease; }
    .glass { backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
    .bg-grid {
      background-image:
        radial-gradient(transparent 1px, rgba(255,255,255,0.04) 1px),
        radial-gradient(transparent 1px, rgba(255,255,255,0.025) 1px);
      background-size: 18px 18px, 36px 36px;
      background-position: 0 0, 9px 9px;
    }
  </style>
</head>

<body class="theme-fade min-h-screen bg-ink-900 text-gray-100 relative overflow-hidden">
  <!-- Decorative background (same as login.php) -->
  <div aria-hidden="true" class="pointer-events-none absolute inset-0">
    <div class="bg-grid absolute inset-0"></div>
    <div class="absolute -top-24 -right-28 h-[22rem] w-[22rem] rounded-full opacity-50 blur-3xl"
         style="background: radial-gradient(60% 60% at 50% 50%, rgba(234,88,12,.55), transparent 70%);"></div>
    <div class="absolute -bottom-28 -left-24 h-[22rem] w-[22rem] rounded-full opacity-40 blur-3xl"
         style="background: radial-gradient(60% 60% at 50% 50%, rgba(249,115,22,.50), transparent 70%);"></div>
  </div>

  <main class="relative z-10 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
      <!-- Logo (optional, matches login placement) -->
      <div class="flex flex-col items-center gap-3 mb-8">
        <img src="logo.png" alt="Meshibon Logo" class="h-16 w-auto drop-shadow-lg">
      </div>

      <!-- Card -->
      <section class="glass rounded-3xl border border-white/10 bg-ink-800/80 p-8 shadow-elevate">
        <header class="mb-6">
          <h2 class="text-lg font-semibold text-brand-500">Forgot password</h2>
          <p class="text-sm text-gray-400">Enter your Employee ID and Email to receive a reset link</p>
        </header>

        <?php if ($sent): ?>
          <div class="mb-4 rounded-xl border border-green-500/30 bg-green-500/10 px-3 py-2.5 text-sm text-green-200">
            If the account exists, a reset link has been sent to the email on file.
          </div>
          <div class="flex justify-end">
            <a href="login.php" class="text-sm text-brand-500 hover:text-brand-400">Back to login</a>
          </div>
        <?php else: ?>
          <form id="forgot-form" method="POST" class="space-y-5" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" id="recaptcha_token" name="recaptcha_token" value="">

            <div class="space-y-2">
              <label class="text-sm text-gray-300">Employee ID</label>
              <input name="employee_id" required
                     class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-3 text-sm outline-none
                            focus:border-brand-600 focus:ring-2 focus:ring-brand-600/50 text-gray-100"
                     placeholder="e.g., EMP12345">
            </div>

            <div class="space-y-2">
              <label class="text-sm text-gray-300">Email</label>
              <input name="email" type="email" required
                     class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-3 text-sm outline-none
                            focus:border-brand-600 focus:ring-2 focus:ring-brand-600/50 text-gray-100"
                     placeholder="you@company.com">
            </div>

            <button id="btn-submit" type="submit"
                    class="mt-2 w-full rounded-xl bg-orange-600 hover:bg-orange-500 active:scale-[.99]
                           px-4 py-3 text-sm font-medium text-white transition shadow-md hover:shadow-lg
                           focus:outline-none focus:ring-2 focus:ring-orange-500/50">
              Send reset link
            </button>

            <div class="flex justify-end pt-2">
              <a href="login.php" class="text-sm text-brand-500 hover:text-brand-400">Back to login</a>
            </div>
          </form>
        <?php endif; ?>
      </section>

      <!-- Footer -->
      <p class="mt-6 text-center text-xs text-gray-500">
        © <?= date('Y') ?> Meshi Bon — Meshibon Portal
      </p>
    </div>
  </main>

  <script>
    // Hidden reCAPTCHA v3 (same flow as login)
    const SITE_KEY = "<?= htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8') ?>";
    const ACTION   = "<?= htmlspecialchars(RECAPTCHA_FORGOT_ACTION, ENT_QUOTES, 'UTF-8') ?>";
    const form = document.getElementById('forgot-form');
    if (form) {
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
    }
  </script>
</body>
</html>
