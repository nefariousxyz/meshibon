<?php
// includes/mobile_nav.php — brand-aligned: dark ink + orange, center QR, depth-aware URLs
if (session_status() === PHP_SESSION_NONE) session_start();
$role = $_SESSION['user']['role'] ?? 'user';

/* Depth-aware root prefix ('' | '../' | '../../' ...) */
$scriptDir = trim(dirname($_SERVER['SCRIPT_NAME']), '/');
$depth     = $scriptDir === '' ? 0 : count(explode('/', $scriptDir));
$root      = $depth ? str_repeat('../', $depth) : '';

function u(string $path) {  // build URL from app root
  global $root;
  return $root . ltrim($path, '/');
}

/* Reuse page-provided unread count if present */
$unreadCountMobile = isset($unreadCount) ? (int)$unreadCount : 0;
if ($unreadCountMobile === 0 && isset($pdo, $_SESSION['user']['id']) && $pdo) {
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user']['id']]);
    $unreadCountMobile = (int)$stmt->fetchColumn();
  } catch (\Throwable $e) { /* ignore */ }
}

/* Active route helper (basename is enough) */
$currentPath = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$active = function(array $targets) use ($currentPath) {
  return in_array($currentPath, $targets, true);
};
?>

<!-- Visible on mobile & tablet, hidden on ≥1024px (lg) -->
<nav class="fixed bottom-0 inset-x-0 z-50 lg:hidden bg-orange-600/95 backdrop-blur border-t border-orange-700 shadow-lg">
  <div class="relative flex justify-between items-end
              px-2 pt-2 pb-[calc(0.75rem+env(safe-area-inset-bottom))]
              text-[12px] md:text-[13px] text-white md:px-4 md:pt-2.5">

    <!-- Home -->
    <a href="<?= u('dashboard.php') ?>"
       aria-label="Home"
       class="group flex flex-col items-center w-full transition"
       <?= $active(['dashboard.php','index.php']) ? 'aria-current="page"' : '' ?>>
      <svg class="w-6 h-6 md:w-7 md:h-7 mb-0.5 text-white" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path d="M10 2 2 8v10h6v-6h4v6h6V8l-8-6z"/>
      </svg>
      <span class="text-white leading-tight">Home</span>
    </a>

    <!-- Account -->
    <a href="<?= u('/pages/profile.php') ?>"
       aria-label="Account"
       class="group flex flex-col items-center w-full transition"
       <?= $active(['my_account.php']) ? 'aria-current="page"' : '' ?>>
      <svg class="w-6 h-6 md:w-7 md:h-7 mb-0.5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M5.121 17.804A9 9 0 0112 15c2.21 0 4.21.805 5.879 2.146M15 10a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      <span class="text-white leading-tight">Profile</span>
    </a>

    <!-- Center QR (keeps brand orange with white icon) -->
    <div class="pointer-events-none absolute -top-9 md:-top-10 left-1/2 -translate-x-1/2">
      <?php
        $qrHref = ($role === 'user') ? u('scan_qr.php') : (in_array($role, ['admin','cashier'], true) ? u('pages/admin_qr_generator.php') : '#');
      ?>
      <a href="<?= $qrHref ?>"
         aria-label="Open QR"
         class="pointer-events-auto grid place-items-center
                w-16 h-16 md:w-18 md:h-18 rounded-full
                bg-gradient-to-b from-orange-600 to-orange-500 text-white
                shadow-xl ring-1 ring-orange-500/40 border-4 border-orange-600
                hover:scale-105 active:scale-95 transition-transform">
        <svg class="w-8 h-8 md:w-9 md:h-9" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M3 3h6v6H3V3zm12 0h6v6h-6V3zM3 15h6v6H3v-6zm12 6v-6h6v6h-6z"/>
        </svg>
      </a>
    </div>

    <!-- Inbox -->
    <a href="<?= u('pages/inbox.php') ?>"
       aria-label="Inbox"
       class="group flex flex-col items-center w-full transition"
       <?= $active(['inbox.php']) ? 'aria-current="page"' : '' ?>>
      <span class="relative inline-block">
        <svg class="w-6 h-6 md:w-7 md:h-7 mb-0.5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
        <?php if (!empty($unreadCountMobile)): ?>
          <span class="absolute -top-1 -right-1 min-w-[16px] h-4 px-1 rounded-full bg-red-600 text-white text-[10px] font-bold leading-none grid place-items-center md:-top-1.5 md:-right-1.5 md:min-w-[18px] md:h-4.5 md:text-[11px]">
            <?= $unreadCountMobile ?>
          </span>
        <?php endif; ?>
      </span>
      <span class="text-white leading-tight">Inbox</span>
    </a>

    <!-- Reserve -->
    <a href="<?= u('pages/reserve_food.php') ?>"
       aria-label="Reserve"
       class="group flex flex-col items-center w-full transition"
       <?= $active(['reserve_food.php']) ? 'aria-current="page"' : '' ?>>
      <svg class="w-6 h-6 md:w-7 md:h-7 mb-0.5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.6 8h13.2M7 13l1.6-8H21"/>
      </svg>
      <span class="text-white leading-tight">Reserve Food</span>
    </a>
  </div>
</nav>