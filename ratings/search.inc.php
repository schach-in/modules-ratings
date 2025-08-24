<?php

/**
 * ratings module
 * search form for ratings
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022, 2024-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mf_ratings_search($q) {
	$data = [];
	$q_string = implode(' ', $q);
	$data['ratings'][0]['searchword'] = $q_string;

	$conditions = [];
	$names[0] = $q_string;
	if (strstr($names[0], ', ')) {
		$names[0] = str_replace(', ', ',', $names[0]);
	}
	if (strstr($names[0], ' ')) {
		$name = explode(' ', $names[0]);
		$names[0] = implode(',', $name);
		$name = array_reverse($name);
		$names[1] = implode(',', $name);
	}
	foreach ($names as $name)
		$conditions[] = sprintf('CONVERT(Spielername USING utf8) LIKE "%%%s%%"', $name);
	$conditions = [implode(' OR ', $conditions)];
	wrap_include('functions', 'ratings');
	$ratings = mf_ratings_ratinglist($conditions);
	if ($ratings) {
		// normalize search term
		$parts = str_replace(',', ' ', $q_string);
		$parts = mb_strtolower($parts);
		$parts = explode(' ', $parts);
		foreach ($parts as $index => $part) $parts[$index] = trim($part);
		sort($parts);
		// check if there are exact matches
		$exact_matches = [];
		foreach ($ratings as $id => $rating) {
			if (!is_numeric($id)) continue;
			if (array_diff($parts, $rating['search_parts'])) continue;
			$exact_matches[$id] = $rating;
			unset($ratings[$id]);
		}
		if ($exact_matches) {
			$exact_matches['searchword'] = $data['ratings'][0]['searchword'];
			$exact_matches['exact_match'] = true;
			$data['ratings'][0]['players'] = wrap_template('ratinglist', $exact_matches);
		}
		if (count($ratings)) {
			$ratings['searchword'] = $data['ratings'][0]['searchword'];
			if ($exact_matches)
				$ratings['partial_match'] = true;
			$data['ratings'][0]['players_partial'] = wrap_template('ratinglist', $ratings);
		}
	}

	// clubs?
	$sql = 'SELECT contacts.contact_id, contact
			, contacts_identifiers.identifier AS zps_code
		FROM contacts
		LEFT JOIN contacts_identifiers
			ON contacts.contact_id = contacts_identifiers.contact_id
			AND contacts_identifiers.current = "yes"
			AND contacts_identifiers.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		LEFT JOIN categories
			ON contacts.contact_category_id = categories.category_id
		WHERE (contact LIKE "%%%s%%" OR contact_short LIKE "%%%s%%")
		AND NOT ISNULL(contacts_identifiers.identifier)
		AND (categories.parameters LIKE "%%&ratings_members=1%%" OR contacts.parameters LIKE "%%&ratings_members=1%%")
		AND ISNULL(end_date)
	';
	$sql = sprintf($sql, $q_string, $q_string);
	$data['ratings'][0]['clubs'] = wrap_db_fetch($sql, 'contact_id');

	// zps codes?
	if (strlen($_GET['q']) <= 5 AND preg_match('/[0-9A-Z]*/', $_GET['q'])) {
		$sql = 'SELECT contacts.contact_id, contact
				, contacts_identifiers.identifier AS zps_code
			FROM contacts
			LEFT JOIN contacts_identifiers
				ON contacts.contact_id = contacts_identifiers.contact_id
				AND contacts_identifiers.current = "yes"
				AND contacts_identifiers.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
			LEFT JOIN categories
				ON contacts.contact_category_id = categories.category_id
			WHERE contacts_identifiers.identifier LIKE "%s%%"
			AND (categories.parameters LIKE "%%&ratings_members=1%%" OR contacts.parameters LIKE "%%&ratings_members=1%%")
			AND ISNULL(end_date)
		';
		$sql = sprintf($sql, $q_string);
		$data['ratings'][0]['clubs'] = array_merge($data['ratings'][0]['clubs'], wrap_db_fetch($sql, 'contact_id'));
	}

	return $data;
}
