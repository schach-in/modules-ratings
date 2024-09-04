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
	wrap_include('file', 'zzwrap');
	$rating = strtolower($params[0]);
	$log = sprintf('ratings/%s', $rating);
	$data = wrap_file_log($log);
	foreach ($data as $index => $line) {
		if ($line['result'])
			$data[$index] += json_decode($line['result'], true);
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
		$action = 'import';
		break;
	case 'import':
		$import = json_decode($last['result'], true);
		if ($import['next_url']) $action = 'import';
		else $action = 'finish';
		break;
	case 'fail':
		$action = false;
		wrap_quit(503);
		break;
	}
	$data['rating'] = $params[0];
	
	switch ($action) {
	case 'download':
		$url = wrap_path('ratings_sync', sprintf('download/%s', $data['rating']));
		if (!$url) wrap_error(wrap_text('No download URL for sync of rating data.'), E_USER_ERROR);
		$result = wrap_trigger_protected_url($url, [], 'POST', [], wrap_setting('robot_username'));
		if ($result[0] == 200)
			wrap_file_log($log, 'write', [time(), 'download', $result[2]]);
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
		break;

	case 'import':
		$url = $import['next_url'] ?? wrap_path('zzform_sync', 'fide-players');
		$result = wrap_trigger_protected_url($url, [], 'POST', [], wrap_setting('robot_username'));
		if ($result[0] == 200) {
			$import = json_decode($result[2], true);
			$return = [
				'updated' => $import['updated'] ?? NULL,
				'inserted' => $import['inserted'] ?? NULL,
				'nothing' => $import['nothing'] ?? NULL,
				'next_url' => $import['next_url'] ?? NULL
			];
			wrap_file_log($log, 'write', [time(), 'import', json_encode($return)]);
		} else {
			wrap_file_log($log, 'write', [time(), 'fail', $result[0]]);
		}
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
	
	if ($action !== 'finish')
		wrap_trigger_protected_url(wrap_setting('request_uri'), [], 'POST', [], wrap_setting('robot_username'));

	$page['title'] = wrap_text('Synchronize %s rating data', ['values' => [$data['rating']]]);
	// @todo think of translating breadcrumb, too
	$page['breadcrumbs'][]['title'] = wrap_text('Sync %s', ['values' => [$data['rating']]]);
	$page['text'] = wrap_template('ratings-sync', $data);
	return $page;
}
