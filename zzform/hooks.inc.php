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
	if (!strstr($ops['record_new'][0]['contact_id'], '-'))
		return [];

	mf_ratings_person_hook_restrict_to_club($ops['record_new'][0]);
	wrap_include('zzform/editing', 'ratings');
	$player = mf_ratings_players_dsb(
		['player_pass_dsb' => $ops['record_new'][0]['contact_id'],
		'include_passive' => true]
	);
	$contact_id = mf_ratings_person_add($player);
	
	$replace['record_replace'][0]['contact_id'] = $contact_id;
	return $replace;
}

/**
 * if persons are restricted to club, check if this is true
 *
 * @todo check if this function really is necessary
 * @param
 * @return bool
 */
function mf_ratings_person_hook_restrict_to_club($record) {
	$event_rights = sprintf('event_id:%d', $record['event_id']);
	if (wrap_access('tournaments_teams_registrations', $event_rights)) return true;
	if (empty($record['club_contact_id'])) return true;

	// JOIN dwz_vereine because this data always represents the current state
	$sql = 'SELECT ZPS
		FROM dwz_vereine
		LEFT JOIN contacts_identifiers
			ON dwz_vereine.ZPS = contacts_identifiers.identifier
		WHERE contacts_identifiers.contact_id = %d
		AND contacts_identifiers.current = "yes"';
	/* simpler query
	$sql = 'SELECT identifier
		FROM contacts_identifiers
		WHERE contact_id = %d
		AND current = "yes"';
	*/		
	$sql = sprintf($sql, $record['club_contact_id']);
	$club_code_from_id = wrap_db_fetch($sql, '', 'single value');
	list($club_code, $membership_no) = explode('-', $record['contact_id']);
	if ($club_code == $club_code_from_id) return true;

	wrap_error(wrap_text(
		'Falsche Übermittlung: ZPS vom Verein weicht von ZPS der Person ab: %s, Verein: %s',
		$record['contact_id'], $club_code_from_id), E_USER_ERROR
	);
}
