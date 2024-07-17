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
 * @copyright Copyright © 2013-2014, 2016-2017, 2019-2021, 2023-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * import rating data
 *
 * @param string $rating
 * @return array $data
 */
function mod_ratings_make_ratings_import($params) {
	wrap_include('sync', 'ratings');
	$dl = mf_ratings_file($params[0]);
	if (!$dl) return false;

	$path = strtolower($params[0]);
	$filename = __DIR__.'/ratings-prepare-'.$path.'.inc.php';
	require_once $filename;
	$function = 'mod_ratings_make_ratings_prepare_'.$path;

	$data = $function([$dl['destination_folder']]);
	if (empty($data)) {
		rmdir($dl['destination_folder']);
		$data['errors'] = mod_ratings_make_ratings_db($path);
		if (!$data['errors']) {
			wrap_setting_write('ratings_status['.$params[0].']', $dl['date']);
			$data['import_successful'] = true;
		}
	}
	$page['text'] = json_encode($data);
	$page['content_type'] = 'json';
	return $page;
}

/**
 * import contents of .sql file
 *
 * @param string $rating
 * @return array
 */
function mod_ratings_make_ratings_db($rating) {
	$filename = mf_ratings_sqlfile($rating);
	$file = fopen($filename, 'r');
	if (!$file) return;
	$errors = [];
	while (($line = fgets($file)) !== false) {
		if (wrap_db_query($line, E_USER_WARNING)) continue;
//		if (mysql_errno() === 1065) continue;
		$errors[]['msg'] = mysqli_error(wrap_db_connection()).' '.$line;
	}
	return $errors;
}
