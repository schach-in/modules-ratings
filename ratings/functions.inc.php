<?php

/**
 * ratings module
 * common functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022, 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * get data for top rating lists
 *
 * @param array $conditions WHERE conditions for query
 * @param int $limit (optional, defaults to 1000)
 * @return array
 */
function mf_ratings_ratinglist($conditions, $limit = 1000) {
	$sql = 'SELECT PID, Spielername AS spielername, Geschlecht AS geschlecht
			, Letzte_Auswertung AS letzte_auswertung
			, DWZ AS dwz, DWZ_Index AS dwz_index
			, contact as club
			, contacts.identifier AS club_identifier
			, IFNULL(contacts_identifiers.identifier, federation_identifiers.identifier) AS zps_code
			, player_id AS player_id_fide
			, title, title_women, title_other
			, standard_rating, rapid_rating, blitz_rating, federation
			, IF(Status = "P", 1, NULL) as passive
	    FROM dwz_spieler
	    LEFT JOIN fide_players
	    	ON dwz_spieler.fide_id = fide_players.player_id
	    LEFT JOIN contacts_identifiers
	    	ON contacts_identifiers.identifier = dwz_spieler.ZPS
	    	AND contacts_identifiers.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
	    	AND contacts_identifiers.current = "yes"
	    LEFT JOIN contacts_identifiers federation_identifiers
	    	ON federation_identifiers.identifier = SUBSTRING(dwz_spieler.ZPS, 1, 3)
	    	AND federation_identifiers.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
	    	AND federation_identifiers.current = "yes"
	    LEFT JOIN contacts
	    	ON contacts.contact_id = IFNULL(contacts_identifiers.contact_id, federation_identifiers.contact_id)
	    WHERE %s
	    ORDER BY IFNULL(DWZ, FIDE_Elo) DESC, FIDE_Elo DESC, PID, Status
	    LIMIT 0, %d
	';
	$sql = sprintf($sql
		, $conditions ? implode(' AND ', $conditions) : ''
		, $limit
	);
	$data = wrap_db_fetch($sql, '_dummy_', 'numeric');
	$players = [];
	foreach ($data as $index => $line) {
		$line = mf_ratings_fidetitle($line);
		if (array_key_exists($line['PID'], $players)) {
			$players[$line['PID']]['memberships'][] = $line;
		} else {
			$players[$line['PID']] = $line;
			$contact = explode(',', $line['spielername']);
			$contact = array_reverse($contact);
			$players[$line['PID']]['contact'] = implode(' ', $contact);
		}
	}
	return $players;
}

/**
 * get FIDE title for players
 *
 * @param array $data with field 'contact_id'
 * @return array
 * @todo read women’s title as well and check which one is higher
 */
function mf_ratings_titles($data) {
	$contact_ids = [];
	foreach ($data as $line)
		$contact_ids[] = $line['contact_id'];

	$sql = 'SELECT contact_id, fide_players.title, fide_players.title_women
		FROM fide_players
		LEFT JOIN contacts_identifiers
			ON fide_players.player_id = contacts_identifiers.identifier
		WHERE contacts_identifiers.current = "yes"
		AND contacts_identifiers.identifier_category_id = /*_ID categories identifiers/id_fide _*/
		AND contacts_identifiers.contact_id IN (%s)
		AND NOT ISNULL(fide_players.title)
	';
	$sql = sprintf($sql, implode(',', $contact_ids));
	$titles = wrap_db_fetch($sql, 'contact_id');

	foreach ($data as $index => $line)
		$data[$index] += mf_ratings_fidetitle($titles[$line['contact_id']] ?? []);
	return $data;
}

/**
 * get ratings for German Chess Federation (DSB) 
 *
 * @param array $contact_ids
 * @return array
 */
function mf_ratings_rating_dsb($contact_ids) {
	$sql = 'SELECT contact_id
			, DWZ AS dwz
			, FIDE_Elo AS elo
			, REPLACE(Spielername, ",", ", ") AS contact_last_first
		FROM dwz_spieler
		LEFT JOIN contacts_identifiers
			ON contacts_identifiers.identifier = CONCAT(ZPS, "-", Mgl_Nr)
			AND contacts_identifiers.current = "yes"
			AND contacts_identifiers.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		WHERE contact_id IN (%s)';
	$sql = sprintf($sql, implode(',', $contact_ids));
	return wrap_db_fetch($sql, 'contact_id');
}

/**
 * get ratings for FIDE
 *
 * @param array $contact_ids
 * @return array
 */
function mf_ratings_rating_fide($contact_ids) {
	$sql = 'SELECT contact_id
			, standard_rating AS elo
			, player AS contact_last_first
		FROM fide_players
		LEFT JOIN contacts_identifiers
			ON contacts_identifiers.identifier = player_id
			AND contacts_identifiers.current = "yes"
			AND contacts_identifiers.identifier_category_id = /*_ID categories identifiers/id_fide _*/
		WHERE contact_id IN (%s)';
	$sql = sprintf($sql, implode(',', $contact_ids));
	return wrap_db_fetch($sql, 'contact_id');
}
