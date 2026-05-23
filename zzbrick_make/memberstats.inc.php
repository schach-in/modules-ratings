<?php

/**
 * ratings module
 * import member statistics from DWZ snapshots
 *
 * Looks at the archived DWZ ZIPs in ratings_dir/dwz/<year>/, opens
 * spieler.sql and vereine.sql for the next missing snapshot, loads them
 * into temporary tables and writes one memberstats row per player with
 * the snapshot date taken from the archive filename. One click in the
 * UI imports exactly one snapshot; subsequent snapshots are not chained
 * automatically. Snapshots already present in `memberstats` are skipped;
 * a single date can be re-imported with ?force=YYYY-MM-DD.
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2025-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * import member statistics from DWZ snapshots
 *
 * One click = one snapshot. The brick has three branches:
 *  - GET: render the status shell; the JS poller hydrates from
 *    `ratings_memberstats_progress` and offers a Start button when idle.
 *  - POST without `sequential`: trigger branch. Logs `queued` and
 *    enqueues a sequential background job, then returns immediately.
 *  - POST with `sequential` (the worker job itself): acquires
 *    wrap_lock('memberstats', 'sequential', 1800), imports exactly one
 *    snapshot (logging each phase), releases the lock and stops. No
 *    chained next-snapshot dispatch — the operator clicks again for the
 *    next one, watching progress live via /_behaviour/ratings/memberstats.js.
 *
 * The 30-minute lock timeout is auto-recovery insurance only; a single
 * snapshot import completes well under that window. Foreign callers
 * that hit the worker URL while a lock is held get 403.
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
		$data['progress_url'] = wrap_path('ratings_memberstats_progress');
		$data['poll_ms'] = (int) wrap_setting('ratings_memberstats_poll_interval') * 1000;
		$page['text'] = wrap_template('memberstats', $data);
		return $page;
	}
	
	if (!array_key_exists('sequential', $_POST)) {
		// trigger branch: enqueue worker, return immediately. We write a
		// `queued` log entry first so the JS poller sees the import as
		// busy on its very next tick, even if the jobmanager hasn't
		// dispatched the worker yet.
		if (!$data['import_next']) {
			$page['status'] = 409;
			$page['text'] = wrap_text('Nothing to import.');
			return $page;
		}
		mf_ratings_memberstats_log('queued', [
			'snapshot' => $data['import_next'],
			'overwrite' => $data['overwrite']
		]);
		wrap_job(wrap_setting('request_uri'), ['sequential' => 1]);
		wrap_job_debug('JOB STARTING memberstats', $_POST);
		$page['text'] = wrap_text('Background job queued.');
		return $page;
	}

	// worker branch
	wrap_include('syndication', 'zzwrap');
	// 1800s = 30 min, longer than any single snapshot's import time;
	// covers worker crashes by auto-recovering after this window.
	$lock = wrap_lock('memberstats', 'sequential', 1800);
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

	mf_ratings_memberstats_log('start', [
		'snapshot' => $next['date'],
		'overwrite' => $data['overwrite']
	]);
	mf_ratings_memberstats_import($next, $data['overwrite']);
	mf_ratings_memberstats_log('done', [
		'snapshot' => $next['date']
	]);

	wrap_unlock('memberstats');

	$page['text'] = sprintf(wrap_text('Imported %s'), $next['date']);
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

	mf_ratings_memberstats_log('unzip', ['snapshot' => $archive['date']]);
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
		$loader($files[$kind]['path'], 'temp_memberstats_'.$kind, $archive['date']);
	}

	mf_ratings_memberstats_log('clubs', ['snapshot' => $archive['date']]);
	mf_ratings_memberstats_clubs($archive['date']);

	if ($overwrite) {
		$sql = sprintf(
			'DELETE FROM memberstats WHERE snapshot_date = "%s"',
			wrap_db_escape($archive['date'])
		);
		wrap_db_query($sql);
	}

	mf_ratings_memberstats_log('insert', ['snapshot' => $archive['date']]);
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
 * Two dump variants are supported for spieler.sql:
 *  - modern (post 2024-05-22): 16 columns, PID first, bare integers and
 *    double-quoted strings, `null` lowercase
 *  - legacy (pre 2024-05-22): 15 columns, ZPS first, an extra
 *    `Spielername_G` between `Spielername` and `Geschlecht`, single-quoted
 *    values and uppercase `NULL`
 *
 * The legacy schema is materialised on the temp table on demand so the
 * positional VALUES (…) line up.
 *
 * The source table name is derived from $target_table by stripping the
 * `temp_memberstats_` prefix.
 *
 * @param string $filename source .sql file
 * @param string $target_table temporary table that should receive the rows
 * @param string $snapshot_date YYYY-MM-DD, for progress log entries
 * @return void
 */
