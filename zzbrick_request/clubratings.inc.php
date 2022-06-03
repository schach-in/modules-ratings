<?php

/**
 * ratings module
 * ratings per club
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_ratings_clubratings($params) {
	if (count($params) !== 1) return false;

	$sql = 'SELECT contact
			, contacts.identifier AS club_identifier
		FROM contacts
		LEFT JOIN contacts_identifiers
			ON contacts.contact_id = contacts_identifiers.contact_id
			AND contacts_identifiers.identifier_category_id = %d
			AND current = "yes"
		WHERE contacts_identifiers.identifier = "%s"
	';
	$sql = sprintf($sql
		, wrap_category_id('identifiers/zps')
		, wrap_db_escape($params[0])
	);
	$data = wrap_db_fetch($sql);
	if (!$data) return false;

	$conditions = [];
	$conditions[] = sprintf('ZPS = "%s"', wrap_db_escape($params[0]));
	
	$ratings = mf_ratings_ratinglist($conditions);
	foreach ($ratings as $index => $line) {
		unset($ratings[$index]['club_identifier']);
	}
	$data = array_merge($data, $ratings);
	$page['text'] = wrap_template('ratinglist', $data);
	$page['text'] .= wrap_template('ratingstatus');
	$page['title'] = 'Wertungszahlen '.$data['contact'];
	$page['meta'][] = ['name' => 'robots', 'content' => 'noindex'];
	$page['breadcrumbs'][] = $data['contact'];
	return $page;
}
