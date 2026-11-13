/*
 * Phones: a menu button in the top bar that slides the sidebar in and out.
 * Only does something below 760px; css/mobile.css does the layout.
 */
(function () {
  function init() {
    var toolbar = document.getElementById('toolbar');
    var filter = document.getElementById('filter');
    if (!toolbar || !filter || document.querySelector('.sw-navtoggle')) { return; }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'sw-navtoggle';
    btn.setAttribute('aria-label', 'Menu');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><path d="M3 5.5h14M3 10h14M3 14.5h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    toolbar.insertBefore(btn, toolbar.firstChild);

    var shade = document.createElement('div');
    shade.className = 'sw-navshade';
    document.body.appendChild(shade);

    function set(open) {
      document.body.classList.toggle('sw-nav-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btn.addEventListener('click', function () { set(!document.body.classList.contains('sw-nav-open')); });
    shade.addEventListener('click', function () { set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { set(false); } });
    // following a link in the menu closes it
    filter.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a[href]') : null;
      if (a && a.getAttribute('href') && a.getAttribute('href').charAt(0) !== '#') { set(false); }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
