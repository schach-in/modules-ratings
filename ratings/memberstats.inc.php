<?php

/**
 * ratings module
 * memberstats functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * drop a memberstats staging table if it exists
 *
 * `DROP TABLE IF EXISTS` would do this in one statement, but MySQL still
 * raises a Note-level warning when the table is missing, and the wrap_db
 * layer surfaces every warning as an E_USER_WARNING log entry. Checking
 * existence first keeps the log clean.
 *
 * @param string $table
 * @return void
 */
function mf_ratings_memberstats_drop($table) {
	$sql = sprintf('SHOW TABLES LIKE "%s"', wrap_db_escape($table));
	$exists = wrap_db_fetch($sql, '_dummy_', 'single value');
	if (!$exists) return;
	$sql = sprintf('DROP TABLE `%s`', $table);
	wrap_db_query($sql);
}

/**
 * append one entry to the memberstats import log
 *
 * Thin wrapper around wrap_file_log() so the worker brick and the
 * streaming loaders share one log format. The log file lives at
 * _logs/ratings/memberstats.log; format is the same line-oriented
 * `<timestamp> <action> <json-result>` triple used by the Elo sync log.
 * The wrap_file_log() helper auto-creates the directory, honours the
 * `ratings_logfile_memberstats` setting and skips writes when the log
 * is disabled, so callers don't have to guard.
 *
 * @param string $action machine-friendly phase / event name
 * @param array $payload further details, json-encoded into the result field
 * @return void
 */
function mf_ratings_memberstats_log($action, $payload = []) {
	wrap_include('file', 'zzwrap');
	wrap_file_log('ratings/memberstats', 'write', [time(), $action, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}
