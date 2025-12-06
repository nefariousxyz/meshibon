<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$user       = $_SESSION['user'] ?? null;
$role       = $user['role'] ?? 'user';
$base       = $base ?? '';
$page_title = $page_title ?? 'Meshibon Portal';

/* Resolve avatar from users.profile_image (stored under /uploads/) */
$avatar = null;
if (!empty($user['profile_image'])) {
  $img = (string)$user['profile_image'];
  if (strpos($img, 'uploads/') === 0 || strpos($img, '/uploads/') === 0) {
    $avatar = $base . '/' . ltrim($img, '/');
  } else {
    $avatar = $base . '/uploads/' . basename($img);
  }
}
$firstName = trim((string)($user['firstname'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($page_title) ?></title>
  <meta name="color-scheme" content="light" />

  <!-- Tailwind config + CDN -->
  <script>
    window.tailwind = window.tailwind || {};
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            brand: { 500:'#f97316', 600:'#ea580c', 700:'#c2410c' } // orange scale
          },
          boxShadow: {
            card: '0 10px 24px rgba(0,0,0,.08)',
            menu: '0 18px 50px rgba(0,0,0,.12)'
          },
          borderRadius: { xl: '0.9rem' }
        }
      }
    };
  </script>
  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    html, body { height: auto; }
    html { overflow-y: auto; scrollbar-gutter: stable; }
    body { overflow-x: hidden; overflow-y: visible; }

    .theme-fade { transition: background-color .2s, color .2s, border-color .2s, box-shadow .2s; }

    @media (prefers-reduced-motion: reduce) {
      .animate-ping, .animate-pulse { animation: none !important; }
    }
  </style>
</head>

