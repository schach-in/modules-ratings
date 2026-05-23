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
	$data['import_next'] = null;
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
		if (empty($data['import_next'])) {
			$page['text'] = wrap_text('All snapshots imported.');
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

	if (empty($data['import_next'])) {
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
 * into staging tables defined in configuration/memberstats.sql (from either
 * .sql or .txt source files, see below). When the snapshot includes
 * verbaende.sql or verbaende.txt, missing federation contacts are created
 * before clubs. Clubs or federations present in the chronologically
 * previous archive's vereine/verbaende but absent from the current snapshot
 * get contacts.end_date set to YYYY-MM-00 (month of the snapshot). auto-creates `contacts` for any ZPS codes whose club is not currently
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
 *  - optional verbaende.sql / verbaende.txt, or legacy verband.sql /
 *    VERBAND.TXT (any casing): when absent, federation auto-import is
 *    skipped entirely
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

	// Staging tables from configuration/memberstats.sql — not LIKE dwz_*,
	// so snapshot import keeps working when the live DWZ schema changes.
	mf_ratings_memberstats_drop('temp_memberstats_spieler_v1');
	mf_ratings_memberstats_drop('temp_memberstats_spieler_v2');
	mf_ratings_memberstats_drop('temp_memberstats_vereine');
	$sql = wrap_sql_query('ratings_memberstats_temp_vereine', 'memberstats');
	wrap_db_query($sql);

	$spieler_version = mf_ratings_memberstats_spieler_version($files['spieler']);
	$spieler_table = 'temp_memberstats_spieler_'.$spieler_version;
	$sql = wrap_sql_query('ratings_memberstats_temp_spieler_'.$spieler_version, 'memberstats');
	wrap_db_query($sql);

	$loader = 'mf_ratings_memberstats_load_'.$files['spieler']['format'];
	$loader($files['spieler']['path'], $spieler_table, $archive['date']);

	$loader = 'mf_ratings_memberstats_load_'.$files['vereine']['format'];
	$loader($files['vereine']['path'], 'temp_memberstats_vereine', $archive['date']);

	mf_ratings_memberstats_spieler_geschlecht_default($spieler_table);

	$verbaende = mf_ratings_memberstats_file_optional($folder, ['verbaende', 'verband']);
	if ($verbaende) {
		mf_ratings_memberstats_drop('temp_memberstats_verbaende');
		$sql = wrap_sql_query('ratings_memberstats_temp_verbaende', 'memberstats');
		wrap_db_query($sql);
		$loader = 'mf_ratings_memberstats_load_'.$verbaende['format'];
		$loader($verbaende['path'], 'temp_memberstats_verbaende', $archive['date']);
		mf_ratings_memberstats_log('verbaende', ['snapshot' => $archive['date']]);
		mf_ratings_memberstats_verbaende($archive['date']);
		mf_ratings_memberstats_drop('temp_memberstats_verbaende');
	}

	mf_ratings_memberstats_close_removed($archive['date'], (bool)$verbaende, $folder);

	mf_ratings_memberstats_log('clubs', ['snapshot' => $archive['date']]);
	mf_ratings_memberstats_clubs($archive['date'], $spieler_table);

	if ($overwrite) {
		$sql = sprintf(
			'DELETE FROM memberstats WHERE snapshot_date = "%s"',
			wrap_db_escape($archive['date'])
		);
		wrap_db_query($sql);
	}

	mf_ratings_memberstats_log('insert', ['snapshot' => $archive['date']]);
	mf_ratings_memberstats_insert($archive['date'], $spieler_table);

	mf_ratings_memberstats_drop($spieler_table);
	mf_ratings_memberstats_drop('temp_memberstats_vereine');

	// the unzip folder is ours; clear out any leftovers (verband.sql,
	// VERBAND.TXT, readme variants, …) the snapshot may have shipped
	mf_ratings_memberstats_rmtree($folder);
}

/**
 * set end_date on club/federation contacts dropped from the DSB export
 *
 * Compares the current snapshot's vereine (and verbaende when shipped)
 * staging tables with the chronologically previous DWZ archive. Codes
 * that existed before but not now get end_date = YYYY-MM-00 from the
 * current snapshot date, but only when a matching pass_dsb contact
 * exists with end_date still NULL.
 *
 * Current snapshot codes are read from $current_folder (the archive
 * just unzipped), not from staging tables that may already be dropped.
 *
 * @param string $snapshot_date YYYY-MM-DD
 * @param bool $has_verbaende current snapshot includes verbaende data
 * @param string $current_folder unzipped current archive directory
 * @return void
 */
function mf_ratings_memberstats_close_removed($snapshot_date, $has_verbaende, $current_folder) {
	$previous = mf_ratings_memberstats_previous_archive($snapshot_date);
	if (!$previous) return;

	mf_ratings_memberstats_log('close_removed', [
		'snapshot' => $snapshot_date,
		'previous_snapshot' => $previous['date']
	]);

	$previous_folder = mf_ratings_unzip('DWZ', $previous['filename']);
	$contacts_closed = 0;

	$previous_vereine = mf_ratings_memberstats_archive_codes($previous_folder, ['vereine'], true);
	$current_vereine = mf_ratings_memberstats_archive_codes($current_folder, ['vereine'], true);
	if ($previous_vereine) {
		$removed = array_diff($previous_vereine, $current_vereine);
		$contacts_closed += mf_ratings_memberstats_contacts_end_date(
			$snapshot_date,
			$removed,
			[
				wrap_category_id('contact/club'),
				wrap_category_id('contact/chess-department')
			],
			'club_code'
		);
	}

	if ($has_verbaende) {
		$previous_verbaende = mf_ratings_memberstats_archive_codes(
			$previous_folder,
			['verbaende', 'verband']
		);
		$current_verbaende = mf_ratings_memberstats_archive_codes(
			$current_folder,
			['verbaende', 'verband']
		);
		if ($previous_verbaende) {
			$removed = array_diff($previous_verbaende, $current_verbaende);
			$contacts_closed += mf_ratings_memberstats_contacts_end_date(
				$snapshot_date,
				$removed,
				[wrap_category_id('contact/federation')],
				'verband_code'
			);
		}
	}

	mf_ratings_memberstats_rmtree($previous_folder);

	mf_ratings_memberstats_log('close_done', [
		'snapshot' => $snapshot_date,
		'contacts_closed' => $contacts_closed
	]);
}

/**
 * chronologically previous DWZ archive before $snapshot_date
 *
 * @param string $snapshot_date YYYY-MM-DD
 * @return array|null ['date' => string, 'filename' => string]
 */
function mf_ratings_memberstats_previous_archive($snapshot_date) {
	foreach (mf_ratings_archives('DWZ') as $archive) {
		if ($archive['date'] < $snapshot_date) return $archive;
	}
	return null;
}

/**
 * pass_dsb codes from a vereine or verbaende file in an unzipped archive
 *
 * @param string $folder
 * @param string|array $basenames file stem(s), e.g. vereine or verbaende/verband
 * @param bool $normalize_zps apply mf_ratings_memberstats_zps_normalize (vereine .txt)
 * @return array list of codes, empty when the file is missing
 */
function mf_ratings_memberstats_archive_codes($folder, $basenames, $normalize_zps = false) {
	$file = mf_ratings_memberstats_file_optional($folder, $basenames);
	if (!$file) return [];
	if ($file['format'] === 'sql')
		return mf_ratings_memberstats_sql_codes($file['path']);
	return mf_ratings_memberstats_txt_codes($file['path'], $normalize_zps);
}

/**
 * first-column codes from a DWZ .sql dump (one REPLACE INTO value per line)
 *
 * @param string $filename
 * @return array
 */
function mf_ratings_memberstats_sql_codes($filename) {
	$codes = [];
	$handle = fopen($filename, 'r');
	if (!$handle) return $codes;
	while (($line = fgets($handle)) !== false) {
		if (!str_contains($line, 'REPLACE INTO')) continue;
		$line = iconv('ISO-8859-1', 'UTF-8', $line);
		if (!preg_match('/VALUES\s*\((.*)\)\s*;?\s*$/', $line, $match)) continue;
		$code = mf_ratings_memberstats_sql_first_value($match[1]);
		if ($code === null || $code === '') continue;
		$codes[$code] = true;
	}
	fclose($handle);
	return array_keys($codes);
}

/**
 * @param string $values leading fragment of a SQL VALUES tuple
 * @return string|null
 */
function mf_ratings_memberstats_sql_first_value($values) {
	$values = ltrim($values);
	if ($values === '') return null;
	if ($values[0] === '"' || $values[0] === "'") {
		$quote = $values[0];
		$end = strpos($values, $quote, 1);
		if ($end === false) return null;
		return substr($values, 1, $end - 1);
	}
	if (preg_match('/^null\b/i', $values)) return null;
	if (preg_match('/^(\d+)/', $values, $match)) return $match[1];
	return null;
}

/**
 * first-column codes from a pipe-separated DWZ .txt file
 *
 * @param string $filename
 * @param bool $normalize_zps
 * @return array
 */
function mf_ratings_memberstats_txt_codes($filename, $normalize_zps = false) {
	$codes = [];
	$handle = fopen($filename, 'r');
	if (!$handle) return $codes;
	while (($line = fgets($handle)) !== false) {
		$line = rtrim($line, "\r\n");
		if ($line === '') continue;
		$line = iconv('CP850', 'UTF-8//TRANSLIT', $line);
		$fields = explode('|', $line);
		if (!$fields) continue;
		$code = $normalize_zps
			? mf_ratings_memberstats_zps_normalize(trim($fields[0]))
			: trim($fields[0]);
		if ($code === '') continue;
		$codes[$code] = true;
	}
	fclose($handle);
	return array_keys($codes);
}

/**
 * dissolution month for contacts.end_date from a snapshot date
 *
 * @param string $snapshot_date YYYY-MM-DD
 * @return string YYYY-MM-00
 */
function mf_ratings_memberstats_end_date($snapshot_date) {
	return substr($snapshot_date, 0, 7).'-00';
}

/**
 * set end_date on live contacts whose pass_dsb code was removed
 *
 * @param string $snapshot_date YYYY-MM-DD
 * @param array $codes pass_dsb identifiers no longer in the export
 * @param array $category_ids contacts.contact_category_id values to match
 * @param string $log_code_field club_code or verband_code in progress log
 * @return int number of contacts updated
 */
function mf_ratings_memberstats_contacts_end_date($snapshot_date, $codes, $category_ids, $log_code_field) {
	if (!$codes) return 0;
	$codes = array_values(array_unique(array_filter($codes)));
	if (!$codes) return 0;

	$end_date = mf_ratings_memberstats_end_date($snapshot_date);
	$escaped = array_map('wrap_db_escape', $codes);
	$in = '"'.implode('","', $escaped).'"';
	$categories = implode(',', array_map('intval', $category_ids));

	$sql = 'SELECT ci.identifier AS code, c.contact_id, c.contact
		FROM contacts c
		INNER JOIN contacts_identifiers ci ON ci.contact_id = c.contact_id
		WHERE ci.identifier IN ('.$in.')
		AND ci.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		AND c.contact_category_id IN ('.$categories.')
		AND ISNULL(c.end_date)';
	$contacts = wrap_db_fetch($sql, 'code');
	if (!$contacts) return 0;

	$sql = 'UPDATE contacts c
		INNER JOIN contacts_identifiers ci ON ci.contact_id = c.contact_id
		SET c.end_date = "%s"
		WHERE ci.identifier IN ('.$in.')
		AND ci.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		AND c.contact_category_id IN ('.$categories.')
		AND ISNULL(c.end_date)';
	$sql = sprintf($sql, wrap_db_escape($end_date));
	wrap_db_query($sql);

	foreach ($contacts as $code => $contact) {
		$entry = [
			'snapshot' => $snapshot_date,
			'contact_id' => $contact['contact_id'],
			'contact' => $contact['contact'],
			'end_date' => $end_date
		];
		$entry[$log_code_field] = $code;
		mf_ratings_memberstats_log('contact_end', $entry);
	}
	return count($contacts);
}

/**
 * set Geschlecht to M where the DSB export left it NULL (meaning male)
 *
 * Same normalisation as zzbrick_make/ratings-prepare-dwz.inc.php; applied
 * after every spieler load (.sql or .txt) before memberstats rows are built.
 *
 * @param string $spieler_table temp_memberstats_spieler_v1 or _v2
 * @return void
 */
function mf_ratings_memberstats_spieler_geschlecht_default($spieler_table) {
	$sql = sprintf(
		'UPDATE `%s` SET Geschlecht = "M" WHERE ISNULL(Geschlecht)',
		$spieler_table
	);
	wrap_db_query($sql);
}

/**
 * pick the spieler staging table version (v1 or v2) for this snapshot
 *
 * .txt snapshots always use v1. .sql snapshots are classified from the
 * first REPLACE INTO line — see mf_ratings_memberstats_sql_spieler_version().
 *
 * @param array $file ['format' => 'sql'|'txt', 'path' => string]
 * @return string 'v1' or 'v2'
 */
function mf_ratings_memberstats_spieler_version($file) {
	if ($file['format'] === 'txt') return 'v1';
	$handle = fopen($file['path'], 'r');
	if (!$handle)
		wrap_error(sprintf('memberstats: unable to open %s', $file['path']), E_USER_ERROR);
	$version = mf_ratings_memberstats_sql_spieler_version($handle);
	fclose($handle);
	return $version;
}

/**
 * peek the first REPLACE INTO line of an open spieler.sql to pick v1 or v2
 *
 * v2: VALUES (1234,"AB01",… — bare integer PID first
 * v1: VALUES ('10614',… or ("10614",… — quoted ZPS first
 *
 * @param resource $handle open file handle, rewound on return
 * @return string 'v1' or 'v2'
 */
function mf_ratings_memberstats_sql_spieler_version($handle) {
	rewind($handle);
	$version = 'v2';
	while (($line = fgets($handle)) !== false) {
		if (!str_contains($line, 'REPLACE INTO')) continue;
		if (preg_match('/REPLACE INTO `\w+` VALUES \(\s*\d+,/', $line)) {
			$version = 'v2';
			break;
		}
		if (preg_match('/REPLACE INTO `\w+` VALUES \(\s*[\'"]/', $line)) {
			$version = 'v1';
			break;
		}
		break;
	}
	rewind($handle);
	return $version;
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
 * Basenames and extensions are matched case-insensitively (SPIELER.TXT
 * on Linux, etc.) via mf_ratings_memberstats_file_optional().
 *
 * @param string $folder
 * @param string $archive original ZIP path, only used for error reporting
 * @return array indexed by 'spieler' and 'vereine':
 *	['format' => 'sql'|'txt', 'path' => string]
 */
function mf_ratings_memberstats_files($folder, $archive) {
	$files = [];
	foreach (['spieler', 'vereine'] as $kind) {
		$file = mf_ratings_memberstats_file_optional($folder, $kind);
		if (!$file) {
			wrap_error(sprintf(
				'memberstats: no %s.sql or %s.txt found in archive %s',
				$kind, $kind, $archive
			), E_USER_ERROR);
		}
		$files[$kind] = $file;
	}
	return $files;
}

/**
 * locate an optional source file in the unzipped archive folder
 *
 * Unlike spieler and vereine, verbaende are not required. Returns null
 * when no matching .sql or .txt exists. Snapshots vary the basename
 * (verbaende vs verband) and casing (VERBAND.TXT); $basenames lists
 * accepted stems, matched case-insensitively via scandir().
 *
 * @param string $folder
 * @param string|array $basenames basename without extension, e.g. verbaende
 * @return array|null ['format' => 'sql'|'txt', 'path' => string]
 */
function mf_ratings_memberstats_file_optional($folder, $basenames) {
	if (!is_array($basenames)) $basenames = [$basenames];
	$by_format = ['sql' => [], 'txt' => []];
	$pattern = '/^('.implode('|', array_map(function($basename) {
		return preg_quote($basename, '/');
	}, $basenames)).')\.(sql|txt)$/i';
	foreach (scandir($folder) as $entry) {
		if ($entry === '.' OR $entry === '..') continue;
		if (!preg_match($pattern, $entry, $match)) continue;
		$by_format[strtolower($match[2])][] = $folder.'/'.$entry;
	}
	if ($by_format['sql'])
		return ['format' => 'sql', 'path' => $by_format['sql'][0]];
	if ($by_format['txt'])
		return ['format' => 'txt', 'path' => $by_format['txt'][0]];
	return null;
}

/**
 * stream-load a DWZ .sql dump into a temporary table
 *
 * the SQL files are emitted in ISO-8859-1 with one REPLACE INTO statement
 * per line; we convert each line to UTF-8, swap the original table name
 * for the temporary one and run batched REPLACE statements inside a
 * transaction. Dummy rows (unnamed players) are skipped.
 *
 * spieler.sql exists in two column layouts, each with its own staging
 * table in configuration/memberstats.sql:
 *  - v1: ZPS first, 15 columns, Spielername_G (single- or double-quoted)
 *  - v2: PID first, 16 columns, bare integer (post 2024-05-22)
 *
 * The source table name in the dump is always dwz_spieler.
 *
 * @param string $filename source .sql file
 * @param string $target_table temporary table that should receive the rows
 * @param string $snapshot_date YYYY-MM-DD, for progress log entries
 * @return void
 */
function mf_ratings_memberstats_load_sql($filename, $target_table, $snapshot_date) {
	$kind = substr($target_table, strlen('temp_memberstats_'));
	if (str_starts_with($kind, 'spieler')) {
		$source_table = 'dwz_spieler';
		$spieler_version = str_ends_with($kind, '_v2') ? 'v2' : 'v1';
		$action = 'load_spieler';
	} else {
		$source_table = 'dwz_'.$kind;
		$spieler_version = null;
		$action = 'load_'.$kind;
	}
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

	$dummies = [];
	if ($spieler_version)
		$dummies = mf_ratings_memberstats_sql_dummies($source_table, $spieler_version);

	$rows_done = 0;
	$bytes_logged = 0;
	$tick = wrap_setting('ratings_memberstats_log_tick_bytes');
	$batch = [];
	$batch_size = mf_ratings_memberstats_load_batch_size();
	$failed = false;

	mf_ratings_memberstats_load_begin();

	while (($line = fgets($handle)) !== false) {
		if (!trim($line)) continue;
		$line = iconv('ISO-8859-1', 'UTF-8', $line);
		if (mf_ratings_memberstats_sql_dummy($line, $dummies)) continue;
		if ($kind === 'verbaende') {
			foreach (['dwz_verbaende', 'dwz_verband', 'verband'] as $source_name) {
				$line = str_replace('`'.$source_name.'`', $replace, $line);
			}
		} else {
			$line = str_replace($needle, $replace, $line);
		}
		$values = mf_ratings_memberstats_sql_values($line);
		if ($values === null) {
			if (!mf_ratings_memberstats_load_sql_flush($replace, $batch)) {
				$failed = true;
				break;
			}
			if (!wrap_db_query($line, E_USER_WARNING)) {
				$failed = true;
				break;
			}
		} else {
			$batch[] = $values;
			if (count($batch) >= $batch_size) {
				if (!mf_ratings_memberstats_load_sql_flush($replace, $batch)) {
					$failed = true;
					break;
				}
			}
		}
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

	if (!$failed AND !mf_ratings_memberstats_load_sql_flush($replace, $batch))
		$failed = true;
	mf_ratings_memberstats_load_end($failed);
	if ($failed)
		wrap_error(sprintf('memberstats: %s load failed for %s', $action, $filename), E_USER_ERROR);

	mf_ratings_memberstats_log($action, [
		'snapshot' => $snapshot_date,
		'bytes_done' => $bytes_total,
		'bytes_total' => $bytes_total,
		'rows_done' => $rows_done
	]);
}

/**
 * VALUES tuple from one REPLACE INTO line (after table-name rewrites)
 *
 * @param string $line
 * @return string|null e.g. (1,"AB01",…) without trailing semicolon
 */
function mf_ratings_memberstats_sql_values($line) {
	if (!preg_match('/^REPLACE INTO `\w+` VALUES\s+(\(.+\))\s*;?\s*$/', trim($line), $match))
		return null;
	return $match[1];
}

/**
 * flush a batch of VALUES tuples as one REPLACE INTO statement
 *
 * @param string $target_table including backticks
 * @param array $batch list of VALUES tuples, cleared on success
 * @return bool
 */
function mf_ratings_memberstats_load_sql_flush($target_table, &$batch) {
	if (!$batch) return true;
	$sql = sprintf('REPLACE INTO %s VALUES %s', $target_table, implode(',', $batch));
	$batch = [];
	return (bool) wrap_db_query($sql, E_USER_WARNING);
}

/**
 * dummy-row filter patterns for spieler.sql v1 and v2
 *
 * matches unnamed players (same as the name filter in
 * zzbrick_make/ratings-prepare-dwz.inc.php)
 *
 * @param string $source_table
 * @param string $version 'v1' or 'v2'
 * @return array list of regex patterns
 */
function mf_ratings_memberstats_sql_dummies($source_table, $version) {
	$prefix = '/^REPLACE INTO `'.preg_quote($source_table, '/').'` VALUES ';
	if ($version === 'v1') {
		return [
			$prefix.'\(\s*\'[^\']*\'\s*,\s*[^\)]*,\s*\'A\'\s*,\s*\'\',/',
			$prefix.'\(\s*\'[^\']*\'\s*,\s*[^\)]*,\s*\'P\'\s*,\s*\'\',/',
			$prefix.'\(\s*"[^"]*"\s*,\s*[^,]*,\s*"A"\s*,\s*"",/',
			$prefix.'\(\s*"[^"]*"\s*,\s*[^,]*,\s*"P"\s*,\s*"",/'
		];
	}
	return [
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
 * verbaende.txt / VERBAND.TXT matches dwz_verbaende:
 * Verband|LV|Uebergeordnet|Verbandname.
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
	if (str_starts_with($kind, 'spieler'))
		$action = 'load_spieler';
	elseif ($kind === 'vereine')
		$action = 'load_vereine';
	else
		$action = 'load_verbaende';

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
	$batch = [];
	$batch_size = mf_ratings_memberstats_load_batch_size();
	$insert_prefix = null;
	$failed = false;

	mf_ratings_memberstats_load_begin();

	while (($line = fgets($handle)) !== false) {
		$line = rtrim($line, "\r\n");
		if ($line === '') continue;
		$line = iconv('CP850', 'UTF-8//TRANSLIT', $line);
		$fields = explode('|', $line);

		if (str_starts_with($kind, 'spieler'))
			$sql = mf_ratings_memberstats_txt_spieler($fields, $target_table);
		elseif ($kind === 'vereine')
			$sql = mf_ratings_memberstats_txt_vereine($fields, $target_table);
		elseif ($kind === 'verbaende')
			$sql = mf_ratings_memberstats_txt_verbaende($fields, $target_table);
		else
			wrap_error(sprintf('memberstats: unknown staging table %s', $target_table), E_USER_ERROR);
		if (!$sql) continue;
		$row = mf_ratings_memberstats_txt_row($sql);
		if (!$row) {
			if (!mf_ratings_memberstats_load_txt_flush($insert_prefix, $batch)) {
				$failed = true;
				break;
			}
			if (!wrap_db_query($sql, E_USER_WARNING)) {
				$failed = true;
				break;
			}
		} else {
			if (!$insert_prefix) $insert_prefix = $row['prefix'];
			$batch[] = $row['values'];
			if (count($batch) >= $batch_size) {
				if (!mf_ratings_memberstats_load_txt_flush($insert_prefix, $batch)) {
					$failed = true;
					break;
				}
			}
		}
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

	if (!$failed AND !mf_ratings_memberstats_load_txt_flush($insert_prefix, $batch))
		$failed = true;
	mf_ratings_memberstats_load_end($failed);
	if ($failed)
		wrap_error(sprintf('memberstats: %s load failed for %s', $action, $filename), E_USER_ERROR);

	mf_ratings_memberstats_log($action, [
		'snapshot' => $snapshot_date,
		'bytes_done' => $bytes_total,
		'bytes_total' => $bytes_total,
		'rows_done' => $rows_done
	]);
}

/**
 * split a single-row INSERT from mf_ratings_memberstats_txt_* for batching
 *
 * @param string $sql
 * @return array|null prefix + values tuple, or null when unparsable
 */
function mf_ratings_memberstats_txt_row($sql) {
	if (!preg_match('/^(INSERT INTO `\w+` \(.+?\)) VALUES (\(.+\))$/', $sql, $match))
		return null;
	return ['prefix' => $match[1], 'values' => $match[2]];
}

/**
 * flush a batch of INSERT VALUES tuples as one statement
 *
 * @param string|null $insert_prefix INSERT INTO … (columns)
 * @param array $batch list of (…) tuples, cleared on success
 * @return bool
 */
function mf_ratings_memberstats_load_txt_flush($insert_prefix, &$batch) {
	if (!$batch OR !$insert_prefix) return true;
	$sql = $insert_prefix.' VALUES '.implode(',', $batch);
	$batch = [];
	return (bool) wrap_db_query($sql, E_USER_WARNING);
}

/**
 * rows per batched REPLACE/INSERT during snapshot staging loads
 *
 * @return int
 */
function mf_ratings_memberstats_load_batch_size() {
	$size = (int) wrap_setting('ratings_memberstats_load_batch_size');
	if ($size < 1) $size = 500;
	return $size;
}

/**
 * begin a transaction for a staging-table loader
 *
 * @return void
 */
function mf_ratings_memberstats_load_begin() {
	$sql = 'START TRANSACTION';
	wrap_db_query($sql, E_USER_WARNING);
}

/**
 * commit or roll back a staging-table loader transaction
 *
 * @param bool $failed
 * @return void
 */
function mf_ratings_memberstats_load_end($failed) {
	if ($failed)
		$sql = 'ROLLBACK';
	else
		$sql = 'COMMIT';
	wrap_db_query($sql, E_USER_WARNING);
}

/**
 * turn one parsed spieler.txt row into an INSERT statement against the
 * temporary table; skip rows without a Spielername
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
	if ($zps === '') return '';

	$sql = sprintf('INSERT INTO `%s` (`ZPS`, `Mgl_Nr`, `Status`, `Spielername`'
		.', `Geschlecht`, `Spielberechtigung`, `Geburtsjahr`, `Letzte_Auswertung`'
		.', `DWZ`, `DWZ_Index`, `FIDE_Elo`, `FIDE_Titel`, `FIDE_ID`, `FIDE_Land`)'
		.' VALUES ("%s", %s, %s, "%s", %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
		$target_table,
		wrap_db_escape($zps),
		mf_ratings_memberstats_txt_string($mgl_nr),
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
 * turn one parsed verbaende.txt row into an INSERT statement against the
 * temporary table
 *
 * @param array $fields
 * @param string $target_table
 * @return string SQL, or '' if the row should be skipped
 */
function mf_ratings_memberstats_txt_verbaende($fields, $target_table) {
	if (count($fields) < 4) return '';
	$verband = trim($fields[0]);
	$lv = $fields[1];
	$uebergeordnet = trim($fields[2]);
	$verbandname = $fields[3];
	if ($verband === '' OR $verbandname === '') return '';
	$sql = sprintf('INSERT INTO `%s` (`Verband`, `LV`, `Uebergeordnet`, `Verbandname`)'
		.' VALUES ("%s", "%s", "%s", "%s")',
		$target_table,
		wrap_db_escape($verband),
		wrap_db_escape($lv),
		wrap_db_escape($uebergeordnet),
		wrap_db_escape($verbandname)
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
 * create `contacts` + `contacts_identifiers` for federations from the
 * snapshot's verbaende staging table
 *
 * Only called when the snapshot ships verbaende.sql or verbaende.txt.
 * Parent links use Uebergeordnet from the same table (two-pass: create
 * contacts first, then contacts_contacts).
 *
 * @param string $snapshot_date YYYY-MM-DD, for progress log entries
 * @return void
 */
function mf_ratings_memberstats_verbaende($snapshot_date) {
	$sql = 'SELECT v.Verband AS verband_code, v.Verbandname AS verband_name
			, TRIM(v.Uebergeordnet) AS parent_code
		FROM temp_memberstats_verbaende v
		LEFT JOIN contacts_identifiers ci
			ON ci.identifier = v.Verband
			AND ci.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		WHERE ISNULL(ci.contact_identifier_id)
		AND v.Verband <> ""
		AND v.Verbandname <> ""';
	$missing = wrap_db_fetch($sql, 'verband_code');
	if (!$missing) return;

	$created = [];
	$contacts_created = 0;
	foreach ($missing as $verband_code => $verband) {
		$contact = [
			'contact_category_id' => wrap_category_id('contact/federation'),
			'contact' => $verband['verband_name']
		];
		$contact_id = zzform_insert('contacts', $contact);
		if (!$contact_id) {
			wrap_error(sprintf(
				'memberstats: unable to insert federation for %s (%s)',
				$verband_code, $verband['verband_name']
			));
			continue;
		}
		mf_ratings_memberstats_log('verband', [
			'snapshot' => $snapshot_date,
			'verband_code' => $verband_code,
			'contact_id' => $contact_id,
			'contact' => $verband['verband_name']
		]);
		$contacts_created++;
		$identifier = [
			'contact_id' => $contact_id,
			'identifier' => $verband_code,
			'identifier_category_id' => wrap_category_id('identifiers/pass_dsb'),
			'current' => 'yes'
		];
		zzform_insert('contacts_identifiers', $identifier, E_USER_WARNING);
		$created[$verband_code] = [
			'contact_id' => $contact_id,
			'parent_code' => $verband['parent_code'] ?? ''
		];
	}

	foreach ($created as $verband_code => $verband) {
		mf_ratings_memberstats_club_parent_link(
			$verband['contact_id'],
			$verband_code,
			$verband['parent_code']
		);
	}

	mf_ratings_memberstats_log('verbaende_done', [
		'snapshot' => $snapshot_date,
		'contacts_created' => $contacts_created
	]);
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
 * `contacts_contacts` (relation/member, published). The parent pass_dsb
 * code is taken from `temp_memberstats_vereine.Verband` (e.g. club `E3401`
 * → parent `E30`), then resolved in `contacts_identifiers` without the
 * `current` filter.
 *
 * @param string $snapshot_date YYYY-MM-DD, for progress log entries
 * @param string $spieler_table temp_memberstats_spieler_v1 or _v2
 * @return void
 */
function mf_ratings_memberstats_clubs($snapshot_date, $spieler_table) {
	$collapse = 'IF(
			FIND_IN_SET(SUBSTRING(s.ZPS, 1, 1),
				"/*_SETTING ratings_dsb_federations_are_clubs _*/")
			, SUBSTRING(s.ZPS, 1, 3)
			, IF(SUBSTRING(s.ZPS, 4, 2) = "00",
				SUBSTRING(s.ZPS, 1, 3), s.ZPS)
		)';
	$sql = 'SELECT codes.code AS club_code, v.Vereinname AS club_name
			, v.Verband AS parent_code
		FROM (
			SELECT DISTINCT '.$collapse.' AS code
			FROM `%s` s
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
	$sql = sprintf($sql, $spieler_table);
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
		mf_ratings_memberstats_club_parent_link(
			$contact_id,
			$club_code,
			$club['parent_code'] ?? ''
		);
	}

	mf_ratings_memberstats_log('clubs_done', [
		'snapshot' => $snapshot_date,
		'contacts_created' => $contacts_created
	]);
}

/**
 * link a new club contact to its federation parent in contacts_contacts
 *
 * Parent code comes from temp_memberstats_vereine.Verband in the snapshot
 * (not from a prefix of the club code). The parent contact_id is looked
 * up in contacts_identifiers without filtering on `current`.
 *
 * @param int $contact_id new club contact
 * @param string $club_code pass_dsb identifier stored on the club
 * @param string $parent_code pass_dsb identifier of the federation (Verband)
 * @return void
 */
function mf_ratings_memberstats_club_parent_link($contact_id, $club_code, $parent_code) {
	if ($parent_code === '' OR $parent_code === $club_code) return;

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
 * insert one memberstats row per player from the spieler staging table
 *
 * @param string $snapshot_date YYYY-MM-DD
 * @param string $spieler_table temp_memberstats_spieler_v1 or _v2
 * @return void
 */
function mf_ratings_memberstats_insert($snapshot_date, $spieler_table) {
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
		FROM `%s` s
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
	$sql = sprintf($sql, wrap_db_escape($snapshot_date), $spieler_table);
	wrap_db_query($sql);
}
