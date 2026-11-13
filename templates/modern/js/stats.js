/*
 * Statistics page: small SVG charts drawn from the JSON in #sw-stats-data.
 * No library; the charts take their colours from the theme tokens, so every
 * theme (light or dark) gets matching charts.
 */
(function () {
  var NS = 'http://www.w3.org/2000/svg';
  var CAT_COLOURS = ['#3B82F6', '#F59E0B', '#10B981', '#EC4899']; // same as the list view

  function el(name, attrs, parent) {
    var node = document.createElementNS(NS, name);
    for (var k in attrs) { if (attrs.hasOwnProperty(k)) { node.setAttribute(k, attrs[k]); } }
    if (parent) { parent.appendChild(node); }
    return node;
  }

  function fmt(n) {
    return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function niceMax(v) {
    if (v <= 0) { return 1; }
    var exp = Math.pow(10, Math.floor(Math.log10(v)));
    var steps = [1, 2, 2.5, 5, 10];
    for (var i = 0; i < steps.length; i++) {
      if (steps[i] * exp >= v) { return steps[i] * exp; }
    }
    return 10 * exp;
  }

  /* one shared tooltip */
  var tip = document.createElement('div');
  tip.className = 'sw-chart-tip';
  tip.setAttribute('role', 'status');
  document.body.appendChild(tip);

  function showTip(evt, html) {
    tip.innerHTML = html;
    tip.style.display = 'block';
    var x = evt.clientX + 14, y = evt.clientY - 10;
    var w = tip.offsetWidth;
    if (x + w > window.innerWidth - 8) { x = evt.clientX - w - 14; }
    tip.style.left = x + 'px';
    tip.style.top = y + 'px';
  }
  function hideTip() { tip.style.display = 'none'; }

  /* vertical bars with a light grid, value on hover */
  function columns(box, data, strings, opts) {
    var width = box.clientWidth || 600;
    var height = opts.height || 220;
    var pad = { top: 12, right: 8, bottom: 26, left: 44 };
    var innerW = width - pad.left - pad.right;
    var innerH = height - pad.top - pad.bottom;
    var max = 0, sum = 0;
    data.forEach(function (d) { max = Math.max(max, d.value); sum += d.value; });
    var top = niceMax(max);
    var avg = data.length ? sum / data.length : 0;

    var svg = el('svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height, role: 'img' });

    // grid + axis labels (4 lines)
    for (var g = 0; g <= 4; g++) {
      var gy = pad.top + innerH - (innerH * g / 4);
      el('line', { x1: pad.left, x2: width - pad.right, y1: gy, y2: gy, 'class': g === 0 ? 'sw-axis' : 'sw-grid' }, svg);
      var t = el('text', { x: pad.left - 8, y: gy + 4, 'class': 'sw-tick', 'text-anchor': 'end' }, svg);
      t.textContent = fmt(top * g / 4);
    }

    var slot = innerW / data.length;
    var barW = Math.max(2, Math.min(36, slot * 0.68));
    var every = Math.ceil(data.length / Math.max(1, Math.floor(innerW / 46)));

    data.forEach(function (d, i) {
      var h = top ? innerH * d.value / top : 0;
      var x = pad.left + slot * i + (slot - barW) / 2;
      var y = pad.top + innerH - h;
      var isLast = opts.highlightLast && i === data.length - 1;
      var bar = el('rect', { x: x, y: y, width: barW, height: Math.max(h, d.value > 0 ? 1 : 0), rx: Math.min(4, barW / 3), 'class': 'sw-bar' + (isLast ? ' sw-bar-current' : '') }, svg);
      // a wider invisible target so thin bars are easy to hover
      var hit = el('rect', { x: pad.left + slot * i, y: pad.top, width: slot, height: innerH, 'class': 'sw-hit' }, svg);
      var label = d.long || d.label;
      hit.addEventListener('mousemove', function (e) { bar.classList.add('sw-bar-hover'); showTip(e, '<strong>' + fmt(d.value) + '</strong> ' + strings.spots + '<br><span>' + label + '</span>'); });
      hit.addEventListener('mouseleave', function () { bar.classList.remove('sw-bar-hover'); hideTip(); });

      if (i % every === 0 || data.length <= 12) {
        var lt = el('text', { x: pad.left + slot * i + slot / 2, y: height - 8, 'class': 'sw-tick', 'text-anchor': 'middle' }, svg);
        lt.textContent = d.label;
      }
    });

    if (opts.average && avg > 0) {
      var ay = pad.top + innerH - innerH * avg / top;
      el('line', { x1: pad.left, x2: width - pad.right, y1: ay, y2: ay, 'class': 'sw-avg' }, svg);
      var at = el('text', { x: width - pad.right, y: ay - 5, 'class': 'sw-avg-label', 'text-anchor': 'end' }, svg);
      at.textContent = strings.average + ' ' + fmt(avg);
    }

    box.appendChild(svg);
  }

  /* horizontal bars with count and share, for the categories */
  function hbars(box, data, strings) {
    var total = 0, max = 0;
    data.forEach(function (d) { total += d.value; max = Math.max(max, d.value); });
    var list = document.createElement('div');
    list.className = 'sw-hbars';
    data.forEach(function (d, i) {
      var share = total ? (100 * d.value / total) : 0;
      var row = document.createElement('div');
      row.className = 'sw-hbar';
      row.innerHTML =
        '<div class="sw-hbar-top"><span class="sw-hbar-label"></span><span class="sw-hbar-value"><strong>' + fmt(d.value) + '</strong> · ' + share.toFixed(1).replace('.', ',') + '%</span></div>' +
        '<div class="sw-hbar-track"><div class="sw-hbar-fill"></div></div>';
      row.querySelector('.sw-hbar-label').textContent = d.label;
      var fill = row.querySelector('.sw-hbar-fill');
      fill.style.width = (max ? (100 * d.value / max) : 0) + '%';
      fill.style.background = CAT_COLOURS[i % CAT_COLOURS.length];
      list.appendChild(row);
    });
    box.appendChild(list);
  }

  function render() {
    var node = document.getElementById('sw-stats-data');
    if (!node) { return; }
    var all;
    try { all = JSON.parse(node.textContent); } catch (e) { return; }
    var strings = all.strings || {};

    Array.prototype.forEach.call(document.querySelectorAll('[data-sw-chart]'), function (box) {
      var key = box.getAttribute('data-sw-chart');
      var data = all[key];
      box.innerHTML = '';
      if (!data || !data.length) { return; }
      if (box.getAttribute('data-sw-kind') === 'hbars') {
        hbars(box, data, strings);
      } else {
        columns(box, data, strings, { height: key === 'perday' ? 240 : 200, average: key === 'perday', highlightLast: key === 'perday' });
      }
    });
  }

  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(render, 150);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
