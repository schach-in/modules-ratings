<?php 

/**
 * ratings module
 * players in FIDE Elo database
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


$zz['title'] = 'FIDE Players';
$zz['table'] = 'fide_players';

$zz['fields'][1]['title'] = 'ID';
$zz['fields'][1]['field_name'] = 'player_id';
$zz['fields'][1]['type'] = 'id';
$zz['fields'][1]['show_id'] = true;
$zz['fields'][1]['explanation'] = 'identification number of a player within FIDE database';
$zz['fields'][1]['import_id_value'] = true;

$zz['fields'][2]['field_name'] = 'player';
$zz['fields'][2]['explanation'] = 'name of a player';

$zz['fields'][3]['field_name'] = 'federation';

$zz['fields'][4]['title_tab'] = 'S.';
$zz['fields'][4]['field_name'] = 'sex';
$zz['fields'][4]['type'] = 'select';
$zz['fields'][4]['enum'] = ['M', 'F'];
$zz['fields'][4]['enum_abbr'] = ['male', 'female'];

$zz['fields'][5]['field_name'] = 'title';
$zz['fields'][5]['type'] = 'select';
$zz['fields'][5]['enum'] = ['GM', 'IM', 'FM', 'CM', 'WGM', 'WIM', 'WFM', 'WCM', NULL];
$zz['fields'][5]['enum_abbr'] = ['Grand Master', 'Interntional Master', 'FIDE Master', 'Candidate Master', 'WGM', 'WIM', 'WFM', 'WCM', NULL];
$zz['fields'][5]['hide_in_list_if_empty'] = true;

$zz['fields'][6]['title_tab'] = 'Title W';
$zz['fields'][6]['field_name'] = 'title_women';
$zz['fields'][6]['type'] = 'select';
$zz['fields'][6]['enum'] = ['WGM', 'WIM', 'WFM', 'WCM', NULL];
$zz['fields'][6]['enum_abbr'] = ['Woman Grand Master', 'Woman International Master', 'Woman FIDE Master', 'Woman Candidate Master', NULL];
$zz['fields'][6]['hide_in_list_if_empty'] = true;

$zz['fields'][7]['field_name'] = 'title_other';
$zz['fields'][7]['type'] = 'select';
$zz['fields'][7]['set'] = ['IA', 'FA', 'NA', 'IO', 'FT', 'FST', 'DI', 'NI', 'LSI', 'SI', 'FI', NULL];
$zz['fields'][7]['set_abbr'] = [
	'International Arbiter', 'FIDE Arbiter', 'National Arbiter', 'International Organizer',
	'FIDE Trainer', 'FIDE Senior Trainer', 'Developmental Instructor', 'National Instructor',
	'Lead School Instructor', 'School Instructor', 'FIDE Instructor', NULL
];
$zz['fields'][7]['hide_in_list_if_empty'] = true;

$zz['fields'][8]['title_tab'] = 'FOA';
$zz['fields'][8]['field_name'] = 'foa_rating';
$zz['fields'][8]['type'] = 'select';
$zz['fields'][8]['enum'] = ['AGM', 'AIM', 'AFM', 'ACM', NULL];
$zz['fields'][8]['enum_abbr'] = ['Arena Grand Master', 'Arena International Master', 'Arena FIDE Master', 'Arena Master Candidate', NULL];
$zz['fields'][8]['hide_in_list_if_empty'] = true;

$zz['fields'][9]['title_tab'] = 'Std';
$zz['fields'][9]['title'] = 'Standard rating';
$zz['fields'][9]['field_name'] = 'standard_rating';
$zz['fields'][9]['type'] = 'number';

$zz['fields'][10]['title_tab'] = 'g.';
$zz['fields'][10]['field_name'] = 'standard_games';
$zz['fields'][10]['type'] = 'number';
$zz['fields'][10]['explanation'] = 'number of STANDARD rated games in given period';

$zz['fields'][11]['title_tab'] = 'K';
$zz['fields'][11]['field_name'] = 'standard_k_factor';
$zz['fields'][11]['type'] = 'select';
$zz['fields'][11]['enum'] = [40, 20, 15, 10, NULL];

$zz['fields'][12]['title_tab'] = 'Rapid';
$zz['fields'][12]['title'] = 'Rapid rating';
$zz['fields'][12]['field_name'] = 'rapid_rating';
$zz['fields'][12]['type'] = 'number';

$zz['fields'][13]['title_tab'] = 'g.';
$zz['fields'][13]['field_name'] = 'rapid_games';
$zz['fields'][13]['type'] = 'number';
$zz['fields'][13]['explanation'] = 'number of RAPID rated games in given period';

$zz['fields'][14]['title_tab'] = 'K';
$zz['fields'][14]['field_name'] = 'rapid_k_factor';
$zz['fields'][14]['type'] = 'select';
$zz['fields'][14]['enum'] = [40, 20, 10, NULL];

$zz['fields'][15]['title_tab'] = 'Blitz';
$zz['fields'][15]['title'] = 'Blitz rating';
$zz['fields'][15]['field_name'] = 'blitz_rating';
$zz['fields'][15]['type'] = 'number';

$zz['fields'][16]['title_tab'] = 'g.';
$zz['fields'][16]['field_name'] = 'blitz_games';
$zz['fields'][16]['type'] = 'number';
$zz['fields'][16]['explanation'] = 'number of BLITZ rated games in given period';

$zz['fields'][17]['title_tab'] = 'K';
$zz['fields'][17]['field_name'] = 'blitz_k_factor';
$zz['fields'][17]['type'] = 'select';
$zz['fields'][17]['enum'] = [40, 20, 10, NULL];

$zz['fields'][18]['field_name'] = 'birth';
$zz['fields'][18]['type'] = 'date';
$zz['fields'][18]['explanation'] = 'year of birth of a player';

$zz['fields'][19]['field_name'] = 'flag';
$zz['fields'][19]['type'] = 'select';
$zz['fields'][19]['enum'] = [NULL, 'i', 'w', 'wi'];
$zz['fields'][19]['enum_abbr'] = ['none', 'inactive', 'woman', 'woman inactive'];

$zz['fields'][99]['field_name'] = 'last_update';
$zz['fields'][99]['type'] = 'timestamp';
$zz['fields'][99]['hide_in_list'] = true;


$zz['sql'] = 'SELECT fide_players.*
	FROM fide_players';
$zz['sqlorder'] = ' ORDER BY player, birth, player_id';

$zz['access'] = 'none';
$zz['if']['batch']['access'] = '';

$zz['explanation'] = sprintf('<p>FIDE Elo rating databse, Stand: %s</p>', wrap_date(wrap_setting('ratings_status[Elo]')));
