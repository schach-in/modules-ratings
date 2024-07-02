<?php

/**
 * ratings module
 * ratings per club
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022, 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_ratings_clubratings($params) {
	if (count($params) !== 1) return false;

	$sql = 'SELECT contact
			, contacts.identifier AS club_identifier
			, contacts.parameters
		FROM contacts
		LEFT JOIN contacts_identifiers
			ON contacts.contact_id = contacts_identifiers.contact_id
			AND contacts_identifiers.identifier_category_id = %d
			AND current = "yes"
		WHERE contacts_identifiers.identifier = "%s"
	';
	$sql = sprintf($sql
		, wrap_category_id('identifiers/pass_dsb')
		, wrap_db_escape($params[0])
	);
	$data = wrap_db_fetch($sql);
	if (!$data) return false;
	if ($data['parameters']) parse_str($data['parameters'], $data['parameters']);

	$conditions = [];
	$rating_code = $data['parameters']['ratings_club_code'] ?? $params[0];
	$conditions[] = sprintf('ZPS = "%s"', wrap_db_escape($rating_code));
	
	$ratings = mf_ratings_ratinglist($conditions);
	if (!$ratings) return false;
	foreach ($ratings as $index => $line)
		unset($ratings[$index]['club_identifier']);

	$data = array_merge($data, $ratings);
	$page['text'] = wrap_template('ratinglist', $data);
	$page['text'] .= wrap_template('ratingstatus');
	$page['title'] = 'Wertungszahlen '.$data['contact'];
	$page['meta'][] = ['name' => 'robots', 'content' => 'noindex'];
	$page['breadcrumbs'][] = $data['contact'];
	return $page;
}
