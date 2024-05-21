<?php 

/**
 * ratings module
 * players in German DWZ database
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/clubs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2013-2017, 2020, 2022-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


$zz['title'] = 'DWZ Spieler';
$zz['table'] = 'dwz_spieler';

$zz['fields'][4]['field_name'] = 'Spielername';

$zz['fields'][5]['field_name'] = 'Spielername_G';
$zz['fields'][5]['hide_in_list'] = true;
$zz['fields'][5]['exclude_from_search'] = true;

$zz['fields'][16]['field_name'] = 'Verein';
$zz['fields'][16]['type'] = 'display';
$zz['fields'][16]['display_field'] = 'Vereinname';
$zz['fields'][16]['character_set'] = 'utf8';

$zz['fields'][1]['field_name'] = 'ZPS';
$zz['fields'][1]['explanation'] = 'ZPS-Nummer des Vereins';

$zz['fields'][2]['title'] = 'Mitgliedsnummer';
$zz['fields'][2]['title_tab'] = 'Mitgl.';
$zz['fields'][2]['field_name'] = 'Mgl_Nr';
$zz['fields'][2]['type'] = 'number';
$zz['fields'][2]['link'] = [
	'area' => 'ratings_dsb_profile',
	'fields' => ['ZPS', 'Mgl_Nr']
];
$zz['fields'][1]['explanation'] = 'Mitgliedsnummer im Verein';

$zz['fields'][3]['title_tab'] = 'St';
$zz['fields'][3]['field_name'] = 'Status';
$zz['fields'][3]['type'] = 'select';
$zz['fields'][3]['enum'] = ['A', 'P'];
$zz['fields'][3]['enum_abbr'] = ['Aktiv', 'Passiv'];

$zz['fields'][6]['title_tab'] = 'G.';
$zz['fields'][6]['field_name'] = 'Geschlecht';
$zz['fields'][6]['type'] = 'select';
$zz['fields'][6]['enum'] = ['M', 'W'];
$zz['fields'][6]['enum_abbr'] = ['männlich', 'weiblich'];

$zz['fields'][7]['title_tab'] = 'Sb.';
$zz['fields'][7]['field_name'] = 'Spielberechtigung';
$zz['fields'][7]['type'] = 'select';
$zz['fields'][7]['enum'] = ['D', 'G', 'E', 'A', '-', 'J'];
$zz['fields'][7]['enum_abbr'] = ['Deutscher', 'Gleichgestellt', 'EU-Ausländer', 'Ausländer', '-', 'J'];

$zz['fields'][8]['title_tab'] = 'Geb.';
$zz['fields'][8]['field_name'] = 'Geburtsjahr';
$zz['fields'][8]['type'] = 'number';

$zz['fields'][9]['title_tab'] = 'DWZ Ausw.';
$zz['fields'][9]['field_name'] = 'Letzte_Auswertung';
$zz['fields'][9]['type'] = 'number';
$zz['fields'][9]['explanation'] = 'Woche der letzten Turnierauswertung';

$zz['fields'][10]['field_name'] = 'DWZ';
$zz['fields'][10]['type'] = 'number';

$zz['fields'][11]['title_tab'] = 'I.';
$zz['fields'][11]['field_name'] = 'DWZ_Index';
$zz['fields'][11]['type'] = 'number';

$zz['fields'][12]['title'] = 'Elo';
$zz['fields'][12]['field_name'] = 'FIDE_Elo';

$zz['fields'][13]['title'] = 'Titel';
$zz['fields'][13]['field_name'] = 'FIDE_Titel';
$zz['fields'][13]['hide_in_list_if_empty'] = true;

$zz['fields'][14]['title'] = 'FIDE-ID';
$zz['fields'][14]['field_name'] = 'FIDE_ID';
$zz['fields'][14]['link'] = [
	'area' => 'ratings_fide_profile',
	'fields' => ['FIDE_ID']
];

$zz['fields'][15]['field_name'] = 'FIDE_Land';


$zz['sql'] = 'SELECT dwz_spieler.*, dwz_vereine.Vereinname
	FROM dwz_spieler
	LEFT JOIN dwz_vereine USING (ZPS)';
$zz['sqlorder'] = ' ORDER BY Spielername, Geburtsjahr, Mgl_Nr';

$zz['access'] = 'none';

$zz['explanation'] = sprintf('<p>DWZ-Datenbank des Deutschen Schachbundes, Stand: %s</p>', wrap_date(wrap_setting('ratings_status[DWZ]')));
