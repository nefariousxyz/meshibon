(function () {
  function qs(id) { return document.getElementById(id); }

  function show(el) {
    if (!el) return;
    el.classList.remove('hidden');
    el.removeAttribute('hidden');
  }
  function hide(el) {
    if (!el) return;
    el.classList.add('hidden');
    el.setAttribute('hidden', 'hidden');
  }
  function isHidden(el) {
    return !el || el.hasAttribute('hidden') || el.classList.contains('hidden');
  }

  function init() {
    const btn  = qs('navToggle');
    const menu = qs('mobileMenu');
    if (!btn || !menu) return;

    function toggle() {
      const willShow = isHidden(menu);
      if (willShow) show(menu); else hide(menu);
      btn.setAttribute('aria-expanded', String(willShow));
    }

    btn.addEventListener('click', toggle, { passive: true });

    // Close when any link is clicked
    menu.addEventListener('click', (e) => {
      if (e.target.closest('a')) { hide(menu); btn.setAttribute('aria-expanded', 'false'); }
    }, { passive: true });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if (isHidden(menu)) return;
      const isInsideMenu = menu.contains(e.target);
      const isBtn = btn.contains(e.target);
      if (!isInsideMenu && !isBtn) {
        hide(menu);
        btn.setAttribute('aria-expanded', 'false');
      }
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !isHidden(menu)) {
        hide(menu);
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
