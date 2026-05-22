<?php

/**
 * ratings module
 * import member statistics from DWZ snapshots
 *
 * iterates over every archived DWZ ZIP in ratings_dir/dwz/<year>/, opens
 * spieler.sql and vereine.sql, loads them into temporary tables, and writes
 * one memberstats row per player with the snapshot date taken from the
 * archive filename. Snapshots already present in `memberstats` are skipped;
 * a single date can be re-imported with /force/YYYY-MM-DD.
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2025-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * import member statistics from all DWZ snapshots on disk
 *
 *
 * use ?force=YYYY-MM-DD to force import a snapshot, overwriting the existing import
 *
 * @param array $params
 * @return array $page
 */
function mod_ratings_make_memberstats($params) {
	wrap_setting('cache', false);
	wrap_include('memberstats', 'ratings');
	wrap_include('sync', 'ratings');
	
	$data = [];
	
	// check if an import is forced
	$page['query_strings'][] = 'force';
	$data['force'] = wrap_http_value('force');
	$data['overwrite'] = false;

	// get all archive files
	$data['archives'] = array_reverse(mf_ratings_archives('DWZ'));
	$data['archives_count'] = count($data['archives']);
	if (!$data['archives']) {
		$page['text'] = wrap_template('memberstats', $data);
		return $page;
	}

	// check if archive files are already imported, what to import next
	$sql = 'SELECT DISTINCT snapshot_date FROM memberstats';
	$snapshots = wrap_db_fetch($sql, 'snapshot_date', 'single value');
	$data['import_count'] = count($snapshots);
	$data['missing_count'] = 0;
	foreach ($data['archives'] as $index => $archive) {
		if (in_array($archive['date'], $snapshots)) {
			$data['archives'][$index]['imported'] = true;
			$data['import_last'] = $archive['date'];
			if (empty($data['import_first']))
				$data['import_first'] = $archive['date'];
		} elseif (empty($data['import_next'])) {
			$data['import_next'] = $archive['date'];
			$data['missing_first'] = $archive['date'];
			$data['missing_count']++;
		} else {
			if (empty($data['missing_first']))
				$data['missing_first'] = $archive['date'];
			$data['missing_last'] = $archive['date'];
			$data['missing_count']++;
		}
		if ($data['force'] AND $archive['date'] === $data['force']) {
			$data['import_next'] = $archive['date'];
			$data['overwrite'] = true;
			$data['force'] = NULL;
		}
	}
	// no force value found?
	if ($data['force']) {
		$data['import_force_not_found'] = true;
		$page['status'] = 404;
		$page['text'] = wrap_template('memberstats', $data);
		return $page;
	}

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$page['text'] = wrap_template('memberstats', $data);
		return $page;
	}
	
	if (!array_key_exists('sequential', $_POST)) {
		wrap_job(wrap_setting('request_uri'), ['sequential' => 1]);
		wrap_job_debug('JOB STARTING memberstats', $_POST);
		$page['text'] = 'Starting background job';
		return $page;
	}

	wrap_include('syndication', 'zzwrap');
	// sequential mode lets chained child jobs inherit the parent's lock hash
	// (passed automatically via X-Lock-Hash by wrap_job()); takeover_seconds
	// is the "previous job is dead, claim it" threshold, not a rate-limit
	$takeover_seconds = 1800;
	$lock = wrap_lock('memberstats', 'sequential', $takeover_seconds);
	if ($lock) {
		$page['status'] = 403;
		$page['text'] = wrap_text('Memberstats import is already running.');
		return $page;
	}

	if (!$data['import_next']) {
		wrap_unlock('memberstats');
		$page['text'] = wrap_text('All snapshots imported.');
		return $page;
	}

	foreach ($data['archives'] as $index => $archive) {
		if ($archive['date'] !== $data['import_next']) continue;
		$next = $archive;
		break;
	}
	mf_ratings_memberstats_import($next, $data['overwrite']);

	wrap_job(wrap_setting('request_uri'), ['sequential' => 1]);

	$page['text'] = sprintf(wrap_text('Imported %s'), $data['import_next']);
	return $page;
}



