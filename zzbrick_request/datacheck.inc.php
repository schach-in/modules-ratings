<?php

/**
 * ratings module
 * ratings per club
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022, 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_ratings_datacheck($params) {
	if (count($params) !== 1) return false;
	
	$query = wrap_sql_query($params[0]);
	if (!$query) return false;

	$page['title'] = trim(substr($query, 2, strpos($query, '*/') -2));
	
	$lines = wrap_db_fetch($query, '_dummy_', 'numeric');
	if (!$lines) {
		$data['no_data'] = true;
		$page['text'] = wrap_template('datacheck', $data);
		return $page;
	}
	$data = [];
	$data['body'] = [];
	foreach (array_keys($lines[0]) as $field_title)
		$data['head'][]['title'] = $field_title;
	foreach ($lines as $index => $line) {
		foreach ($line as $field_name => $value) {
			$data['body'][$index]['fields'][] = [
				'value' => $value,
				'path' => mod_ratings_datacheck_path($field_name, $value)
			];
		}
	}
	$data['total_records'] = count($lines);
	
	$page['text'] = wrap_template('datacheck', $data);
	$page['breadcrumbs'][]['title'] = $page['title'];
	return $page;
}

function mod_ratings_datacheck_path($field_name, $value) {
	switch ($field_name) {
	case 'FIDE_ID':
		return wrap_path('ratings_fide_profile', $value);
	case 'PID':
		return wrap_path('ratings_dsb_pid_profile', $value);
	}
	return NULL;
}