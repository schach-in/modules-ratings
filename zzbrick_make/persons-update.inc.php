<?php

/**
 * ratings module
 * update person records with changes from FIDE and DSB data
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2015, 2019-2022, 2024-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 *
 * Variables
 * translate_pot = admin
 */


function mod_ratings_make_persons_update() {
	wrap_setting('cache', false);

	if ($_SERVER['REQUEST_METHOD'] === 'POST' AND !array_key_exists('sequential', $_POST)) {
		wrap_include('file', 'zzwrap');
		wrap_file_log('ratings/persons-update', 'delete', []);
		wrap_job(wrap_path('ratings_persons_update'), [
			'sequential' => 1,
			'trigger' => 1,
			'job_logfile_result' => 'ratings/persons-update',
		]);
		$page['text'] = wrap_text('Background job queued.');
		return $page;
	}

	$data = mod_ratings_make_persons_update_dsb();
	if (!empty($data['job_continue'])) {
		$page['extra']['job_continue'] = $data['job_continue'];
		unset($data['job_continue']);
	}
	$page['query_strings'][] = 'zps';
	$page['query_strings'][] = 'mgl';
	$page['text'] = wrap_template('persons-update', $data);
	return $page;
}

/**
 * check DSB data for differences
 *
 * @return array
 */
