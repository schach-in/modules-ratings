<?php

/**
 * ratings module
 * retrieve DWZ ratings
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024-2025 Gustaf Mossakowski
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

/**
 * show all organisations on top level, i. e. no clubs
 * (clubs that are new will be shown, too)
 *
 * @return array
 */
function mod_ratings_make_dewis_federations() {
	wrap_package_activate('zzform'); // CSS
	$sql = 'SELECT id, club, vkz, parent_id, last_update
		FROM dewis_clubs
		WHERE ISNULL(last_sync_members) OR last_sync_members = ""
		ORDER BY vkz';
	$data = wrap_db_fetch($sql, 'id');
	foreach ($data as $id => $line) {
		$data[$id]['level'] = isset($data[$line['parent_id']]['level']) ? $data[$line['parent_id']]['level'] + 1 : 0;
		if (strlen($line['vkz']) === 5 AND $line['parent_id'] !== '1')
			$data[$id]['new'] = 1;
	}
	
	$page['text'] = wrap_template('dewis-federations', $data);
	return $page;
}

function mod_ratings_make_dewis_organisations($params) {
	$pattern = '/^[A-Z0-9]{3,5}$/';
	if (!preg_match($pattern, $params[0])) return false;
	
	$client = mf_ratings_dewis_connect();
	$data = (array) $client->organizations($params[0]);
	if (!array_key_exists('id', $data))
		wrap_quit(404, wrap_text('There is no organisation with the code %s.', ['values' => [$params[0]]]));

	$clubs = mf_ratings_dewis_organisations($data);
	if (in_array($params[0], ['000', 'C00']))
		mf_ratings_dewis_organisations_c00();
	mf_ratings_dewis_save_organisations($clubs);
	
	$sql = 'SELECT id, club, vkz, parent_id, assessor_id, last_sync_members, last_update
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
		'assessor_id' => $line['assessor'] ?? NULL
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
		wrap_syndication_http_request($url);
		usleep(wrap_setting('ratings_dewis_wait_ms'));
	}
}

