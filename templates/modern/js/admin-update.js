/*
 * Admin > About & updates: check, choose channel, update now and follow
 * the update until the database upgrade is done.
 */
(function () {
	'use strict';

	function init() {
		var tab = document.getElementById('editsettingstab-11');
		if (!tab || tab.getAttribute('data-sw-ready')) { return; }
		tab.setAttribute('data-sw-ready', '1');

		var url = tab.getAttribute('data-sw-url');
		var repo = tab.getAttribute('data-sw-repo');
		var xsrf = tab.getAttribute('data-sw-xsrf');
		var xsrfAt = Date.now();
		var S = parse(tab.getAttribute('data-sw-strings')) || {};
		var channels = parse(tab.getAttribute('data-sw-channels')) || {};
		var state = parse(tab.getAttribute('data-sw-state')) || {};
		var panel = tab.querySelector('.sw-update-panel');
		var btnCheck = tab.querySelector('[data-sw-upd-check]');
		var btnStart = tab.querySelector('[data-sw-upd-start]');
		var btnRetry = tab.querySelector('[data-sw-upd-retrydb]');
		var bar = panel.querySelector('.sw-progress');
		var jobKey = null;
		var pollTimer = null;
		var phases = ['prepare', 'git', 'download', 'extract', 'plan', 'backup', 'copy', 'webhook', 'database', 'done'];

		function parse(s) { try { return JSON.parse(s); } catch (e) { return null; } }
		function el(name) { return tab.querySelector('[data-sw-upd="' + name + '"]'); }
		function fmt(s) {
			var args = Array.prototype.slice.call(arguments, 1);
			return String(s || '').replace(/%[sd]/g, function () { return args.length ? args.shift() : ''; });
		}
		function ago(ts) {
			var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
			if (s < 60) { return S.justnow; }
			if (s < 3600) { return fmt(S.minutes, Math.floor(s / 60)); }
			if (s < 172800) { return fmt(S.hours, Math.floor(s / 3600)); }
			return new Date(ts * 1000).toLocaleDateString();
		}
		function when(ts) { return ts ? new Date(ts * 1000).toLocaleString() : '–'; }
		function short(sha) { return sha ? String(sha).substr(0, 7) : '–'; }
		function show(node, on) { if (node) { node.hidden = !on; } }

		function post(action, extra) {
			var refresh = (Date.now() - xsrfAt > 20 * 60 * 1000) ? request('GET', url) : Promise.resolve(null);
			return refresh.then(function () {
				var body = new URLSearchParams();
				body.append('adminupdateform[xsrfid]', xsrf);
				body.append('adminupdateform[submit' + action + ']', '1');
				Object.keys(extra || {}).forEach(function (k) { body.append('adminupdateform[' + k + ']', extra[k]); });
				return request('POST', url, body);
			});
		}
		function request(method, target, body) {
			return fetch(target, { method: method, body: body, credentials: 'same-origin', cache: 'no-store',
				headers: { 'Accept': 'application/json' } })
				.then(function (r) {
					return r.json().catch(function () { return {}; }).then(function (data) {
						if (data && data.xsrf) { xsrf = data.xsrf; xsrfAt = Date.now(); }
						if (!r.ok) { data._status = r.status; }
						return data;
					});
				});
		}

		function renderCheck(check, config) {
			var title = el('title'), sub = el('sub'), list = el('changes');
			var st = check ? check.state : 'never';
			var branch = (check && check.branch) || (config && config.branch) || '';
			title.textContent = (check && check.blocked) ? S.blocked : (S[st] || S.never);
			var subText = '';
			if (check) {
				if (st === 'behind') { subText = fmt(S.sub_behind, check.ahead, branch); }
				else if (st === 'switch') { subText = fmt(S.sub_switch, branch); }
				else if (st === 'unknown') { subText = fmt(S.sub_unknown, branch); }
				else if (st === 'uptodate') { subText = fmt(check.detected ? S.sub_detected : S.sub_uptodate, branch) + (check.head ? ' · ' + short(check.head.sha) : ''); }
				else if (st === 'nobranch') { subText = fmt(S.sub_nobranch, branch); }
			}
			sub.textContent = subText;
			el('checked').textContent = check ? fmt(S.checked, ago(check.checked)) : '';

			var err = el('error');
			var msg = check ? (check.blocked ? check.blocked + ' ' + S.blocked_hint : (st === 'error' ? check.error : '')) : '';
			err.textContent = msg;
			show(err, !!msg);

			list.innerHTML = '';
			if (check && check.commits && check.commits.length && (st === 'behind' || st === 'switch')) {
				check.commits.forEach(function (c) {
					var li = document.createElement('li');
					var a = document.createElement('a');
					a.href = 'https://github.com/' + repo + '/commit/' + c.sha;
					a.target = '_blank'; a.rel = 'noopener';
					a.className = 'sw-mono';
					a.textContent = short(c.sha);
					var t = document.createElement('span');
					t.textContent = c.message;
					var d = document.createElement('small');
					d.textContent = c.date ? new Date(c.date * 1000).toLocaleDateString() : '';
					li.appendChild(a); li.appendChild(t); li.appendChild(d);
					list.appendChild(li);
				});
				if (check.more > 0) {
					var more = document.createElement('li');
					more.className = 'sw-changes-more';
					more.textContent = fmt(S.more, check.more);
					list.appendChild(more);
				}
			}
			show(list, list.children.length > 0);

			var canStart = check && !check.blocked && (st === 'behind' || st === 'switch' || st === 'unknown');
			show(btnStart, !!canStart);
			panel.setAttribute('data-status', canStart ? 'available' : 'idle');
		}

		function renderJob(job) {
			var phaseEl = el('phase');
			if (!job || job.status === 'idle') { show(bar, false); show(phaseEl, false); show(btnRetry.parentNode, false); return; }

			renderLast(job);
			var busy = ['running', 'files_done', 'database'].indexOf(job.status) >= 0;
			var pct = job.status === 'done' ? 100 : Math.round(100 * Math.max(0, phases.indexOf(job.phase)) / (phases.length - 1));
			show(bar, busy || (!!jobKey && (job.status === 'done' || job.status === 'failed')));
			bar.setAttribute('aria-valuenow', pct);
			bar.querySelector('i').style.width = Math.max(4, pct) + '%';

			if (busy) {
				panel.setAttribute('data-status', 'running');
				el('title').textContent = S.s_running;
				el('sub').textContent = '';
				phaseEl.textContent = S['p_' + (job.status === 'files_done' ? 'database' : job.phase)] || '';
				show(phaseEl, true);
				btnCheck.disabled = true;
				show(btnStart, false);
			} else {
				btnCheck.disabled = false;
				show(phaseEl, false);
			}
			show(btnRetry.parentNode, (job.status === 'failed' || job.status === 'stalled') && job.phase === 'database' && !!jobKey);
		}

		function renderLast(job) {
			if (!job || !job.started) { return; }
			show(el('last'), true);
			el('last-by').textContent = job.by || '–';
			el('last-when').textContent = when(job.started);
			el('last-fromto').textContent = short(job.from) + ' → ' + short(job.to) + (job.branch ? ' (' + job.branch + ')' : '');
			var busy = ['running', 'files_done', 'database'].indexOf(job.status) >= 0;
			var result = busy ? S.s_running : (S['s_' + job.status] || job.status);
			if (job.status === 'failed' && job.message) { result += ': ' + job.message; }
			if (job.status === 'done' && job.result) { result += ' · ' + fmt(S.files, job.result.changed, job.result.added, job.result.removed); }
			el('last-result').textContent = result;
		}

		function renderAll(data) {
			if (!data) { return; }
			if (data.config) {
				el('channel').textContent = channels[data.config.channel] || data.config.channel;
				el('branch').textContent = data.config.branch;
			}
			renderCheck(data.check, data.config);
			renderJob(data.job);
		}

		function poll() {
			clearTimeout(pollTimer);
			if (!jobKey) { return; }
			request('GET', url + '&job=' + encodeURIComponent(jobKey) + '&action=status').then(function (job) {
				follow(job);
			}).catch(function () {
				// the site may be briefly unavailable while files are replaced
				pollTimer = setTimeout(poll, 3000);
			});
		}

		function follow(job) {
			if (!job || job._status === 403) { return; }
			if (job._status === 503 || job.status === 'unreachable') { pollTimer = setTimeout(poll, 3000); return; }
			renderJob(job);
			if (job.status === 'done') {
				el('title').textContent = S.s_done;
				el('sub').textContent = S.done_sub;
				panel.setAttribute('data-status', 'done');
				try { sessionStorage.removeItem('sw-update-job'); } catch (e) {}
				setTimeout(function () { window.location.reload(); }, 1800);
				return;
			}
			if (job.status === 'requested') {
				el('title').textContent = S.s_requested;
				el('sub').textContent = S.requested_sub;
				return;
			}
			if (job.status === 'failed' || job.status === 'stalled') {
				panel.setAttribute('data-status', 'failed');
				el('title').textContent = S['s_' + job.status];
				var err = el('error');
				err.textContent = job.message || '';
				show(err, !!job.message);
				return;
			}
			pollTimer = setTimeout(poll, 1500);
		}

		btnCheck.addEventListener('click', function () {
			btnCheck.disabled = true;
			el('sub').textContent = '…';
			post('check').then(function (data) { state = data; renderAll(data); }).catch(function () { el('error').textContent = S.failed; show(el('error'), true); })
				.then(function () { btnCheck.disabled = false; });
		});

		btnStart.addEventListener('click', function () {
			var check = state.check || {};
			var question = check.state === 'switch' ? fmt(S.confirm_switch, check.branch) : S.confirm;
			if (!window.confirm(question)) { return; }
			btnStart.disabled = true;
			btnCheck.disabled = true;
			renderJob({ status: 'running', phase: 'prepare', started: Math.floor(Date.now() / 1000) });
			post('start').then(function (data) {
				btnStart.disabled = false;
				if (!data.started) {
					btnCheck.disabled = false;
					el('title').textContent = S.s_failed;
					el('error').textContent = data.message || S.failed;
					show(el('error'), true);
					show(bar, false);
					show(el('phase'), false);
					return;
				}
				jobKey = data.key;
				try { sessionStorage.setItem('sw-update-job', jobKey); } catch (e) {}
				setTimeout(poll, 1000);
			}).catch(function () {
				// the answer can get lost while files change; keep following
				setTimeout(poll, 2000);
			});
		});

		btnRetry.addEventListener('click', function () {
			if (!jobKey) { return; }
			btnRetry.disabled = true;
			request('POST', url + '&job=' + encodeURIComponent(jobKey) + '&action=retrydb').then(function (job) {
				btnRetry.disabled = false;
				follow(job);
			});
		});

		// channel: save straight away, then show what switching would do
		Array.prototype.forEach.call(tab.querySelectorAll('input[name="sw-update-channel"]'), function (radio) {
			radio.addEventListener('change', function () {
				if (!radio.checked) { return; }
				el('sub').textContent = '…';
				post('save', { channel: radio.value }).then(function (data) {
					state = data; renderAll(data);
				});
			});
		});

		tab.querySelector('[data-sw-upd-save]').addEventListener('click', function () {
			var extra = {
				method: tab.querySelector('#sw-update-method').value,
				webhook: tab.querySelector('#sw-update-webhook').value,
				token: tab.querySelector('#sw-update-token').value
			};
			var clear = tab.querySelector('#sw-update-cleartoken');
			if (clear && clear.checked) { extra.cleartoken = '1'; }
			var msg = el('savemsg');
			msg.textContent = '…';
			post('save', extra).then(function (data) {
				msg.textContent = data.errors ? data.errors.join(' ') : S.saved;
				tab.querySelector('#sw-update-token').value = '';
				if (!data.errors) { state = data; renderAll(data); }
			});
		});

		// Enter in these fields must not submit the settings form around them
		Array.prototype.forEach.call(tab.querySelectorAll('input'), function (input) {
			input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
		});

		renderAll(state);

		// pick up an update that is still running (page reloaded)
		try { jobKey = sessionStorage.getItem('sw-update-job'); } catch (e) {}
		if (jobKey && state.job && ['running', 'files_done', 'database', 'failed', 'stalled'].indexOf(state.job.status) >= 0) {
			poll();
		} else if (state.job && state.job.busy) {
			// started elsewhere: follow it through the normal page
			(function watch() {
				setTimeout(function () {
					request('GET', url).then(function (data) {
						state = data; renderAll(data);
						if (data.job && data.job.busy) { watch(); } else { window.location.reload(); }
					}).catch(watch);
				}, 3000);
			})();
		} else if (!state.check || (Date.now() / 1000 - state.check.checked) > 6 * 3600) {
			// check by itself now and then, so the page shows something useful
			btnCheck.click();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
