<?php

/**
 * ratings module
 * update person records from FIDE and DSB data
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2015, 2019-2022, 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_ratings_make_personupdate() {
	// FIDE-ID
	$sql = 'SELECT persons.contact_id, person_id, fide.identifier AS player_id_fide
			, CONCAT(IFNULL(CONCAT(name_particle, " "), ""), last_name, ",", first_name) AS spieler
			, YEAR(date_of_birth) AS geburtsjahr
			, UCASE(IF(SUBSTRING(sex, 1, 1) = "f", "W", SUBSTRING(sex, 1, 1))) AS sex
			, zps.identifier AS player_pass_dsb
			, zps.contact_identifier_id AS zps_pk_id
			, contacts.identifier
		FROM persons
		LEFT JOIN contacts USING (contact_id)
		LEFT JOIN contacts_identifiers fide USING (contact_id)
		LEFT JOIN contacts_identifiers zps
			ON persons.contact_id = zps.contact_id
			AND zps.current = "yes"
			AND zps.identifier_category_id = %d
		WHERE fide.identifier_category_id = %d
		AND fide.current = "yes"';
	$sql = sprintf($sql,
		wrap_category_id('identifiers/pass_dsb'),
		wrap_category_id('identifiers/id_fide')
	);
	$fide_ids = wrap_db_fetch($sql, 'player_id_fide');

	$sql = 'SELECT
		FIDE_ID AS player_id_fide, Spielername AS spieler, Geburtsjahr AS geburtsjahr,
		Geschlecht AS sex, CONCAT(ZPS, "-", Mgl_Nr) AS player_pass_dsb
		FROM dwz_spieler
		WHERE FIDE_ID IN (%s)
		AND (ISNULL(Status) OR Status != "P")';
	$sql = sprintf($sql, implode(',', array_keys($fide_ids)));
	$dwz_fide_ids = wrap_db_fetch($sql, 'player_id_fide');

	$i = 0;
	$notes = [];
	foreach ($dwz_fide_ids as $fide_id => $person) {
		$diff = array_diff($person, $fide_ids[$fide_id]);
		if (!$diff) continue;
		list($notes, $i) = mod_ratings_make_personupdate_update($diff, $person, $fide_ids[$fide_id], $notes, $i);
	}

	$sql = 'SELECT persons.contact_id, person_id, fide.identifier AS player_id_fide
			, CONCAT(IFNULL(CONCAT(name_particle, " "), ""), last_name, ",", first_name) AS spieler
			, YEAR(date_of_birth) AS geburtsjahr
			, UCASE(IF(SUBSTRING(sex, 1, 1) = "f", "W", SUBSTRING(sex, 1, 1))) AS sex
			, zps.identifier AS player_pass_dsb
			, zps.contact_identifier_id AS zps_pk_id
			, fide.contact_identifier_id AS fide_pk_id
			, contacts.identifier
		FROM persons
		LEFT JOIN contacts USING (contact_id)
		LEFT JOIN contacts_identifiers zps USING (contact_id)
		LEFT JOIN contacts_identifiers fide
			ON persons.contact_id = fide.contact_id
			AND fide.current = "yes"
			AND fide.identifier_category_id = %d
		WHERE zps.identifier_category_id = %d
		AND zps.current = "yes"';
	$sql = sprintf($sql,
		wrap_category_id('identifiers/id_fide'),
		wrap_category_id('identifiers/pass_dsb')
	);
	$player_passes_dsb = wrap_db_fetch($sql, 'player_pass_dsb');

	// FIDE-IDs zu bestehenden ZPS-Codes
	$sql = 'SELECT
		FIDE_ID AS player_id_fide, Spielername AS spieler, Geburtsjahr AS geburtsjahr,
		Geschlecht AS sex, CONCAT(ZPS, "-", Mgl_Nr) AS player_pass_dsb
		FROM dwz_spieler
		WHERE CONCAT(ZPS, "-", Mgl_Nr) IN ("%s")
		AND (ISNULL(Status) OR Status != "P")';
	$sql = sprintf($sql, implode('","', array_keys($player_passes_dsb)));
	$dwz_player_passes_dsb = wrap_db_fetch($sql, 'player_pass_dsb');

	// Nicht in aktueller DWZ-Datenbank = inaktiv
	$zps_inaktiv = array_diff(array_keys($player_passes_dsb), array_keys($dwz_player_passes_dsb));
	foreach ($zps_inaktiv as $code) {
		if (!$code) continue;
		$notes[$i] = mod_ratings_make_personupdate_remove_zps_code($player_passes_dsb[$code]['zps_pk_id'], $code);
		$notes[$i] += $player_passes_dsb[$code];
		$i++;
	}

	foreach ($dwz_player_passes_dsb as $code => $person) {
		$diff = array_diff($person, $player_passes_dsb[$code]);
		if (!$diff) continue;
		list($notes, $i) = mod_ratings_make_personupdate_update($diff, $person, $player_passes_dsb[$code], $notes, $i);
	}

	// Nicht vorhandene ZPS-Codes inaktiv setzen
	// @todo

	// Personen ohne Daten?
	$sql = 'SELECT rel_id, detail_table, detail_field, master_field
		FROM _relations
		WHERE master_table IN ("persons", "contacts")';
	$relations = wrap_db_fetch($sql, 'rel_id');

	$sql = 'SELECT contact_id, identifier, person_id, contact
		FROM persons
		LEFT JOIN contacts USING (contact_id)';
	$persons = wrap_db_fetch($sql, 'identifier');

	foreach ($persons as $identifier => $person) {
		continue; // @todo remove orphan people
		$found = false;
		foreach ($relations as $relation) {
			$sql = 'SELECT %s FROM %s WHERE %s = %d';
			$sql = sprintf($sql,
				$relation['detail_field'], $relation['detail_table'],
				$relation['detail_field'], $person[$relation['master_field']]);
			$exists = wrap_db_fetch($sql, $relation['detail_field']);
			if ($exists) {
				$found = true;
				break;
			}
		}
		if (!$found) {
			$notes[$i] = mod_ratings_make_personupdate_delete($id);
			// @todo add more information about person
			$notes[$i] += $person;
			$i++;
			unset($persons[$identifier]);
		}
	}

	// normalize identifiers, if one gets deleted
	// first.last.2 becomes first.last
	foreach ($persons as $identifier => $person) {
		preg_match('/^(.+)\.([0-9]{0,1}[0-9])$/', $person['identifier'], $matches);
		if (!$matches) continue;
		$found = false;
		$index = $matches[2];
		while (!$found) {
			$index--;
			if ($index === 1) $index = ''; 
			$lower_match = $matches[1].($index ? '.'.$index : '');
			if (array_key_exists($lower_match, $persons)) {
				$found = true;
				break;
			}
			if (!$index) break;
		}
		if ($found) continue;
		$notes[$i] = mod_ratings_make_personupdate_change_identifier($person['contact_id'], $person['identifier']);
		$notes[$i] += $person;
		$i++;
	}

	$contact_ids = array_column($notes, 'contact_id');
	array_multisort($contact_ids, $notes);
	$last_note = false;
	foreach ($notes as $index => $note) {
		// remove duplicate notes
		if ($last_note === $note['note'])
			unset($notes[$index]);
		else
			$notes[$index]['link'] = wrap_path('contacts_profile[person]', $note['identifier']);
		$last_note = $note['note'];
	}
	
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$notes['show_form'] = true;
	}

	$page['text'] = wrap_template('personupdate', $notes);
	return $page;
}

function mod_ratings_make_personupdate_update($diff, $person, $existing, $notes, $i) {
	foreach ($diff as $field_name => $value) {
		if ($field_name === 'spieler') {
			// Doktortitel ist unwichtig
			if ($person['spieler'] === $existing['spieler'].',Dr.') continue;
		}
		switch ($field_name) {
		case 'player_id_fide':
			if (!$existing['player_id_fide']) {
				$notes[$i] = mod_ratings_make_personupdate_add_id_fide($value, $existing['contact_id']);
			} elseif ($value) {
				$notes[$i] = mod_ratings_make_personupdate_update_id_fide($value, $existing['fide_pk_id'], $existing['player_id_fide']);
			} else {
				$notes[$i]['note'] = sprintf('FIDE-Code löschen? (Alt: %d).', $existing['player_id_fide']);
			}
			break;
		case 'player_pass_dsb':
			if ($existing['player_pass_dsb']) {
				$notes[$i] = mod_ratings_make_personupdate_remove_zps_code($existing['zps_pk_id'], $existing['player_pass_dsb']);
			}
			$notes[$i] = mod_ratings_make_personupdate_add_zps_code($value, $existing['contact_id']);
			break;
		case 'geburtsjahr':
			$notes[$i] = mod_ratings_make_personupdate_update_birth($person['geburtsjahr'], $existing['person_id'], $existing['geburtsjahr']);
			break;
		case 'sex':
			$notes[$i] = mod_ratings_make_personupdate_update_sex($person['sex'], $existing['person_id']);
			break;
		case 'spieler':
			$notes[$i]['note'] = sprintf('Spielername weicht ab: DWZ-DB %s / DSJ %s'
				, $person['spieler']
				, $existing['spieler']
			);
			break;
		default:
			echo wrap_print($notes);
			echo wrap_print($diff);
			echo wrap_print($person);
			echo wrap_print($existing);
			exit;
		}
		if (empty($notes[$i])) $notes[$i] = [];
		$notes[$i] += $existing;
		if (empty($notes[$i]['note'])) $notes[$i]['note'] = '';
		$i++;
	}
	return [$notes, $i];
}

/**
 * add field contacts_identifiers.identifier for a given contact_identifier_id
 *
 * @param string $new
 * @param int $contact_id
 * @return array
 */
