<?php

/**
 * ratings module
 * synchronize rating data
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024, 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 *
 * Variables
 * translate_pot = admin
 */


/**
 * synchronize rating data
 *
 * One sequential job. Each request does one step; wrap_job_finish()
 * fires the next URL (download, zzform sync, or this page again).
 *
 * @param string $rating
 * @return array $data
 */
function mod_ratings_make_ratings_sync($params) {
	$rating = strtolower($params[0]);
	$log = sprintf('ratings/%s', $rating);

	if (!array_key_exists('sequential', $_POST)) {
		wrap_job(wrap_setting('request_uri'), [
			'sequential' => 1,
			'job_logfile_result' => $log,
		]);
		wrap_job_debug('JOB STARTING', $_POST);
		$page['text'] = 'Starting background job';
		return $page;
	}
	
	wrap_include('file', 'zzwrap');
	$data = wrap_file_log($log);
	foreach ($data as $index => $line) {
		if (!empty($line['result'])) {
			$result = json_decode($line['result'], true);
			if (is_array($result))
				$data[$index] += $result;
			else
				wrap_error(['Unable to read log line, key `result`.', ['data' => $line]]);
		}
		if (!empty($line['timestamp']))
			$data[$index]['time'] = date('Y-m-d H:i:s', $line['timestamp']);
	}
	if (!$data) $data[] = ['action' => 'finish'];

	$last = end($data);
	switch ($last['action']) {
	case 'download':
		$action = 'unpack';
		break;
	case 'unpack':
		$action = 'sync';
		break;
	case 'sync':
		$import = json_decode($last['result'] ?? '', true);
		$action = !empty($import['next_url']) ? 'sync' : 'finish';
		break;
	case 'fail':
		wrap_unlock('sync-'.$rating);
		wrap_quit(503, wrap_text('Sync job failed. Read log for details.'));
		break;
	default:
		$action = 'download';
		break;
	}
	wrap_job_debug(sprintf('JOB LAST ACTION %s, NEXT ACTION %s', $last['action'], $action));
	$data['rating'] = $params[0];

	$page = [];
	switch ($action) {
	case 'download':
		$url = wrap_path('ratings_sync', sprintf('download/%s', $data['rating']));
		if (!$url) wrap_error(['No download URL for sync of rating data.'], E_USER_ERROR);
		$page['extra']['job_continue'] = $url;
		break;

	case 'unpack':
		wrap_include('sync', 'ratings');
		$return = mf_ratings_file($data['rating']);
		if ($return) {
			$filename = wrap_setting('ratings_sync_file['.$data['rating'].']');
			$source = sprintf('%s/%s', $return['destination_folder'], $filename);
			$dest = sprintf('%s/%s/%s', wrap_setting('tmp_dir'), $rating, $filename);
			rename($source, $dest);
			rmdir($return['destination_folder']);
			wrap_file_log($log, 'write', [time(), 'unpack', json_encode($return)]);
		} else {
			wrap_file_log($log, 'write', [time(), 'finish', json_encode(['msg' => 'Nothing to update'])]);
		}
		$page['extra']['job_continue'] = true;
		break;

	case 'sync':
		$url = $import['next_url'] ?? wrap_path('zzform_sync', 'fide-players');
		$page['extra']['job_continue'] = $url;
		break;

	case 'finish':
		$date = '';
		foreach ($data as $index => $line) {
			if (!is_numeric($index)) continue;
			if (!in_array($line['action'], ['download', 'unpack'])) continue;
			if (!empty($line['date'])) $date = $line['date'];
		}
		if ($date) wrap_setting_write('ratings_status['.$data['rating'].']', $date);
		wrap_unlock('sync-'.$rating);
		break;
	}
	
	$page['title'] = wrap_text('Synchronize %s rating data', ['values' => [$data['rating']]]);
	// @todo think of translating breadcrumb, too
	$page['breadcrumbs'][]['title'] = wrap_text('Sync %s', ['values' => [$data['rating']]]);
	$page['text'] = wrap_template('ratings-sync', $data);
	return $page;
}
