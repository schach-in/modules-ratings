<?php 

/**
 * ratings module
 * Editing functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2012-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


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
		AND IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr) = "%s"';
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
	$sql = 'SELECT CONCAT(ZPS, "-", IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr)) AS player_pass_dsb
			, FIDE_ID AS player_id_fide
			, (CASE WHEN Geschlecht = "W" THEN "female"
				WHEN Geschlecht = "M" THEN "male"
				ELSE "" END
			) AS sex
			, CONCAT(clubs.contact, " | ", vk.identifier) AS verein
			, lvk.contact_id AS federation_contact_id
			, Spielername
		FROM dwz_spieler
		LEFT JOIN contacts_identifiers vk
			ON dwz_spieler.ZPS = vk.identifier
			AND vk.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
			AND vk.current = "yes"
		LEFT JOIN contacts clubs USING (contact_id)
		LEFT JOIN contacts_identifiers lvk
			ON CONCAT(SUBSTRING(dwz_spieler.ZPS, 1, 1), "00") = lvk.identifier
			AND lvk.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
			AND lvk.current = "yes"
		WHERE Spielername LIKE _latin1"%s,%s%%"
		AND Geburtsjahr = %d	
		AND Status = "A"';
	$sql = sprintf($sql
		, wrap_db_escape(wrap_convert_string($data['last_name'], 'ISO-8859-1'))
		, wrap_db_escape(wrap_convert_string($data['first_name'], 'ISO-8859-1'))
		, substr($data['date_of_birth'], 0, 4)
	);
	$player = wrap_db_fetch($sql, 'player_pass_dsb');
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
				ELSE "" END
			) AS sex
		FROM fide_players
		WHERE player = "%s, %s"
		AND birth = %d';
	$sql = sprintf($sql
		, wrap_db_escape($data['last_name'])
		, wrap_db_escape($data['first_name'])
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

/**
 * add person from dwz_spieler to contacts/persons table
 *
 * @param array $player
 * @return int $contact_id
 */
function mf_ratings_person_add($player) {
	$identifiers = [];
	$keys = ['player_id_fide', 'player_pass_dsb', 'player_id_dsb'];
	foreach ($keys as $key) {
		if (empty($player[$key])) continue;
		$identifier_key = substr($key, 7);
		$identifiers[$identifier_key] = $player[$key];
	}

	$id_text = [];
	$contact_ids = [];
	foreach ($identifiers as $category => $identifier) {
		$sql = 'SELECT contact_id
			FROM contacts_identifiers
			WHERE identifier = "%s"
			AND identifier_category_id = /*_ID categories identifiers/%s _*/';
		$sql = sprintf($sql, $identifier, $category);
		$id = wrap_db_fetch($sql, '', 'single value');
		if ($id) $contact_ids[] = $id;
		$id_text[] = sprintf('%s: %s', $identifier, $category);
	}
	if (!$identifiers) {
		// Keine Kennungen vorhanden, Abgleich Vorname, Nachname, Geburtsdatum
		$sql = 'SELECT contact_id
			FROM persons
			WHERE first_name = "%s" AND last_name = "%s" AND date_of_birth = "%s"';
		$sql = sprintf($sql, $player['first_name'], $player['last_name'], $player['date_of_birth']);
		$contact_ids = wrap_db_fetch($sql, 'contact_id', 'single value');
	}
	$contact_ids = array_unique($contact_ids);
	if (count($contact_ids) === 1) {
		$contact_id = reset($contact_ids);
		// Geburtsdatum vollständig?
		wrap_include('batch', 'zzform');
		zzform_update_date($player, 'persons', 'contact_id', 'date_of_birth');
	} else {
		// Prüfe auf Abweichungen
		$old_contact_id = '';
		foreach ($contact_ids as $contact_id) {
			if ($old_contact_id AND $contact_id !== $old_contact_id) {
				wrap_error(sprintf(
					'Abweichende Kontakt-IDs gefunden. Kontakt-IDs: %s, Kennungen: %s'
					, implode(', ', $contact_ids)
					, implode(', ', $id_text)
				));
			}
			$old_contact_id = $contact_id;
		}
	}

	if (empty($contact_id)) {
		wrap_include('zzform/batch', 'contacts');
		$person = [
			'first_name' => $player['first_name'],
			'last_name' => $player['last_name'],
			'date_of_birth' => $player['date_of_birth'] ? $player['date_of_birth'] : $player['birth_year'],
			'sex' => $player['sex']
		];
		$contact_id = mf_contacts_add_person($person);
	}
	
	if ($identifiers)
		mf_ratings_contacts_identifiers($contact_id, $identifiers);
	return $contact_id;
}

/**
 * add contacts identifiers from dwz_spieler
 *
 * @param int $contact_id
 * @param array $identifiers Liste Kategorie-Kennung = Kennung
 * @return void
 */
function mf_ratings_contacts_identifiers($contact_id, $identifiers) {
	wrap_include('zzbrick_request_get/contactdata', 'contacts');
	$existing = mf_contacts_identifiers([$contact_id]);
	$existing = $existing[$contact_id]['identifiers'] ?? [];

	foreach ($existing as $identifier) {
		if (empty($identifiers[$identifier['path']])) continue;
		if ($identifiers[$identifier['path']] === $identifier['identifier']) {
			// Eintrag ist bereits in Datenbank, aktuell oder alt
			unset($identifiers[$identifier['path']]);
		} elseif ($identifier['current']) {
			// Veralteter Eintrag ist in Datenbank
			$line = [
				'contact_identifier_id' => $identifier['contact_identifier_id'],
				'current' => ''
			];
			zzform_update('contacts_identifiers', $line, E_USER_ERROR);
		}
		// current = false: nix
	}

	foreach ($identifiers as $path => $identifier) {
		if (!$identifier) continue;
		$line = [
			'contact_id' => $contact_id,
			'current' => 'yes',
			'identifier' => $identifier,
			'identifier_category_id' => wrap_category_id('identifiers/'.$path)
		];
		zzform_insert('contacts_identifiers', $line, E_USER_ERROR);
	}
}
