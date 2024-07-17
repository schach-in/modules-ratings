<?php

/**
 * ratings module
 * import functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * get latest download folder and date with files for update
 *
 * @param string $rating
 * @return array
 */
function mf_ratings_file($rating) {
	// get latest download file
	$dl = mf_ratings_latest($rating);
	if (!$dl) return [];

	// check if update is necessary
	$update = mf_ratings_update($rating, $dl['date']);
	if (!$update) return [];

	// unzip data
	$dl['destination_folder'] = mf_ratings_unzip($rating, $dl['filename']);
	return $dl;
}

/**
 * unpack archive
 *
 * @param string $rating
 * @param string $archive name of destination folder
 * @return string string $dest_folder = successful, false: error
 */
function mf_ratings_unzip($rating, $archive) {
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
		return '';
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
function mf_ratings_latest($rating) {
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
function mf_ratings_update($rating, $date) {
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
