<?php

/**
 * ratings module
 * nuLiga club venues (spielorte)
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


$zz['title'] = 'nuLiga Venues';
$zz['table'] = 'nuliga_venues';

$zz['fields'][1]['title'] = 'ID';
$zz['fields'][1]['field_name'] = 'venue_id';
$zz['fields'][1]['type'] = 'id';

$zz['fields'][2]['title'] = 'Club';
$zz['fields'][2]['field_name'] = 'nuliga_club_id';
$zz['fields'][2]['type'] = 'select';
$zz['fields'][2]['sql'] = 'SELECT nuliga_club_id, club_name, zps
	FROM nuliga_clubs
	ORDER BY zps, club_name';
$zz['fields'][2]['display_field'] = 'club_name';
$zz['fields'][2]['search'] = 'CONCAT(nuliga_clubs.zps, " ", nuliga_clubs.club_name)';
$zz['fields'][2]['character_set'] = 'utf8';
$zz['fields'][2]['if']['where']['hide_in_form'] = true;
$zz['fields'][2]['if']['where']['hide_in_list'] = true;

$zz['fields'][3]['title'] = 'No.';
$zz['fields'][3]['field_name'] = 'sequence';
$zz['fields'][3]['type'] = 'number';
$zz['fields'][3]['auto_value'] = 'increment';
$zz['fields'][3]['def_val_ignore'] = true;

$zz['fields'][4]['title'] = 'Venue';
$zz['fields'][4]['field_name'] = 'venue_name';

$zz['fields'][5]['title'] = 'Street';
$zz['fields'][5]['field_name'] = 'street';

$zz['fields'][6]['field_name'] = 'postcode';
$zz['fields'][6]['size'] = 8;
$zz['fields'][6]['append_next'] = true;
$zz['fields'][6]['title_append'] = 'Postcode/Place';

$zz['fields'][7]['field_name'] = 'place';

$zz['fields'][99]['field_name'] = 'last_update';
$zz['fields'][99]['type'] = 'timestamp';
$zz['fields'][99]['hide_in_list'] = true;

$zz['subselect']['sql'] = 'SELECT nuliga_club_id, venue_name, street, postcode, place
	FROM nuliga_venues
	ORDER BY sequence';
$zz['unless']['export_mode']['subselect']['field_suffix'][0] = '<br>';
$zz['unless']['export_mode']['subselect']['field_suffix'][1] = ' ';
$zz['unless']['export_mode']['subselect']['field_suffix'][2] = '<br>';
$zz['unless']['export_mode']['subselect']['field_suffix'][3] = ' ';
$zz['if']['export_mode']['subselect']['field_suffix'][0] = ', ';
$zz['if']['export_mode']['subselect']['field_suffix'][1] = ' ';
$zz['if']['export_mode']['subselect']['field_suffix'][2] = ', ';
$zz['if']['export_mode']['subselect']['field_suffix'][3] = ' ';

$zz['sql'] = 'SELECT nuliga_venues.*
		, nuliga_clubs.club_name
		, nuliga_clubs.zps
	FROM nuliga_venues
	LEFT JOIN nuliga_clubs USING (nuliga_club_id)';
$zz['sqlorder'] = ' ORDER BY zps, club_name, sequence';

$zz['access'] = 'none';
$zz['if']['batch_mode']['access'] = '';

$zz['export'][] = 'CSV Excel';
$zz['export_no_html'] = true;
