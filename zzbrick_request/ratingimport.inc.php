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
 * @copyright Copyright © 2012 Jacob Roggon
 * @copyright Copyright © 2013-2014, 2016-2017, 2019, 2021-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_ratings_ratingimport() {
	wrap_setting('syndication_timeout_ms', false); // big files ahead
	ini_set('max_execution_time', 120);

	// get rating data
	$downloads = wrap_setting('ratings_download');
	if (!$downloads) return false; // @todo show error
	$index = 0;
	$data = [];
	foreach (array_keys($downloads) as $rating) {
		$data[$index] = mod_ratings_ratingimport_download($rating);
		$data[$index]['stand'] = wrap_setting('ratings_status['.$rating.']') ?? '';
		if ($data[$index]['stand'] === $data[$index]['date']) {
			$data[$index]['aktueller_stand'] = true;
		} elseif ($data[$index]['stand'] > $data[$index]['date']) {
			$data[$index]['zukuenftiger_stand'] = true;
		} else {
			$data[$index]['veraltete_daten'] = true;
			$data[$index]['formular'] = true;
			if (!empty($_POST['submit_'.$data[$index]['path']])) {
				list($status, $headers, $content)
					= wrap_get_protected_url('/_jobs/ratings/import/'.$rating, [], 'POST', [], wrap_setting('default_robot_username'));
				if ($status === 200) {
					$return = json_decode($content, true);
					if (!empty($return['import_successful'])) {
						// Daten für Vereinsdatenbank aktualisieren
						wrap_trigger_protected_url('https://schach.in/_jobs/clubstats');
						$data[$index]['formular'] = false;
						$data[$index]['erfolgreich'] = true;
						$data[$index]['stand'] = $data[$index]['date'];
					} else {
						$data[$index] = array_merge($data[$index], $return);
					}
				}
			}
		}
		$index++;
	}
	$page['text'] = wrap_template('ratingimport', $data);
	return $page;
}

/**
 * download ratinf file
 *
 * @param string $rating
 * @return array
 */
function mod_ratings_ratingimport_download($rating) {
	list($status, $headers, $content)
		= wrap_get_protected_url('/_jobs/ratings/download/'.$rating, [], 'POST', [], wrap_setting('default_robot_username'));
	if ($status === 200) return json_decode($content, true);

	$line = [
		'rating' => $rating,
		'path' => strtolower($rating),
		'date' => ''
	];
	if ($status === 403) {
		$line['please_wait'] = true;
		return $line;
	}
	$line['not_found'] = true;
	return $line;
}