/**
 * import one snapshot from a DWZ archive into memberstats
 *
 * unzips the archive into a temp folder, loads spieler and vereine data
 * into temporary tables (from either .sql or .txt source files, see below),
 * auto-creates `contacts` for any ZPS codes whose club is not currently
 * linked, and inserts one row per player into memberstats with the given
 * snapshot_date. With $overwrite, existing rows for that date are removed
 * first so a re-import is idempotent.
 *
 * Two on-disk formats are supported because the DSB switched export
 * formats over time:
 *  - newer ZIPs (named *-LV-0-sql*.zip) contain spieler.sql / vereine.sql
 *    with REPLACE INTO statements in ISO-8859-1
 *  - older ZIPs contain spieler.txt / vereine.txt with pipe-separated
 *    values in a DOS code page (CP850); column order differs from the
 *    SQL format, see mf_ratings_memberstats_load_txt()
 *
 * @param array $archive ['date' => 'YYYY-MM-DD', 'filename' => string]
 * @param bool $overwrite when true, delete existing rows for this date first
 * @return void
 */
function mf_ratings_memberstats_import($archive, $overwrite) {
	wrap_include('sync', 'ratings');

	$folder = mf_ratings_unzip('DWZ', $archive['filename']);
	$files = mf_ratings_memberstats_files($folder, $archive['filename']);

	// regular (non-TEMPORARY) staging tables: visible across connections
	// for progress monitoring and debugging, and immune to any per-request
	// MySQL reconnect that would silently drop session-local TEMP tables.
	// Drop any leftovers from a previous failed run before recreating.
	mf_ratings_memberstats_drop('temp_memberstats_spieler');
	$sql = 'CREATE TABLE `temp_memberstats_spieler` LIKE dwz_spieler';
	wrap_db_query($sql);
	// PID was only introduced with the *_v2 export; older .txt snapshots
	// have no PID. Make it nullable on the temp table so those rows insert.
	$sql = 'ALTER TABLE temp_memberstats_spieler MODIFY `PID` int unsigned NULL DEFAULT NULL';
	wrap_db_query($sql);
	// Mgl_Nr can be alphanumeric in old .txt snapshots (e.g. "B49") even
	// though current dwz_spieler stores it as smallint
	$sql = 'ALTER TABLE temp_memberstats_spieler MODIFY `Mgl_Nr` varchar(8) NOT NULL';
	wrap_db_query($sql);
	// Geburtsjahr is YEAR in dwz_spieler (range 1901-2155), but old .txt
	// snapshots use 1900 as a placeholder for "unknown" and modern strict
	// MySQL would reject those rows. Widen to smallint here; the downstream
	// INSERT into memberstats clamps to a valid YEAR range
	$sql = 'ALTER TABLE temp_memberstats_spieler MODIFY `Geburtsjahr` smallint unsigned NULL DEFAULT NULL';
	wrap_db_query($sql);

	mf_ratings_memberstats_drop('temp_memberstats_vereine');
	$sql = 'CREATE TABLE `temp_memberstats_vereine` LIKE dwz_vereine';
	wrap_db_query($sql);

	foreach (['spieler', 'vereine'] as $kind) {
		$loader = 'mf_ratings_memberstats_load_'.$files[$kind]['format'];
		$loader($files[$kind]['path'], 'temp_memberstats_'.$kind);
	}

	mf_ratings_memberstats_clubs();

	if ($overwrite) {
		$sql = sprintf(
			'DELETE FROM memberstats WHERE snapshot_date = "%s"',
			wrap_db_escape($archive['date'])
		);
		wrap_db_query($sql);
	}

	mf_ratings_memberstats_insert($archive['date']);

	mf_ratings_memberstats_drop('temp_memberstats_spieler');
	mf_ratings_memberstats_drop('temp_memberstats_vereine');

	// the unzip folder is ours; clear out any leftovers (verband.sql,
	// VERBAND.TXT, readme variants, …) the snapshot may have shipped
	mf_ratings_memberstats_rmtree($folder);
}

