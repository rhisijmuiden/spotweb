(function () {
  var STORAGE_KEY = 'spotweb_theme';

  var T = window.SpotwebThemes;
  function getPref() { return T ? T.getPref() : 'site'; }
  function updateLabel(t, pref) {
    var el = document.getElementById('themeCurrent');
    if (!el || !T) return;
    el.textContent = T.label + ': ' + (pref === 'site' ? T.siteLabel : T.names[t.id]);
  }
  function apply(pref) {
    if (!T) return;
    updateLabel(T.apply(pref), pref);
  }
  function set(pref) {
    try {
      if (pref === 'site') { localStorage.removeItem(STORAGE_KEY); } else { localStorage.setItem(STORAGE_KEY, pref); }
    } catch (e) {}
    apply(pref);
  }
  function init() {
    apply(getPref());
    var toolbar = document.getElementById('toolbar');
    // Theme dropdown
    if (toolbar && !document.getElementById('themeCurrent')) {
      var themeWrap = document.createElement('div');
      themeWrap.className = 'toolbarButton theme dropdown right';
      var items = '<li><a href="#" data-sw-theme="site"></a></li>';
      if (T) { for (var id in T.names) { if (T.names.hasOwnProperty(id)) { items += '<li><a href="#" data-sw-theme="' + id + '"></a></li>'; } } }
      themeWrap.innerHTML = '<ul><li><p><a id="themeCurrent">Theme</a></p><ul>' + items + '</ul></li></ul>';
      var links = themeWrap.querySelectorAll('a[data-sw-theme]');
      for (var i = 0; i < links.length; i++) {
        var key = links[i].getAttribute('data-sw-theme');
        links[i].textContent = key === 'site' ? (T ? T.siteLabel : 'Site default') : T.names[key];
        links[i].onclick = function () { set(this.getAttribute('data-sw-theme')); return false; };
      }
      toolbar.appendChild(themeWrap);
      apply(getPref());
    }

    // View controls: the admin sets the default view and can lock it for everyone
    var siteView = (T && T.view) || 'cards';
    var viewLocked = !!(T && T.viewLocked);
    var L = (T && T.viewLabels) || { view: 'View', cards: 'Cards', table: 'Table' };
    function storedView() {
      var v = null;
      try { v = localStorage.getItem('spotweb_view'); } catch (e) {}
      return (v === 'cards' || v === 'table') ? v : null;
    }
    function setViewCookie(v) { try { document.cookie = 'spotweb_view=' + v + '; path=/; max-age=' + (60*60*24*365); } catch (e) {} }
    function clearViewCookie() { try { document.cookie = 'spotweb_view=; path=/; max-age=0'; } catch (e) {} }
    function chooseView(v) {
      var u = new URL(location.href);
      if (v === 'site') {
        try { localStorage.removeItem('spotweb_view'); } catch (e) {}
        clearViewCookie();
        u.searchParams.delete('view');
      } else {
        try { localStorage.setItem('spotweb_view', v); } catch (e) {}
        setViewCookie(v);
        u.searchParams.set('view', v);
      }
      location.href = u.toString();
    }

    if (viewLocked) {
      // a locked view ignores personal choices; forget them so nothing lingers
      try { localStorage.removeItem('spotweb_view'); } catch (e) {}
      clearViewCookie();
    } else if (toolbar && !document.getElementById('viewCurrent')) {
      var viewPref = storedView();
      var current = viewPref || siteView;
      var viewWrap = document.createElement('div');
      viewWrap.className = 'toolbarButton theme dropdown right';
      viewWrap.innerHTML = '<ul><li><p><a id="viewCurrent"></a></p><ul>' +
        '<li><a href="#" data-sw-view="site"></a></li>' +
        '<li><a href="#" data-sw-view="cards"></a></li>' +
        '<li><a href="#" data-sw-view="table"></a></li>' +
        '</ul></li></ul>';
      toolbar.appendChild(viewWrap);
      document.getElementById('viewCurrent').textContent = L.view + ': ' + (viewPref ? L[current] : (T ? T.siteLabel : 'Site default'));
      var vlinks = viewWrap.querySelectorAll('a[data-sw-view]');
      for (var vi = 0; vi < vlinks.length; vi++) {
        var key = vlinks[vi].getAttribute('data-sw-view');
        vlinks[vi].textContent = key === 'site' ? ((T ? T.siteLabel : 'Site default') + ' (' + L[siteView] + ')') : L[key];
        vlinks[vi].onclick = function () { chooseView(this.getAttribute('data-sw-view')); return false; };
      }
      // keep the cookie in step with the stored choice, so the server renders the same view
      if (viewPref) { setViewCookie(viewPref); }

      // a stored choice that differs from what the page shows: reload once with it
      try {
        var cu = new URL(location.href);
        if (viewPref && !cu.searchParams.has('view')) {
          var shown = document.querySelector('.cardsGrid') ? 'cards' : (document.querySelector('table.spots') ? 'table' : null);
          if (shown && shown !== viewPref) { cu.searchParams.set('view', viewPref); location.replace(cu.toString()); }
        }
      } catch (e) {}
    }
    try {
      var mql = window.matchMedia('(prefers-color-scheme: dark)');
      var onChange = function(){ apply(getPref()); };
      if (mql.addEventListener) { mql.addEventListener('change', onChange); }
      else if (mql.addListener) { mql.addListener(onChange); }
    } catch (e) {}
  }
  window.SpotwebTheme = { init: init, set: set };
  document.addEventListener('DOMContentLoaded', function () { try { window.SpotwebTheme.init(); } catch (e) {} });
})();
