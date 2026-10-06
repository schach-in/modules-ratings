<?php

/**
 * ratings module
 * download rating data from other server
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Jacob Roggon
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © ... Jacob Roggon
 * @copyright Copyright © 2013-2014, 2016-2017, 2019-2020, 2022-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 *
 * Variables
 * translate_pot = admin
 */


/**
 * download rating data from other server
 * download as a ZIP file
 *
 * @param string $rating
 * @return array $data
 */
function mod_ratings_make_ratings_download($params) {
	if (count($params) !== 1) return false;
	
	$data = [];
	$data['rating'] = $params[0];
	$data['path'] = mf_ratings_folder($data['rating']);
	$data['url'] = wrap_setting('ratings_download['.$data['rating'].']');
	if (!$data['url']) return false;

	// fetches the rating file from the server
	// might take a little longer, but if possible, If-Modified-Since and 304s
	// are taken into account
	wrap_include('syndication', 'zzwrap');
	// a transfer might break off (e. g. HTTP/2 stream closed early), try twice
	$attempts = 2;
	while ($attempts--) {
		$rating_data = wrap_syndication($data['url'], [
			'type' => 'file',
			'cache_age_syndication' => 0,
		]);
		if ($rating_data) break;
	}
	if (!$rating_data)
		wrap_error(['Unable to download rating file for %s.', ['values' => [$params[0]]]], E_USER_ERROR);

	// save metadata
	$meta = $rating_data['_'];
	if (empty($meta['filename']))
		wrap_error([
			'No meta data given after download rating file for %s',
			['values' => [$params[0]], 'data' => $meta]
		], E_USER_ERROR);

	// archive into ratings_dir/[rating]/[year]
	$archive_date = mf_ratings_download_archive_date($meta, $data['rating']);
	$data['date'] = $archive_date['date'];
	$data['date_source'] = $archive_date['source'];
	$year = substr($data['date'], 0, 4);
	$destination_folder = sprintf('%s/%d', $data['path'], $year);
	if (!file_exists($destination_folder)) mkdir($destination_folder, 0775, true);

	$filename = $meta['filename'];
	if (strpos($filename, '/') !== false)
		$filename = substr($filename, strrpos($filename, '/') + 1);
	if (strpos($filename, '%2F') !== false)
		$filename = substr($filename, strrpos($filename, '%2F') + 3);
	$filename = sprintf('%s-%s', $data['date'], $filename);

	// 3. archive file
	$destination = realpath($destination_folder);
	if (!$destination) {
		wrap_error([
			'File path for downloaded rating file for %s is wrong: %s/%s.',
			['values' => [$data['rating'], $destination_folder, $filename]]
		], E_USER_ERROR);
	}
	$data['filename'] = $destination.'/'.$filename;
	if (!file_exists($data['filename'])) {
		copy($meta['filename'], $data['filename']);
	}
	$page['text'] = json_encode($data);
	$page['extra']['job'] = 'download';
	$page['content_type'] = 'json';
	return $page;
}

/**
 * snapshot date used to archive a downloaded rating zip
 *
 * @param array $meta syndication meta (Last-Modified, filename)
 * @param string $rating DWZ, Elo, …
 * @return array ['date' => 'YYYY-MM-DD', 'source' => string]
 */
function mf_ratings_download_archive_date($meta, $rating) {
	$last_modified = $meta['Last-Modified'] ?? '';
	if ($last_modified) {
		$timestamp = strtotime($last_modified);
		if ($timestamp)
			return [
				'date' => date('Y-m-d', $timestamp),
				'source' => 'Last-Modified'
			];
	}

	// Workaround: the liga.nu DWZ zip has no Last-Modified header.
	// Fall back to "DWZ-Datenbank vom DD.MM.YYYY" in README.txt inside the zip.
	if ($rating === 'DWZ' AND !empty($meta['filename'])) {
		$from_readme = mf_ratings_download_date_from_readme($meta['filename']);
		if ($from_readme)
			return [
				'date' => $from_readme,
				'source' => 'readme.txt (workaround, no Last-Modified)'
			];
	}

	wrap_error([
		'No usable Last-Modified date for rating download %s',
		['values' => [$rating], 'data' => $meta]
	], E_USER_ERROR);
}

/**
 * Workaround: parse the snapshot date from README.txt inside a DWZ zip
 * when the HTTP response has no Last-Modified header.
 *
 * @param string $archive path to the downloaded zip
 * @return string|null YYYY-MM-DD
 */
function mf_ratings_download_date_from_readme($archive) {
	if (!class_exists('ZipArchive')) return null;
	$zip = new ZipArchive;
	if ($zip->open($archive) !== true) return null;
	$readme = '';
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = $zip->getNameIndex($i);
		if (!$name) continue;
		$basename = basename($name);
		if (!preg_match('/^readme\.txt$/i', $basename)) continue;
		$readme = $zip->getFromIndex($i);
		break;
	}
	$zip->close();
	if ($readme === '' OR $readme === false) return null;
	if (!preg_match('/DWZ-Datenbank vom (\d{2})\.(\d{2})\.(\d{4})/', $readme, $match))
		return null;
	return sprintf('%s-%s-%s', $match[3], $match[2], $match[1]);
}