function mod_ratings_make_persons_update_dsb() {
	list($zps, $mgl) = mf_ratings_persons_update_cursor();
	$after = '';
	if ($zps AND $mgl) {
		$after = sprintf(
			' AND (ZPS, Mgl_Nr) > ("%s", %d)',
			wrap_db_escape($zps),
			(int) $mgl
		);
	}

	$sql = 'SELECT NU_ID AS player_id_nuliga_person
			, CONCAT(
				ZPS, "-", IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr)
			) AS player_pass_dsb
			, ZPS, Mgl_Nr
			, FIDE_ID AS player_id_fide
			, IF(Status = "P", 1, NULL) AS player_pass_dsb_inactive
			, Geburtsjahr AS birth_year
			, SUBSTRING_INDEX(Spielername, ",", 1) AS last_name
			, SUBSTRING_INDEX(SUBSTRING_INDEX(Spielername, ",", 2), ",", -1) AS first_name
			, (CASE Geschlecht
				WHEN "M" THEN "male"
				WHEN "W" THEN "female"
				ELSE "" END
			) AS sex
			, nuliga.contact_id AS contact_id_nuliga
			, pass.contact_id AS contact_id_pass
			, fide.contact_id AS contact_id_fide
		FROM dwz_spieler
		LEFT JOIN contacts_identifiers AS nuliga
			ON nuliga.identifier = dwz_spieler.NU_ID
			AND nuliga.identifier_category_id = /*_ID categories identifiers/id-nuliga-person _*/
			AND dwz_spieler.NU_ID != ""
		LEFT JOIN contacts_identifiers AS pass
			ON pass.identifier = CONCAT(ZPS, "-", IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr))
			AND pass.identifier_category_id = /*_ID categories identifiers/pass-dsb _*/
		LEFT JOIN contacts_identifiers AS fide
			ON fide.identifier = dwz_spieler.FIDE_ID
			AND fide.identifier_category_id = /*_ID categories identifiers/id-fide _*/
			AND dwz_spieler.FIDE_ID > 0
		WHERE (!ISNULL(nuliga.contact_id)
			OR !ISNULL(pass.contact_id)
			OR !ISNULL(fide.contact_id))
		'.$after.'
		ORDER BY ZPS, Mgl_Nr
		LIMIT /*_SETTING ratings_persons_update_limit _*/';
	$data = wrap_db_fetch($sql, 'player_pass_dsb');
	$continue = '';
	if (count($data) >= wrap_setting('ratings_persons_update_limit')
		AND $_SERVER['REQUEST_METHOD'] === 'POST'
		AND array_key_exists('sequential', $_POST)
	) {
		$last = end($data);
		wrap_include('file', 'zzwrap');
		wrap_file_log('ratings/persons-update', 'write', [
			time(),
			'continue',
			json_encode([
				'zps' => $last['ZPS'],
				'mgl' => (int) $last['Mgl_Nr'],
			]),
		]);
		$continue = true;
	}
	$contacts = [];
	foreach ($data as $index => $line) {
		$line_contact_ids = array_unique(array_filter(
			[$line['contact_id_nuliga'], $line['contact_id_pass'], $line['contact_id_fide']]
		));
		if (count($line_contact_ids) > 1) {
			wrap_error([
				'Mismatch of contact IDs found for %s %s',
				['values' => [$line['first_name'], $line['last_name']], 'data' => $line]
			], E_USER_WARNING);
			continue;
		}
		$contact_id = reset($line_contact_ids);
		if (!array_key_exists($contact_id, $contacts)) {
			$contacts[$contact_id] = [
				'contact_id' => $contact_id,
				'last_name' => $line['last_name'],
				'first_name' => $line['first_name'],
				'birth_year' => $line['birth_year'],
				'sex' => $line['sex'],
			];
		}
		mf_ratings_persons_identifiers($contacts[$contact_id], $line, 'id-nuliga-person');
		mf_ratings_persons_identifiers($contacts[$contact_id], $line, 'id-fide');
		mf_ratings_persons_identifiers($contacts[$contact_id], $line, 'pass-dsb');
	}

	if (!$contacts) {
		if (!$continue) return [];
		return ['job_continue' => $continue];
	}

	// get contact identifiers
	$sql = 'SELECT contact_identifier_id, contact_id
		, identifier_category_id, identifier, current
		FROM contacts_identifiers
		WHERE contact_id IN (%s)';
	$sql = sprintf($sql, implode(',', array_keys($contacts)));
	$identifiers = wrap_db_fetch($sql, ['contact_id', 'contact_identifier_id']);

	$actions = [
		'clear' => [],
		'update' => [],
		'insert' => []
	];
	foreach ($contacts as $contact_id => $contact) {
		foreach ($identifiers[$contact_id] ?? [] as $idf) {
			if (empty($contact['identifiers'][$idf['identifier_category_id']][$idf['identifier']])) {
				// check for current
				if ($idf['current']) {
					foreach ($contact['identifiers'][$idf['identifier_category_id']] ?? [] as $new) {
						if (!$new['current']) continue;
						$idf['current'] = null;
						$actions['clear'][] = $idf;
					}
				}
				continue;
			}
			$new = $contact['identifiers'][$idf['identifier_category_id']][$idf['identifier']];
			if ($idf['current'] !== $new['current']) {
				$this_action = $new['current'] ? 'update' : 'clear';
				$actions[$this_action][] = [
					'contact_identifier_id' => $idf['contact_identifier_id'],
					'contact_id' => $contact_id,
					'identifier_category_id' => $idf['identifier_category_id'], // for log only
					'identifier' => $idf['identifier'], // for log only
					'current' => $new['current']
				];
			}
			$contact['identifiers'][$idf['identifier_category_id']][$idf['identifier']]['ignore'] = true;
		}
		foreach ($contact['identifiers'] ?? [] as $category) {
			foreach ($category as $line) {
				if (!empty($line['ignore'])) continue;
				$actions['insert'][] = $line;
			}
		}
	}
	$log = [];
	foreach ($actions as $action => $lines) {
		foreach ($lines as $line) {
			if ($_SERVER['REQUEST_METHOD'] === 'POST') {
				if ($action === 'insert')
					$result = zzform_insert('contacts-identifiers', $line);
				else
					$result = zzform_update('contacts-identifiers', $line);
			} else {
				$result = true;
			}
			$log[] = mf_ratings_persons_log($action, $line, $result, $contacts);
		}
	}
	if ($continue)
		$log['job_continue'] = $continue;
	return $log;
}

/**
 * position for the next batch
 *
 * The job repeats the same URL. The last ZPS and member number are in the log.
 * A preview reads them from the query string.
 *
 * @return array
 */
