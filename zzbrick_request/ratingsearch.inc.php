<?php

/**
 * ratings module
 * search form for ratings
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022, 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_ratings_ratingsearch($params) {
	$data = [];

	$page['text'] = '';
	if (!empty($_GET['name'])) {
		$_GET['name'] = trim($_GET['name']);
		$conditions = [];
		$names[0] = $_GET['name'];
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
			$conditions[] = sprintf('CONVERT(Spielername USING utf8) LIKE "%%%s%%"', wrap_db_escape($name));
		$conditions = [implode(' OR ', $conditions)];
		$ratings = mf_ratings_ratinglist($conditions);
		if ($ratings) {
			$ratings['searchword'] = $_GET['name'];
			// normalize search term
			$parts = str_replace(',', ' ', $_GET['name']);
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
				$exact_matches['searchword'] = $ratings['searchword'];
				$exact_matches['exact_match'] = true;
				$page['text'] .= wrap_template('ratinglist', $exact_matches);
			}
			if (count($ratings) > 1) {
				if ($exact_matches)
					$ratings['partial_match'] = true;
				$page['text'] .= wrap_template('ratinglist', $ratings);
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
			AND contacts_identifiers.identifier != 10517
		';
		$sql = sprintf($sql, wrap_db_escape($_GET['name']), wrap_db_escape($_GET['name']));
		$data['clubs'] = wrap_db_fetch($sql, 'contact_id');

		// zps codes?
		if (strlen($_GET['name']) <= 5 AND preg_match('/[0-9A-Z]*/', $_GET['name'])) {
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
			$sql = sprintf($sql, wrap_db_escape($_GET['name']));
			$data['clubs'] = array_merge($data['clubs'], wrap_db_fetch($sql, 'contact_id'));
		}
		if (!$ratings AND !$data['clubs'])
			$data['no_ratings_found'] = true;
		
		$data['searchword'] = $_GET['name'];
		$page['meta'][] = ['name' => 'robots', 'content' => 'noindex'];
	}

	$page['query_strings'][] = 'name';
	$page['text'] .= wrap_template('ratingsearch', $data);
	$page['text'] .= wrap_template('ratingstatus');
	return $page;
}
