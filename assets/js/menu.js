(function () {
  function $(id) { return document.getElementById(id); }

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

  function initDropdown() {
    const btn  = $('userMenuBtn');
    const menu = $('userMenu');
    if (!btn || !menu) return;

    function open()  { show(menu);  btn.setAttribute('aria-expanded', 'true'); }
    function close() { hide(menu);  btn.setAttribute('aria-expanded', 'false'); }
    function toggle(){ isHidden(menu) ? open() : close(); }

    btn.addEventListener('click', (e) => {
      e.preventDefault();
      toggle();
    });

    // Close when clicking a link inside the menu
    menu.addEventListener('click', (e) => {
      if (e.target.closest('a')) close();
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if (isHidden(menu)) return;
      const clickInsideMenu = menu.contains(e.target);
      const clickOnBtn      = btn.contains(e.target);
      if (!clickInsideMenu && !clickOnBtn) close();
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !isHidden(menu)) close();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDropdown);
  } else {
    initDropdown();
  }
})();
