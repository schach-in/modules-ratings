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
			, title_other
			, wikidata_players.person
			, (SELECT uri FROM wikidata_uris
				WHERE wikidata_uris.wikidata_id = wikidata_players.wikidata_id
				ORDER BY uri_lang ASC LIMIT 1
			) AS wikipedia_url
	    FROM dwz_spieler
	    LEFT JOIN fide_players
	    	ON dwz_spieler.fide_id = fide_players.player_id
	    LEFT JOIN wikidata_players
	    	ON wikidata_players.fide_id = fide_players.player_id
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
	    ORDER BY IFNULL(DWZ, standard_rating) DESC, standard_rating DESC, PID, Status
	    LIMIT 0, %d
	';
	$sql = sprintf($sql
		, $conditions ? implode(' AND ', $conditions) : ''
		, $limit
	);
	$data = wrap_db_fetch($sql, '_dummy_', 'numeric');
	$players = [];
	foreach ($data as $index => $line) {
		// only show players with at least one rating
		if (!$line['dwz'] AND !$line['standard_rating'] AND !$line['rapid_rating'] AND !$line['blitz_rating'])
			continue;
		$line = mf_ratings_fidetitle($line);
		$line = mf_ratings_fideother($line);
		if (array_key_exists($line['PID'], $players)) {
			$players[$line['PID']]['memberships'][] = $line;
		} else {
			$players[$line['PID']] = $line;
			$contact = explode(',', $line['spielername']);
			$players[$line['PID']]['search_parts'] = $contact;
			sort($players[$line['PID']]['search_parts']);
			$contact = array_reverse($contact);
			$players[$line['PID']]['contact'] = implode(' ', $contact);
			// only take Wikipedia name for the accents etc.
			if ($line['person'] AND wrap_filename($line['person']) === wrap_filename($players[$line['PID']]['contact']))
				$players[$line['PID']]['contact'] = $line['person'];
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
			ON contacts_identifiers.identifier = CONCAT(ZPS, "-", IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr))
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

/**
 * get top ten active players of club
 *
 * @param array $club
 * @return array
 */
function mf_ratings_toplist($club) {
	$data = [];
	$has_toplist = false;
	if (!empty($club['contact_parameters']['ratings_members'])) $has_toplist = true;
	elseif (!empty($club['parameters']['ratings_members'])) $has_toplist = true;
	if (!$has_toplist)
		return $data;
		
	$club['code'] = $club['contact_parameters']['ratings_club_code'] ?? $club['zps_code'];

	$sql = 'SELECT title, title_women, Spielername, DWZ, standard_rating
		FROM dwz_spieler
		LEFT JOIN fide_players
			ON dwz_spieler.fide_id = fide_players.player_id
		WHERE ZPS = "%s"
		AND (Status = "A" OR ISNULL(Status))
		ORDER BY DWZ DESC, standard_rating DESC
		LIMIT 10';
	$sql = sprintf($sql, $club['code']);
	$data = wrap_db_fetch($sql, '_dummy_', 'numeric');
	$i = 1;
	foreach ($data as $index => &$player) {
		$player['no'] = $i;
		$player_name = explode(',', $player['Spielername']);
		$player_name = array_reverse($player_name);
		$player['spieler'] = implode(' ', $player_name);
		$player = mf_ratings_fidetitle($player);
		$i++;
	}
	return $data;
}

/**
 * get rating folder per rating
 *
 * @param string $rating
 * @return string
 */
function mf_ratings_folder($rating) {
	return sprintf('%s/%s', wrap_setting('media_folder'), strtolower($rating));
}

/**
 * get rating folder per rating
 *
 * @param string $rating
 * @return string
 */
function mf_ratings_sqlfile($rating) {
	return sprintf('%s/%s/%s.sql', wrap_setting('tmp_dir'), $rating, $rating);
}

/**
 * write SQL line into .sql file per rating
 *
 * @param string $rating
 * @param string $line (optional)
 * @return bool
 */
function mf_ratings_log($rating, $line = '') {
	$sql_file = mf_ratings_sqlfile($rating);
	if (!$line) {
		if (file_exists($sql_file)) unlink($sql_file);
		touch($sql_file);
		return false;
	}
	$line = trim($line);
	$line = rtrim($line, ';');
	error_log($line.";\n", 3, $sql_file);
	return true;
}

/**
 * format wikidata data
 *
 * @param array $raw
 * @return array
 */
function mf_ratings_wikidata_format($raw) {
	if (empty($raw['results']['bindings'])) return [[], 0];
	
	$count = count($raw['results']['bindings']);
	$data = [];
	foreach ($raw['results']['bindings'] as $line) {
		$qid = $line['personId']['value'];
		if (!array_key_exists($qid, $data)) {
			$data[$qid] = [
				'wikidata_id' => $qid,
				'fide_id' => $line['fideId']['value'],
				'person' => $line['personLabel']['value'],
				'wikidata_uris[de][uri]' => NULL,
				'wikidata_uris[de][uri_lang]' => NULL,
				'wikidata_uris[en][uri]' => NULL,
				'wikidata_uris[en][uri_lang]' => NULL
			];
		}
		if (!empty($line['wikipediaUrl']['value'])) {
			$data[$qid]['wikidata_uris['.$line['langCode']['value'].'][uri]'] = $line['wikipediaUrl']['value'];
			$data[$qid]['wikidata_uris['.$line['langCode']['value'].'][uri_lang]'] = $line['langCode']['value'];
		}
	}
	return [$data, $count];
}
