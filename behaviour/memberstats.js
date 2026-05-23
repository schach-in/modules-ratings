/*
 * ratings module
 * progress UI for memberstats import
 *
 * Hydrates the .memberstats shell rendered by
 * templates/memberstats.template.txt: fetches state from the
 * `ratings_memberstats_progress` brick, renders a progress bar, status
 * line and log tail, and triggers the background worker via a plain JS
 * POST against the page itself when the operator clicks Start.
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */

(function () {
	'use strict';

	const root = document.querySelector('.memberstats');
	if (!root) return;

	const progressUrl = root.dataset.progressUrl || '';
	const pollMs = parseInt(root.dataset.pollMs, 10) || 1000;
	const autoDelay = parseInt(root.dataset.autoDelaySeconds, 10) || 10;
	const overwrite = root.dataset.overwrite === '1';
	const importNext = root.dataset.importNext || '';
	const missingCount = parseInt(root.dataset.missingCount, 10) || 0;
	const labels = {
		importNext: '%%% text Import next missing snapshot %%%',
		reimport: '%%% text Re-import snapshot %%%',
		queued: '%%% text Queued… %%%',
		running: '%%% text Memberstats import is running. %%%',
		stuck: '%%% text Previous memberstats import did not finish. %%%',
		done: '%%% text All snapshots imported. %%%',
		rows: '%%% text rows %%%',
		contacts: '%%% text contacts %%%',
		closed: '%%% text closed %%%',
		overwrite: '%%% text overwrite %%%',
		logContact: '%%% text contact %%%',
		logVerband: '%%% text verband %%%',
		logContactEnd: '%%% text contact_end %%%',
		failedStart: '%%% text Could not start background job. %%%',
		autoStop: '%%% text Stop auto import (%s) %%%',
		autoStopped: '%%% text Auto import stopped %%%'
	};

	const button = root.querySelector('.memberstats-start');
	const autoButton = root.querySelector('.memberstats-auto-stop');
	const bar = root.querySelector('.memberstats-bar');
	const status = root.querySelector('.memberstats-status');
	const log = root.querySelector('.memberstats-log');

	let pollTimer = null;
	let countdownTimer = null;
	let auto = true;
	let watched = false;
	let remaining = missingCount;

	function startButtonLabel() {
		return overwrite ? labels.reimport + ' ' + importNext : labels.importNext;
	}

	function show(el) { el.hidden = false; }
	function hide(el) { el.hidden = true; }

	function clearCountdown() {
		if (countdownTimer) clearInterval(countdownTimer);
		countdownTimer = null;
		hide(autoButton);
	}

	function scheduleNext() {
		if (!auto || overwrite || remaining <= 0) return;
		let left = autoDelay;
		autoButton.disabled = false;
		autoButton.textContent = labels.autoStop.replace('%s', left);
		show(autoButton);
		hide(button);
		countdownTimer = setInterval(function () {
			left--;
			if (left <= 0) {
				clearCountdown();
				remaining--;
				start();
				return;
			}
			autoButton.textContent = labels.autoStop.replace('%s', left);
		}, 1000);
	}

	function renderIdle(state) {
		hide(bar);
		hide(status);
		if (state && state.tail && state.tail.length) {
			log.textContent = state.tail.map(formatEntry).join('\n');
			show(log);
		} else {
			hide(log);
		}
		if (countdownTimer) {
			hide(button);
			return;
		}
		if (overwrite || remaining > 0) {
			button.textContent = startButtonLabel();
			button.disabled = false;
			show(button);
		} else {
			hide(button);
			if (!(state && state.tail && state.tail.length)) {
				status.textContent = labels.done;
				show(status);
			}
		}
	}

	function renderBusy(state) {
		clearCountdown();
		hide(button);
		hide(autoButton);
		if (state.percent !== null && state.percent !== undefined) {
			bar.value = state.percent;
			bar.removeAttribute('indeterminate');
		} else {
			bar.removeAttribute('value');
		}
		show(bar);

		const parts = [];
		parts.push(state.action === 'queued' ? labels.queued : labels.running);
		if (state.snapshot) parts.push(state.snapshot);
		if (state.action) parts.push(state.action);
		if (state.rows_done !== null && state.rows_done !== undefined)
			parts.push(state.rows_done.toLocaleString() + ' ' + labels.rows);
		if (state.club_code) parts.push(state.club_code);
		if (state.contact) parts.push(state.contact);
		if (state.contacts_created !== null && state.contacts_created !== undefined)
			parts.push(state.contacts_created.toLocaleString() + ' ' + labels.contacts);
		status.textContent = parts.join(' · ');
		show(status);

		log.textContent = (state.tail || []).map(formatEntry).join('\n');
		show(log);
	}

	function renderStuck(state) {
		clearCountdown();
		watched = false;
		hide(bar);
		const parts = [labels.stuck];
		if (state.snapshot) parts.push(state.snapshot);
		if (state.action) parts.push(state.action);
		if (state.club_code) parts.push(state.club_code);
		if (state.contact) parts.push(state.contact);
		if (state.contacts_created !== null && state.contacts_created !== undefined)
			parts.push(state.contacts_created.toLocaleString() + ' ' + labels.contacts);
		status.textContent = parts.join(' · ');
		show(status);
		log.textContent = (state.tail || []).map(formatEntry).join('\n');
		show(log);
		if (overwrite || remaining > 0) {
			button.textContent = startButtonLabel();
			button.disabled = false;
			show(button);
		} else {
			hide(button);
		}
	}

	function render(state) {
		if (!state) return;
		if (state.state === 'busy') renderBusy(state);
		else if (state.state === 'stuck') renderStuck(state);
		else renderIdle(state);
	}

	function parseResult(raw) {
		if (!raw) return {};
		if (typeof raw === 'object') return raw;
		if (typeof raw !== 'string') return {};
		try {
			const parsed = JSON.parse(raw);
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch (e) {
			return {};
		}
	}

	function formatEntry(entry) {
		const time = new Date(entry.timestamp * 1000).toLocaleTimeString();
		const result = parseResult(entry.result);
		const extras = [];
		if (entry.action === 'contact') {
			if (result.club_code) extras.push(result.club_code);
			if (result.contact) extras.push(result.contact);
			if (result.contact_id) extras.push('#' + result.contact_id);
			if (result.snapshot) extras.push(result.snapshot);
			return time + '  ' + labels.logContact + (extras.length ? '  ' + extras.join(', ') : '');
		}
		if (entry.action === 'verband') {
			if (result.verband_code) extras.push(result.verband_code);
			if (result.contact) extras.push(result.contact);
			if (result.contact_id) extras.push('#' + result.contact_id);
			if (result.snapshot) extras.push(result.snapshot);
			return time + '  ' + labels.logVerband + (extras.length ? '  ' + extras.join(', ') : '');
		}
		if (entry.action === 'contact_end') {
			if (result.club_code) extras.push(result.club_code);
			if (result.verband_code) extras.push(result.verband_code);
			if (result.contact) extras.push(result.contact);
			if (result.contact_id) extras.push('#' + result.contact_id);
			if (result.end_date) extras.push(result.end_date);
			if (result.snapshot) extras.push(result.snapshot);
			return time + '  ' + labels.logContactEnd + (extras.length ? '  ' + extras.join(', ') : '');
		}
		if (result.snapshot) extras.push(result.snapshot);
		if (result.bytes_total) {
			const pct = Math.round(100 * (result.bytes_done || 0) / result.bytes_total);
			extras.push(pct + '%');
		}
		if (result.rows_done !== undefined && result.rows_done !== null)
			extras.push(result.rows_done.toLocaleString() + ' ' + labels.rows);
		if (result.contacts_created !== undefined && result.contacts_created !== null)
			extras.push(result.contacts_created.toLocaleString() + ' ' + labels.contacts);
		if (result.contacts_closed !== undefined && result.contacts_closed !== null)
			extras.push(result.contacts_closed.toLocaleString() + ' ' + labels.closed);
		if (result.overwrite) extras.push(labels.overwrite);
		if (result.club_code) extras.push(result.club_code);
		if (result.contact) extras.push(result.contact);
		if (result.contact_id) extras.push('#' + result.contact_id);
		if (result.msg) extras.push(result.msg);
		return time + '  ' + entry.action + (extras.length ? '  ' + extras.join(', ') : '');
	}

	async function poll() {
		if (!progressUrl) return;
		try {
			const response = await fetch(progressUrl, {cache: 'no-store'});
			if (!response.ok) return;
			const state = await response.json();
			render(state);
			if (state.state === 'busy') {
				watched = true;
			} else {
				stopPolling();
				if (watched && state.action === 'done' && remaining > 0) {
					watched = false;
					scheduleNext();
				}
			}
		} catch (e) {
			// network glitch — next tick will retry
		}
	}

	function startPolling() {
		if (pollTimer) return;
		pollTimer = setInterval(poll, pollMs);
	}

	function stopPolling() {
		if (!pollTimer) return;
		clearInterval(pollTimer);
		pollTimer = null;
	}

	async function start() {
		button.disabled = true;
		clearCountdown();
		auto = true;
		watched = true;
		autoButton.disabled = false;
		renderBusy({
			state: 'busy',
			action: 'queued',
			snapshot: importNext,
			rows_done: null,
			bytes_done: 0,
			bytes_total: null,
			percent: null,
			ts: Math.floor(Date.now() / 1000),
			tail: []
		});
		startPolling();
		try {
			const response = await fetch(window.location.href, {method: 'POST'});
			if (!response.ok) throw new Error(response.statusText);
		} catch (e) {
			stopPolling();
			watched = false;
			hide(bar);
			status.textContent = labels.failedStart;
			show(status);
			button.disabled = false;
			button.textContent = startButtonLabel();
			show(button);
		}
	}

	button.addEventListener('click', start);
	autoButton.addEventListener('click', function () {
		auto = false;
		clearCountdown();
		autoButton.textContent = labels.autoStopped;
		autoButton.disabled = true;
		show(autoButton);
		button.disabled = false;
		show(button);
	});

	async function init() {
		if (!progressUrl) {
			renderIdle(null);
			return;
		}
		try {
			const response = await fetch(progressUrl, {cache: 'no-store'});
			if (!response.ok) throw new Error(response.statusText);
			const state = await response.json();
			render(state);
			if (state.state === 'busy') {
				watched = true;
				startPolling();
			}
		} catch (e) {
			renderIdle(null);
		}
	}
	init();
})();
