<?php 

/**
 * ratings module
 * chess players in Wikidata
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


$zz['title'] = 'Wikidata Chess Players';
$zz['table'] = 'wikidata_players';

$zz['fields'][1]['title'] = 'ID';
$zz['fields'][1]['field_name'] = 'wikidata_id';
$zz['fields'][1]['type'] = 'id';
$zz['fields'][1]['show_id'] = true;
$zz['fields'][1]['prefix'] = 'Q';
$zz['fields'][1]['explanation'] = 'Wikidata Q-ID';
$zz['fields'][1]['import_id_value'] = true;
$zz['fields'][1]['link'] = 'https://www.wikidata.org/wiki/Q';

$zz['fields'][2]['field_name'] = 'fide_id';
$zz['fields'][2]['explanation'] = 'identification number of a player within FIDE database';
$zz['fields'][2]['link'] = [
	'area' => 'ratings_fide_profile',
	'fields' => ['fide_id']
];
$zz['fields'][2]['type'] = 'number';	

$zz['fields'][3]['field_name'] = 'person';

$zz['fields'][4] = zzform_include('wikidata-uris');
$zz['fields'][4]['title'] = 'Wikipedia';
$zz['fields'][4]['type'] = 'subtable';
$zz['fields'][4]['fields'][2]['type'] = 'foreign_key';

$zz['fields'][99]['field_name'] = 'last_update';
$zz['fields'][99]['type'] = 'timestamp';
$zz['fields'][99]['hide_in_list'] = true;


$zz['sql'] = 'SELECT wikidata_players.*
	FROM wikidata_players';
$zz['sqlorder'] = ' ORDER BY person, fide_id, wikidata_id';

$zz['access'] = 'none';
$zz['if']['batch']['access'] = '';
