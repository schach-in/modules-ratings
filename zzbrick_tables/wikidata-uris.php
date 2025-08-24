<?php 

/**
 * ratings module
 * URLs of chess players in Wikipedia
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


$zz['title'] = 'Wikipedia Chess Players';
$zz['table'] = 'wikidata_uris';

$zz['fields'][1]['title'] = 'ID';
$zz['fields'][1]['field_name'] = 'uri_id';
$zz['fields'][1]['type'] = 'id';

$zz['fields'][2]['field_name'] = 'wikidata_id';
$zz['fields'][2]['prefix'] = 'Q';
$zz['fields'][2]['explanation'] = 'Wikidata Q-ID';
$zz['fields'][2]['link'] = 'https://www.wikidata.org/wiki/Q';

$zz['fields'][3]['field_name'] = 'uri';
$zz['fields'][3]['type'] = 'url';

$zz['fields'][4]['field_name'] = 'uri_lang';

$zz['fields'][99]['field_name'] = 'last_update';
$zz['fields'][99]['type'] = 'timestamp';
$zz['fields'][99]['hide_in_list'] = true;


$zz['sql'] = 'SELECT wikidata_uris.*
	FROM wikidata_uris
	LEFT JOIN wikidata_players USING (wikidata_id)';
$zz['sqlorder'] = ' ORDER BY person, fide_id, wikidata_id, uri_lang';

//$zz['access'] = 'none';
$zz['if']['batch']['access'] = '';

$zz['subselect']['sql'] = 'SELECT wikidata_id, uri
    FROM wikidata_uris
    ORDER BY uri_lang';
