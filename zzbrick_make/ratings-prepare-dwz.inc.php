<?php

/**
 * ratings module
 * prepare DWZ rating data
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Jacob Roggon
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © ... Jacob Roggon
 * @copyright Copyright © 2013-2014, 2016-2017, 2019-2024, 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 *
 * Variables
 * translate_pot = admin
 */


/**
 * import DWZ rating data
 * Liest DWZ-Daten aus Dateien in dwz_*-Tabellen ein
 *
 * Reads the liga.nu dwzliste .csv export (Windows-1252, from 2026-07-22).
 *
 * @param array $params
 *		[0]: string folder name
 * @return array $data
 */
function mod_ratings_make_ratings_prepare_dwz($params) {
	wrap_include('dwz-csv', 'ratings');

	$files = [
//		1 => [
//			'filename' => 'verband.sql',
//			'table' => 'dwz_verbaende'
//		],
		2 => [
			'filename' => 'verbaende.csv',
			'table' => 'dwz_verbaende'
		],
		3 => [
			'filename' => 'vereine.csv',
			'table' => 'dwz_vereine'
		],
		4 => [
			'filename' => 'spieler.csv',
			'table' => 'dwz_spieler'
		]
	];
	$data['errors'] = [];
	mf_ratings_log('dwz');

	foreach ($files AS $file) {
		$filename = $params[0].'/'.$file['filename'];
		if (!file_exists($filename)) {
			$data['errors'][]['msg'] = wrap_text('File %s not found for rating import.', ['values' => $file['filename']]);
			continue;
		}
		if (!filesize($filename)) {
			$data['errors'][]['msg'] = wrap_text('File for rating import %s is empty.', ['values' => $file['filename']]);
			continue;
		}
		mf_ratings_prepare_dwz_csv($filename, $file['table']);
		unlink($filename);
	}

	// Keine Spielberechtigung ist NULL statt bisher -
	$sql = 'UPDATE dwz_spieler SET Spielberechtigung = "-" WHERE ISNULL(Spielberechtigung)';
	mf_ratings_log('dwz', $sql);

	foreach (scandir($params[0]) as $entry) {
		if (!preg_match('/^readme\.txt$/i', $entry)) continue;
		unlink($params[0].'/'.$entry);
	}

	if (empty($data['errors'])) unset($data['errors']);
	return $data;
}

/**
 * convert a liga.nu dwzliste .csv into REPLACE INTO statements
 *
 * @param string $filename
 * @param string $table
 * @return void
 */
function mf_ratings_prepare_dwz_csv($filename, $table) {
	$handle = fopen($filename, 'r');
	if (!$handle) return;
	$header_line = fgetcsv($handle, 0, ',', '"', '\\');
	if (!$header_line) {
		fclose($handle);
		return;
	}
	$header = mf_ratings_dwz_csv_header($header_line);

	$sql = 'TRUNCATE %s';
	$sql = sprintf($sql, $table);
	mf_ratings_log('dwz', $sql);

	while (($fields = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
		if ($fields === [null]) continue;
		$row = mf_ratings_dwz_csv_row($header, $fields, 'Windows-1252');
		$record = mf_ratings_prepare_dwz_record($table, $row);
		if (!$record) continue;
		mf_ratings_log('dwz', mf_ratings_prepare_dwz_replace($table, $record));
	}
	fclose($handle);
}

/**
 * record for a table from a .csv row
 *
 * @param string $table
 * @param array $row
 * @return array field name => value, [] if the row should be skipped
 */
function mf_ratings_prepare_dwz_record($table, $row) {
	switch ($table) {
	case 'dwz_spieler':
		$record = mf_ratings_dwzliste_spieler($row);
		if (!$record) return [];
		if (!str_starts_with($record['NU_ID'], 'NU')) return [];
		return $record;
	case 'dwz_vereine':
		return mf_ratings_dwzliste_vereine($row);
	case 'dwz_verbaende':
		return mf_ratings_dwzliste_verbaende($row);
	}
	return [];
}

/**
 * REPLACE INTO statement for a record
 *
 * dwz_spieler: empty values are NULL; dwz_vereine, dwz_verbaende: columns
 * are NOT NULL DEFAULT ''
 *
 * @param string $table
 * @param array $record
 * @return string
 */
function mf_ratings_prepare_dwz_replace($table, $record) {
	$values = [];
	foreach ($record as $value) {
		if ($value === '' AND $table === 'dwz_spieler')
			$values[] = 'NULL';
		else
			$values[] = sprintf('"%s"', wrap_db_escape($value));
	}
	return sprintf('REPLACE INTO `%s` (`%s`) VALUES (%s)'
		, $table
		, implode('`, `', array_keys($record))
		, implode(', ', $values)
	);
}
