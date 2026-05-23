<?php

/**
 * ratings module
 * memberstats import: progress JSON for the in-page poller
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * return current memberstats import state as JSON
 *
 * Reads _logs/ratings/memberstats.log via wrap_file_log() and condenses
 * it into a small status object the /_behaviour/ratings/memberstats.js
 * poller consumes. Each log entry has shape
 * `<timestamp> <action> <json-encoded payload>`; the last entry decides
 * the current state (always from the last line in the log file). Log
 * entries for the current import run only are returned for the on-page
 * log view (from the latest `queued`/`start` pair).
 * `ratings_memberstats_progress_tail` optionally keeps only the last N of
 * those entries: 0 means all, a positive value truncates the tail.
 *
 * State rules:
 *  - empty log: idle
 *  - last action 'done': idle (last successful import surfaced for context)
 *  - last action 'error': stuck
 *  - any other action with timestamp >= now - stale_after: busy
 *  - any other action older than stale_after: stuck (worker likely died)
 *
 * The JSON payload of each entry is pre-decoded so the JS doesn't need
 * a second JSON.parse step per entry. Top-level fields mirror the last
 * tail entry's payload (snapshot, rows/bytes progress, and contact
 * details when action is `contact`).
 *
 * @return array $page
 */
function mod_ratings_memberstatsprogress() {
	wrap_setting('cache', false);
	wrap_include('file', 'zzwrap');
	wrap_setting('ratings_logfile_memberstats_spaces', true);

	$lines = wrap_file_log('ratings/memberstats');
	foreach ($lines as &$entry) {
		$entry['timestamp'] = (int) $entry['timestamp'];
		$decoded = json_decode($entry['result'], true);
		$entry['result'] = is_array($decoded) ? $decoded : [];
	}
	unset($entry);

	$run = mod_ratings_memberstatsprogress_run($lines);

	$tail_size = (int) wrap_setting('ratings_memberstats_progress_tail');
	$tail = $tail_size > 0 ? array_slice($run, -$tail_size) : $run;

	$data = [
		'state' => 'idle',
		'action' => null,
		'snapshot' => null,
		'rows_done' => null,
		'bytes_done' => null,
		'bytes_total' => null,
		'percent' => null,
		'club_code' => null,
		'contact' => null,
		'contact_id' => null,
		'contacts_created' => null,
		'ts' => null,
		'tail' => $tail
	];

	if ($lines) {
		$last = end($lines);
		$payload = $last['result'];
		$data['action'] = $last['action'];
		$data['ts'] = $last['timestamp'];
		$data['snapshot'] = $payload['snapshot'] ?? null;
		$data['rows_done'] = $payload['rows_done'] ?? null;
		$data['bytes_done'] = $payload['bytes_done'] ?? null;
		$data['bytes_total'] = $payload['bytes_total'] ?? null;
		$data['club_code'] = $payload['club_code'] ?? null;
		$data['contact'] = $payload['contact'] ?? null;
		$data['contact_id'] = $payload['contact_id'] ?? null;
		$data['contacts_created'] = $payload['contacts_created'] ?? null;
		if (!empty($data['bytes_total']))
			$data['percent'] = (int) round(100 * $data['bytes_done'] / $data['bytes_total']);

		$stale_after = (int) wrap_setting('ratings_memberstats_progress_stale_seconds');
		if ($stale_after <= 0) $stale_after = 30;

		if ($last['action'] === 'done')
			$data['state'] = 'idle';
		elseif ($last['action'] === 'error')
			$data['state'] = 'stuck';
		elseif ($last['timestamp'] >= time() - $stale_after)
			$data['state'] = 'busy';
		else
			$data['state'] = 'stuck';
	}

	$page['text'] = json_encode($data);
	$page['content_type'] = 'json';
	return $page;
}

/**
 * log lines belonging to the current import run only
 *
 * One run begins at `queued` (operator click) and `start` (worker). Walk
 * backwards from the end of the log and return everything from that
 * pair — or from `queued` alone when the worker has not logged `start`
 * yet.
 *
 * @param array $lines decoded log rows from wrap_file_log()
 * @return array
 */
function mod_ratings_memberstatsprogress_run($lines) {
	if (!$lines) return [];

	$begin = null;
	for ($index = count($lines) - 1; $index >= 0; $index--) {
		if ($lines[$index]['action'] !== 'start') continue;
		$begin = $index;
		if ($begin > 0 && $lines[$begin - 1]['action'] === 'queued')
			$begin--;
		break;
	}
	if ($begin === null) {
		for ($index = count($lines) - 1; $index >= 0; $index--) {
			if ($lines[$index]['action'] !== 'queued') continue;
			$begin = $index;
			break;
		}
	}
	if ($begin === null) return [];

	return array_slice($lines, $begin);
}