function mod_ratings_make_personupdate_add_id_fide($new, $contact_id) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$note['note'] = sprintf('FIDE-Code %d würde ergänzt.', $new);
		return $note;
	}
	$values = [];
	$values['action'] = 'insert';
	$values['ids'] = ['contact_id', 'identifier_category_id'];
	$values['POST']['contact_id'] = $contact_id;
	$values['POST']['identifier_category_id'] = wrap_category_id('identifiers/id_fide');
	$values['POST']['identifier'] = $new;
	$values['POST']['current'] = 'yes';
	$ops = zzform_multi('contacts-identifiers', $values);
	if (!$ops['id']) {
		$note['note'] = 'FIDE-Code konnte nicht ergänzt werden. '.implode($ops['error']);
		$note['error'] = true;
	} else {
		$note['note'] = 'FIDE-Code ergänzt.';
	}
	return $note;
}

/**
 * update field contacts_identifiers.identifier for a given contact_identifier_id
 *
 * @param string $new
 * @param int $contact_identifier_id
 * @param string $old
 * @return array
 */
function mod_ratings_make_personupdate_update_id_fide($new, $contact_identifier_id, $old) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$note['note'] = sprintf('FIDE-Code %d würde korrigiert zu %d.', $old, $new);
		return $note;
	}
	$values = [];
	$values['action'] = 'update';
	$values['POST']['contact_identifier_id'] = $contact_identifier_id;
	$values['POST']['identifier'] = $new;
	$ops = zzform_multi('contacts-identifiers', $values);
	if (!$ops['id']) {
		$note['note'] = 'FIDE-Code konnte nicht korrigiert werden. '.implode($ops['error']);
		$note['error'] = true;
	} else {
		$note['note'] = sprintf('FIDE-Code korrigert (Alt: %d, neu %d).', $old, $new);
	}
	return $note;
}

