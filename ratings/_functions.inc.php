<?php

/**
 * ratings module
 * always available functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * get highest FIDE title
 *
 * @param array $line with 'title', 'title_women'
 * @return array
 */
function mf_ratings_fidetitle($line) {
	static $titles = [];

	$line['fide_title'] = '';
	$line['fide_title_long'] = '';
	if (empty($line['title']) AND empty($line['women_title'])) return $line;

	if (!$titles) {
		$sql = 'SELECT category_id, category_short, category
				, SUBSTRING_INDEX(path, "/", -1) AS path_short
			FROM categories
			WHERE main_category_id = /*_ID categories fide-title _*/
			ORDER BY sequence ASC';
		$titles = wrap_db_fetch($sql, '_dummy_', 'numeric');
	}
	$title_fields = ['title', 'women_title'];

	foreach ($titles as $title) {
		foreach ($title_fields as $field) {
			if (!array_key_exists($field, $line)) continue;
			if ($line[$field] !== $title['category_short']) continue;
			$line['fide_title'] = $title['category_short'];
			$line['fide_title_long'] = $title['category'];
			return $line;
		}
	}
	return $line;
}
