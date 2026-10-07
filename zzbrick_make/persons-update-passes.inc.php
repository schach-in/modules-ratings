<?php

/**
 * ratings module
 * deactivate DSB passes that are no longer in the DWZ list
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 *
 * Variables
 * translate_pot = admin
 */


function mod_ratings_make_persons_update_passes() {
	wrap_setting('cache', false);

	if ($_SERVER['REQUEST_METHOD'] === 'POST' AND !array_key_exists('sequential', $_POST)) {
		wrap_job(wrap_path('ratings_persons_update_passes'), [
			'sequential' => 1,
			'trigger' => 1,
		]);
		$page['text'] = wrap_text('Background job queued.');
		return $page;
	}

	$data = mf_ratings_persons_update_passes();
	$page['text'] = wrap_template('persons-update', $data);
	return $page;
}

/**
 * current DSB passes whose number is not in dwz_spieler
 *
 * The person update batches only see passes that still have a row there.
 *
 * @return array
 */
function mf_ratings_persons_update_passes() {
	wrap_include('persons-log', 'ratings');
	$sql = 'SELECT contacts_identifiers.contact_identifier_id
			, contacts_identifiers.contact_id
			, contacts_identifiers.identifier_category_id
			, contacts_identifiers.identifier
			, persons.first_name
			, persons.last_name
		FROM contacts_identifiers
		LEFT JOIN persons USING (contact_id)
		LEFT JOIN dwz_spieler
			ON dwz_spieler.ZPS = SUBSTRING_INDEX(contacts_identifiers.identifier, "-", 1)
			AND dwz_spieler.Mgl_Nr = SUBSTRING_INDEX(contacts_identifiers.identifier, "-", -1)
		WHERE contacts_identifiers.identifier_category_id = /*_ID categories identifiers/pass-dsb _*/
		AND contacts_identifiers.current = "yes"
		AND ISNULL(dwz_spieler.ZPS)';
	$rows = wrap_db_fetch($sql, 'contact_identifier_id');
	$contacts = [];
	$log = [];
	foreach ($rows as $row) {
		$contacts[$row['contact_id']] = [
			'first_name' => $row['first_name'] ?? '',
			'last_name' => $row['last_name'] ?? '',
		];
		$line = [
			'contact_identifier_id' => $row['contact_identifier_id'],
			'contact_id' => $row['contact_id'],
			'identifier_category_id' => $row['identifier_category_id'],
			'identifier' => $row['identifier'],
			'current' => null,
		];
		if ($_SERVER['REQUEST_METHOD'] === 'POST')
			$result = zzform_update('contacts-identifiers', $line);
		else
			$result = true;
		$log[] = mf_ratings_persons_log('clear', $line, $result, $contacts);
	}
	return $log;
}