/**
 * remove status field contacts_identifiers.current for given contact_identifier_id
 *
 * @param int $contact_identifier_id
 * @param string $old
 * @return array
 */
function mod_ratings_make_personupdate_remove_zps_code($contact_identifier_id, $old) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$note['note'] = sprintf('ZPS-Code %s würde auf inaktiv gesetzt.', $old);
		return $note;
	}
	$values = [];
	$values['action'] = 'update';
	$values['POST']['contact_identifier_id'] = $contact_identifier_id;
	$values['POST']['current'] = '';
	$ops = zzform_multi('contacts-identifiers', $values);
	if (!$ops['id']) {
		$note['note'] = 'ZPS-Code konnte nicht inaktiviert werden. '.implode($ops['error']);
		$note['error'] = true;
	} else {
		$note['note'] = 'ZPS-Code auf inaktiv gesetzt.';
	}
	return $note;
}

/**
 * add field contacts_identifiers.identifier for given identifier_category_id
 * check if it was active before and re-activate it if possible
 *
 * @param string $new
 * @param int $contact_id
 * @return array
 */
function mod_ratings_make_personupdate_add_zps_code($new, $contact_id) {
	$sql = 'SELECT contact_identifier_id
		FROM contacts_identifiers
		WHERE contact_id = %d
		AND identifier = "%s"
		AND identifier_category_id = %d
		AND ISNULL(current)';
	$sql = sprintf($sql,
		$contact_id,
		$new,
		wrap_category_id('identifiers/pass_dsb')
	);
	$pk_id = wrap_db_fetch($sql, '', 'single value');
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		if ($pk_id) {
			$note['note'] = sprintf('ZPS-Code %s würde wieder auf aktiv gestellt.', $new);
		} else {
			$note['note'] = sprintf('ZPS-Code %s würde ergänzt.', $new);
		}
		return $note;
	}
	$values = [];
	$values['POST']['current'] = 'yes';
	if ($pk_id) {
		$values['action'] = 'update';
		$values['POST']['contact_identifier_id'] = $pk_id;
	} else {
		$values['action'] = 'insert';
		$values['ids'] = ['contact_id', 'identifier_category_id'];
		$values['POST']['contact_id'] = $contact_id;
		$values['POST']['identifier_category_id'] = wrap_category_id('identifiers/pass_dsb');
		$values['POST']['identifier'] = $new;
	}
	$ops = zzform_multi('contacts-identifiers', $values);
	if (!$ops['id']) {
		$note['note'] = sprintf('ZPS-Code %s konnte nicht ergänzt werden. '.implode($ops['error']), $new);
		$note['error'] = true;
	} else {
		$note['note'] = ' ZPS-Code ergänzt.';
	}
	return $note;
}

