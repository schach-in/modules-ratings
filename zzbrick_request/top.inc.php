<?php

/**
 * ratings module
 * output of a top list
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_ratings_top($params, $settings) {
	if (empty($params))
		$params[0] = 10; // show top 10
	
	$limit = array_shift($params);
	$conditions = [];
	$conditions[] = 'status = "A"';
	if (!empty($settings['female'])) $conditions[] = 'Geschlecht = "W"';
	if ($params) {
		if (str_starts_with($params[0], 'u')) {
			$conditions[] = sprintf(
				'Geburtsjahr >= YEAR(CURDATE()) - %d', substr($params[0], 1)
			);
		} else {
			// @todo add other filters
			return false;
		}
	}
	
	$data = mf_ratings_ratinglist($conditions, $limit);
	$page['text'] = wrap_template('ratinglist', $data);	
	return $page;
}
