<?php

/**
 * ratings module
 * synchronize rating data
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * synchronize rating data
 *
 * @param string $rating
 * @return array $data
 */
function mod_ratings_make_ratings_sync($params) {
	if (!array_key_exists('sequential', $_POST)) {
		// start as a background job
		mod_ratings_make_ratings_sync_self();
		wrap_job_debug('JOB STARTING', $_POST);
		$page['text'] = 'Starting background job';
		return $page;
	}
	
	wrap_include('file', 'zzwrap');
	$rating = strtolower($params[0]);
	$log = sprintf('ratings/%s', $rating);
	$data = wrap_file_log($log);
	foreach ($data as $index => $line) {
		if ($line['result']) {
			$result = json_decode($line['result'], true);
			if (is_array($result))
				$data[$index] += $result;
			else
				wrap_error('Unable to read log line, key `result`: '.json_encode($line));
		}
		$data[$index]['time'] = date('Y-m-d H:i:s', $line['timestamp']);
	}
	if (!$data) $data[] = ['action' => 'finish'];

	$last = end($data);
	switch ($last['action']) {
	case 'finish':
		$action = 'download';
		break;
	case 'download':
		$action = 'unpack';
		break;
	case 'unpack':
		$action = 'sync';
		break;
	case 'sync':
		$import = json_decode($last['result'], true);
		if ($import['next_url']) $action = 'sync';
		else $action = 'finish';
		break;
	case 'fail':
		$action = false;
		wrap_quit(503, 'Sync job failed. Read log for details.');
		break;
	}
	wrap_job_debug(sprintf('JOB LAST ACTION %s, NEXT ACTION %s', $last['action'], $action);
	$data['rating'] = $params[0];
	
	switch ($action) {
	case 'download':
		$url = wrap_path('ratings_sync', sprintf('download/%s', $data['rating']));
		if (!$url) wrap_error(wrap_text('No download URL for sync of rating data.'), E_USER_ERROR);
		mod_ratings_make_ratings_sync_next($url, $log);
		break;

	case 'unpack':
		wrap_include('sync', 'ratings');
		$return = mf_ratings_file($data['rating']);
		if ($return) {
			$filename =  wrap_setting('ratings_sync_file['.$data['rating'].']'); 
			$source = sprintf('%s/%s', $return['destination_folder'], $filename);
			$dest = sprintf('%s/%s/%s', wrap_setting('tmp_dir'), $rating, $filename);
			rename($source, $dest);
			rmdir($return['destination_folder']);
			wrap_file_log($log, 'write', [time(), 'unpack', json_encode($return)]);
		} else {
			wrap_file_log($log, 'write', [time(), 'finish', json_encode(['msg' => 'Nothing to update'])]);
		}
		mod_ratings_make_ratings_sync_self();
		break;

	case 'sync':
		$url = $import['next_url'] ?? wrap_path('zzform_sync', 'fide-players');
		mod_ratings_make_ratings_sync_next($url, $log);
		break;

	case 'finish':
		// get data
		$date = '';
		foreach ($data as $index => $line) {
			if (!is_numeric($index)) continue;
			if (!in_array($line['action'], ['download', 'unpack'])) continue;
			$date = $line['date'];
		}
		wrap_setting_write('ratings_status['.$data['rating'].']', $date);
		break;
	}
	
	$page['title'] = wrap_text('Synchronize %s rating data', ['values' => [$data['rating']]]);
	// @todo think of translating breadcrumb, too
	$page['breadcrumbs'][]['title'] = wrap_text('Sync %s', ['values' => [$data['rating']]]);
	$page['text'] = wrap_template('ratings-sync', $data);
	return $page;
}

/**
 * call background job for syncing
 */
function mod_ratings_make_ratings_sync_self() {
	wrap_job(wrap_setting('request_uri'), ['sequential' => 1]);
}

/**
 * call subordinate job for syncing
 *
 * @param string $url
 * @param string $log
 * @return void
 */
function mod_ratings_make_ratings_sync_next($url, $log) {
	$data = [
		'job_logfile_result' => $log,
		'job_url_next' => wrap_setting('request_uri')
	];
	list($status, $headers, $response) = wrap_trigger_protected_url(
		$url, false, true, $data
	);
}
