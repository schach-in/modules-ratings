<?php

/**
 * ratings module
 * review person records with changes from FIDE and DSB data
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2015, 2019-2022, 2024-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 *
 * Variables
 * translate_pot = admin
 */


function mod_ratings_make_persons_review() {
	// FIDE-ID
	$sql = 'SELECT persons.contact_id, person_id, fide.identifier AS player_id_fide
			, CONCAT(IFNULL(CONCAT(name_particle, " "), ""), last_name, ",", first_name) AS player
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
			AND zps.identifier_category_id = /*_ID categories identifiers/pass-dsb _*/
		WHERE fide.identifier_category_id = /*_ID categories identifiers/id-fide _*/
		AND fide.current = "yes"';
	$fide_ids = wrap_db_fetch($sql, 'player_id_fide');

	$sql = 'SELECT
		FIDE_ID AS player_id_fide, Spielername AS player, Geburtsjahr AS geburtsjahr,
		Geschlecht AS sex
		, CONCAT(ZPS, "-", IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr)) AS player_pass_dsb
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
		list($notes, $i) = mf_ratings_persons_update($diff, $person, $fide_ids[$fide_id], $notes, $i);
	}

	$sql = 'SELECT persons.contact_id, person_id, fide.identifier AS player_id_fide
			, CONCAT(IFNULL(CONCAT(name_particle, " "), ""), last_name, ",", first_name) AS player
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
			AND fide.identifier_category_id = /*_ID categories identifiers/id-fide _*/
		WHERE zps.identifier_category_id = /*_ID categories identifiers/pass-dsb _*/
		AND zps.current = "yes"';
	$player_passes_dsb = wrap_db_fetch($sql, 'player_pass_dsb');

	// FIDE-IDs zu bestehenden ZPS-Codes
	$sql = 'SELECT
		FIDE_ID AS player_id_fide, Spielername AS player, Geburtsjahr AS geburtsjahr,
		Geschlecht AS sex
		, CONCAT(ZPS, "-", IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr)) AS player_pass_dsb
		FROM dwz_spieler
		WHERE CONCAT(ZPS, "-", IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr)) IN ("%s")
		AND (ISNULL(Status) OR Status != "P")';
	$sql = sprintf($sql, implode('","', array_keys($player_passes_dsb)));
	$dwz_player_passes_dsb = wrap_db_fetch($sql, 'player_pass_dsb');

	foreach ($dwz_player_passes_dsb as $code => $person) {
		$diff = array_diff($person, $player_passes_dsb[$code]);
		if (!$diff) continue;
		list($notes, $i) = mf_ratings_persons_update($diff, $person, $player_passes_dsb[$code], $notes, $i);
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
			$notes[$i] = mf_ratings_persons_delete($id);
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
		$notes[$i] = mf_ratings_persons_change_identifier($person['contact_id'], $person['identifier']);
		$notes[$i] += $person;
		$i++;
	}

	$contact_ids = array_column($notes, 'contact_id');
	array_multisort($contact_ids, $notes);
	$last_note = false;
	foreach ($notes as $index => $note) {
		// remove duplicate notes
		if (isset($note['note']) AND $last_note === $note['note'])
			unset($notes[$index]);
		$last_note = $note['note'] ?? '';
	}
	
	if ($_SERVER['REQUEST_METHOD'] !== 'POST')
		$notes['show_form'] = true;

	$page['text'] = wrap_template('persons-review', $notes);
	return $page;
}

