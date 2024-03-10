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
 * @copyright Copyright © 2013-2014, 2016-2017, 2019-2023 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * import DWZ rating data
 * Liest DWZ-Daten aus Dateien in dwz_*-Tabellen ein
 *
 * @param array $params
 *		[0]: string folder name
 * @return array $data
 */
function mod_ratings_make_ratings_import_dwz($params) {
	$files = [
//		1 => [
//			'filename' => 'verband.sql',
//			'table' => 'dwz_verbaende'
//		],
		2 => [
			'filename' => 'verbaende.sql',
			'table' => 'dwz_verbaende'
		],
		3 => [
			'filename' => 'vereine.sql',
			'table' => 'dwz_vereine'
		],
		4 => [
			'filename' => 'spieler.sql',
			'table' => 'dwz_spieler'
		]
	];
	$data['errors'] = [];

	foreach ($files AS $file) {
		$filename = $params[0].'/'.$file['filename'];
		if (!file_exists($filename)) {
			$data['errors'][]['msg'] = sprintf(wrap_text('File %s not found for rating import.'), $file['filename']);
			continue;
		}
		if (!filesize($filename)) {
			$data['errors'][]['msg'] = sprintf(wrap_text('File for rating import %s is empty.'), $file['filename']);
			continue;
		}
		if ($handle = fopen($filename, 'r')) {
			$sql = 'TRUNCATE %s';
			$sql = sprintf($sql, $file['table']);
			wrap_db_query($sql);
			while ($line = fgets($handle)) {
				$line = iconv("ISO-8859-1", "UTF-8", $line);
				// fix data because the new system does not work correctly
				// 1. passive players without membership no. are dummy entries for
				// people in the board of a club who are not members
				if (preg_match('/^REPLACE INTO `dwz_spieler` VALUES \("[0-9A-Z]+",null,"P",.+$/', $line)) continue;
				// 2. there are some people without names (sic!)
				if (preg_match('/^REPLACE INTO `dwz_spieler` VALUES \("[0-9A-Z]+","\d+","A","","",.+$/', $line)) continue;
				if (preg_match('/^REPLACE INTO `dwz_spieler` VALUES \("[0-9A-Z]+","\d+","P","","",.+$/', $line)) continue;
				if (wrap_db_query($line, E_USER_WARNING)) continue;
//				if (mysql_errno() === 1065) continue;
				$data['errors'][]['msg'] = mysqli_error(wrap_db_connection()).' '.$line;
			}
		}
		fclose($handle);
		unlink($filename);
	}

	// seltsame Änderung in den Daten, statt M steht jetzt NULL in Geschlecht
	// für männlich
	$sql = 'UPDATE dwz_spieler SET Geschlecht = "M" WHERE ISNULL(Geschlecht)';
	wrap_db_query($sql);

	// Keine Spielberechtigung ist NULL statt bisher -
	$sql = 'UPDATE dwz_spieler SET Spielberechtigung = "-" WHERE ISNULL(Spielberechtigung)';
	wrap_db_query($sql);

	$deletable[] = 'readme.txt';
	foreach ($deletable as $file) {
		unlink($params[0].'/'.$file);
	}
	if (empty($data['errors'])) unset($data['errors']);
	return $data;
}