function mod_ratings_make_dewis_clubimport() {
	$sql = 'SELECT dewis_clubs.id, dewis_clubs.club, dewis_clubs.vkz
			, parent_federation.vkz AS federation_vkz
			, SUBSTRING(parent_federation.vkz, 1, 1) AS confederation_vkz
			, dewis_clubs.last_update
		FROM dewis_clubs
		LEFT JOIN dewis_clubs parent_federation
			ON dewis_clubs.parent_id = parent_federation.id
		LEFT JOIN dwz_vereine
			ON dewis_clubs.vkz = dwz_vereine.ZPS
		WHERE ISNULL(dwz_vereine.ZPS)
		AND LENGTH(dewis_clubs.vkz) > 3
		AND parent_federation.vkz != "000"'; // no L0001, M0001
	$data = wrap_db_fetch($sql, 'vkz');

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$id = substr(key($_POST), 3);
		$line = $data[$id] ?? [];
		if ($line) {
			$sql = 'INSERT INTO dwz_vereine (ZPS, LV, Verband, Vereinname) VALUES ("%s", "%s", "%s", "%s")';
			$sql = sprintf($sql, $line['vkz'], $line['confederation_vkz'], $line['federation_vkz'], $line['club']);
			$success = wrap_db_query($sql);
			wrap_redirect_change();
		}
	}
	$data = array_values($data); // numeric keys
	
	$page['text'] = wrap_template('dewis-clubimport', $data);
	return $page;
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
	
	$template = 'INSERT INTO `dewis_clubs` (id, club, vkz, parent_id, assessor_id, last_update) VALUES (%s, "%s", "%s", %s, %s, NOW())';
	foreach ($clubs as $index => $club) {
		if (!$club['parent_id']) $club['parent_id'] = 'NULL';
		if (!$club['assessor_id']) $club['assessor_id'] = 'NULL';
		if (!array_key_exists($club['id'], $data)) {
			$sql = sprintf($template, $club['id'], wrap_db_escape($club['club']), $club['vkz'], $club['parent_id'], $club['assessor_id']);
			wrap_db_query($sql);
		} else {
			foreach ($club as $field_name => $value) {
				if (!empty($data[$club['id']][$field_name]) AND $data[$club['id']][$field_name] === $value) continue 2;
			}
			$sql = 'UPDATE dewis_clubs SET club = "%s", vkz = "%s", parent_id = %s, assessor_id = %s, last_update = NOW()
				WHERE id = %d';
			$sql = sprintf($sql, wrap_db_escape($club['club']), $club['vkz'], $club['parent_id'], $club['assessor_id']);
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
		foreach ($diff as $field_name => $value) {
			if ($value) $fields[] = sprintf('`%s` = "%s"', $field_name, $value);
			else $fields[] = sprintf('`%s` = NULL', $field_name);
		}
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
		(%s, "%s", "%s", %s, "%s", %s, %s, %s,
		 %s, %s, %s, %s, %s, %s, %s, %d, NOW())';
	foreach ($inserts as $member) {
		$sql = sprintf($template
			, $member['pid']
			, wrap_db_escape($member['surname'])
			, wrap_db_escape($member['firstname'])
			, mf_ratings_nullstring($member['title'])
			, $member['membership']
			, mf_ratings_nullstring($member['state'])
			, mf_ratings_nullstring($member['rating'])
			, mf_ratings_nullstring($member['ratingIndex'])
			, mf_ratings_nullstring($member['tcode'])
			, mf_ratings_nullstring($member['finishedOn'])
			, mf_ratings_nullstring($member['gender'])
			, mf_ratings_nullstring($member['yearOfBirth'])
			, mf_ratings_nullstring($member['idfide'])
			, mf_ratings_nullstring($member['elo'])
			, mf_ratings_nullstring($member['fideTitle'])
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
 * return NULL or string in parentheses
 *
 * @param string $string
 * @return string
 */
function mf_ratings_nullstring($string) {
	if (!$string) return 'NULL';
	return sprintf ('"%s"', $string);
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
	wrap_include('syndication', 'zzwrap');
	$sql = 'SELECT * FROM dewis_clubs
		WHERE LENGTH(vkz) = 5
		AND ISNULL(last_sync_members)
		OR DATE_ADD(last_sync_members, INTERVAL 90 MINUTE) < NOW()';
	$clubs = wrap_db_fetch($sql, 'id');
	
	$import = [];
	foreach ($clubs as $club) {
		$url = wrap_path('ratings_dewis_members', $club['vkz']);
		$url = wrap_setting('host_base').$url;
		list ($status, $headers, $data) = wrap_syndication_http_request($url);
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
	$sql = 'SELECT ZPS, IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr) AS Mgl_Nr
			, DWZ, DWZ_Index, rating, ratingIndex
		FROM dewis_members
		LEFT JOIN dewis_clubs
			ON dewis_members.club_id = dewis_clubs.id
		LEFT JOIN dwz_spieler
			ON dwz_spieler.ZPS = dewis_clubs.vkz
			AND TRIM(LEADING "0" FROM dwz_spieler.Mgl_Nr) = dewis_members.membership
		WHERE DWZ != rating
		OR DWZ_Index != ratingIndex';
	$updates = wrap_db_fetch($sql, '_dummy_', 'numeric');
	
	$template = 'UPDATE dwz_spieler SET DWZ = "%s", DWZ_Index = "%s" WHERE ZPS = "%s" AND IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr) = "%s";';
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
 * insert missing players from dewis_members into dwz_spieler
 *
 */
function mod_ratings_make_dewis_insert() {
	$sql = 'SELECT dewis_members.*, dewis_clubs.vkz
			, CONCAT(YEAR(finishedOn), MONTH(finishedOn)) AS letzte_auswertung
			, IF (NOT ISNULL(duplicates.pid), 1, NULL) AS duplicate
		FROM dewis_members
		LEFT JOIN dewis_clubs
			ON dewis_members.club_id = dewis_clubs.id
		LEFT JOIN dwz_spieler
			ON dwz_spieler.pid = dewis_members.pid
		LEFT JOIN dwz_spieler duplicates
			ON duplicates.ZPS = dewis_clubs.vkz
			AND TRIM(LEADING "0" FROM duplicates.Mgl_Nr) = dewis_members.membership
		WHERE ISNULL(dwz_spieler.pid)';
	$data = wrap_db_fetch($sql, '_dummy_', 'numeric');

	$template = 'INSERT INTO dwz_spieler (
			PID, ZPS, Mgl_Nr, Status, Spielername, Geschlecht,
			Spielberechtigung, Geburtsjahr, Letzte_Auswertung, DWZ, DWZ_Index, FIDE_Elo,
			FIDE_Titel, FIDE_ID, FIDE_Land
		) VALUES (
			%s, "%s", %s, "%s", "%s", "%s", NULL, %s, %s, %s, %s, %s, %s, %s, NULL
		);';
	foreach ($data as $line) {
		if ($line['duplicate']) {
			wrap_error('Duplicate membership. '.json_encode($line));
			continue;
		}
		$sql = sprintf($template
			, $line['pid']
			, $line['vkz']
			, $line['membership']
			, $line['state'] ? $line['state'] : 'A'
			, $line['surname'].','.$line['firstname'].($line['title'] ? ','.$line['title'] : '')
			, $line['gender']
			, mf_ratings_nullstring($line['yearOfBirth'])
			, mf_ratings_nullstring($line['letzte_auswertung'])
			, mf_ratings_nullstring($line['rating'])
			, mf_ratings_nullstring($line['ratingIndex'])
			, mf_ratings_nullstring($line['elo'])
			, mf_ratings_nullstring($line['fideTitle'])
			, mf_ratings_nullstring($line['idfide'])
		);
		$result = wrap_db_query($sql);
	}
	return false;
}

/**
 * connect to DeWIS
 *
 * @return object
 */
function mf_ratings_dewis_connect() {
	ini_set('default_socket_timeout', wrap_setting('ratings_dewis_socket_timeout'));
	if (wrap_setting('ratings_dewis_ssl')) {
		$client = new SOAPClient(wrap_setting('ratings_dewis_url'));
	} else {
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
	return $client;
}
