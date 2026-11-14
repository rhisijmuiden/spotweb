/*
 * Resizable columns in the spot list (table view).
 *
 * Drag a column border in the header to set its width. The title column
 * always takes the space that is left, so the table never gets wider than
 * the page and the sticky header keeps working:
 *   - the border right of the category column sets the category width
 *   - the border right of the title trades width with the first resizable
 *     column after it (drag right = longer titles)
 *   - the border left of genre, sender, date and size sets that column's width
 * Double-click a border (or press Enter on it) to reset that column. Arrow keys
 * move a focused border by 10px. Widths are remembered per user in this
 * browser; the reset button in the title header forgets them.
 *
 * Phone layout and the split view (spot next to the list) are left alone.
 */
(function () {
  var COLS = ['category', 'genre', 'poster', 'date', 'filesize'];
  var MIN = { category: 44, genre: 50, poster: 50, date: 60, filesize: 50 };
  var TITLE_MIN = 160;
  var DESKTOP = '(min-width: 761px)';
  var STEP = 10;

  var table, storeKey, labels, widths = {}, styleEl, resetBtn;

  function qs(sel, root) { return (root || document).querySelector(sel); }

  function load() {
    try {
      var raw = localStorage.getItem(storeKey);
      var obj = raw ? JSON.parse(raw) : {};
      var out = {};
      COLS.forEach(function (c) {
        var v = parseInt(obj && obj[c], 10);
        if (v > 0 && v < 2000) { out[c] = v; }
      });
      return out;
    } catch (e) { return {}; }
  }

  function save() {
    try {
      if (Object.keys(widths).length) { localStorage.setItem(storeKey, JSON.stringify(widths)); }
      else { localStorage.removeItem(storeKey); }
    } catch (e) {}
  }

  // one <style> block, so rows added later by infinite scroll get the same widths
  function render() {
    if (!styleEl) {
      styleEl = document.createElement('style');
      styleEl.id = 'sw-colwidths';
      document.head.appendChild(styleEl);
    }
    var css = '';
    Object.keys(widths).forEach(function (c) {
      var w = widths[c] + 'px';
      var sel = 'html body:not(.sw-split) table.spots.sw-resizable th.' + c + ', html body:not(.sw-split) table.spots.sw-resizable td.' + c;
      css += sel + '{width:' + w + ' !important;min-width:' + w + ' !important;max-width:' + w + ' !important;}';
    });
    styleEl.textContent = css ? '@media ' + DESKTOP + '{' + css + '}' : '';
    if (resetBtn) { resetBtn.hidden = !Object.keys(widths).length; }
  }

  function th(c) { return qs('tr.head th.' + c, table); }
  function visible(el) { return !!el && el.offsetWidth > 0; }
  function widthOf(el) { return Math.round(el.getBoundingClientRect().width); }

  // the column the title border trades width with
  function titlePartner() {
    var cell = th('title');
    for (cell = cell && cell.nextElementSibling; cell; cell = cell.nextElementSibling) {
      for (var i = 1; i < COLS.length; i++) {
        if (cell.classList.contains(COLS[i]) && visible(cell)) { return COLS[i]; }
      }
    }
    return null;
  }

  // new width for column c when it should change by delta, within limits
  function clamp(c, start, delta, titleStart) {
    var w = Math.max(MIN[c], start + delta);
    if (c !== 'title') {
      // the title gives up what the column gains; keep it readable
      var maxGrow = titleStart - TITLE_MIN;
      if (w - start > maxGrow) { w = start + Math.max(0, maxGrow); }
    }
    return Math.round(w);
  }

  function apply(handle, delta, starts) {
    var col = handle.getAttribute('data-col');
    if (col === 'title') {
      var p = titlePartner();
      if (!p) { return; }
      // title grows by delta, its partner shrinks by the same amount
      // the title may not drop below TITLE_MIN, the partner not below its minimum
      var maxW = Math.max(MIN[p], starts[p] + starts.title - TITLE_MIN);
      widths[p] = Math.round(Math.min(maxW, Math.max(MIN[p], starts[p] - delta)));
    } else {
      var sign = handle.getAttribute('data-edge') === 'left' ? -1 : 1;
      widths[col] = clamp(col, starts[col], sign * delta, starts.title);
    }
    render();
  }

  function measure() {
    var s = { title: th('title') ? widthOf(th('title')) : 0 };
    COLS.forEach(function (c) { var el = th(c); if (visible(el)) { s[c] = widthOf(el); } });
    return s;
  }

  function resetColumn(handle) {
    var col = handle.getAttribute('data-col');
    if (col === 'title') {
      var p = titlePartner();
      if (p) { delete widths[p]; }
    } else {
      delete widths[col];
    }
    render();
    save();
  }

  function startDrag(ev) {
    if (ev.button !== undefined && ev.button !== 0) { return; }
    ev.preventDefault();
    ev.stopPropagation();
    var handle = ev.currentTarget;
    var x0 = ev.clientX;
    var starts = measure();
    document.body.classList.add('sw-col-resizing');
    handle.classList.add('active');
    try { handle.setPointerCapture(ev.pointerId); } catch (e) {}

    function move(e) { apply(handle, e.clientX - x0, starts); }
    function end() {
      handle.removeEventListener('pointermove', move);
      handle.removeEventListener('pointerup', end);
      handle.removeEventListener('pointercancel', end);
      document.body.classList.remove('sw-col-resizing');
      handle.classList.remove('active');
      save();
    }
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);
  }

  function onKey(ev) {
    var handle = ev.currentTarget;
    if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') {
      ev.preventDefault();
      apply(handle, ev.key === 'ArrowRight' ? STEP : -STEP, measure());
      save();
    } else if (ev.key === 'Enter' || ev.key === 'Delete' || ev.key === 'Backspace') {
      ev.preventDefault();
      resetColumn(handle);
    }
  }

  function addHandle(cell, col, edge) {
    var h = document.createElement('span');
    h.className = 'sw-col-resizer ' + edge;
    h.setAttribute('data-col', col);
    h.setAttribute('data-edge', edge);
    h.setAttribute('role', 'separator');
    h.setAttribute('aria-orientation', 'vertical');
    h.setAttribute('tabindex', '0');
    h.setAttribute('title', labels.handle);
    h.setAttribute('aria-label', labels.handle);
    h.addEventListener('pointerdown', startDrag);
    h.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); });
    h.addEventListener('dblclick', function (e) { e.preventDefault(); e.stopPropagation(); resetColumn(h); });
    h.addEventListener('keydown', onKey);
    cell.appendChild(h);
  }

  function init() {
    table = qs('table.spots');
    if (!table || qs('.cardsGrid') || table.classList.contains('sw-resizable')) { return; }
    var head = qs('tr.head', table);
    if (!head) { return; }

    try { labels = JSON.parse(table.getAttribute('data-sw-colresize') || '{}'); } catch (e) { labels = {}; }
    labels.handle = labels.handle || 'Drag to resize the column, double-click to reset';
    labels.reset = labels.reset || 'Reset column widths';
    storeKey = 'spotweb_colwidths_' + (table.getAttribute('data-sw-user') || '0');

    table.classList.add('sw-resizable');
    if (th('category')) { addHandle(th('category'), 'category', 'right'); }
    if (th('title')) { addHandle(th('title'), 'title', 'right'); }
    COLS.slice(1).forEach(function (c) { if (th(c)) { addHandle(th(c), c, 'left'); } });

    var titleTh = th('title');
    if (titleTh) {
      resetBtn = document.createElement('button');
      resetBtn.type = 'button';
      resetBtn.className = 'sw-col-reset';
      resetBtn.title = labels.reset;
      resetBtn.setAttribute('aria-label', labels.reset);
      resetBtn.textContent = '↺';
      resetBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        widths = {};
        render();
        save();
      });
      titleTh.appendChild(resetBtn);
    }

    widths = load();
    render();
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
