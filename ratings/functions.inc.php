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
	$sql = 'SELECT Spielername AS spielername, Geschlecht AS geschlecht
			, Letzte_Auswertung AS letzte_auswertung
			, DWZ AS dwz, DWZ_Index AS dwz_index
			, contact as club
			, contacts.identifier AS club_identifier
			, ZPS AS zps_code
			, player_id AS fide_id
			, title, title_women, title_other
			, standard_rating, rapid_rating, blitz_rating, federation
			, IF(Status = "P", 1, NULL) as passive
	    FROM dwz_spieler
	    LEFT JOIN fide_players
	    	ON dwz_spieler.fide_id = fide_players.player_id
	    LEFT JOIN contacts_identifiers
	    	ON contacts_identifiers.identifier = dwz_spieler.ZPS
	    	AND contacts_identifiers.identifier_category_id = %d
	    	AND contacts_identifiers.current = "yes"
	    LEFT JOIN contacts USING (contact_id)
	    WHERE %s
	    ORDER BY DWZ DESC, FIDE_Elo DESC
	    LIMIT 0, %d
	';
	$sql = sprintf($sql
		, wrap_category_id('identifiers/zps')
		, $conditions ? implode(' AND ', $conditions) : ''
		, $limit
	);
	$data = wrap_db_fetch($sql, '_dummy_', 'numeric');
	foreach ($data as $index => $line) {
		$contact = explode(',', $line['spielername']);
		$contact = array_reverse($contact);
		$data[$index]['contact'] = implode(' ', $contact);
	}
	return $data;
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

	$titles = [];
	// check in FIDE database, German database does not have all titles
	// why? no players without membership in German federation
	// players that have a new FIDE ID
	$sql = 'SELECT contact_id, fide_players.title
		FROM fide_players
		LEFT JOIN contacts_identifiers
			ON fide_players.player_id = contacts_identifiers.identifier
		WHERE contacts_identifiers.current = "yes"
		AND contacts_identifiers.identifier_category_id = %d
		AND contacts_identifiers.contact_id IN (%s)
		AND NOT ISNULL(fide_players.title)
	';
	$sql = sprintf($sql
		, wrap_category_id('identifiers/fide-id')
		, implode(',', $contact_ids)
	);
	$titles += wrap_db_fetch($sql, 'contact_id', 'key/value');

	// probably unnecessary query since all titles should be in FIDE database
	$sql = 'SELECT contact_id, dwz_spieler.FIDE_Titel
		FROM dwz_spieler
		LEFT JOIN contacts_identifiers
			ON contacts_identifiers.identifier = CONCAT(dwz_spieler.ZPS, "-", dwz_spieler.Mgl_Nr)
		WHERE contacts_identifiers.current = "yes"
		AND contacts_identifiers.identifier_category_id = %d
		AND contacts_identifiers.contact_id IN (%s)
		AND NOT ISNULL(dwz_spieler.FIDE_Titel)
	';
	$sql = sprintf($sql
		, wrap_category_id('identifiers/zps')
		, implode(',', $contact_ids)
	);
	$titles += wrap_db_fetch($sql, 'contact_id', 'key/value');

	foreach ($data as $index => $line) {
		if (!array_key_exists($line['contact_id'], $titles)) continue;
		$data[$index]['fide_title'] = $titles[$line['contact_id']];
	}
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
			AND contacts_identifiers.identifier_category_id = %d
		WHERE contact_id IN (%s)';
	$sql = sprintf($sql
		, wrap_category_id('identifiers/zps')
		, implode(',', $contact_ids)
	);
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
			AND contacts_identifiers.identifier_category_id = %d
		WHERE contact_id IN (%s)';
	$sql = sprintf($sql
		, wrap_category_id('identifiers/fide-id')
		, implode(',', $contact_ids)
	);
	return wrap_db_fetch($sql, 'contact_id');
}