/**
 * update field persons.date_of_birth
 *
 * @param string $new
 * @param int $person_id
 * @param string $old
 * @return array
 */
function mod_ratings_make_personupdate_update_birth($new, $person_id, $old) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		if ($old) {
			$note['note'] = sprintf('Geburtsjahr würde geändert von %s zu %s.', $old, $new);
			$note['checkbox'] = 'birth-'.$person_id;
		} else {
			$note['note'] = sprintf('Geburtsjahr %s würde ergänzt.', $new);
		}
		return $note;
	}
	if (empty($_POST['birth-'.$person_id])) {
		$note['note'] = sprintf('Geburtsjahr NICHT geändert von %s zu %s.', $old, $new);
		return $note;
	}
	$values = [];
	$values['action'] = 'update';
	$values['ids'] = ['person_id'];
	$values['POST']['person_id'] = $person_id;
	$values['POST']['date_of_birth'] = $new;
	$ops = zzform_multi('persons', $values);
	if (!$ops['id']) {
		$note['note'] .= 'Geburtsjahr konnte nicht aktualisiert werden. '.implode($ops['error']);
		$note['error'] = true;
	} elseif ($old) {
		$note['note'] = sprintf('Geburtsjahr geändert (%d => %d).', $old, $new);
	} else {
		$note['note'] = sprintf('Geburtsjahr %d ergänzt.', $new
		);
	}
	return $note;
}

/**
 * update field persons.sex
 *
 * @param string $new
 * @param int $person_id
 * @return array
 */
function mod_ratings_make_personupdate_update_sex($new, $person_id) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$note['note'] = sprintf('Geschlecht würde korrigiert zu %s.', $new);
		$note['checkbox'] = 'sex-'.$person_id;
		return $note;
	}
	if (empty($_POST['sex-'.$person_id])) {
		$note['note'] = sprintf('Geschlecht NICHT korrigiert zu %s.', $new);
		return $note;
	}
	$new = $new === 'W' ? 'female' : 'male';
	$values = [];
	$values['action'] = 'update';
	$values['ids'] = ['person_id'];
	$values['POST']['person_id'] = $person_id;
	$values['POST']['sex'] = $new;
	$ops = zzform_multi('persons', $values);
	if (!$ops['id']) {
		$note['note'] .= 'Geschlecht konnte nicht korrigiert werden. '.implode($ops['error']);
		$note['error'] = true;
	} else {
		$note['note'] = sprintf('Geschlecht korrigiert (%s).', $new);
	}
	return $note;
}

/**
 * delete contacts.*
 *
 * @param int $contact_id
 * @return array
 */
function mod_ratings_make_personupdate_delete($contact_id) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$note['note'] = sprintf('Person mit Contact ID %d würde gelöscht', $contact_id);
		return $note;
	}
	
	$deleted = zzform_delete('contacts', $contact_id);
	if (!$deleted) {
		$note['note'] .= 'Person konnte nicht gelöscht werden.';
		$note['error'] = true;
	} else {
		$note['note'] = 'Person gelöscht.';
	}
	return $note;
}

/**
 * update contacts.identifier
 *
 * @param int $contact_id
 * @return array
 */
function mod_ratings_make_personupdate_change_identifier($contact_id, $old) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$note['note'] = sprintf('Kennung %s würde aktualisiert.', $old);
		return $note;
	}
	$values = [];
	$values['action'] = 'update';
	$values['ids'] = ['contact_id'];
	$values['POST']['contact_id'] = $contact_id;
	$values['POST']['kennung_aendern'] = 'ja';
	// @todo move to script from contacts module
	$ops = zzform_multi('../zzbrick_forms/persons', $values);
	if (!$ops['id']) {
		$note['note'] = 'Kennung konnte nicht aktualisiert werden. '.implode($ops['error']);
		$note['error'] = true;
	} else {
		$note['note'] = sprintf('Kennung wurde von %s aktualisiert.', $new, $old);
	}
	return $note;
}
