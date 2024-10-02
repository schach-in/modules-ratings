<?php

/**
 * ratings module
 * import rating data
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Jacob Roggon
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © ... Jacob Roggon
 * @copyright Copyright © 2013-2014, 2016-2017, 2019-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * import rating data
 *
 * @param string $rating
 * @return array $data
 */
function mod_ratings_make_ratings($params) {
	if (count($params) !== 2) return false;
	$data = [
		'action' => $params[0],
		'rating' => $params[1]
	];
	if (!in_array($data['action'], ['download', 'import', 'sync'])) return false;
	if (!wrap_setting('ratings_download['.$data['rating'].']')) return false;

	switch ($data['action']) {
		case 'download': $title = 'Download %s rating data'; break;
		case 'import': $title = 'Import %s rating data'; break;
		case 'sync': $title = 'Synchronize %s rating data'; break;
	}
	$page['title'] = wrap_text($title, ['values' => [$data['rating']]]);
	// @todo think of translating breadcrumb, too
	$page['breadcrumbs'][]['title'] = sprintf('%s %s', ucfirst($data['action']), $data['rating']);
	
	// @todo show webpage with possible downloads if there are no parameters,
	// allow to trigger downloads

	// @todo show webpage form that allows to trigger download for this rating file
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$page['text'] = wrap_template('ratingsync', $data);
		return $page;
	}

	// big files, no timeout please
	wrap_setting('syndication_timeout_ms', false);

	if (!wrap_setting('local_access') AND $data['action'] !== 'sync') {
		wrap_include('syndication', 'zzwrap');
		$lock_realm = strtolower(implode('-', $params));
		$wait_seconds = 300;
		$lock = wrap_lock($lock_realm, 'wait', $wait_seconds);
		if ($lock) {
			$page['status'] = 403;
			$page['text'] = sprintf(wrap_text(
				'Please wait. Rating sync is only allowed to run once every %s.'
			), wrap_duration($wait_seconds));
			return $page;
		}
	}
	
	$filename = __DIR__.'/ratings-'.$data['action'].'.inc.php';
	require_once $filename;
	$function = 'mod_ratings_make_ratings_'.strtolower($data['action']);
	return $function([$data['rating']]);
}
