<?php 

/**
 * ratings module
 * contact functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * get rating and club data for contact profile
 *
 * @param array $data
 * @param array $ids
 * @return array
 */
function mf_ratings_contact($data, $ids) {
	$contact_id = key($data);
	// ratings
	$data[$contact_id] += mf_ratings_by_contact($contact_id);
	// clubs
	$data[$contact_id]['clubs'] = mf_ratings_clubs_from_code($data[$contact_id]['identifiers'] ?? []);
	
	$data['templates']['contact_5'][] = 'contact-ratings-clubs';
	$data['templates']['contact_6'][] = 'contact-ratings';
	return $data;
}

/**
 * get club data for contact profile
 *
 * @param array $data
 * @param array $ids
 * @return array
 */
function mf_ratings_clubs_from_code($identifiers) {
	if (!$identifiers) return [];
	
	$club_codes = [];
	$current_club = NULL;
	foreach ($identifiers as $identifier) {
		if ($identifier['path'] !== 'pass_dsb') continue;
		$club_code = substr($identifier['identifier'], 0 , strpos($identifier['identifier'], '-'));
		$club_codes[] = $club_code;
		if ($identifier['current']) $current_club = $club_code;
	}
	$club_codes = array_unique($club_codes);

	$sql = 'SELECT contact_id, contact, contacts.identifier
			, contacts_identifiers.identifier
		FROM contacts
		LEFT JOIN contacts_identifiers USING (contact_id)
		WHERE contacts_identifiers.identifier IN ("%s")';
	$sql = sprintf($sql, implode('","', $club_codes));
	$clubs = wrap_db_fetch($sql, 'contact_id');
	
	if ($current_club) {
		foreach ($clubs as $contact_id => $contact) {
			if ($contact['identifier'] !== $current_club) continue;
			$clubs[$contact_id]['current'] = true;
		}
	}
	
	return $clubs;
}
