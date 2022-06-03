<?php

/**
 * ratings module
 * common functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
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
