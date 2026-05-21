<?php 

/**
 * ratings module
 * membership statistics per snapshot date
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


$zz['title'] = 'Member Statistics';
$zz['table'] = 'memberstats';

$zz['fields'][1]['title'] = 'ID';
$zz['fields'][1]['field_name'] = 'memberstat_id';
$zz['fields'][1]['type'] = 'id';

$zz['fields'][2]['title'] = 'Snapshot Date';
$zz['fields'][2]['field_name'] = 'snapshot_date';
$zz['fields'][2]['type'] = 'date';

$zz['fields'][3]['title'] = 'Club Code';
$zz['fields'][3]['field_name'] = 'club_code';
$zz['fields'][3]['explanation'] = 'ZPS code of the club';

$zz['fields'][4]['title'] = 'Club';
$zz['fields'][4]['field_name'] = 'club_contact_id';
$zz['fields'][4]['type'] = 'select';
$zz['fields'][4]['sql'] = 'SELECT contacts.contact_id, contact
		, contacts_identifiers.identifier AS club_code
	FROM contacts
	LEFT JOIN contacts_identifiers
		ON contacts_identifiers.contact_id = contacts.contact_id
		AND contacts_identifiers.current = "yes"
		AND contacts_identifiers.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
	LEFT JOIN categories
		ON contacts.contact_category_id = categories.category_id
	WHERE categories.parameters LIKE "%&organisation=1%"
	ORDER BY contacts_identifiers.identifier, contact';
$zz['fields'][4]['display_field'] = 'contact';
$zz['fields'][4]['search'] = 'contacts.contact';
$zz['fields'][4]['character_set'] = 'utf8';

$zz['fields'][5]['title_tab'] = 'Birth';
$zz['fields'][5]['title'] = 'Birth Year';
$zz['fields'][5]['field_name'] = 'birth_year';
$zz['fields'][5]['type'] = 'number';

$zz['fields'][6]['title'] = 'Rating';
$zz['fields'][6]['field_name'] = 'rating';
$zz['fields'][6]['type'] = 'number';

$zz['fields'][7]['title_tab'] = 'S.';
$zz['fields'][7]['field_name'] = 'sex';
$zz['fields'][7]['type'] = 'select';
$zz['fields'][7]['enum'] = ['female', 'male', 'diverse'];
$zz['fields'][7]['enum_title'] = [wrap_text('female'), wrap_text('male'), wrap_text('diverse')];

$zz['fields'][8]['title_tab'] = 'St.';
$zz['fields'][8]['field_name'] = 'status';
$zz['fields'][8]['type'] = 'select';
$zz['fields'][8]['enum'] = ['active', 'passive'];
$zz['fields'][8]['enum_abbr'] = ['Aktiv', 'Passiv'];


$zz['sql'] = 'SELECT memberstats.*
		, contact
	FROM memberstats
	LEFT JOIN contacts
		ON contacts.contact_id = memberstats.club_contact_id';
$zz['sqlorder'] = ' ORDER BY snapshot_date DESC, club_code, birth_year, rating DESC';

$zz['access'] = 'none';
$zz['if']['batch_mode']['access'] = '';

$zz['filter'][1]['title'] = wrap_text('Snapshot Date');
$zz['filter'][1]['type'] = 'list';
$zz['filter'][1]['where'] = 'snapshot_date';
$zz['filter'][1]['field_name'] = 'snapshot_date';
$zz['filter'][1]['sql'] = 'SELECT DISTINCT snapshot_date, snapshot_date
	FROM memberstats
	ORDER BY snapshot_date DESC';

$zz['filter'][2]['title'] = wrap_text('Club Code');
$zz['filter'][2]['type'] = 'list';
$zz['filter'][2]['where'] = 'club_code';
$zz['filter'][2]['field_name'] = 'club_code';
$zz['filter'][2]['sql'] = 'SELECT DISTINCT club_code, club_code
	FROM memberstats
	ORDER BY club_code';

$zz['filter'][3]['title'] = wrap_text('Status');
$zz['filter'][3]['type'] = 'list';
$zz['filter'][3]['where'] = 'status';
$zz['filter'][3]['field_name'] = 'status';
$zz['filter'][3]['selection'] = array_combine($zz['fields'][8]['enum'], $zz['fields'][8]['enum_abbr']);
