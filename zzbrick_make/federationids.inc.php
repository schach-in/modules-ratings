<?php

/**
 * ratings module
 * write federation identifiers to database
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2025-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 *
 * Variables
 * translate_pot = admin
 */


function mod_ratings_make_federationids() {
	$data = mod_ratings_make_federationids_dsb();
	$contact_ids = [];
	foreach ($data as $index => $line)
		$contact_ids[$index] = $line['contact_id'];
	
	if ($contact_ids) {
		$sql = 'SELECT contact_id, contact, identifier
			FROM contacts
			WHERE contact_id IN (%s)';
		$sql = sprintf($sql, implode(',', $contact_ids));
		$contacts = wrap_db_fetch($sql, 'contact_id');
		foreach ($data as $index => $line) {
			$data[$index]['contact'] = $contacts[$contact_ids[$index]]['contact'];
			$data[$index]['identifier'] = $contacts[$contact_ids[$index]]['identifier'];
		}
	} else {
		$data['no_data'] = 1;
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
	$sql = wrap_sql_query('ratings_federation_dsb');
	$remote_data = wrap_db_fetch($sql, '_dummy_', 'numeric');
	if (!$remote_data) return [];
	$contact_ids = [];
	foreach ($remote_data as $line)
		$contact_ids[$line['contact_id']] = $line['contact_id'];
	
	$sql = wrap_sql_query('ratings_federation_contact_identifiers');
	$sql = sprintf($sql, implode(',', $contact_ids));
	$local_data = wrap_db_fetch($sql, ['contact_id', 'contact_identifier_id']);

	$data = [];
	foreach ($remote_data as $line) {
		$contact_id = $line['contact_id'];
		$details = mod_ratings_make_federationids_update($line, $local_data[$contact_id] ?? []);
		if (!$details) continue;
		$data[$contact_id]['contact_id'] = $contact_id;
		foreach ($details as $detail)
			$data[$contact_id]['details'][] = $detail;
		// a contact can have several rows (one per membership): re-read after changes
		$sql = wrap_sql_query('ratings_federation_contact_identifiers');
		$sql = sprintf($sql, $contact_id);
		$local_data[$contact_id] = wrap_db_fetch($sql, 'contact_identifier_id');
	}
	return $data;
}

/**
 * update federation IDs
 *
 * @param array $remote data from remote source
 * @param array $records local data in database
 * @return array list of messages
 */
function mod_ratings_make_federationids_update($remote, $records) {
	$paths = ['pass-dsb', 'id-fide', 'id-nuliga-person'];
	
	// check existing records
	$new = $remote;
	$actions = [
		'update' => [],
		'insert' => []
	];
	foreach ($paths as $path) {
		$key = sprintf('player_%s', str_replace('-', '_', $path));
		if (empty($remote[$key])) continue;
		$current = sprintf('%s_current', $key);
		$full_path = sprintf('identifiers/%s', $path);
		foreach ($records as $contact_identifier_id => $record) {
			if ($record['identifier_category_id'] !== wrap_category_id($full_path)) continue;
			if ($record['identifier'] === $remote[$key]) {
				if (array_key_exists($current, $remote) AND $remote[$current] !== $record['current']) {
					$is_current = array_key_exists($current, $remote) ? ($remote[$current] ? 1 : NULL) : 1;
					$actions['update'][] = [
						'contact_identifier_id' => $record['contact_identifier_id'],
						'current' => $is_current ? 'yes' : NULL,
						'msg' => [
							'category' => $full_path,
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
						'category' => $full_path,
						'identifier' => $record['identifier'],
						'action' => 'deactivate'
					]
				];
			}
		}
	}
	
	// add new records?
	foreach ($paths as $path) {
		$key = sprintf('player_%s', str_replace('-', '_', $path));
		if (empty($new[$key])) continue;
		$current = sprintf('%s_current', $key);
		$full_path = sprintf('identifiers/%s', $path);
		$actions['insert'][] = [
			'contact_id' => $new['contact_id'],
			'identifier' => $new[$key],
			'identifier_category_id' => wrap_category_id($full_path),
			'current' => array_key_exists($current, $new) ? ($new[$current] ? 'yes' : NULL) : 'yes',
			'msg' => [
			   'category' => $full_path,
			   'identifier' => $new[$key],
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

	$messages = [];
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
			$messages[] = $msg;
		}
	}
	return $messages;
}