<body class="theme-fade min-h-screen bg-white text-gray-900 relative">
  <!-- Soft orange glows -->
  <div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden">
    <div class="absolute right-0 top-0 h-72 w-72 translate-x-1/4 rounded-full opacity-25 blur-2xl"
         style="background: radial-gradient(60% 60% at 50% 50%, rgba(234,88,12,.18), transparent 70%);"></div>
    <div class="absolute left-0 bottom-0 h-72 w-72 -translate-x-1/4 rounded-full opacity-15 blur-2xl"
         style="background: radial-gradient(60% 60% at 50% 50%, rgba(249,115,22,.15), transparent 70%);"></div>
  </div>

  <!-- NAV -->
  <header class="sticky top-0 z-40">
    <nav class="bg-white/95 border-b border-gray-200 shadow-sm backdrop-blur">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 h-14 flex items-center justify-between">
        <!-- Brand -->
        <a href="<?= $base ?>/dashboard.php" class="flex items-center gap-3 min-w-0">
          <img src="<?= $base ?>/assets/mesh.png" alt="Meshibon"
               class="h-8 w-auto" loading="lazy" decoding="async" />
          <span class="hidden sm:block text-base font-semibold tracking-tight truncate">Meshi Bon</span>
        </a>

        <!-- Desktop inline links -->
        <div class="hidden md:flex items-center gap-6 text-sm">
          <a href="<?= $base ?>/dashboard.php" class="text-gray-600 hover:text-gray-900">Home</a>
          <?php if (in_array($role, ['admin','finance'], true)): ?>
            <a href="<?= $base ?>/pages/finance.php" class="text-gray-600 hover:text-gray-900">Transactions</a>
          <?php endif; ?>
          <?php if ($role === 'admin'): ?>
            <a href="<?= $base ?>/pages/admin.php" class="text-gray-600 hover:text-gray-900">Admin Panel</a>
          <?php endif; ?>
          <a href="<?= $base ?>/pages/reserve_food.php" class="text-gray-600 hover:text-gray-900">Reserve Food</a>
          <?php if ($role === 'cashier'): ?>
              <a href="https://meshibon.app/pages/admin_qr_generator.php"
                 class="text-gray-600 hover:text-gray-900">Cashier Menu</a>
            <?php endif; ?>
        </div>

        <!-- Right: Greeting + Avatar OR Login -->
        <div class="flex items-center gap-3">
          <?php if ($user): ?>
            <span class="hidden sm:block text-sm text-gray-600">
              Hi, <strong class="text-gray-900"><?= htmlspecialchars($firstName !== '' ? $firstName : ($user['username'] ?? 'User')) ?></strong>
            </span>

            <!-- Avatar button opens menu -->
            <button
              id="userMenuBtn"
              class="relative inline-flex items-center justify-center w-9 h-9 rounded-full border border-gray-300 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-600/50"
              aria-haspopup="menu"
              aria-expanded="false"
              aria-controls="userMenu"
              data-menu-button>
              <?php if ($avatar): ?>
                <img src="<?= htmlspecialchars($avatar) ?>" alt="Profile"
                     class="w-9 h-9 rounded-full object-cover" />
              <?php else: ?>
                <!-- Fallback SVG avatar -->
                <svg class="w-5 h-5 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                  <path d="M12 14a5 5 0 100-10 5 5 0 000 10zM4 20a8 8 0 0116 0" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              <?php endif; ?>
            </button>
          <?php else: ?>
            <a href="<?= $base ?>/auth/login.php"
               class="rounded-lg bg-brand-600 hover:bg-brand-500 text-white px-3 py-2 text-sm">
              Login
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Dropdown menu (light, white) -->
      <?php if ($user): ?>
      <div id="userMenu"
           class="hidden"
           hidden
           role="menu"
           aria-label="User menu">
        <!-- Positioning container -->
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 relative">
          <div class="absolute right-4 sm:right-6 lg:right-8 mt-2 w-60 rounded-xl bg-white border border-gray-200 shadow-menu overflow-hidden">
            <!-- Header strip -->
            <div class="px-4 py-3 bg-white border-b border-gray-200">
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full overflow-hidden border border-gray-200 bg-gray-100 flex items-center justify-center">
                  <?php if ($avatar): ?>
                    <img src="<?= htmlspecialchars($avatar) ?>" alt="" class="w-full h-full object-cover" />
                  <?php else: ?>
                    <svg class="w-5 h-5 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                      <path d="M12 14a5 5 0 100-10 5 5 0 000 10zM4 20a8 8 0 0116 0" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <div class="text-sm font-medium text-gray-900 truncate">
                    <?= htmlspecialchars($firstName !== '' ? $firstName : ($user['username'] ?? 'User')) ?>
                  </div>
                  <div class="text-[11px] text-gray-500 truncate">@<?= htmlspecialchars($user['username'] ?? '') ?></div>
                </div>
              </div>
            </div>

            <div class="py-1 text-sm bg-white">
              <a href="<?= $base ?>/dashboard.php" class="block px-4 py-2 hover:bg-orange-50" role="menuitem">Home</a>
              <a href="<?= $base ?>/pages/profile.php" class="block px-4 py-2 hover:bg-orange-50" role="menuitem">My Profile</a>
              <?php if (in_array($role, ['admin','finance'], true)): ?>
                <a href="<?= $base ?>/pages/finance.php" class="block px-4 py-2 hover:bg-orange-50" role="menuitem">Transactions</a>
              <?php endif; ?>
              <?php if ($role === 'admin'): ?>
                <a href="<?= $base ?>/pages/admin.php" class="block px-4 py-2 hover:bg-orange-50" role="menuitem">Admin Panel</a>
              <?php endif; ?>
              <a href="<?= $base ?>/pages/reserve_food.php" class="block px-4 py-2 hover:bg-orange-50" role="menuitem">Reserve Food</a>
            </div>

            <div class="border-t border-gray-200 py-1 text-sm bg-white">
              <a href="<?= $base ?>/auth/logout.php" class="block px-4 py-2 text-brand-600 hover:bg-orange-50" role="menuitem">Logout</a>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </nav>
  </header>

  <!-- Dropdown behavior -->
  <script src="<?= $base ?>/assets/js/menu.js" defer></script>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 pb-24 sm:pb-10">