function mf_ratings_persons_update($diff, $person, $existing, $notes, $i) {
	foreach ($diff as $field_name => $value) {
		if ($field_name === 'player') {
			// Doktortitel ist unwichtig
			if ($person['player'] === $existing['player'].',Dr.') continue;
		}
		switch ($field_name) {
		case 'player_id_fide':
			if (!$existing['player_id_fide']) {
				// removed
				continue 2;
			} elseif ($value) {
				// removed
				continue 2;
			} else {
				$notes[$i]['note'] = wrap_text(
					'Delete FIDE code? (old: %d).',
					['values' => [$existing['player_id_fide']]]
				);
			}
			break;
		case 'player_pass_dsb':
			continue 2; // removed
		case 'geburtsjahr':
			if (!$existing['geburtsjahr']) continue 2;
			$notes[$i] = mf_ratings_persons_update_birth($person['geburtsjahr'], $existing['person_id'], $existing['geburtsjahr']);
			break;
		case 'sex':
			if (!$existing['sex']) continue 2;
			$notes[$i] = mf_ratings_persons_update_sex($person['sex'], $existing['person_id']);
			break;
		case 'player':
			$notes[$i]['note'] = wrap_text(
				'Player name differs: DWZ database %s / DSJ %s',
				['values' => [$person['player'], $existing['player']]]
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
 * update field persons.date_of_birth
 *
 * @param string $new
 * @param int $person_id
 * @param string $old
 * @return array
 */
function mf_ratings_persons_update_birth($new, $person_id, $old) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$note['note'] = wrap_text('Year of birth would be changed from %s to %s.', ['values' => [$old, $new]]);
		$note['checkbox'] = 'birth-'.$person_id;
		return $note;
	}
	if (empty($_POST['birth-'.$person_id])) {
		$note['note'] = wrap_text('Year of birth NOT changed from %s to %s.', ['values' => [$old, $new]]);
		return $note;
	}
	$line = [
		'person_id' => $person_id,
		'date_of_birth' => $new
	];
	$result = zzform_update('persons', $line);
	if (is_null($result)) {
		$note['note'] = wrap_text('Year of birth could not be updated.');
		$note['error'] = true;
	} else {
		$note['note'] = wrap_text('Year of birth changed (%d => %d).', ['values' => [$old, $new]]);
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
function mf_ratings_persons_update_sex($new, $person_id) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$note['note'] = wrap_text('Sex would be corrected to %s.', ['values' => [$new]]);
		$note['checkbox'] = 'sex-'.$person_id;
		return $note;
	}
	if (empty($_POST['sex-'.$person_id])) {
		$note['note'] = wrap_text('Sex NOT corrected to %s.', ['values' => [$new]]);
		return $note;
	}
	$new = $new === 'W' ? 'female' : 'male';
	$line = [
		'person_id' => $person_id,
		'sex' => $new
	];
	$result = zzform_update('persons', $line);
	if (is_null($result)) {
		$note['note'] = wrap_text('Sex could not be corrected.');
		$note['error'] = true;
	} else {
		$note['note'] = wrap_text('Sex corrected (%s).', ['values' => [$new]]);
	}
	return $note;
}

/**
 * delete contacts.*
 *
 * @param int $contact_id
 * @return array
 */
function mf_ratings_persons_delete($contact_id) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$note['note'] = wrap_text('Person with contact ID %d would be deleted', ['values' => [$contact_id]]);
		return $note;
	}
	
	$deleted = zzform_delete('contacts', $contact_id);
	if (!$deleted) {
		$note['note'] = wrap_text('Person could not be deleted.');
		$note['error'] = true;
	} else {
		$note['note'] = wrap_text('Person deleted.');
	}
	return $note;
}

/**
 * update contacts.identifier
 *
 * @param int $contact_id
 * @return array
 */
function mf_ratings_persons_change_identifier($contact_id, $old) {
	$note = [];
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$note['note'] = wrap_text('Identifier %s would be updated.', ['values' => [$old]]);
		return $note;
	}
	$line = [
		'contact_id' => $contact_id,
		'change_identifier' => 'yes'
	];
	$contact_id = zzform_update('forms/persons', $line);
	if (is_null($contact_id)) {
		$note['note'] = wrap_text('Identifier could not be updated.');
		$note['error'] = true;
	} else {
		$sql = 'SELECT identifier FROM contacts WHERE contact_id = %d';
		$sql = sprintf($sql, $contact_id);
		$new = wrap_db_fetch($sql, '', 'single value');
		if ($new)
			$note['note'] = wrap_text('Identifier updated from %s to %s.', ['values' => [$old, $new]]);
	}
	return $note;
}
