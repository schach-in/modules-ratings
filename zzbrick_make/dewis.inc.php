<?php

/**
 * ratings module
 * retrieve DWZ ratings
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mod_ratings_make_dewis($params) {
	// @todo show overview of functions
}

/**
 * -----------------------------------------------
 * organisations
 * -----------------------------------------------
 */

function mod_ratings_make_dewis_organisations($params) {
	$pattern = '/^[A-Z0-9]{3,5}$/';
	if (!preg_match($pattern, $params[0])) return false;
	
	$client = mf_ratings_dewis_connect();
	$data = (array) $client->organizations($params[0]);
	$clubs = mf_ratings_dewis_organisations($data);
	if (in_array($params[0], ['000', 'C00']))
		mf_ratings_dewis_organisations_c00();
	mf_ratings_dewis_save_organisations($clubs);
	
	$sql = 'SELECT id, club, vkz, parent_id, assessor, last_sync_members, last_update
		FROM dewis_clubs
		%s
		ORDER BY vkz';
	$sql = sprintf($sql, $params[0] !== '000' ? sprintf('WHERE vkz LIKE "%s%%"', $params[0]) : '');
	$data = wrap_db_fetch($sql, 'id');
	$data['top_organisation_identifier'] = $params[0];

	$page['text'] = wrap_template('dewis-organisations', $data);
	return $page;
}

function mf_ratings_dewis_organisations($line) {
	$clubs = mf_ratings_dewis_organisation($line);
	return $clubs;
}

function mf_ratings_dewis_organisation($line) {
	$clubs[] = [
		'id' => $line['id'],
		'club' => $line['club'],
		'vkz' => $line['vkz'],
		'parent_id' => $line['p'],
		'assessor' => $line['assessor'] ?? ''
	];
	if (!empty($line['children'])) {
		foreach ($line['children'] as $child) {
			$child = (array) $child;
			$clubs = array_merge($clubs, mf_ratings_dewis_organisation($child));
		}
	}
	return $clubs;
}

function mf_ratings_dewis_organisations_c00() {
	$sql = 'SELECT ZPS AS vkz
		FROM dwz_vereine WHERE ZPS LIKE "C%"';
	$clubs = wrap_db_fetch($sql, '_dummy_', 'numeric');
	
	foreach ($clubs as $club) {
		$url = wrap_path('ratings_dewis_organisations', $club['vkz']);
		$url = wrap_setting('host_base').$url;
		wrap_syndication_retrieve_via_http($url);
		usleep(wrap_setting('ratings_dewis_wait_ms'));
	}
}

/**
 * -----------------------------------------------
 * members
 * -----------------------------------------------
 */

function mod_ratings_make_dewis_members($params) {
	$pattern = '/^[A-Z0-9]{3,5}$/';
	if (!preg_match($pattern, $params[0])) return false;
	$client = mf_ratings_dewis_connect();

	$data = (array) $client->unionRatingList($params[0]);
	return mf_ratings_dewis_save_members($data);
}


function mf_ratings_dewis_save_organisations($clubs) {
	$sql = 'SELECT * FROM dewis_clubs';
	$data = wrap_db_fetch($sql, 'id');
	
	$template = 'INSERT INTO `dewis_clubs` (id, club, vkz, parent_id, assessor, last_update) VALUES (%s, "%s", "%s", "%s", "%s", NOW())';
	foreach ($clubs as $index => $club) {
		if (!array_key_exists($club['id'], $data)) {
			$sql = sprintf($template, $club['id'], wrap_db_escape($club['club']), $club['vkz'], $club['parent_id'], $club['assessor']);
			wrap_db_query($sql);
		} else {
			foreach ($club as $field_name => $value) {
				if (!empty($data[$club['id']][$field_name]) AND $data[$club['id']][$field_name] === $value) continue 2;
			}
			$sql = 'UPDATE dewis_clubs SET club = "%s", vkz = "%s", parent_id = "%s", assessor = "%s", last_update = NOW()
				WHERE id = %d';
			$sql = sprintf($sql, wrap_db_escape($club['club']), $club['vkz'], $club['parent_id'], $club['assessor']);
			wrap_db_query($sql);
		}
	}
}


