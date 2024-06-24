<?php

/**
 * ratings module
 * debug queries to find errors
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022, 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_ratings_datachecks($params) {
	$queries = wrap_sql_query('ratings_debug_**');
	
	$data = [];
	foreach ($queries as $key => $line) {
		$query = trim($line[0]);
		if (str_starts_with($query, '/*')) {
			$comment = trim(substr($query, 2, strpos($query, '*/') -2));
			$query = trim(substr($query, strpos($query, '*/') + 2));
		} else {
			$comment = '';
		}
		if (!strstr($query, 'HAVING')) {
			$sql = wrap_edit_sql($query, 'SELECT', 'COUNT(*)', 'replace');
			$count = wrap_db_fetch($sql, '', 'single value');
		} else {
			$count = '?';
		}
		$data[] = [
			'key' => ($count <= 1000 OR $count === '?') ? $key : '',
			'query' => $query,
			'comment' => $comment,
			'count' => $count
		];
	}
	
	$page['text'] = wrap_template('datachecks', $data);
	return $page;
}
