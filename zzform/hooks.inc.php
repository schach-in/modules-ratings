<?php 

/**
 * ratings module
 * functions that are called before or after changing a record
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * hook that allows to add persons from the DWZ database of the German chess federation
 *
 * @param array $ops
 * @return array
 */
function mf_ratings_person_hook($ops) {
	// no data = unable to add person
	if (empty($ops['record_new'][0]['contact_id'])) {
		zz_error_exit(true);
		return false;
	}
	// already a contact ID?
	if (!strstr($ops['record_new'][0]['contact_id'], '-')) {
		return [];
	}

	list($zps, $mgl_nr) = explode('-', $ops['record_new'][0]['contact_id']);
	if (!brick_access_rights('Webmaster') AND !empty($ops['record_new'][0]['club_contact_id'])) {
		$club_contact_id = $ops['record_new'][0]['club_contact_id'];
		$sql = 'SELECT DISTINCT ZPS
			FROM dwz_spieler
			LEFT JOIN contacts_identifiers ok
				ON dwz_spieler.ZPS = ok.identifier
			WHERE contact_id = %d
			AND ok.current = "yes"';
		$sql = sprintf($sql, $club_contact_id);
		$zps_aus_db = wrap_db_fetch($sql, '', 'single value');
		if ($zps != $zps_aus_db)
			wrap_error(sprintf('Falsche Übermittlung: ZPS vom Verein weicht von ZPS der Person ab: %s, Verein: %s',
				$ops['record_new'][0]['contact_id'], $zps_aus_db), E_USER_ERROR
			);
	}
	wrap_include_files('zzform/editing', 'ratings');
	$spieler = mf_ratings_player_data_dsb($ops['record_new'][0]['contact_id']);
	$contact_id = mf_ratings_person_add($spieler);
	
	$replace['record_replace'][0]['contact_id'] = $contact_id;
	return $replace;
}