function mf_ratings_persons_update_cursor() {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' OR !array_key_exists('sequential', $_POST))
		return [$_GET['zps'] ?? '', $_GET['mgl'] ?? ''];

	wrap_include('file', 'zzwrap');
	$rows = wrap_file_log('ratings/persons-update');
	if (!$rows) return ['', ''];
	$last = end($rows);
	if (($last['action'] ?? '') !== 'continue') return ['', ''];
	$cursor = json_decode($last['result'] ?? '', true);
	if (!is_array($cursor)) return ['', ''];
	return [$cursor['zps'] ?? '', (string) ($cursor['mgl'] ?? '')];
}

/**
 * create a record for every identifier
 *
 * @param array $contact
 * @param array $line
 * @param string $path
 */
function mf_ratings_persons_identifiers(&$contact, $line, $path) {
	$key = sprintf('player_%s', str_replace('-', '_', $path));
	if (empty($line[$key])) return;
	$category_id = wrap_category_id('identifiers/'.$path);
	$current = empty($line[$key.'_inactive']) ? 'yes' : NULL;
	if ($current) {
		foreach ($contact['identifiers'][$category_id] ?? [] as $existing_identifier => $existing) {
			if ((string)$existing_identifier === (string)$line[$key]) continue;
			if (!$existing['current']) continue;
			$current = NULL;
			break;
		}
	}
	$contact['identifiers'][$category_id][$line[$key]] = [
		'contact_id' => $contact['contact_id'],
		'identifier_category_id' => $category_id,
		'identifier' => $line[$key],
		'current' => $current
	];
}

/**
 * log one identifier change
 *
 * @param string $action clear, update or insert
 * @param array $line
 * @param mixed $result
 * @param array $contacts
 * @return array
 */
function mf_ratings_persons_log($action, $line, $result, $contacts) {
	static $categories = [];
	if (!$categories) {
		$sql = 'SELECT category_id, category
			FROM categories
			WHERE category_id IN (
				/*_ID categories identifiers/id-nuliga-person _*/
				, /*_ID categories identifiers/id-fide _*/
				, /*_ID categories identifiers/pass-dsb _*/
			)';
		$categories = wrap_db_fetch($sql, 'category_id');
	}

	$values = [
		$categories[$line['identifier_category_id']]['category'] ?? '',
		$line['identifier']
	];
	$active = !empty($line['current']);
	$apply = ($_SERVER['REQUEST_METHOD'] === 'POST');
	$failed = $apply && ($action === 'insert' ? !$result : is_null($result));
	if ($action === 'insert' && !$apply && $active)
		$text = wrap_text('%s %s would be added as active.', ['values' => $values]);
	elseif ($action === 'insert' && !$apply)
		$text = wrap_text('%s %s would be added as inactive.', ['values' => $values]);
	elseif ($action === 'insert' && $failed)
		$text = wrap_text('%s %s could not be added.', ['values' => $values]);
	elseif ($action === 'insert' && $active)
		$text = wrap_text('%s %s added as active.', ['values' => $values]);
	elseif ($action === 'insert')
		$text = wrap_text('%s %s added as inactive.', ['values' => $values]);
	elseif (!$apply && $active)
		$text = wrap_text('%s %s would be set to active.', ['values' => $values]);
	elseif (!$apply)
		$text = wrap_text('%s %s would be set to inactive.', ['values' => $values]);
	elseif ($failed && $active)
		$text = wrap_text('%s %s could not be set to active.', ['values' => $values]);
	elseif ($failed)
		$text = wrap_text('%s %s could not be set to inactive.', ['values' => $values]);
	elseif ($active)
		$text = wrap_text('%s %s set to active.', ['values' => $values]);
	else
		$text = wrap_text('%s %s set to inactive.', ['values' => $values]);
	$note = [
		'note' => $text,
		'first_name' => $contacts[$line['contact_id']]['first_name'],
		'last_name' => $contacts[$line['contact_id']]['last_name']
	];
	if ($failed)
		$note['error'] = true;
	return $note;
}