function mf_ratings_memberstats_load_sql($filename, $target_table, $snapshot_date) {
	$kind = substr($target_table, strlen('temp_memberstats_'));
	$action = 'load_'.$kind;
	$source_table = 'dwz_'.$kind;
	$needle = '`'.$source_table.'`';
	$replace = '`'.$target_table.'`';

	$bytes_total = filesize($filename);
	$handle = fopen($filename, 'r');
	if (!$handle)
		wrap_error(sprintf('memberstats: unable to open %s', $filename), E_USER_ERROR);

	mf_ratings_memberstats_log($action, [
		'snapshot' => $snapshot_date,
		'bytes_done' => 0,
		'bytes_total' => $bytes_total,
		'rows_done' => 0
	]);

	$format = mf_ratings_memberstats_sql_format($handle);
	if ($target_table === 'temp_memberstats_spieler' AND $format === 'legacy') {
		// reshape to the pre-2024 spieler dump: no PID, Spielername_G
		// between Spielername and Geschlecht
		$sql = 'ALTER TABLE `temp_memberstats_spieler`
			DROP COLUMN `PID`,
			ADD COLUMN `Spielername_G` varchar(60) NULL AFTER `Spielername`';
		wrap_db_query($sql);
	}

	$dummies = mf_ratings_memberstats_sql_dummies($source_table, $format);
	$rows_done = 0;
	$bytes_logged = 0;
	$tick = wrap_setting('ratings_memberstats_log_tick_bytes');

	while (($line = fgets($handle)) !== false) {
		if (!trim($line)) continue;
		$line = iconv('ISO-8859-1', 'UTF-8', $line);
		if (mf_ratings_memberstats_sql_dummy($line, $dummies)) continue;
		$line = str_replace($needle, $replace, $line);
		wrap_db_query($line, E_USER_WARNING);
		$rows_done++;
		$bytes_done = ftell($handle);
		if ($bytes_done - $bytes_logged >= $tick) {
			mf_ratings_memberstats_log($action, [
				'snapshot' => $snapshot_date,
				'bytes_done' => $bytes_done,
				'bytes_total' => $bytes_total,
				'rows_done' => $rows_done
			]);
			$bytes_logged = $bytes_done;
		}
	}
	fclose($handle);

	mf_ratings_memberstats_log($action, [
		'snapshot' => $snapshot_date,
		'bytes_done' => $bytes_total,
		'bytes_total' => $bytes_total,
		'rows_done' => $rows_done
	]);
}

/**
 * peek the first REPLACE INTO line of an open .sql dump to tell modern
 * (post 2024-05-22) from legacy dumps apart, then rewind for the caller
 *
 * modern: `REPLACE INTO …` VALUES (1234,"AB01",… — bare integer first
 * legacy: `REPLACE INTO …` VALUES ('1234', '255', … — single-quoted first
 *
 * @param resource $handle open file handle, rewound on return
 * @return string 'modern' (default) or 'legacy'
 */
function mf_ratings_memberstats_sql_format($handle) {
	rewind($handle);
	$format = 'modern';
	while (($line = fgets($handle)) !== false) {
		if (!str_contains($line, 'REPLACE INTO')) continue;
		if (preg_match('/REPLACE INTO `\w+` VALUES \(\s*\'/', $line))
			$format = 'legacy';
		break;
	}
	rewind($handle);
	return $format;
}

/**
 * dummy-row filter patterns for the SQL dump variants
 *
 * matches the same three cases as zzbrick_make/ratings-prepare-dwz.inc.php
 * (passive without Mgl_Nr, active/passive without name)
 *
 * @param string $source_table
 * @param string $format 'modern' or 'legacy'
 * @return array list of regex patterns
 */
function mf_ratings_memberstats_sql_dummies($source_table, $format) {
	$prefix = '/^REPLACE INTO `'.preg_quote($source_table, '/').'` VALUES ';
	if ($format === 'legacy') {
		return [
			$prefix.'\(\s*\'\d+\'\s*,\s*NULL\s*,\s*\'P\',/',
			$prefix.'\(\s*\'\d+\'\s*,\s*\'\d+\'\s*,\s*\'A\'\s*,\s*\'\',/',
			$prefix.'\(\s*\'\d+\'\s*,\s*\'\d+\'\s*,\s*\'P\'\s*,\s*\'\',/'
		];
	}
	return [
		$prefix.'\(\d+,"[0-9A-Z]+",null,"P",/',
		$prefix.'\(\d+,"[0-9A-Z]+",\d+,"A","",/',
		$prefix.'\(\d+,"[0-9A-Z]+",\d+,"P","",/'
	];
}

/**
 * @param string $line
 * @param array $patterns
 * @return bool
 */
