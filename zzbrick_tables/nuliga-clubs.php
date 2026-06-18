<?php

/**
 * ratings module
 * nuLiga club staging data
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


$zz['title'] = 'nuLiga Clubs';
$zz['table'] = 'nuliga_clubs';

$zz['fields'][1]['title'] = 'ID';
$zz['fields'][1]['field_name'] = 'nuliga_club_id';
$zz['fields'][1]['type'] = 'id';
$zz['fields'][1]['show_id'] = true;
$zz['fields'][1]['import_id_value'] = true;
$zz['fields'][1]['explanation'] = 'nuLiga club number';
$zz['fields'][1]['link'] = 'https://dsb-schach.liga.nu/cgi-bin/WebObjects/nuLigaSCHACHDE.woa/wa/clubInfoDisplay?club=';

$zz['fields'][2]['title'] = 'ZPS';
$zz['fields'][2]['field_name'] = 'zps';
$zz['fields'][2]['size'] = 6;
$zz['fields'][2]['explanation'] = 'German club code (Passnummer Verein)';

$zz['fields'][3]['title'] = 'Club';
$zz['fields'][3]['field_name'] = 'club_name';
$zz['fields'][3]['unless']['export_mode']['list_prefix'] = '<strong>';
$zz['fields'][3]['unless']['export_mode']['list_suffix'] = '</strong>';
$zz['fields'][3]['character_set'] = 'utf8';
$zz['fields'][3]['unless']['export_mode']['list_append_next'] = true;
$zz['fields'][3]['link'] = [
	'area' => 'contacts_profile[%s]',
	'area_fields' => ['contact_scope'],
	'fields' => ['identifier'],
];

$zz['fields'][4] = zzform_include('nuliga-venues');
$zz['fields'][4]['title'] = 'Venues';
$zz['fields'][4]['type'] = 'subtable';
$zz['fields'][4]['form_display'] = 'vertical';
$zz['fields'][4]['min_records'] = 0;
$zz['fields'][4]['max_records'] = 10;
$zz['fields'][4]['fields'][2]['type'] = 'foreign_key';
$zz['fields'][4]['fields'][3]['type'] = 'sequence';
$zz['fields'][4]['sql'] .= ' ORDER BY sequence';
$zz['fields'][4]['unless']['export_mode']['subselect']['prefix'] = '<br>';
$zz['fields'][4]['unless']['export_mode']['subselect']['concat_rows'] = '<br>';
$zz['fields'][4]['if']['export_mode']['subselect']['prefix'] = '';
$zz['fields'][4]['if']['export_mode']['subselect']['suffix'] = '';
$zz['fields'][4]['if']['export_mode']['subselect']['concat_rows'] = '; ';
$zz['fields'][4]['if']['export_mode']['subselect']['field_suffix'][0] = ', ';
$zz['fields'][4]['if']['export_mode']['subselect']['field_suffix'][1] = ' ';
$zz['fields'][4]['if']['export_mode']['subselect']['field_suffix'][2] = ', ';

$zz['fields'][5]['title'] = 'Federation';
$zz['fields'][5]['field_name'] = 'federation';

$zz['fields'][6]['title'] = 'Confederation';
$zz['fields'][6]['field_name'] = 'confederation';
$zz['fields'][6]['hide_in_list'] = true;

$zz['fields'][7]['title'] = 'Contact';
$zz['fields'][7]['field_name'] = 'contact_name';
$zz['fields'][7]['unless']['export_mode']['list_append_next'] = true;

$zz['fields'][8]['title'] = 'Street';
$zz['fields'][8]['field_name'] = 'contact_street';
$zz['fields'][8]['unless']['export_mode']['list_prefix'] = '<p>';
$zz['fields'][8]['unless']['export_mode']['list_append_next'] = true;

$zz['fields'][9]['field_name'] = 'contact_postcode';
$zz['fields'][9]['size'] = 8;
$zz['fields'][9]['title_append'] = 'Postcode/Place';
$zz['fields'][9]['unless']['export_mode']['append_next'] = true;
$zz['fields'][9]['unless']['export_mode']['list_prefix'] = '<p>';
$zz['fields'][9]['unless']['export_mode']['list_append_next'] = true;

$zz['fields'][10]['field_name'] = 'contact_place';
$zz['fields'][10]['unless']['export_mode']['list_prefix'] = ' ';
$zz['fields'][10]['unless']['export_mode']['list_append_next'] = true;

$zz['fields'][11]['field_name'] = 'phone';
$zz['fields'][11]['unless']['export_mode']['list_prefix'] = '<p>';
$zz['fields'][11]['unless']['export_mode']['list_append_next'] = true;

$zz['fields'][12]['field_name'] = 'email';
$zz['fields'][12]['type'] = 'email';
$zz['fields'][12]['unless']['export_mode']['list_prefix'] = '<p>';

$zz['fields'][13]['field_name'] = 'website';
$zz['fields'][13]['type'] = 'url';
$zz['fields'][13]['hide_in_list'] = true;

$zz['fields'][14]['title'] = 'Import run';
$zz['fields'][14]['field_name'] = 'import_run_id';
$zz['fields'][14]['type'] = 'select';
$zz['fields'][14]['sql'] = 'SELECT run_id
		, CONCAT("#", run_id, " ", IFNULL(federation, "all"), " ", started_at) AS import_run
	FROM nuliga_import_runs
	ORDER BY run_id DESC';
$zz['fields'][14]['display_field'] = 'import_run';
$zz['fields'][14]['hide_in_list'] = true;
$zz['fields'][14]['export'] = false;
$zz['fields'][14]['exclude_from_search'] = true;

$zz['fields'][99]['field_name'] = 'last_update';
$zz['fields'][99]['type'] = 'timestamp';
$zz['fields'][99]['hide_in_list'] = true;

$zz['sql'] = 'SELECT nuliga_clubs.*
		, contacts.identifier
		, (CASE WHEN LOCATE("&type=", contact_categories.parameters) > 0 THEN
			SUBSTRING_INDEX(SUBSTRING_INDEX(contact_categories.parameters, "&type=", -1), "&", 1)
			ELSE "*" END
		) AS contact_scope
	FROM nuliga_clubs
	LEFT JOIN contacts_identifiers ok
		ON ok.identifier = nuliga_clubs.zps
		AND ok.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		AND ok.current = "yes"
	LEFT JOIN contacts
		ON contacts.contact_id = ok.contact_id
		AND contacts.contact_category_id IN (
			/*_ID categories contact/club _*/,
			/*_ID categories contact/chess-department _*/
		)
	LEFT JOIN categories contact_categories
		ON contacts.contact_category_id = contact_categories.category_id';
$zz['sqlorder'] = ' ORDER BY federation, zps, club_name';

$zz['access'] = 'none';
$zz['if']['batch_mode']['access'] = '';

$zz['export'][] = 'CSV Excel';
$zz['export_no_html'] = true;

$zz['filter'][1]['title'] = wrap_text('Federation');
$zz['filter'][1]['type'] = 'list';
$zz['filter'][1]['where'] = 'federation';
$zz['filter'][1]['field_name'] = 'federation';
$zz['filter'][1]['sql'] = 'SELECT DISTINCT federation, federation
	FROM nuliga_clubs
	ORDER BY federation';

$zz['filter'][2]['title'] = wrap_text('ZPS');
$zz['filter'][2]['type'] = 'search';
$zz['filter'][2]['where'] = 'zps';
$zz['filter'][2]['field_name'] = 'zps';
