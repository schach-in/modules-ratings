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
 * read player data from German ratings database
 * 
 * @param mixed $code
 * @return array
 */
function mf_ratings_playerdata_dwz($code) {
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
