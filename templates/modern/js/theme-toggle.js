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

    // View controls
    if (toolbar && !document.getElementById('viewCurrent')) {
      var viewPref = localStorage.getItem('spotweb_view') || 'cards';

      var viewWrap = document.createElement('div');
      viewWrap.className = 'toolbarButton theme dropdown right';
      viewWrap.innerHTML = "<ul><li><p><a id=\"viewCurrent\">View: " + (viewPref==='table'?'Table':'Cards') + "</a></p>" +
                          "<ul>" +
                          "<li><a href=\"#\" id=\"viewCards\">Cards</a></li>" +
                          "<li><a href=\"#\" id=\"viewTable\">Table</a></li>" +
                          "</ul></li></ul>";
      toolbar.appendChild(viewWrap);

      function setViewCookie(v){ try { document.cookie = 'spotweb_view=' + v + '; path=/; max-age=' + (60*60*24*365); } catch(e){} }
      document.getElementById('viewCards').onclick = function(){
        try { localStorage.setItem('spotweb_view','cards'); } catch(e){}
        setViewCookie('cards');
        var u = new URL(location.href);
        u.searchParams.set('view','cards');
        location.href = u.toString();
        return false;
      };
      document.getElementById('viewTable').onclick = function(){
        try { localStorage.setItem('spotweb_view','table'); } catch(e){}
        setViewCookie('table');
        var u = new URL(location.href);
        u.searchParams.set('view','table');
        location.href = u.toString();
        return false;
      };

      // Sync cookie from storage if missing
      try {
        if (!document.cookie.match(/(?:^|; )spotweb_view=/)) {
          var prefView = localStorage.getItem('spotweb_view') || 'cards';
          setViewCookie(prefView);
        }
      } catch(e){}
    }

    // If a view preference exists but URL lacks ?view=, redirect to it
    try {
      var u = new URL(location.href);
      if (!u.searchParams.has('view')) {
        var prefView = localStorage.getItem('spotweb_view');
        if (prefView) { u.searchParams.set('view', prefView); location.replace(u.toString()); }
      }
    } catch(e){}
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