/**
 * recursively delete a directory the importer created via
 * mf_ratings_unzip(). Snapshots ship varying filename casing and the
 * occasional extra file (verband.sql, VERBAND.TXT, readme.txt …); we own
 * the folder, so wipe everything inside before removing it
 *
 * @param string $path
 * @return void
 */
function mf_ratings_memberstats_rmtree($path) {
	if (!is_dir($path)) return;
	$entries = scandir($path);
	foreach ($entries as $entry) {
		if ($entry === '.' OR $entry === '..') continue;
		$child = $path.'/'.$entry;
		if (is_dir($child) AND !is_link($child)) {
			mf_ratings_memberstats_rmtree($child);
			continue;
		}
		unlink($child);
	}
	rmdir($path);
}

/**
 * locate spieler + vereine sources in the unzipped archive folder, prefer
 * the .sql variant where both exist
 *
 * @param string $folder
 * @param string $archive original ZIP path, only used for error reporting
 * @return array indexed by 'spieler' and 'vereine':
 *	['format' => 'sql'|'txt', 'path' => string]
 */
function mf_ratings_memberstats_files($folder, $archive) {
	$files = [];
	foreach (['spieler', 'vereine'] as $kind) {
		foreach (['sql', 'txt'] as $format) {
			$path = sprintf('%s/%s.%s', $folder, $kind, $format);
			if (!file_exists($path)) continue;
			$files[$kind] = ['format' => $format, 'path' => $path];
			continue 2;
		}
		wrap_error(sprintf(
			'memberstats: no %s.sql or %s.txt found in archive %s',
			$kind, $kind, $archive
		), E_USER_ERROR);
	}
	return $files;
}

/**
 * stream-load a DWZ .sql dump into a temporary table
 *
 * the SQL files are emitted in ISO-8859-1 with one REPLACE INTO statement
 * per line; we convert each line to UTF-8, swap the original table name
 * for the temporary one and run the statement. Dummy rows (passive
 * players with no Mgl_Nr and unnamed players) are skipped, identical to
 * the filter in zzbrick_make/ratings-prepare-dwz.inc.php
 *
 * The source table name is derived from $target_table by stripping the
 * `temp_memberstats_` prefix.
 *
 * @param string $filename source .sql file
 * @param string $target_table temporary table that should receive the rows
 * @return void
 */
function mf_ratings_memberstats_load_sql($filename, $target_table) {
	$source_table = 'dwz_'.substr($target_table, strlen('temp_memberstats_'));
	$needle = '`'.$source_table.'`';
	$replace = '`'.$target_table.'`';

	$handle = fopen($filename, 'r');
	if (!$handle)
		wrap_error(sprintf('memberstats: unable to open %s', $filename), E_USER_ERROR);

	while (($line = fgets($handle)) !== false) {
		if (!trim($line)) continue;
		$line = iconv('ISO-8859-1', 'UTF-8', $line);
		// 1. passive players without membership no. are dummy entries for
		// people in the board of a club who are not members
		if (preg_match('/^REPLACE INTO `dwz_spieler` VALUES \(\d+,"[0-9A-Z]+",null,"P",.+$/', $line)) continue;
		// 2. there are some people without names (sic!)
		if (preg_match('/^REPLACE INTO `dwz_spieler` VALUES \(\d+,"[0-9A-Z]+",\d+,"A","",.+$/', $line)) continue;
		if (preg_match('/^REPLACE INTO `dwz_spieler` VALUES \(\d+,"[0-9A-Z]+",\d+,"P","",.+$/', $line)) continue;

		$line = str_replace($needle, $replace, $line);
		wrap_db_query($line, E_USER_WARNING);
	}
	fclose($handle);
}

