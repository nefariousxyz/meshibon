  </main>

  <footer class="mt-10 border-t border-white/10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 text-center text-xs text-neutral-400">
      © <?= date('Y') ?> Meshi Bon — Created by Franz Abiva
    </div>
  </footer>

  <script>
    // mobile nav toggle
    const toggler = document.getElementById('navToggle');
    const mobileMenu = document.getElementById('mobileMenu');
    if (toggler && mobileMenu) {
      toggler.addEventListener('click', () => mobileMenu.classList.toggle('hidden'));
    }
  </script>
</body>
</html>
