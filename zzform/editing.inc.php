<?php 

/**
 * ratings module
 * Editing functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2012-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * read player data from ratings database of German Chess Federation DSB
 * 
 * @param mixed $code
 * @return array
 */
function mf_ratings_player_data_dsb($code) {
	if (!is_array($code)) $code = explode('-', $code);
	list($zps, $mgl_nr) = $code;

	$sql = 'SELECT dwz_spieler.ZPS, dwz_spieler.Mgl_Nr
			, dwz_spieler.Spielername, dwz_spieler.Geburtsjahr, dwz_spieler.Geschlecht
			, dwz_spieler.DWZ, dwz_spieler.FIDE_Elo, dwz_spieler.FIDE_Titel
			, dwz_spieler.FIDE_ID
			, contacts.contact_id, contacts.contact
		FROM dwz_spieler
		LEFT JOIN contacts_identifiers
			ON dwz_spieler.ZPS = contacts_identifiers.identifier
			AND contacts_identifiers.current = "yes"
		LEFT JOIN contacts USING (contact_id)
		WHERE ZPS = "%s" AND Mgl_Nr = "%s"
	';
	$sql = sprintf($sql, wrap_db_escape($zps), wrap_db_escape($mgl_nr));
	$player = wrap_db_fetch($sql);
	if (!$player) return [];

	// player name
	$player_name = explode(',', $player['Spielername']);
	$player['last_name'] = $player_name[0];
	$player['first_name'] = $player_name[1];
	return $player;
}

/**
 * get player rating and title from German Chess Federation DSB
 *
 * @param string $code
 * @param string $prefix field prefix
 * @return array
 */
function mf_ratings_player_rating_dsb($code, $prefix = 't_') {
	$code = explode('-', $code);
	$sql = 'SELECT DWZ AS %sdwz, FIDE_Elo AS %selo, FIDE_Titel AS %sfidetitel
		FROM dwz_spieler
		WHERE ZPS = "%s"
		AND Mgl_Nr = "%s"';
	$sql = sprintf($sql
		, $prefix, $prefix, $prefix
		, $code[0], $code[1]
	);
	return wrap_db_fetch($sql);
}

/**
 * get player rating and title from World Chess Federation FIDE
 *
 * @param string $code
 * @param string $prefix field prefix
 * @return array
 */
function mf_ratings_player_rating_fide($code, $prefix = 't_') {
	$sql = 'SELECT standard_rating AS %selo, title AS %sfidetitel
		FROM fide_players
		WHERE player_id = %d';
	$sql = sprintf($sql
		, $prefix, $prefix
		, $code
	);
	return wrap_db_fetch($sql);
}

/**
 * search for a player in the databases of the federations
 *
 * @param array $data (last_name, first_name, date_of_birth)
 * @return mixed
 */
function mf_ratings_player_search($federation, $data) {
	$function = sprintf('mf_ratings_player_search_%s', $federation);
	if (!function_exists($function)) return [];
	return $function($data);
}

/**
 * search for a player in the database of the German Chess Federation DSB
 *
 * @param array $data (last_name, first_name, date_of_birth)
 * @return mixed
 */
function mf_ratings_player_search_dsb($data) {
	$sql = 'SELECT CONCAT(ZPS, "-", Mgl_Nr) AS player_id_dsb
			, FIDE_ID AS player_id_fide
			, (CASE WHEN Geschlecht = "W" THEN "female"
				WHEN Geschlecht = "M" THEN "male"
				ELSE "unknown" END
			) AS sex
			, CONCAT(clubs.contact, " | ", vk.identifier) AS verein
			, lvk.contact_id AS federation_contact_id
			, Spielername
		FROM dwz_spieler
		LEFT JOIN contacts_identifiers vk
			ON dwz_spieler.ZPS = vk.identifier
			AND vk.identifier_category_id = %d
			AND vk.current = "yes"
		LEFT JOIN contacts clubs USING (contact_id)
		LEFT JOIN contacts_identifiers lvk
			ON CONCAT(SUBSTRING(dwz_spieler.ZPS, 1, 1), "00") = lvk.identifier
			AND lvk.identifier_category_id = %d
			AND lvk.current = "yes"
		WHERE Spielername LIKE "%s,%s%%"
		AND Geburtsjahr = %d	
		AND Status = "A"';
	$sql = sprintf($sql
		, wrap_category_id('identifiers/zps')
		, wrap_category_id('identifiers/zps')
		, $data['last_name']
		, $data['first_name']
		, substr($data['date_of_birth'], 0, 4)
	);
	$player = wrap_db_fetch($sql, 'player_id_dsb');
	if (!$player) return [];
	// multiple persons with same name and birth year:
	// no further information can be gathered
	if (count($player) > 1) return -1;
	
	$player = reset($player);

	$name = explode(',', $player['Spielername']);
	$player['last_name'] = $name[0];
	$player['first_name'] = $name[1];
	if (!empty($name[2])) $player['title_prefix'] = $name[1];
	unset($player['Spielername']);
	return $player;
}

/**
 * search for a player in the database of the World Chess Federation FIDE
 *
 * @param array $data (last_name, first_name, date_of_birth)
 * @return array
 */
function mf_ratings_player_search_fide($data) {
	$sql = 'SELECT player_id AS player_id_fide
			, player
			, (CASE WHEN sex = "F" THEN "female"
				WHEN sex = "M" THEN "male"
				ELSE "unknown" END
			) AS sex
		FROM fide_players
		WHERE player = "%s, %s"
		AND birth = %d';
	$sql = sprintf($sql
		, $data['last_name']
		, $data['first_name']
		, substr($data['date_of_birth'], 0, 4)
	);
	$player = wrap_db_fetch($sql);
	if (!$player) return $player;

	$name = explode(',', $player['player']);
	$player['last_name'] = trim($name[0]);
	$player['first_name'] = trim($name[1]);
	unset($player['player']);
	return $player;
}