/**
 * stream-load a DWZ .txt dump (pipe-separated values, DOS code page) into
 * a temporary table
 *
 * Column order in spieler.txt differs from the dwz_spieler SQL schema —
 * older DSB exports place Status before Spielername and join DWZ /
 * DWZ_Index into one token. Mapping (0-indexed):
 *
 *  0: ZPS               (varchar 5, digits only in this format)
 *  1: Mgl_Nr
 *  2: Status            ("" = active, otherwise "P"/"D"/"J"/"V"/"N")
 *  3: Spielername
 *  4: Geschlecht
 *  5: Spielberechtigung ("D" Deutscher, "G" Gleichgestellt, …)
 *  6: Geburtsjahr
 *  7: Letzte_Auswertung
 *  8: DWZ-DWZ_Index     ("2785-13" → DWZ=2785, DWZ_Index=13; "R" → NULL)
 *  9: FIDE_Elo
 *  10: FIDE_Titel       ("G" GM, "I" IM, "F" FM, …)
 *  11: FIDE_ID
 *  12: FIDE_Land
 *
 * vereine.txt has the same four columns as dwz_vereine in the same order:
 * ZPS|LV|Verband|Vereinname.
 *
 * The file encoding is CP850 (DOS multilingual) — `ü` is stored as the
 * single byte 0x81, which is what BBEdit mis-renders as `Å` in Mac OS
 * Roman.
 *
 * @param string $filename source .txt file
 * @param string $target_table temporary table that should receive the rows
 * @return void
 */
function mf_ratings_memberstats_load_txt($filename, $target_table) {
	$handle = fopen($filename, 'r');
	if (!$handle)
		wrap_error(sprintf('memberstats: unable to open %s', $filename), E_USER_ERROR);

	$kind = substr($target_table, strlen('temp_memberstats_'));
	while (($line = fgets($handle)) !== false) {
		$line = rtrim($line, "\r\n");
		if ($line === '') continue;
		$line = iconv('CP850', 'UTF-8//TRANSLIT', $line);
		$fields = explode('|', $line);

		if ($kind === 'spieler')
			$sql = mf_ratings_memberstats_txt_spieler($fields, $target_table);
		else
			$sql = mf_ratings_memberstats_txt_vereine($fields, $target_table);
		if (!$sql) continue;
		wrap_db_query($sql, E_USER_WARNING);
	}
	fclose($handle);
}

/**
 * turn one parsed spieler.txt row into an INSERT statement against the
 * temporary table; same dummy-row filters as the SQL loader (passive
 * players with no Mgl_Nr, players without a name)
 *
 * @param array $fields fields from explode('|', $line)
 * @param string $target_table
 * @return string SQL, or '' if the row should be skipped
 */
function mf_ratings_memberstats_txt_spieler($fields, $target_table) {
	if (count($fields) < 13) return '';
	$zps              = mf_ratings_memberstats_zps_normalize($fields[0]);
	$mgl_nr           = $fields[1];
	$status           = $fields[2] !== '' ? $fields[2] : 'A';
	$spielername      = $fields[3];
	$geschlecht       = $fields[4];
	$spielberechtigung = $fields[5];
	$geburtsjahr      = $fields[6];
	$letzte_auswertung = $fields[7];
	$dwz              = '';
	$dwz_index        = '';
	if ($fields[8] !== '' AND $fields[8] !== 'R') {
		$parts = explode('-', $fields[8], 2);
		$dwz       = $parts[0];
		$dwz_index = $parts[1] ?? '';
	}
	$fide_elo         = $fields[9];
	$fide_titel       = $fields[10];
	$fide_id          = $fields[11];
	$fide_land        = $fields[12];

	if ($spielername === '') return '';
	if ($status === 'P' AND ($mgl_nr === '' OR $mgl_nr === '0')) return '';
	if ($zps === '' OR $mgl_nr === '') return '';

	$sql = sprintf('INSERT INTO `%s` (`ZPS`, `Mgl_Nr`, `Status`, `Spielername`'
		.', `Geschlecht`, `Spielberechtigung`, `Geburtsjahr`, `Letzte_Auswertung`'
		.', `DWZ`, `DWZ_Index`, `FIDE_Elo`, `FIDE_Titel`, `FIDE_ID`, `FIDE_Land`)'
		.' VALUES ("%s", "%s", %s, "%s", %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
		$target_table,
		wrap_db_escape($zps),
		wrap_db_escape($mgl_nr),
		mf_ratings_memberstats_txt_string($status),
		wrap_db_escape($spielername),
		mf_ratings_memberstats_txt_string($geschlecht),
		mf_ratings_memberstats_txt_string($spielberechtigung),
		mf_ratings_memberstats_txt_number($geburtsjahr),
		mf_ratings_memberstats_txt_number($letzte_auswertung),
		mf_ratings_memberstats_txt_number($dwz),
		mf_ratings_memberstats_txt_number($dwz_index),
		mf_ratings_memberstats_txt_number($fide_elo),
		mf_ratings_memberstats_txt_string($fide_titel),
		mf_ratings_memberstats_txt_number($fide_id),
		mf_ratings_memberstats_txt_string($fide_land)
	);
	return $sql;
}

