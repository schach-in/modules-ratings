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
	wrap_include('sync', 'ratings');
	$dl = mf_ratings_file($params[0]);
	if (!$dl) wrap_quit(404, wrap_text('No rating sync required for %s.', ['values' => [$params[0]]]));

	// @todo
	return false;
}
