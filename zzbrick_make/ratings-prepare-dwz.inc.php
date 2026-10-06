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
 * Each .csv is loaded into a temporary table first; then the dwz_* table is
 * updated from it (insert new, update changed, delete missing records), so
 * the table is never empty and `last_update` only changes for changed records.
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
			'table' => 'dwz_verbaende',
			'keys' => ['Verband']
		],
		3 => [
			'filename' => 'vereine.csv',
			'table' => 'dwz_vereine',
			'keys' => ['ZPS']
		],
		4 => [
			'filename' => 'spieler.csv',
			'table' => 'dwz_spieler',
			'keys' => ['ZPS', 'Mgl_Nr']
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
		$import_table = $file['table'].'_import';
		$fields = mf_ratings_prepare_dwz_csv($filename, $file['table'], $import_table);
		unlink($filename);
		if (!$fields) {
			$data['errors'][]['msg'] = wrap_text('File for rating import %s has no usable records.', ['values' => $file['filename']]);
			continue;
		}
		if ($file['table'] === 'dwz_spieler') {
			// Keine Spielberechtigung ist NULL statt bisher -
			$sql = 'UPDATE `%s` SET Spielberechtigung = "-" WHERE ISNULL(Spielberechtigung)';
			mf_ratings_log('dwz', sprintf($sql, $import_table));
		}
		foreach (mf_ratings_prepare_dwz_merge($file['table'], $import_table, $fields, $file['keys']) as $sql)
			mf_ratings_log('dwz', $sql);
	}

	foreach (scandir($params[0]) as $entry) {
		if (!preg_match('/^readme\.txt$/i', $entry)) continue;
		unlink($params[0].'/'.$entry);
	}

	if (empty($data['errors'])) unset($data['errors']);
	return $data;
}

/**
 * load a liga.nu dwzliste .csv into a temporary import table
 *
 * @param string $filename
 * @param string $table
 * @param string $import_table
 * @return array field names of the records, [] if there are no records
 */
function mf_ratings_prepare_dwz_csv($filename, $table, $import_table) {
	$handle = fopen($filename, 'r');
	if (!$handle) return [];
	$header_line = fgetcsv($handle, 0, ',', '"', '\\');
	if (!$header_line) {
		fclose($handle);
		return [];
	}
	$header = mf_ratings_dwz_csv_header($header_line);

	mf_ratings_log('dwz', sprintf('DROP TEMPORARY TABLE IF EXISTS `%s`', $import_table));
	mf_ratings_log('dwz', sprintf('CREATE TEMPORARY TABLE `%s` LIKE `%s`', $import_table, $table));

	$fields = [];
	$records = [];
	while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
		if ($line === [null]) continue;
		$row = mf_ratings_dwz_csv_row($header, $line, 'Windows-1252');
		$record = mf_ratings_prepare_dwz_record($table, $row);
		if (!$record) continue;
		if (!$fields) $fields = array_keys($record);
		$records[] = $record;
		if (count($records) < 1000) continue;
		mf_ratings_log('dwz', mf_ratings_prepare_dwz_insert($import_table, $table, $fields, $records));
		$records = [];
	}
	fclose($handle);
	if ($records)
		mf_ratings_log('dwz', mf_ratings_prepare_dwz_insert($import_table, $table, $fields, $records));
	return $fields;
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
 * REPLACE INTO statement for a batch of records
 *
 * dwz_spieler: empty values are NULL; dwz_vereine, dwz_verbaende: columns
 * are NOT NULL DEFAULT ''
 *
 * @param string $import_table
 * @param string $table
 * @param array $fields
 * @param array $records
 * @return string
 */
function mf_ratings_prepare_dwz_insert($import_table, $table, $fields, $records) {
	$rows = [];
	foreach ($records as $record) {
		$values = [];
		foreach ($fields as $field) {
			$value = $record[$field] ?? '';
			if ($value === '' AND $table === 'dwz_spieler')
				$values[] = 'NULL';
			else
				$values[] = sprintf('"%s"', wrap_db_escape($value));
		}
		$rows[] = sprintf('(%s)', implode(', ', $values));
	}
	return sprintf('REPLACE INTO `%s` (`%s`) VALUES %s'
		, $import_table
		, implode('`, `', $fields)
		, implode(', ', $rows)
	);
}

/**
 * statements to update a dwz_* table from its import table
 *
 * insert new and update changed records (unchanged records keep their
 * `last_update`), delete records missing in the import, drop import table
 *
 * @param string $table
 * @param string $import_table
 * @param array $fields
 * @param array $keys primary key fields
 * @return array
 */
function mf_ratings_prepare_dwz_merge($table, $import_table, $fields, $keys) {
	$updates = [];
	foreach ($fields as $field)
		$updates[] = sprintf('`%s`.`%s` = `%s`.`%s`', $table, $field, $import_table, $field);
	$conditions = [];
	foreach ($keys as $key)
		$conditions[] = sprintf('`%s`.`%s` = `%s`.`%s`', $import_table, $key, $table, $key);

	$sql = [];
	$sql[] = sprintf('INSERT INTO `%s` (`%s`) SELECT `%s` FROM `%s` ON DUPLICATE KEY UPDATE %s'
		, $table
		, implode('`, `', $fields)
		, implode('`, `', $fields)
		, $import_table
		, implode(', ', $updates)
	);
	$sql[] = sprintf('DELETE FROM `%s` WHERE NOT EXISTS (SELECT 1 FROM `%s` WHERE %s)'
		, $table
		, $import_table
		, implode(' AND ', $conditions)
	);
	$sql[] = sprintf('DROP TEMPORARY TABLE `%s`', $import_table);
	return $sql;
}