/**
 * turn one parsed vereine.txt row into an INSERT statement against the
 * temporary table
 *
 * @param array $fields
 * @param string $target_table
 * @return string SQL, or '' if the row should be skipped
 */
function mf_ratings_memberstats_txt_vereine($fields, $target_table) {
	if (count($fields) < 4) return '';
	$zps         = mf_ratings_memberstats_zps_normalize($fields[0]);
	$lv          = $fields[1];
	$verband     = $fields[2];
	$vereinname  = $fields[3];
	if ($zps === '') return '';
	$sql = sprintf('INSERT INTO `%s` (`ZPS`, `LV`, `Verband`, `Vereinname`)'
		.' VALUES ("%s", "%s", "%s", "%s")',
		$target_table,
		wrap_db_escape($zps),
		wrap_db_escape($lv),
		wrap_db_escape($verband),
		wrap_db_escape($vereinname)
	);
	return $sql;
}

/**
 * convert a legacy 6-char numeric ZPS code to the modern 5-char form
 *
 * old DSB exports used a 2-digit federation prefix (10, 11, 12, …) that
 * was renamed to a single letter (A, B, C, …), so a 6-char code like
 * "100123" becomes "A0123". Only codes that are exactly six characters
 * long with a numeric prefix in 10–35 are converted; codes already in
 * the modern form (letter + 4 digits) or shorter legacy codes are
 * returned unchanged.
 *
 * @param string $zps
 * @return string
 */
function mf_ratings_memberstats_zps_normalize($zps) {
	if (strlen($zps) !== 6) return $zps;
	$prefix = substr($zps, 0, 2);
	if (!ctype_digit($prefix)) return $zps;
	$prefix = (int)$prefix;
	if ($prefix < 10 OR $prefix > 35) return $zps;
	return chr(ord('A') + $prefix - 10).substr($zps, 2);
}

/**
 * format a string field for SQL literal: empty → NULL, otherwise escaped
 *
 * @param string $value
 * @return string
 */
function mf_ratings_memberstats_txt_string($value) {
	if ($value === '' OR $value === null) return 'NULL';
	return '"'.wrap_db_escape($value).'"';
}

/**
 * format a numeric field for SQL literal: empty / non-numeric → NULL
 *
 * @param string $value
 * @return string
 */
function mf_ratings_memberstats_txt_number($value) {
	if ($value === '' OR $value === null) return 'NULL';
	if (!is_numeric($value)) return 'NULL';
	return (string)(int)$value;
}