function mf_ratings_memberstats_sql_dummy($line, $patterns) {
	foreach ($patterns as $pattern)
		if (preg_match($pattern, $line)) return true;
	return false;
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
 * @param string $snapshot_date YYYY-MM-DD, for progress log entries
 * @return void
 */
function mf_ratings_memberstats_load_txt($filename, $target_table, $snapshot_date) {
	$kind = substr($target_table, strlen('temp_memberstats_'));
	$action = 'load_'.$kind;

	$bytes_total = filesize($filename);
	$handle = fopen($filename, 'r');
	if (!$handle)
		wrap_error(sprintf('memberstats: unable to open %s', $filename), E_USER_ERROR);

	mf_ratings_memberstats_log($action, [
		'snapshot' => $snapshot_date,
		'bytes_done' => 0,
		'bytes_total' => $bytes_total,
		'rows_done' => 0
	]);

	$rows_done = 0;
	$bytes_logged = 0;
	$tick = wrap_setting('ratings_memberstats_log_tick_bytes');

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
		$rows_done++;
		$bytes_done = ftell($handle);
		if ($bytes_done - $bytes_logged >= $tick) {
			mf_ratings_memberstats_log($action, [
				'snapshot' => $snapshot_date,
				'bytes_done' => $bytes_done,
				'bytes_total' => $bytes_total,
				'rows_done' => $rows_done
			]);
			$bytes_logged = $bytes_done;
		}
	}
	fclose($handle);

	mf_ratings_memberstats_log($action, [
		'snapshot' => $snapshot_date,
		'bytes_done' => $bytes_total,
		'bytes_total' => $bytes_total,
		'rows_done' => $rows_done
	]);
}

/**
 * turn one parsed spieler.txt row into an INSERT statement against the
 * temporary table; same dummy-row filters as the SQL loader (passive
 * players with no Mgl_Nr, players without a name)
 *
 * INSERT IGNORE because old .txt snapshots occasionally list the same
 * (ZPS, Mgl_Nr) twice — once as the established member and once with
 * Status='N' (Neu) for a pending re-registration; we keep whichever row
 * the file ordered first
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

	$sql = sprintf('INSERT IGNORE INTO `%s` (`ZPS`, `Mgl_Nr`, `Status`, `Spielername`'
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
 * Each new club is also linked to its federation parent in
 * `contacts_contacts` (relation/member, published). The parent contact
 * is resolved via the first three characters of the club code against
 * `contacts_identifiers` (pass_dsb), ignoring the `current` flag.
 *
 * @param string $snapshot_date YYYY-MM-DD, for progress log entries
 * @return void
 */
function mf_ratings_memberstats_clubs($snapshot_date) {
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

	$contacts_created = 0;
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
		mf_ratings_memberstats_log('contact', [
			'snapshot' => $snapshot_date,
			'club_code' => $club_code,
			'contact_id' => $contact_id,
			'contact' => $club['club_name']
		]);
		$contacts_created++;
		$identifier = [
			'contact_id' => $contact_id,
			'identifier' => $club_code,
			'identifier_category_id' => wrap_category_id('identifiers/pass_dsb'),
			'current' => 'yes'
		];
		zzform_insert('contacts_identifiers', $identifier, E_USER_WARNING);
		mf_ratings_memberstats_club_parent_link($contact_id, $club_code);
	}

	mf_ratings_memberstats_log('clubs_done', [
		'snapshot' => $snapshot_date,
		'contacts_created' => $contacts_created
	]);
}

/**
 * link a new club contact to its federation parent in contacts_contacts
 *
 * Parent code is the first three characters of the club's pass_dsb
 * identifier (e.g. `E1301` → `E13`). The parent contact_id is looked up
 * in contacts_identifiers without filtering on `current`. Three-character
 * club codes and missing parents are skipped quietly except for a warning
 * when no parent identifier exists.
 *
 * @param int $contact_id new club contact
 * @param string $club_code pass_dsb identifier stored on the club
 * @return void
 */
function mf_ratings_memberstats_club_parent_link($contact_id, $club_code) {
	if (strlen($club_code) <= 3) return;
	$parent_code = substr($club_code, 0, 3);
	if ($parent_code === $club_code) return;

	$sql = 'SELECT contact_id FROM contacts_identifiers
		WHERE identifier = "%s"
		AND identifier_category_id = /*_ID categories identifiers/pass_dsb _*/';
	$sql = sprintf($sql, wrap_db_escape($parent_code));
	$parent_contact_id = wrap_db_fetch($sql, '', 'single value');
	if (!$parent_contact_id) {
		wrap_error(sprintf(
			'memberstats: no parent contact for %s (parent code %s)',
			$club_code, $parent_code
		), E_USER_WARNING);
		return;
	}

	$line = [
		'contact_id' => $contact_id,
		'main_contact_id' => $parent_contact_id,
		'relation_category_id' => wrap_category_id('relation/member'),
		'published' => 'yes'
	];
	zzform_insert('contacts_contacts', $line, E_USER_WARNING);
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
