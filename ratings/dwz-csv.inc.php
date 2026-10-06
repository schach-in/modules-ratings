<?php

/**
 * ratings module
 * read DWZ .csv files (DSB 2014 export, liga.nu dwzliste export)
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * column keys from the header row of a DWZ .csv file
 *
 * lowercase, '-' and ' ' become '_': FIDE-ID → fide_id,
 * Letzte Auswertung → letzte_auswertung, ZPS-Nummer → zps_nummer
 *
 * @param array $fields
 * @return array key => column index
 */
function mf_ratings_dwz_csv_header($fields) {
	$header = [];
	foreach ($fields as $index => $name) {
		$name = trim((string)$name);
		if ($name !== '')
			$name = iconv('ISO-8859-1', 'UTF-8//TRANSLIT', $name);
		$key = strtolower(str_replace(['-', ' '], '_', $name));
		$header[$key] = $index;
	}
	return $header;
}

/**
 * one data row of a DWZ .csv file as UTF-8 values per column key
 *
 * @param array $header from mf_ratings_dwz_csv_header()
 * @param array $fields
 * @param string $encoding
 * @return array
 */
function mf_ratings_dwz_csv_row($header, $fields, $encoding = 'ISO-8859-1') {
	$row = [];
	foreach ($header as $key => $index) {
		$value = trim((string)($fields[$index] ?? ''));
		if ($value !== '')
			$value = iconv($encoding, 'UTF-8//TRANSLIT', $value);
		$row[$key] = $value;
	}
	return $row;
}

/**
 * dwz_spieler record from a row of the liga.nu spieler.csv
 *
 * Spielberechtigung is always empty in this export (see README.txt), it is
 * kept for the structure. FIDE-Frauentitel, FIDE-Elozahl-Schnellschach and
 * FIDE-Elozahl-Blitz exist since the export of 2026-09-30.
 *
 * @param array $row from mf_ratings_dwz_csv_row()
 * @return array field name => value ('' for no value), [] if row is skipped
 */
function mf_ratings_dwzliste_spieler($row) {
	$mgl_nr = $row['mitgliedsnummer'] ?? '';
	if (!ctype_digit($mgl_nr)) return [];
	// people in the board of a club who are not members
	if (!(int)$mgl_nr) return [];
	$zps = $row['zps'] ?? '';
	if ($zps === '') return [];
	$name = mf_ratings_dwzliste_name($row);
	if (!$name) return [];

	return [
		'NU_ID' => $row['id'] ?? '',
		'ZPS' => $zps,
		'Mgl_Nr' => (string)(int)$mgl_nr,
		'Status' => $row['status'] ?? '',
		'Spielername' => $name,
		'Geschlecht' => $row['geschlecht'] ?? '',
		'Spielberechtigung' => $row['spielberechtigung'] ?? '',
		'Geburtsjahr' => $row['geburtsjahr'] ?? '',
		'Letzte_Auswertung' => $row['letzte_auswertung'] ?? '',
		'DWZ' => $row['dwz'] ?? '',
		'DWZ_Index' => $row['index'] ?? '',
		'FIDE_Elo' => $row['fide_elozahl'] ?? '',
		'FIDE_Titel' => $row['fide_titel'] ?? '',
		'FIDE_ID' => $row['fide_id'] ?? '',
		'FIDE_Land' => $row['fide_land'] ?? '',
		'FIDE_Frauentitel' => $row['fide_frauentitel'] ?? '',
		'FIDE_Elo_Schnellschach' => $row['fide_elozahl_schnellschach'] ?? '',
		'FIDE_Elo_Blitz' => $row['fide_elozahl_blitz'] ?? ''
	];
}

/**
 * player name in the form Nachname,Vorname
 *
 * a comma in Vorname (e. g. "Clemens, Philip") would be read as a third
 * name part (title), so it is replaced by a space
 *
 * @param array $row
 * @return string
 */
function mf_ratings_dwzliste_name($row) {
	$last_name = $row['nachname'] ?? '';
	$first_name = preg_replace('/\s*,\s*/', ' ', $row['vorname'] ?? '');
	if ($last_name === '' AND $first_name === '') return '';
	return $last_name.','.$first_name;
}

/**
 * dwz_vereine record from a row of the liga.nu vereine.csv
 *
 * @param array $row from mf_ratings_dwz_csv_row()
 * @return array field name => value, [] if row is skipped
 */
function mf_ratings_dwzliste_vereine($row) {
	$zps = $row['zps_nummer'] ?? '';
	if ($zps === '') return [];
	$name = $row['vereinsname'] ?? '';
	if ($name === '') return [];

	return [
		'ZPS' => $zps,
		'LV' => $row['landesverband'] ?? '',
		'Verband' => $row['uebergeordneterverband'] ?? '',
		'Vereinname' => $name
	];
}

/**
 * dwz_verbaende record from a row of the liga.nu verbaende.csv
 *
 * @param array $row from mf_ratings_dwz_csv_row()
 * @return array field name => value, [] if row is skipped
 */
function mf_ratings_dwzliste_verbaende($row) {
	$code = $row['verbandnummer'] ?? '';
	if ($code === '') return [];
	$name = $row['verbandname'] ?? '';
	if ($name === '') return [];

	return [
		'Verband' => $code,
		'LV' => $row['landesverband'] ?? '',
		'Uebergeordnet' => $row['uebergeordneterverband'] ?? '',
		'Verbandname' => $name
	];
}
