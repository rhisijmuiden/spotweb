/*
 * Spot page: tabs, "spot x of y", and the split view next to the list.
 * Works for the overlay (loaded with jQuery .load, which drops inline
 * scripts) and for the stand-alone spot page.
 */
(function () {
  var SPLIT_MIN_WIDTH = 1200;

  // open spots next to the list on wide screens
  window.swSplitView = function () {
    return window.innerWidth >= SPLIT_MIN_WIDTH && !!document.querySelector('table.spots tbody tr');
  };

  function showTab(root, name) {
    if (!root) { return; }
    var tabs = root.querySelectorAll('[data-sw-tab]');
    for (var i = 0; i < tabs.length; i++) {
      var on = tabs[i].getAttribute('data-sw-tab') === name;
      tabs[i].classList.toggle('active', on);
      tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
    }
    var panels = root.querySelectorAll('[data-sw-panel]');
    for (var j = 0; j < panels.length; j++) {
      panels[j].classList.toggle('active', panels[j].getAttribute('data-sw-panel') === name);
    }
  }
  window.swShowTab = function (name) { showTab(document.getElementById('details'), name); };

  document.addEventListener('click', function (e) {
    var tab = e.target.closest ? e.target.closest('[data-sw-tab]') : null;
    if (tab) {
      showTab(tab.closest('#details'), tab.getAttribute('data-sw-tab'));
    }
  });

  // called by openSpot() once the overlay has the spot, and on the stand-alone page
  window.swDetailReady = function () {
    var details = document.getElementById('details');
    if (!details) { return; }
    var rows = document.querySelectorAll('table.spots tbody tr');
    var pos = details.querySelector('.sw-spotpos');
    if (!rows.length) {
      details.classList.add('sw-no-list');      // stand-alone page: nothing to browse
      return;
    }
    var active = document.querySelector('table.spots tbody tr.active');
    var index = Array.prototype.indexOf.call(rows, active);
    if (pos && index >= 0) {
      pos.textContent = (pos.getAttribute('data-label') || '%1$s / %2$s')
        .replace('%1$s', index + 1)
        .replace('%2$s', rows.length);
    }
    if (location.hash.indexOf('comments') !== -1) { showTab(details, 'comments'); }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { window.swDetailReady(); });
  } else {
    window.swDetailReady();
  }
})();