/**
 * create `contacts` + `contacts_identifiers` for clubs that appear in the
 * current snapshot but have no row at all in contacts_identifiers (under
 * the pass_dsb category)
 *
 * contacts_identifiers has UNIQUE KEY (identifier_category_id, identifier),
 * so each ZPS code can exist only once per category — regardless of the
 * `current` flag. We therefore look up the identifier without the
 * current="yes" filter; codes that exist with current=NULL (a club that
 * used to hold that ZPS) are reused, and only codes truly missing from
 * the table get a new contact.
 *
 * The ZPS code stored on the new contacts_identifiers row uses the
 * federation-collapse rule (see zzbrick_make/clubstats.inc.php): for the
 * letters listed in ratings_dsb_federations_are_clubs, the contact lives
 * at the 3-character federation level (`A001` → `A00`); for ZPS codes
 * ending in `00`, the same collapse applies; otherwise the raw 5-char
 * code is used. The club name is read from the snapshot's vereine.sql
 * (joined via the collapsed code). Codes that are not in vereine.sql are
 * left unmapped — the corresponding memberstats rows will have a NULL
 * club_contact_id, but club_code is still populated.
 *
 * @return void
 */
function mf_ratings_memberstats_clubs() {
	$collapse = 'IF(
			FIND_IN_SET(SUBSTRING(s.ZPS, 1, 1),
				"/*_SETTING ratings_dsb_federations_are_clubs _*/")
			, SUBSTRING(s.ZPS, 1, 3)
			, IF(SUBSTRING(s.ZPS, 4, 2) = "00",
				SUBSTRING(s.ZPS, 1, 3), s.ZPS)
		)';
	$sql = 'SELECT codes.code AS club_code, v.Vereinname AS club_name
		FROM (
			SELECT DISTINCT '.$collapse.' AS code
			FROM temp_memberstats_spieler s
			WHERE s.Spielername <> ""
		) codes
		LEFT JOIN temp_memberstats_vereine v
			ON v.ZPS = codes.code
		LEFT JOIN contacts_identifiers ci
			ON ci.identifier = codes.code
			AND ci.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		WHERE ISNULL(ci.contact_identifier_id)
		AND NOT ISNULL(v.Vereinname)
		AND v.Vereinname <> ""';
	$missing = wrap_db_fetch($sql, 'club_code');
	if (!$missing) return;

	foreach ($missing as $club_code => $club) {
		$contact = [
			'contact_category_id' => wrap_category_id('contact/club'),
			'contact' => $club['club_name']
		];
		$contact_id = zzform_insert('contacts', $contact);
		if (!$contact_id) {
			wrap_error(sprintf(
				'memberstats: unable to insert contact for %s (%s)',
				$club_code, $club['club_name']
			));
			continue;
		}
		$identifier = [
			'contact_id' => $contact_id,
			'identifier' => $club_code,
			'identifier_category_id' => wrap_category_id('identifiers/pass_dsb'),
			'current' => 'yes'
		];
		zzform_insert('contacts_identifiers', $identifier, E_USER_WARNING);
	}
}

/**
 * insert one memberstats row per player from temp_memberstats_spieler
 *
 * @param string $snapshot_date YYYY-MM-DD
 * @return void
 */
function mf_ratings_memberstats_insert($snapshot_date) {
	$sql = 'INSERT INTO memberstats
			(snapshot_date, club_code, club_contact_id,
				birth_year, rating, sex, status)
		SELECT
			"%s"
			, s.ZPS
			, ci.contact_id
			, NULLIF(s.Geburtsjahr, 0)
			, NULLIF(s.DWZ, 0)
			, CASE s.Geschlecht
				WHEN "W" THEN "female"
				WHEN "M" THEN "male"
			  END
			, CASE s.Status
				WHEN "A" THEN "active"
				WHEN "P" THEN "passive"
			  END
		FROM temp_memberstats_spieler s
		LEFT JOIN contacts_identifiers ci
			ON ci.identifier = IF(
				FIND_IN_SET(SUBSTRING(s.ZPS, 1, 1),
					"/*_SETTING ratings_dsb_federations_are_clubs _*/")
				, SUBSTRING(s.ZPS, 1, 3)
				, IF(SUBSTRING(s.ZPS, 4, 2) = "00",
					SUBSTRING(s.ZPS, 1, 3), s.ZPS)
			)
			AND ci.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		WHERE s.Spielername <> ""';
	$sql = sprintf($sql, wrap_db_escape($snapshot_date));
	wrap_db_query($sql);
}
