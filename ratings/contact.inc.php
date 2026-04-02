<?php 

/**
 * ratings module
 * contact functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * get rating and club data for contact profile
 *
 * @param array $data
 * @param array $ids
 * @return array
 */
function mf_ratings_contact($data, $ids) {
	$contact_id = key($data);
	// ratings
	$data[$contact_id] += mf_ratings_by_contact($contact_id);
	// clubs
	$data[$contact_id]['clubs'] = mf_ratings_clubs_from_code($data[$contact_id]['identifiers'] ?? []);

	$titles = [];
	$title_fields = [
		'fide_title', 'fide_title_women', 'fide_title_other'
	];
	foreach ($title_fields as $title_field) {
		if (empty($data[$contact_id][$title_field])) continue;
		$my_titles = explode(',', $data[$contact_id][$title_field]);
		foreach ($my_titles as $title) $titles[$title] = $title;
	}
	if ($titles) {
		$prefix = wrap_template('fide-titles', mf_ratings_fide_title_prefix_items($titles));
		if ($prefix)
			$data[$contact_id]['title_prefix'] = $prefix.' '.$data[$contact_id]['title_prefix'];
	}

	$data['templates']['contact_5'][] = 'contact-ratings-clubs';
	$data['templates']['contact_6'][] = 'contact-ratings';
	return $data;
}

/**
 * build loop rows for fide-titles.template.txt (abbr short / title long)
 *
 * @param array $titles list of FIDE title abbreviations (e.g. GM, IA)
 * @return array
 */
function mf_ratings_fide_title_prefix_items($titles) {
	static $map = null;
	if ($map === null) {
		$map = [];
		foreach (['fide-titles', 'fide-titles-women', 'fide-titles-other', 'fide-titles-arena'] as $tsv) {
			$map = array_merge($map, wrap_tsv_parse($tsv, 'ratings'));
		}
	}
	$items = [];
	foreach ($titles as $short) {
		$english = $map[$short] ?? null;
		$long = $english ? wrap_text($english) : $short;
		$items[] = [
			'title_short' => $short,
			'title_long' => wrap_html_escape($long),
		];
	}
	return $items;
}

/**
 * get club data for contact profile
 *
 * @param array $data
 * @param array $ids
 * @return array
 */
function mf_ratings_clubs_from_code($identifiers) {
	if (!$identifiers) return [];
	
	$club_codes = [];
	$current_club = NULL;
	foreach ($identifiers as $identifier) {
		if ($identifier['path'] !== 'pass_dsb') continue;
		$club_code = substr($identifier['identifier'], 0 , strpos($identifier['identifier'], '-'));
		$club_codes[] = $club_code;
		if ($identifier['current']) $current_club = $club_code;
	}
	$club_codes = array_unique($club_codes);

	$sql = 'SELECT contact_id, contact, contacts.identifier
			, contacts_identifiers.identifier AS c_identifier
		FROM contacts
		LEFT JOIN contacts_identifiers USING (contact_id)
		WHERE contacts_identifiers.identifier IN ("%s")';
	$sql = sprintf($sql, implode('","', $club_codes));
	$clubs = wrap_db_fetch($sql, 'contact_id');
	
	if ($current_club) {
		foreach ($clubs as $contact_id => $contact) {
			if ($contact['c_identifier'] !== $current_club) continue;
			$clubs[$contact_id]['current'] = true;
		}
	}
	
	return $clubs;
}
