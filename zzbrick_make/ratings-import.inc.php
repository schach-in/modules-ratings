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
	// get latest download file
	$dl = mod_ratings_make_ratings_latest($params[0]);
	if (!$dl) return false;

	// check if update is necessary
	$update = mod_ratings_make_ratings_update($params[0], $dl['date']);
	if (!$update) return false;

	$path = strtolower($params[0]);
	$filename = __DIR__.'/ratings-prepare-'.$path.'.inc.php';
	require_once $filename;
	$function = 'mod_ratings_make_ratings_prepare_'.$path;

	$dest_folder = mod_ratings_make_ratings_unzip($path, $dl['filename']);
	$data = $function([$dest_folder]);
	if (empty($data)) {
		rmdir($dest_folder);
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
 * unpack archive
 *
 * @param string $archive filename of archive
 * @param string $dest_folder name of destination folder
 * @return mixed string $dest_folder = successful, false: error
 */
function mod_ratings_make_ratings_unzip($rating, $archive) {
	$path = strtolower($rating);
	$tmp_dir = wrap_setting('tmp_dir').'/'.$path;
	if (!file_exists($tmp_dir)) mkdir($tmp_dir);
	$dest_folder = tempnam($tmp_dir, $path);
	unlink($dest_folder);
	mkdir($dest_folder);

	if (!class_exists('ZipArchive')) {
		wrap_error(sprintf('php with ZipArchive class needed to extract files. (%s)', __FUNCTION__), E_USER_ERROR);
	}
	$zip = new ZipArchive;
	$res = $zip->open($archive);
	if ($res !== true) {
		wrap_error(sprintf(wrap_text('Error while unpacking file %s, Code %s'), $archive, $res), E_USER_ERROR);
		return false;
	}
	$zip->extractTo($dest_folder);
	$zip->close();
	return $dest_folder;
}

/**
 * get latest non-corrupt file for rating
 *
 * @param string $rating
 * @return array
 */
function mod_ratings_make_ratings_latest($rating) {
	$corrupt_dates = wrap_setting('ratings_corrupt['.$rating.']');
	$ratings_path = mf_ratings_folder($rating);

	// check all archive files
	$files = scandir($ratings_path, SCANDIR_SORT_DESCENDING);
	foreach ($files as $folder) {
		if (str_starts_with($folder, '.')) continue;
		$folder_path = $ratings_path.'/'.$folder;
		if (!is_dir($folder_path)) continue;
		$archives = scandir($folder_path, SCANDIR_SORT_DESCENDING);
		foreach ($archives as $archive) {
			if (str_starts_with($archive, '.')) continue;
			$archive_path = $folder_path.'/'.$archive;
			if (is_dir($archive_path)) continue;
			$date = substr($archive, 0, 10);
			if (in_array($date, $corrupt_dates)) continue;
			return [
				'filename' => $archive_path,
				'date' => $date
			];
		}
	}
	return [];
}

/**
 * check if update is necessary
 *
 * @param string $rating
 * @param string $date
 * @return bool
 */
function mod_ratings_make_ratings_update($rating, $date) {
	$latest_update = wrap_setting('ratings_status['.$rating.']');
	$corrupt_dates = wrap_setting('ratings_corrupt['.$rating.']');

	// no data so far
	if (!$latest_update) return true;
	// is current data corrupt?
	if (in_array($latest_update, $corrupt_dates)) return true;
	// newer version available
	if ($latest_update < $date) return true;
	// no update necessary
	return false;
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
