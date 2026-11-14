/*
 * Radarr & Sonarr
 *
 *  - My account > Radarr & Sonarr: test the connection, load root folders
 *    and quality profiles, fetch the library now
 *  - spot list and spot page: "+ Not in Radarr" opens a dialog to pick the
 *    right title and add it
 *  - after a download: show what happened with Radarr / Sonarr
 */
(function () {
    'use strict';

    var T = {
        en: {
            add: 'Add', cancel: 'Cancel', close: 'Close', searching: 'Searching…', adding: 'Adding…',
            title: 'Add to %s', pick: 'Title', root: 'Root folder', profile: 'Quality profile',
            first: 'First one in the list', inlib: 'already in your library', none: 'Nothing found with this title.',
            seasons: '%d seasons', monitor: 'Only the current season will be monitored.',
            failed: 'Could not reach Spotweb. Try again.', added: 'Added', ok: 'OK'
        },
        nl: {
            add: 'Toevoegen', cancel: 'Annuleren', close: 'Sluiten', searching: 'Zoeken…', adding: 'Toevoegen…',
            title: 'Toevoegen aan %s', pick: 'Titel', root: 'Hoofdmap', profile: 'Kwaliteitsprofiel',
            first: 'De eerste uit de lijst', inlib: 'staat al in je bibliotheek', none: 'Niets gevonden met deze titel.',
            seasons: '%d seizoenen', monitor: 'Alleen het lopende seizoen wordt gevolgd.',
            failed: 'Spotweb is niet bereikbaar. Probeer het opnieuw.', added: 'Toegevoegd', ok: 'OK'
        }
    };
    var lang = null;
    function t(key, arg) {
        if (!lang) {
            var holder = document.querySelector('[data-sw-arr-lang]');
            lang = (holder && holder.getAttribute('data-sw-arr-lang') === 'nl') ? 'nl' : 'en';
        }
        var s = (T[lang][key] || T.en[key] || key);
        return (arg === undefined) ? s : s.replace('%s', arg).replace('%d', arg);
    }

    function el(tag, attrs, text) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'class') { e.className = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
        });
        if (text !== undefined && text !== null) { e.textContent = text; }
        return e;
    }

    function endpoint() {
        var holder = document.querySelector('[data-sw-arr-url][data-sw-arr-xsrf]');
        return holder ? { url: holder.getAttribute('data-sw-arr-url'), xsrf: holder.getAttribute('data-sw-arr-xsrf') } : null;
    }

    function post(url, xsrf, action, fields) {
        var body = new URLSearchParams();
        body.append('arrform[xsrfid]', xsrf);
        body.append('arrform[submit' + action + ']', '1');
        Object.keys(fields || {}).forEach(function (k) { body.append('arrform[' + k + ']', fields[k]); });
        return fetch(url, {
            method: 'POST', credentials: 'same-origin', body: body,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: t('failed') }; });
        }, function () {
            return { ok: false, message: t('failed') };
        });
    }

    // ------------------------------------------------------------------
    // toast
    // ------------------------------------------------------------------
    function toast(message, ok) {
        if (!message) { return; }
        var box = document.querySelector('.sw-arr-toasts');
        if (!box) {
            box = el('div', { 'class': 'sw-arr-toasts', 'role': 'status', 'aria-live': 'polite' });
            document.body.appendChild(box);
        }
        var item = el('div', { 'class': 'sw-arr-toast ' + (ok ? 'is-ok' : 'is-bad') });
        item.appendChild(el('b', {}, ok ? '✓' : '!'));
        item.appendChild(el('span', {}, message));
        box.appendChild(item);
        setTimeout(function () { item.classList.add('is-gone'); }, 6000);
        setTimeout(function () { if (item.parentNode) { item.parentNode.removeChild(item); } }, 6600);
    }

    // ------------------------------------------------------------------
    // labels
    // ------------------------------------------------------------------
    function updateLabels(messageId, view) {
        if (!view || !view.label) { return; }
        document.querySelectorAll('.sw-arr-pill[data-sw-arr-msg]').forEach(function (pill) {
            if (pill.getAttribute('data-sw-arr-msg') !== messageId || pill.closest('.sw-arr-card')) { return; }
            var tag = view.link ? 'a' : 'span';
            var fresh = el(tag, { 'class': 'sw-arr-pill sw-arr-' + view.tone, 'data-sw-arr-msg': messageId, 'data-sw-arr-state': view.state || '', 'title': view.long });
            if (view.link) { fresh.setAttribute('href', view.link); fresh.setAttribute('target', '_blank'); fresh.setAttribute('rel', 'noopener'); }
            fresh.setAttribute('aria-label', view.label);
            fresh.appendChild(el('i', {}));
            fresh.appendChild(el('span', { 'class': 'sw-arr-txt' }, view.label));
            pill.parentNode.replaceChild(fresh, pill);
        });
        var card = document.querySelector('.sw-arr-card[data-sw-arr-msg]');
        if (card && card.getAttribute('data-sw-arr-msg') === messageId) {
            card.className = 'sw-arr-card sw-arr-tone-' + view.tone;
            card.setAttribute('data-sw-arr-state', view.state || '');
            var strong = card.querySelector('.sw-arr-card-text strong');
            var span = card.querySelector('.sw-arr-card-text span');
            if (strong && view.title) { strong.textContent = view.title + (view.year ? ' (' + view.year + ')' : ''); }
            if (span) { span.textContent = view.long; }
            var addBtn = card.querySelector('[data-sw-arr-add]');
            if (addBtn) { addBtn.parentNode.removeChild(addBtn); }
            if (view.link && !card.querySelector('.sw-arr-card-actions a')) {
                var open = el('a', { 'class': 'sw-btn sw-btn-quiet', href: view.link, target: '_blank', rel: 'noopener' }, view.name + ' ↗');
                card.querySelector('.sw-arr-card-actions').appendChild(open);
            }
        }
    }

    function stateFor(messageId) {
        var node = document.querySelector('[data-sw-arr-msg="' + (window.CSS && CSS.escape ? CSS.escape(messageId) : messageId) + '"]');
        return node ? node.getAttribute('data-sw-arr-state') : null;
    }

    function messageIdForDownload(id) {
        var button = document.querySelector('.sab_' + id);
        if (!button) { return null; }
        var scope = button.closest('tr, .spotCard, .sw-detail, #details') || document;
        var node = scope.querySelector('[data-sw-arr-msg]');
        return node ? node.getAttribute('data-sw-arr-msg') : null;
    }

    // called by downloadSabnzbd() in scripts.js when Spotweb sent the download itself
    window.swArrAfterDownload = function (id, data) {
        var arr = data && data.data && data.data.arr;
        if (!arr) { return; }
        toast(arr.message, arr.ok);
        var messageId = messageIdForDownload(id);
        if (messageId && arr.view) { updateLabels(messageId, arr.view); }
    };

    // called by downloadSabnzbd() when the browser sends the download to SABnzbd
    window.swArrEnsure = function (id) {
        var ep = endpoint();
        var messageId = messageIdForDownload(id);
        if (!ep || !messageId) { return; }
        var state = stateFor(messageId);
        if (state && state !== 'notin') { return; }
        post(ep.url, ep.xsrf, 'ensure', { messageid: messageId }).then(function (r) {
            if (r.message) { toast(r.message, r.ok); }
            if (r.view) { updateLabels(messageId, r.view); }
        });
    };

    // ------------------------------------------------------------------
    // "Add to Radarr / Sonarr" dialog
    // ------------------------------------------------------------------
    function closeDialog(overlay) {
        if (overlay && overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        document.removeEventListener('keydown', overlay._esc);
    }

    function fillSelect(select, items, valueKey, labelKey, selected) {
        select.innerHTML = '';
        select.appendChild(el('option', { value: valueKey === 'id' ? '0' : '' }, t('first')));
        (items || []).forEach(function (item) {
            var opt = el('option', { value: String(item[valueKey]) }, item[labelKey]);
            if (String(item[valueKey]) === String(selected)) { opt.selected = true; }
            select.appendChild(opt);
        });
    }

    function openAddDialog(messageId) {
        var ep = endpoint();
        if (!ep) { return; }

        var overlay = el('div', { 'class': 'sw-arr-overlay' });
        var dialog = el('div', { 'class': 'sw-arr-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'sw-arr-dialog-title' });
        var heading = el('h3', { id: 'sw-arr-dialog-title' }, t('searching'));
        var bodyBox = el('div', { 'class': 'sw-arr-dialog-body' });
        var status = el('p', { 'class': 'sw-arr-dialog-status', role: 'status' });
        var foot = el('div', { 'class': 'sw-arr-dialog-foot' });
        var cancel = el('button', { type: 'button', 'class': 'sw-btn sw-btn-quiet' }, t('cancel'));
        var add = el('button', { type: 'button', 'class': 'sw-btn sw-btn-primary', disabled: 'disabled' }, t('add'));
        foot.appendChild(cancel);
        foot.appendChild(add);
        dialog.appendChild(heading);
        dialog.appendChild(bodyBox);
        dialog.appendChild(status);
        dialog.appendChild(foot);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        overlay._esc = function (ev) { if (ev.key === 'Escape') { closeDialog(overlay); } };
        document.addEventListener('keydown', overlay._esc);
        overlay.addEventListener('click', function (ev) { if (ev.target === overlay) { closeDialog(overlay); } });
        cancel.addEventListener('click', function () { closeDialog(overlay); });
        cancel.focus();

        post(ep.url, ep.xsrf, 'lookup', { messageid: messageId }).then(function (r) {
            if (!r.ok) {
                heading.textContent = t('title', 'Radarr / Sonarr');
                status.textContent = r.message || t('failed');
                status.classList.add('is-bad');
                cancel.textContent = t('close');
                return;
            }

            var name = r.service === 'radarr' ? 'Radarr' : 'Sonarr';
            heading.textContent = t('title', name);
            if (!r.candidates || !r.candidates.length) {
                status.textContent = r.message || t('none');
                cancel.textContent = t('close');
                return;
            }

            var pickLabel = el('label', { 'for': 'sw-arr-pick' }, t('pick'));
            var pick = el('select', { id: 'sw-arr-pick' });
            r.candidates.forEach(function (c) {
                var text = c.title + (c.year ? ' (' + c.year + ')' : '');
                if (r.service === 'sonarr' && c.seasons) { text += ' · ' + t('seasons', c.seasons); }
                if (c.imdbid) { text += ' · ' + c.imdbid; }
                if (c.inlib) { text += ' · ' + t('inlib'); }
                var opt = el('option', { value: c.ref }, text);
                if (c.best) { opt.selected = true; }
                pick.appendChild(opt);
            });

            var grid = el('div', { 'class': 'sw-arr-dialog-grid' });
            var rootWrap = el('div', {});
            var rootLabel = el('label', { 'for': 'sw-arr-root' }, t('root'));
            var root = el('select', { id: 'sw-arr-root' });
            fillSelect(root, r.options.rootfolders, 'path', 'path', r.options.rootfolder);
            rootWrap.appendChild(rootLabel);
            rootWrap.appendChild(root);
            var profWrap = el('div', {});
            var profLabel = el('label', { 'for': 'sw-arr-profile' }, t('profile'));
            var profile = el('select', { id: 'sw-arr-profile' });
            fillSelect(profile, r.options.profiles, 'id', 'name', r.options.profile);
            profWrap.appendChild(profLabel);
            profWrap.appendChild(profile);
            grid.appendChild(rootWrap);
            grid.appendChild(profWrap);

            bodyBox.appendChild(pickLabel);
            bodyBox.appendChild(pick);
            bodyBox.appendChild(grid);
            if (r.service === 'sonarr') { bodyBox.appendChild(el('p', { 'class': 'sw-arr-dialog-hint' }, t('monitor'))); }

            add.removeAttribute('disabled');
            pick.focus();
            add.addEventListener('click', function () {
                add.setAttribute('disabled', 'disabled');
                add.textContent = t('adding');
                status.textContent = '';
                post(ep.url, ep.xsrf, 'add', { messageid: messageId, ref: pick.value, rootfolder: root.value, profile: profile.value }).then(function (res) {
                    if (res.ok) {
                        closeDialog(overlay);
                        toast(res.message, true);
                        if (res.view) { updateLabels(messageId, res.view); }
                    } else {
                        add.removeAttribute('disabled');
                        add.textContent = t('add');
                        status.textContent = res.message || t('failed');
                        status.classList.add('is-bad');
                    }
                });
            });
        });
    }

    document.addEventListener('click', function (ev) {
        var trigger = ev.target.closest ? ev.target.closest('[data-sw-arr-add]') : null;
        if (!trigger) { return; }
        ev.preventDefault();
        ev.stopPropagation();
        openAddDialog(trigger.getAttribute('data-sw-arr-add'));
    }, true);

    // ------------------------------------------------------------------
    // My account > Radarr & Sonarr
    // ------------------------------------------------------------------
    function initPrefs(tab) {
        var url = tab.getAttribute('data-sw-arr-url');
        var xsrf = tab.getAttribute('data-sw-xsrf');

        tab.querySelectorAll('[data-sw-arr-service]').forEach(function (panel) {
            var service = panel.getAttribute('data-sw-arr-service');
            var result = panel.querySelector('.sw-arr-result');
            var testBtn = panel.querySelector('[data-sw-arr-test]');
            var refreshBtn = panel.querySelector('[data-sw-arr-refresh]');
            var syncLine = panel.querySelector('[data-sw-arr-syncline]');

            function field(name) { return panel.querySelector('[data-sw-arr-field="' + name + '"]'); }
            function list(name) { return panel.querySelector('[data-sw-arr-list="' + name + '"]'); }

            function show(r, quiet) {
                if (quiet && r.ok) { result.textContent = ''; } else { result.textContent = r.message || ''; }
                result.className = 'sw-arr-result ' + (r.ok ? 'is-ok' : 'is-bad');
                if (!r.ok) { return; }
                var roots = list('rootfolders');
                fillSelect(roots, (r.rootfolders || []).map(function (f) { return { path: f.path }; }), 'path', 'path', roots.value);
                var profiles = list('profiles');
                fillSelect(profiles, r.profiles || [], 'id', 'name', profiles.value);
                var languages = list('languageprofiles');
                if (languages) {
                    var v3 = !!(r.languageprofiles && r.languageprofiles.length);
                    panel.querySelectorAll('.sw-arr-v3').forEach(function (n) { n.hidden = !v3; });
                    if (v3) { fillSelect(languages, r.languageprofiles, 'id', 'name', languages.value); }
                }
            }

            function test(quiet) {
                if (!quiet) {
                    result.textContent = tab.getAttribute('data-sw-text-testing');
                    result.className = 'sw-arr-result';
                }
                testBtn.disabled = true;
                post(url, xsrf, 'test', { service: service, url: field('url').value, apikey: field('apikey').value }).then(function (r) {
                    testBtn.disabled = false;
                    show(r, quiet);
                });
            }

            testBtn.addEventListener('click', function () { test(false); });

            if (refreshBtn) {
                refreshBtn.addEventListener('click', function () {
                    refreshBtn.disabled = true;
                    syncLine.textContent = tab.getAttribute('data-sw-text-refreshing');
                    post(url, xsrf, 'refresh', { service: service }).then(function (r) {
                        refreshBtn.disabled = false;
                        syncLine.textContent = r.message || tab.getAttribute('data-sw-text-failed');
                    });
                });
            }

            // saved and switched on: load the folders and profiles quietly
            var enabled = panel.querySelector('input.enabler');
            var keySaved = (field('apikey').getAttribute('placeholder') || '').indexOf('••') !== -1;
            if (enabled && enabled.checked && keySaved && field('url').value) { test(true); }
        });
    }

    function init() {
        var tab = document.querySelector('.sw-arr-prefs');
        if (tab) { initPrefs(tab); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