function mf_ratings_dewis_save_members($data) {
	$data['union'] = (array) $data['union'];
	$sql = 'SELECT id FROM dewis_clubs WHERE vkz = "%s"';
	$sql = sprintf($sql, wrap_db_escape($data['union']['vkz']));
	$club_id = wrap_db_fetch($sql, '', 'single value');

	$sql = 'SELECT pid, surname, firstname, title,
		membership, state, rating, ratingIndex, tcode, finishedOn, gender, yearOfBirth,
		idfide, elo, fideTitle, club_id, last_update
		FROM dewis_members WHERE club_id = %d';
	$sql = sprintf($sql, $club_id);
	$members = wrap_db_fetch($sql, 'membership');
	
	$no_update = [];
	$updates = [];
	$inserts = [];
	$deletes = [];
	foreach ($data['members'] as $member) {
		$member = (array) $member;
		if (array_key_exists($member['membership'], $members)) {
			$diff = array_diff($member, $members[$member['membership']]);
			if (!$diff) {
				$no_update[] = $member['membership'];
				$members[$member['membership']]['status'] = 'no_update';
			} else {
				$updates[] = $member;
				$members[$member['membership']]['status'] = 'update';
			}
		} else {
			$inserts[] = $member;
			$members[$member['membership']] = $member;
			$members[$member['membership']]['status'] = 'insert';
		}
	}
	foreach ($members as $membership => $member) {
		if (!empty($member['status'])) continue;
		$members[$member['membership']]['status'] = 'delete';
		$deletes[] = $member;
	}
	
	foreach ($updates as $member) {
		$diff = array_diff($member, $members[$member['membership']]);
		$fields = [];
		foreach ($diff as $field_name => $value)
			$fields[] = sprintf('`%s` = "%s"', $field_name, $value);
		$sql = 'UPDATE dewis_members
			SET %s, last_update = NOW()
			WHERE club_id = %d
			AND membership = %d';
		$sql = sprintf($sql, implode(', ', $fields), $club_id, $member['membership']);
		wrap_db_query($sql);
	}

	$template = 'INSERT INTO `dewis_members` (pid, surname, firstname, title,
		membership, state, rating, ratingIndex, tcode, finishedOn, gender, yearOfBirth,
		idfide, elo, fideTitle, club_id, last_update)
		VALUES 
		(%s, "%s", "%s", "%s", "%s", "%s", "%s", "%s",
		 "%s", "%s", "%s", "%s", "%s", "%s", "%s", %d, NOW())';
	foreach ($inserts as $member) {
		$sql = sprintf($template
			, $member['pid']
			, wrap_db_escape($member['surname'])
			, wrap_db_escape($member['firstname'])
			, $member['title']
			, $member['membership']
			, $member['state']
			, $member['rating']
			, $member['ratingIndex']
			, $member['tcode']
			, $member['finishedOn']
			, $member['gender']
			, $member['yearOfBirth']
			, $member['idfide']
			, $member['elo']
			, $member['fideTitle']
			, $club_id
		);
		wrap_db_query($sql);
	}

	foreach ($deletes as $member) {
		$sql = 'DELETE FROM dewis_members
			WHERE club_id = %d
			AND membership = %d';
		$sql = sprintf($sql, $club_id, $member['membership']);
		wrap_db_query($sql);
	}

	$sql = 'UPDATE dewis_clubs SET last_sync_members = NOW() WHERE id = %d';
	$sql = sprintf($sql, $club_id);
	wrap_db_query($sql);

	$members['club_id'] = $club_id;
	$members['name'] = $data['union']['name'];
	$members['vkz'] = $data['union']['vkz'];
	$members['organisationsart'] = $data['union']['organisationsart'];

	$page['text'] = wrap_template('dewis-members', $members);
	return $page;
}

/**
 * -----------------------------------------------
 * import
 * -----------------------------------------------
 */

/**
 * import member list for all clubs
 *
 */
function mod_ratings_make_dewis_import() {
	wrap_include_files('syndication', 'zzwrap');
	$sql = 'SELECT * FROM dewis_clubs
		WHERE LENGTH(vkz) = 5
		AND ISNULL(last_sync_members)
		OR DATE_ADD(last_sync_members, INTERVAL 90 MINUTE) < NOW()';
	$clubs = wrap_db_fetch($sql, 'id');
	
	$import = [];
	foreach ($clubs as $club) {
		$url = wrap_path('ratings_dewis_members', $club['vkz']);
		$url = wrap_setting('host_base').$url;
		list ($status, $headers, $data) = wrap_syndication_retrieve_via_http($url);
		$import[] = [
			'club_identifier' => $club['vkz'],
			'status' => $status,
			'url' => $url,
			'time' => date('Y-m-d H:i:s')
		];
		usleep(wrap_setting('ratings_dewis_wait_ms'));
	}
	$page['text'] = wrap_template('dewis-import', $import);
	return $page;
}

/**
 * -----------------------------------------------
 * update
 * -----------------------------------------------
 */

/**
 * update existing dwz_spieler table with updated ratings
 *
 */
function mod_ratings_make_dewis_update() {
	$sql = 'SELECT ZPS, Mgl_Nr, DWZ, DWZ_Index, rating, ratingIndex
		FROM dewis_members
		LEFT JOIN dewis_clubs
			ON dewis_members.club_id = dewis_clubs.id
		LEFT JOIN dwz_spieler
			ON dwz_spieler.ZPS = dewis_clubs.vkz
			AND TRIM(LEADING "0" FROM dwz_spieler.Mgl_Nr) = dewis_members.membership
		WHERE DWZ != rating
		OR DWZ_Index != ratingIndex';
	$updates = wrap_db_fetch($sql, '_dummy_', 'numeric');
	
	$template = 'UPDATE dwz_spieler SET DWZ = "%s", DWZ_Index = "%s" WHERE ZPS = "%s" AND Mgl_Nr = "%s";';
	foreach ($updates as $line) {
		if (!$line['rating'] OR !$line['Mgl_Nr']) {
			wrap_error('illegal line in DWZ update: '.json_encode($line));
			continue;
		}
		$sql = sprintf($template, $line['rating'], $line['ratingIndex'], $line['ZPS'], $line['Mgl_Nr']);
		wrap_db_query($sql);
	}
	$page['text'] = wrap_template('dewis-update', $updates);
	return $page;
}

/**
 * connect to DeWIS
 *
 * @return object
 */
function mf_ratings_dewis_connect() {
	ini_set('default_socket_timeout', wrap_setting('ratings_dewis_socket_timeout'));
	$client = new SOAPClient(wrap_setting('ratings_dewis_url'));
	return $client;

	$context = stream_context_create([
		'ssl' => [
			// set some SSL/TLS specific options
			'verify_peer' => false,
			'verify_peer_name' => false,
			'allow_self_signed' => true
		]
	]);
	$client = new SOAPClient(wrap_setting('ratings_dewis_url'), [
		'stream_context' => $context
	]);
}
