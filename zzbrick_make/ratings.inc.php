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
 * @copyright Copyright © 2013-2014, 2016-2017, 2019-2023 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * import rating data
 *
 * @param string $rating
 * @return array $data
 */
function mod_ratings_make_ratings($params) {
	require_once wrap_setting('core').'/syndication.inc.php';
	$downloads = wrap_setting('ratings_download');

	// @todo show webpage with possible downloads if there are no parameters,
	// allow to trigger downloads

	// @todo show webpage form that allows to trigger download for this rating file
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;

	if (count($params) !== 2) return false;
	if (!in_array($params[0], ['download', 'import'])) return false;
	if (empty($downloads[$params[1]])) return false;

	// big files, no timeout please
	wrap_setting('syndication_timeout_ms', false);

	$lock_realm = strtolower(implode('-', $params));
	$wait_seconds = 300;
	$lock = wrap_lock($lock_realm, 'wait', $wait_seconds);
	if ($lock) {
		$page['status'] = 202;
		$page['text'] = sprintf(wrap_text(
			'Please wait. Rating sync is only allowed to run once every %s.'
		), wrap_duration($wait_seconds));
		return $page;
	}
	
	$filename = __DIR__.'/ratings-'.$params[0].'.inc.php';
	require_once $filename;
	$function = 'mod_ratings_make_ratings_'.strtolower($params[0]);
	return $function([$params[1]]);
}
