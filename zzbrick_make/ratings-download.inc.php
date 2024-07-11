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
 * @copyright Copyright © 2013-2014, 2016-2017, 2019-2020, 2022-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
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
	require_once wrap_setting('core').'/syndication.inc.php';
	$rating_data = wrap_syndication_get($data['url'], 'file');
	if (!$rating_data)
		wrap_error(sprintf(wrap_text('Unable to download rating file for %s.'), $params[0]), E_USER_ERROR);

	// save metadata
	$meta = $rating_data['_'];
	if (empty($meta['filename']))
		wrap_error(sprintf(wrap_text('No meta data given after download rating file for %s (meta: %s).'), $params[0], json_encode($meta)), E_USER_ERROR);

	// move current rating file into /files/[path] folder unless already done
	// 1. create folder
	$year = date('Y', strtotime($meta['Last-Modified']));
	$destination_folder = sprintf('%s/%d', $data['path'], $year);
	if (!file_exists($destination_folder)) mkdir($destination_folder);

	// 2. get filename
	$data['date'] = date('Y-m-d', strtotime($meta['Last-Modified']));
	$filename = $meta['filename'];
	if (strpos($filename, '/') !== false)
		$filename = substr($filename, strrpos($filename, '/') + 1);
	if (strpos($filename, '%2F') !== false)
		$filename = substr($filename, strrpos($filename, '%2F') + 3);
	$filename = sprintf('%s-%s', $data['date'], $filename);

	// 3. archive file
	$destination = realpath($destination_folder);
	if (!$destination) {
		wrap_error(sprintf(
			wrap_text('File path for downloaded rating file for %s is wrong: %s/%s.'), $data['rating'], $destination_folder, $filename
		), E_USER_ERROR);
	}
	$data['filename'] = $destination.'/'.$filename;
	if (!file_exists($data['filename'])) {
		copy($meta['filename'], $data['filename']);
	}
	$page['text'] = json_encode($data);
	$page['content_type'] = 'json';
	return $page;
}
