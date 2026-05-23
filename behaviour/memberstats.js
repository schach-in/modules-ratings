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
	const overwrite = root.dataset.overwrite === '1';
	const importNext = root.dataset.importNext || '';
	const missingCount = parseInt(root.dataset.missingCount, 10) || 0;
	const labels = {
		importNext: root.dataset.labelImportNext || 'Import next missing snapshot',
		reimport: root.dataset.labelReimport || 'Re-import snapshot',
		queued: root.dataset.labelQueued || 'Queued…',
		running: root.dataset.labelRunning || 'Import is running.',
		stuck: root.dataset.labelStuck || 'Previous import did not finish.',
		done: root.dataset.labelDone || 'All snapshots imported.',
		rows: root.dataset.labelRows || 'rows',
		failedStart: root.dataset.labelFailedStart || 'Could not start background job.'
	};

	const button = root.querySelector('.memberstats-start');
	const bar = root.querySelector('.memberstats-bar');
	const status = root.querySelector('.memberstats-status');
	const log = root.querySelector('.memberstats-log');

	let pollTimer = null;

	function startButtonLabel() {
		return overwrite ? labels.reimport + ' ' + importNext : labels.importNext;
	}

	function show(el) { el.hidden = false; }
	function hide(el) { el.hidden = true; }

	function renderIdle(state) {
		hide(bar);
		hide(status);
		if (state && state.tail && state.tail.length) {
			log.textContent = state.tail.map(formatEntry).join('\n');
			show(log);
		} else {
			hide(log);
		}
		if (overwrite || missingCount > 0) {
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
		hide(button);
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
			parts.push(state.contacts_created.toLocaleString() + ' contacts');
		status.textContent = parts.join(' · ');
		show(status);

		log.textContent = (state.tail || []).map(formatEntry).join('\n');
		show(log);
	}

	function renderStuck(state) {
		hide(bar);
		const parts = [labels.stuck];
		if (state.snapshot) parts.push(state.snapshot);
		if (state.action) parts.push(state.action);
		if (state.club_code) parts.push(state.club_code);
		if (state.contact) parts.push(state.contact);
		if (state.contacts_created !== null && state.contacts_created !== undefined)
			parts.push(state.contacts_created.toLocaleString() + ' contacts');
		status.textContent = parts.join(' · ');
		show(status);
		log.textContent = (state.tail || []).map(formatEntry).join('\n');
		show(log);
		if (overwrite || missingCount > 0) {
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
			return time + '  contact' + (extras.length ? '  ' + extras.join(', ') : '');
		}
		if (result.snapshot) extras.push(result.snapshot);
		if (result.bytes_total) {
			const pct = Math.round(100 * (result.bytes_done || 0) / result.bytes_total);
			extras.push(pct + '%');
		}
		if (result.rows_done !== undefined && result.rows_done !== null)
			extras.push(result.rows_done.toLocaleString() + ' ' + labels.rows);
		if (result.contacts_created !== undefined && result.contacts_created !== null)
			extras.push(result.contacts_created.toLocaleString() + ' contacts');
		if (result.overwrite) extras.push('overwrite');
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
			if (state.state !== 'busy') stopPolling();
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
		// optimistic busy state so the operator sees instant feedback
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
			// the worker will append log lines; the next poll picks them up
		} catch (e) {
			stopPolling();
			hide(bar);
			status.textContent = labels.failedStart;
			show(status);
			button.disabled = false;
			button.textContent = startButtonLabel();
			show(button);
		}
	}

	button.addEventListener('click', start);

	async function init() {
		if (!progressUrl) {
			// nothing to poll — fall back to the static idle UI
			renderIdle(null);
			return;
		}
		try {
			const response = await fetch(progressUrl, {cache: 'no-store'});
			if (!response.ok) throw new Error(response.statusText);
			const state = await response.json();
			render(state);
			if (state.state === 'busy') startPolling();
		} catch (e) {
			renderIdle(null);
		}
	}
	init();
})();
