(function () {
  function updateOffset() {
    var docEl = document.documentElement;
    if (!docEl) { return; }
    var toolbar = document.getElementById('toolbar');
    if (!toolbar) { return; }
    var height = toolbar.offsetHeight || 0;
    if (height <= 0) { return; }
    var value = height + 'px';
    docEl.style.setProperty('--sw-toolbar-offset', value);
    docEl.style.setProperty('--sw-header-offset', value);
  }

  function init() {
    updateOffset();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.addEventListener('resize', updateOffset);

  // the bar changes height when buttons are added later or wrap on a phone
  if (window.ResizeObserver) {
    var watch = function () {
      var toolbar = document.getElementById('toolbar');
      if (toolbar) { new ResizeObserver(updateOffset).observe(toolbar); }
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', watch);
    } else {
      watch();
    }
  }
})();
