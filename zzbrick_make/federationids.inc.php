<?php

/**
 * ratings module
 * write federation identifiers to database
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_ratings_make_federationids() {
	$data = mod_ratings_make_federationids_dsb();
	$contact_ids = [];
	foreach ($data as $index => $line)
		$contact_ids[$index] = $line['contact_id'];
	
	
	$sql = 'SELECT contact_id, contact, identifier
		FROM contacts
		WHERE contact_id IN (%s)';
	$sql = sprintf($sql, implode(',', $contact_ids));
	$contacts = wrap_db_fetch($sql, 'contact_id');
	foreach ($data as $index => $line) {
		$data[$index]['contact'] = $contacts[$contact_ids[$index]]['contact'];
		$data[$index]['identifier'] = $contacts[$contact_ids[$index]]['identifier'];
	}

	$page['text'] = wrap_template('federationids', $data);
	return $page;
}

/**
 * update data from German Chess Federation (DSB)
 *
 * @return array
 */
function mod_ratings_make_federationids_dsb() {
	$sql = wrap_sql_query('ratings_federation_dsb_id');
	$remote_data = wrap_db_fetch($sql, '_dummy_', 'numeric');
	$contact_ids = [];
	foreach ($remote_data as $index => $line)
		$contact_ids[$index] = $line['contact_id'];
	
	$sql = wrap_sql_query('ratings_federation_contact_identifiers');
	$sql = sprintf($sql, implode(',', $contact_ids));
	$local_data = wrap_db_fetch($sql, ['contact_id', 'contact_identifier_id']);

	$data = [];
	foreach ($remote_data as $index => $line)
		$data += mod_ratings_make_federationids_update($line, $local_data[$contact_ids[$index]]);
	return $data;
}

/**
 * update federation IDs
 *
 * @param array $remote data from remote source
 * @param array $records local data in database
 */
function mod_ratings_make_federationids_update($remote, $records) {
	$categories = ['pass_dsb', 'id_dsb', 'id_fide'];
	
	// check existing records
	$new = $remote;
	$actions = [
		'update' => [],
		'insert' => []
	];
	foreach ($categories as $category) {
		$key = sprintf('player_%s', $category);
		if (empty($remote[$key])) continue;
		$current = sprintf('player_%s_current', $category);
		$path = sprintf('identifiers/%s', $category);
		foreach ($records as $contact_identifier_id => $record) {
			if ($record['identifier_category_id'] !== wrap_category_id($path)) continue;
			if ($record['identifier'] === $remote[$key]) {
				if (array_key_exists($current, $remote) AND $remote[$current] !== $record['current']) {
					$is_current = array_key_exists($current, $remote) ? ($remote[$current] ? 1 : NULL) : 1;
					$actions['update'][] = [
						'contact_identifier_id' => $record['contact_identifier_id'],
						'current' => $is_current ? 'yes' : NULL,
						'msg' => [
							'category' => $path,
							'identifier' => $record['identifier'],
							'action' => $is_current ? 'activate' : 'deactivate'
						]
					];
				}
				unset($new[$key]);
			} elseif (array_key_exists($current, $remote) AND $remote[$current] AND $record['current']) {
				$actions['update'][] = [
					'contact_identifier_id' => $record['contact_identifier_id'],
					'current' => NULL,
					'msg' => [
						'category' => $path,
						'identifier' => $record['identifier'],
						'action' => 'deactivate'
					]
				];
			}
		}
	}
	
	// add new records?
	foreach ($categories as $category) {
		$key = sprintf('player_%s', $category);
		if (empty($new[$key])) continue;
		$current = sprintf('player_%s_current', $category);
		$path = sprintf('identifiers/%s', $category);
		$actions['insert'][] = [
			'contact_id' => $new['contact_id'],
			'identifier' => $new[$key],
			'identifier_category_id' => wrap_category_id($path),
			'current' => array_key_exists($current, $new) ? ($new[$current] ? 'yes' : NULL) : 'yes',
			'msg' => [
			   'category' => $path,
			   'identifier' => $record['identifier'],
			   'action' => 'add'
			]
		];
	}

	if ($actions['update']) {
		// sort actions so that current = yes will be set last to avoid problems with UNIQUE
		usort($actions['update'], function($a, $b) {
			if (empty($a['current']) && !empty($b['current']))
				return -1;
			if (!empty($a['current']) && empty($b['current']))
				return 1;
			return 0;
		});
	}

	$data = [];	
	// actions, first update, then insert
	$types = ['update', 'insert'];
	foreach ($types as $type) {
		foreach ($actions[$type] as $line) {
			$msg = $line['msg'];
			unset($line['msg']);
			switch ($type) {
				case 'update':
					$success = zzform_update('contacts-identifiers', $line); break;
				case 'insert':
					$success = zzform_insert('contacts-identifiers', $line); break;
			}
			if (!$success) $msg['error'] = true;
			$data[$new['contact_id']]['contact_id'] = $new['contact_id'];
			$data[$new['contact_id']]['details'][] = $msg;
		}
	}
	return $data;
}
