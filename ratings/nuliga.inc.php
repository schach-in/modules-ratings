<?php

/**
 * ratings module
 * nuLiga club list import (parser and staging helpers)
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Build clubSearch URL for a regional list.
 *
 * @param string $federation nuLiga searchPattern (e.g. DE.NO.06)
 * @param string|null $confederation nuLiga federation parameter (e.g. LV0 DSB)
 * @return string
 */
function mf_ratings_nuliga_list_url($federation, $confederation = null) {
	if (!$confederation)
		$confederation = wrap_setting('ratings_nuliga_confederation');
	$query = http_build_query([
		'searchPattern' => $federation,
		'federation' => $confederation,
	], '', '&', PHP_QUERY_RFC3986);
	return sprintf(
		'%s?%s',
		wrap_setting('ratings_nuliga_club_search_url'),
		$query
	);
}

/**
 * Fetch HTML from nuLiga (GET, cached via wrap_syndication).
 *
 * @param string $url
 * @return string|false
 */
function mf_ratings_nuliga_fetch($url) {
	wrap_include('syndication', 'zzwrap');
	$result = wrap_syndication($url, [
		'type' => 'html',
		'error_code' => E_USER_NOTICE,
	]);
	if (empty($result['_']['data']))
		return false;
	$filename = $result['_']['filename'] ?? wrap_cache_filename('url', $url);
	if (!$filename OR !is_readable($filename))
		return false;
	$data = file_get_contents($filename);
	if ($data === false OR $data === '')
		return false;
	return $data;
}

/**
 * Look up one club by DSB ZPS via nuLiga clubSearch (GET).
 *
 * @param string $zps
 * @param string|null $confederation
 * @return array|null parsed club row, or null if not found
 */
function mf_ratings_nuliga_fetch_club_by_zps($zps, $confederation = null) {
	if (!$confederation)
		$confederation = wrap_setting('ratings_nuliga_confederation');
	$zps = mf_ratings_zps_normalize($zps);
	$query = http_build_query([
		'federation' => $confederation,
		'searchFor' => $zps,
	], '', '&', PHP_QUERY_RFC3986);
	$url = sprintf(
		'%s?%s',
		wrap_setting('ratings_nuliga_club_search_url'),
		$query
	);
	$data = mf_ratings_nuliga_fetch($url);
	if (!$data)
		return null;
	$clubs = mf_ratings_nuliga_parse_club_list($data, 'zps:'.$zps, $confederation);
	foreach ($clubs as $club) {
		if ($club['zps'] === $zps)
			return $club;
	}
	return null;
}

/**
 * Parse nuLiga clubSearch HTML into club rows.
 *
 * @param string $html
 * @param string $federation nuLiga searchPattern or gapfill sentinel
 * @param string $confederation nuLiga federation parameter
 * @return array list of club records with venues sub-array
 */
function mf_ratings_nuliga_parse_club_list($html, $federation, $confederation) {
	$html = mf_ratings_nuliga_decode_emails($html);
	libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);
	$rows = $xpath->query('//tr[.//a[contains(@href, "clubInfoDisplay?club=")]]');
	if (!$rows)
		return [];

	$clubs = [];
	foreach ($rows as $row) {
		$club = mf_ratings_nuliga_parse_club_row($row, $xpath, $federation, $confederation);
		if (!$club)
			continue;
		$clubs[] = $club;
	}
	return $clubs;
}

/**
 * Save parsed clubs and venues to staging tables.
 *
 * @param array $clubs
 * @param int $run_id
 * @return array counts clubs, venues
 */
function mf_ratings_nuliga_save_clubs($clubs, $run_id) {
	$counts = ['clubs' => 0, 'venues' => 0];
	foreach ($clubs as $club) {
		$venues = $club['venues'] ?? [];
		unset($club['venues']);
		mf_ratings_nuliga_save_club($club, $run_id);
		$counts['clubs']++;

		$sql = sprintf(
			'DELETE FROM nuliga_venues WHERE nuliga_club_id = %d',
			$club['nuliga_club_id']
		);
		wrap_db_query($sql);
		foreach ($venues as $venue) {
			mf_ratings_nuliga_save_venue($club['nuliga_club_id'], $venue);
			$counts['venues']++;
		}
	}
	return $counts;
}

/**
 * Start an import run record.
 *
 * @param string|null $federation null = all federations
 * @param string $confederation
 * @return int run_id
 */
function mf_ratings_nuliga_import_run_start($federation, $confederation) {
	$federation_sql = $federation
		? '"'.wrap_db_escape($federation).'"'
		: 'NULL';
	$sql = 'INSERT INTO nuliga_import_runs (federation, confederation, started_at)
		VALUES (%s, "%s", NOW())';
	$sql = sprintf($sql, $federation_sql, wrap_db_escape($confederation));
	$result = wrap_db_query($sql);
	return $result['id'] ?? 0;
}

/**
 * Finish an import run record.
 *
 * @param int $run_id
 * @param array $counts
 * @param string|null $error_message
 * @return void
 */
function mf_ratings_nuliga_import_run_finish($run_id, $counts, $error_message = null) {
	$sql = 'UPDATE nuliga_import_runs SET
			clubs_fetched = %d,
			clubs_saved = %d,
			venues_saved = %d,
			finished_at = NOW(),
			error_message = %s
		WHERE run_id = %d';
	$sql = sprintf(
		$sql,
		$counts['clubs_fetched'] ?? 0,
		$counts['clubs_saved'] ?? 0,
		$counts['venues_saved'] ?? 0,
		$error_message ? '"'.wrap_db_escape($error_message).'"' : 'NULL',
		$run_id
	);
	wrap_db_query($sql);
}

/**
 * Import one or all regional nuLiga club lists.
 *
 * @param string|null $federation null = all federations from settings
 * @return array summary for template
 */
function mf_ratings_nuliga_import($federation = null) {
	$confederation = wrap_setting('ratings_nuliga_confederation');
	$federations = wrap_setting('ratings_nuliga_federations');
	if ($federation) {
		if (!array_key_exists($federation, $federations))
			wrap_quit(404, wrap_text('Unknown nuLiga federation %s.', ['values' => [$federation]]));
		$federations = [$federation => $federations[$federation]];
	}

	$run_id = mf_ratings_nuliga_import_run_start($federation, $confederation);
	$summary = [
		'run_id' => $run_id,
		'federations' => [],
		'clubs_fetched' => 0,
		'clubs_saved' => 0,
		'venues_saved' => 0,
		'errors' => [],
	];

	foreach ($federations as $code => $label) {
		$url = mf_ratings_nuliga_list_url($code, $confederation);
		$html = mf_ratings_nuliga_fetch($url);
		if (!$html) {
			$summary['errors'][] = ['message' => sprintf('%s: fetch failed', $code)];
			continue;
		}
		$clubs = mf_ratings_nuliga_parse_club_list($html, $code, $confederation);
		$summary['clubs_fetched'] += count($clubs);
		$counts = mf_ratings_nuliga_save_clubs($clubs, $run_id);
		$summary['clubs_saved'] += $counts['clubs'];
		$summary['venues_saved'] += $counts['venues'];
		$summary['federations'][$code] = [
			'label' => $label,
			'clubs' => count($clubs),
			'venues' => $counts['venues'],
		];
		usleep(200000);
	}

	$error_message = $summary['errors']
		? implode("\n", array_column($summary['errors'], 'message'))
		: null;
	mf_ratings_nuliga_import_run_finish($run_id, $summary, $error_message);
	return $summary;
}

/**
 * Fill staging gaps: ZPS on schach.in contacts missing from nuliga_clubs.
 *
 * @return array
 */
function mf_ratings_nuliga_gapfill() {
	$sql = 'SELECT ok.identifier AS zps
		FROM contacts_identifiers ok
		LEFT JOIN contacts USING (contact_id)
		LEFT JOIN nuliga_clubs nc ON nc.zps = ok.identifier
		WHERE ok.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		AND ok.current = "yes"
		AND contacts.contact_category_id IN (
			/*_ID categories contact/club _*/,
			/*_ID categories contact/chess-department _*/
		)
		AND ISNULL(nc.nuliga_club_id)
		ORDER BY ok.identifier';
	$missing = wrap_db_fetch($sql, 'zps');
	if (!$missing)
		return ['gapfill' => 0, 'clubs_saved' => 0, 'venues_saved' => 0];

	$run_id = mf_ratings_nuliga_import_run_start('gapfill', wrap_setting('ratings_nuliga_confederation'));
	$counts = ['clubs_saved' => 0, 'venues_saved' => 0];
	foreach ($missing as $zps) {
		$club = mf_ratings_nuliga_fetch_club_by_zps($zps);
		if (!$club)
			continue;
		$saved = mf_ratings_nuliga_save_clubs([$club], $run_id);
		$counts['clubs_saved'] += $saved['clubs'];
		$counts['venues_saved'] += $saved['venues'];
		usleep(200000);
	}
	mf_ratings_nuliga_import_run_finish($run_id, [
		'clubs_fetched' => count($missing),
		'clubs_saved' => $counts['clubs_saved'],
		'venues_saved' => $counts['venues_saved'],
	]);
	return array_merge(['gapfill' => count($missing)], $counts);
}

/**
 * Merge id_nuliga identifiers from staging into contacts (matched by ZPS).
 *
 * @return array
 */
function mf_ratings_nuliga_merge_identifiers() {
	$category_id = wrap_category_id('identifiers/id_nuliga');
	if (!$category_id)
		wrap_quit(500, wrap_text('Category identifiers/id_nuliga is not configured.'));

	$sql = 'SELECT ci.contact_id, ci.identifier
		FROM contacts_identifiers ci
		INNER JOIN contacts c USING (contact_id)
		WHERE ci.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		AND ci.current = "yes"
		AND c.contact_category_id IN (
			/*_ID categories contact/club _*/,
			/*_ID categories contact/chess-department _*/
		)';
	$contacts_by_zps = [];
	$data = wrap_db_fetch($sql, 'contact_id');
	foreach ($data as $row) {
		$zps = mf_ratings_zps_normalize($row['identifier']);
		if (!isset($contacts_by_zps[$zps]))
			$contacts_by_zps[$zps] = (int) $row['contact_id'];
	}

	$sql = 'SELECT nuliga_club_id, zps FROM nuliga_clubs ORDER BY zps';
	$clubs = wrap_db_fetch($sql, 'nuliga_club_id');
	$stats = ['matched' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];

	foreach ($clubs as $club) {
		$zps = mf_ratings_zps_normalize($club['zps']);
		$contact_id = $contacts_by_zps[$zps] ?? null;
		if (!$contact_id) {
			$stats['skipped']++;
			continue;
		}
		$stats['matched']++;
		$nuliga_id = (string) $club['nuliga_club_id'];
		$sql = 'SELECT contact_identifier_id, identifier, current
			FROM contacts_identifiers
			WHERE contact_id = %d
			AND identifier_category_id = %d';
		$sql = sprintf($sql, $contact_id, $category_id);
		$existing = wrap_db_fetch($sql, 'contact_identifier_id');
		$found = false;
		foreach ($existing as $record) {
			if ($record['identifier'] === $nuliga_id) {
				$found = true;
				if (!$record['current']) {
					zzform_update('contacts-identifiers', [
						'contact_identifier_id' => $record['contact_identifier_id'],
						'current' => 'yes',
					]);
					$stats['updated']++;
				}
				continue;
			}
			if ($record['current']) {
				zzform_update('contacts-identifiers', [
					'contact_identifier_id' => $record['contact_identifier_id'],
					'current' => null,
				]);
			}
		}
		if ($found)
			continue;
		zzform_insert('contacts-identifiers', [
			'contact_id' => $contact_id,
			'identifier' => $nuliga_id,
			'identifier_category_id' => $category_id,
			'current' => 'yes',
		], E_USER_WARNING);
		$stats['inserted']++;
	}
	return $stats;
}

/**
 * Parse one club table row.
 *
 * @param DOMElement $row
 * @param DOMXPath $xpath
 * @param string $federation
 * @param string $confederation
 * @return array|null
 */
function mf_ratings_nuliga_parse_club_row($row, $xpath, $federation, $confederation) {
	$link = $xpath->query('.//a[contains(@href, "clubInfoDisplay?club=")]', $row)->item(0);
	if (!$link)
		return null;
	if (!preg_match('/club=(\d+)/', $link->getAttribute('href'), $match))
		return null;

	$cells = $xpath->query('./td', $row);
	$zps = null;
	$club_cell = $cells->item(0);
	if ($club_cell) {
		$club_cell_text = trim($club_cell->textContent);
		if (preg_match('/\(([A-Z0-9]{5})\)\s*$/', $club_cell_text, $zps_match))
			$zps = $zps_match[1];
	}
	if (!$zps)
		return null;

	$contact = mf_ratings_nuliga_parse_contact_cell($cells->item(1));
	$venues = mf_ratings_nuliga_parse_venues_cell($cells->item(2));

	return array_merge([
		'nuliga_club_id' => (int) $match[1],
		'zps' => $zps,
		'club_name' => trim($link->textContent),
		'federation' => $federation,
		'confederation' => $confederation,
	], $contact, ['venues' => $venues]);
}

/**
 * Parse official contact column.
 *
 * @param DOMNode|null $cell
 * @return array
 */
function mf_ratings_nuliga_parse_contact_cell($cell) {
	$data = [
		'contact_name' => null,
		'contact_street' => null,
		'contact_postcode' => null,
		'contact_place' => null,
		'phone' => null,
		'email' => null,
		'website' => null,
	];
	if (!$cell)
		return $data;

	$website = null;
	foreach ($cell->getElementsByTagName('a') as $anchor) {
		$href = $anchor->getAttribute('href');
		if (str_starts_with($href, 'http'))
			$website = $href;
	}
	$data['website'] = $website;

	$lines = mf_ratings_nuliga_node_lines($cell);
	$phone_lines = [];
	foreach ($lines as $index => $line) {
		if ($index === 0) {
			if (str_contains($line, ' - ')) {
				[$data['contact_name'], $address_line] = explode(' - ', $line, 2);
				$data['contact_name'] = trim($data['contact_name']);
				$parsed = mf_ratings_nuliga_parse_address_line(trim($address_line));
				$data['contact_street'] = $parsed['street'];
				$data['contact_postcode'] = $parsed['postcode'];
				$data['contact_place'] = $parsed['place'];
			}
			continue;
		}
		if (str_contains($line, '@')) {
			$data['email'] = $line;
			continue;
		}
		if (preg_match('/^[mTGf]:?\s/i', $line) OR preg_match('/\+?\d/', $line))
			$phone_lines[] = $line;
	}
	if ($phone_lines)
		$data['phone'] = implode(', ', $phone_lines);
	return $data;
}

/**
 * Parse venues column.
 *
 * @param DOMNode|null $cell
 * @return array
 */
function mf_ratings_nuliga_parse_venues_cell($cell) {
	$venues = [];
	if (!$cell)
		return $venues;
	foreach ($cell->getElementsByTagName('li') as $item) {
		$text = trim(preg_replace('/\s+/', ' ', $item->textContent));
		if (!$text)
			continue;
		$sequence = 1;
		if (preg_match('/^\((\d+)\)\s*(.+)$/', $text, $match)) {
			$sequence = (int) $match[1];
			$text = trim($match[2]);
		}
		$parsed = mf_ratings_nuliga_parse_address_line($text, true);
		$venues[] = [
			'sequence' => $sequence,
			'venue_name' => $parsed['name'],
			'street' => $parsed['street'],
			'postcode' => $parsed['postcode'],
			'place' => $parsed['place'],
		];
	}
	return $venues;
}

/**
 * Split table cell into text lines at &lt;br&gt; boundaries.
 *
 * @param DOMNode $cell
 * @return array
 */
function mf_ratings_nuliga_node_lines($cell) {
	$lines = [];
	$current = '';
	foreach ($cell->childNodes as $child) {
		if ($child->nodeName === 'br') {
			if (trim($current) !== '')
				$lines[] = trim(preg_replace('/\s+/', ' ', $current));
			$current = '';
			continue;
		}
		if ($child->nodeName === 'a') {
			$href = $child->getAttribute('href');
			if (str_starts_with($href, 'http'))
				continue;
		}
		if ($child->nodeName === 'script')
			continue;
		$current .= $child->textContent;
	}
	if (trim($current) !== '')
		$lines[] = trim(preg_replace('/\s+/', ' ', $current));
	return $lines;
}

/**
 * Insert or update one club in nuliga_clubs.
 *
 * @param array $club
 * @param int $run_id
 * @return void
 */
function mf_ratings_nuliga_save_club($club, $run_id) {
	$fields = [
		'zps', 'club_name', 'federation', 'confederation',
		'contact_name', 'contact_street', 'contact_postcode', 'contact_place',
		'phone', 'email', 'website',
	];
	$values = [];
	foreach ($fields as $field) {
		$value = $club[$field] ?? null;
		$values[] = $value === null ? 'NULL' : '"'.wrap_db_escape($value).'"';
	}
	$on_duplicate = [];
	$row_alias = mf_ratings_nuliga_mysql_insert_row_alias();
	foreach ($fields as $field) {
		if ($row_alias)
			$on_duplicate[] = $field.' = new.'.$field;
		else
			$on_duplicate[] = $field.' = VALUES('.$field.')';
	}
	$on_duplicate[] = $row_alias
		? 'import_run_id = new.import_run_id'
		: 'import_run_id = VALUES(import_run_id)';
	$on_duplicate[] = 'last_update = NOW()';
	$row_alias_sql = $row_alias ? ' AS new' : '';
	$sql = 'INSERT INTO nuliga_clubs (
			nuliga_club_id, zps, club_name, federation, confederation,
			contact_name, contact_street, contact_postcode, contact_place,
			phone, email, website, import_run_id, last_update
		) VALUES (
			%d, %s, %s, %s, %s,
			%s, %s, %s, %s,
			%s, %s, %s, %d, NOW()
		)'.$row_alias_sql.' ON DUPLICATE KEY UPDATE
			'.implode(",\n\t\t\t", $on_duplicate);
	$sql = sprintf(
		$sql,
		...array_merge([$club['nuliga_club_id']], $values, [$run_id])
	);
	wrap_db_query($sql);
}

/**
 * Whether INSERT … AS alias ON DUPLICATE KEY UPDATE is supported (MySQL 8.0.19+).
 *
 * @return bool
 */
function mf_ratings_nuliga_mysql_insert_row_alias() {
	static $supported;

	if (isset($supported))
		return $supported;

	$version = mysqli_get_server_info(wrap_db_connection());
	if (stripos($version, 'MariaDB') !== false) {
		$supported = false;
		return $supported;
	}
	if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $match)) {
		$supported = false;
		return $supported;
	}
	$supported = version_compare(
		sprintf('%d.%d.%d', $match[1], $match[2], $match[3]),
		'8.0.19',
		'>='
	);
	return $supported;
}

/**
 * Insert one venue row.
 *
 * @param int $nuliga_club_id
 * @param array $venue
 * @return void
 */
function mf_ratings_nuliga_save_venue($nuliga_club_id, $venue) {
	$fields = ['venue_name', 'street', 'postcode', 'place'];
	$values = [];
	foreach ($fields as $field) {
		$value = $venue[$field] ?? null;
		$values[] = $value === null ? 'NULL' : '"'.wrap_db_escape($value).'"';
	}
	$sql = 'INSERT INTO nuliga_venues (
			nuliga_club_id, sequence, venue_name, street, postcode, place, last_update
		) VALUES (%d, %d, %s, %s, %s, %s, NOW())';
	$sql = sprintf(
		$sql,
		...array_merge([$nuliga_club_id, $venue['sequence'] ?? 1], $values)
	);
	wrap_db_query($sql);
}

/**
 * Parse German address line(s) into components.
 *
 * @param string $text
 * @param bool $with_name leading segment before street is a venue name
 * @return array
 */
function mf_ratings_nuliga_parse_address_line($text, $with_name = false) {
	$result = ['name' => null, 'street' => null, 'postcode' => null, 'place' => null];
	if (!preg_match('/,\s*(\d{5})\s+(.+)$/', $text, $match))
		return array_merge($result, ['street' => $text ?: null]);

	$result['postcode'] = $match[1];
	$result['place'] = trim($match[2]);
	$before = trim(substr($text, 0, -strlen($match[0])));
	$parts = array_map('trim', explode(',', $before));
	if (!$with_name OR count($parts) === 1) {
		$result['street'] = $before ?: null;
		return $result;
	}
	$result['street'] = array_pop($parts);
	$result['name'] = implode(', ', $parts) ?: null;
	return $result;
}

/**
 * Replace encodeEmail(...) script tags with plain addresses.
 *
 * @param string $html
 * @return string
 */
function mf_ratings_nuliga_decode_emails($html) {
	return preg_replace_callback(
		"/<script[^>]*>\s*encodeEmail\s*\(\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*\)\s*<\/script>/i",
		function ($match) {
			return mf_ratings_nuliga_encode_email(
				$match[1], $match[2], $match[3], $match[4]
			);
		},
		$html
	);
}

/**
 * Build e-mail from nuLiga encodeEmail parts.
 *
 * @param string $domain TLD part (de, com, …)
 * @param string $user local part
 * @param string $host domain host
 * @param string $user2 optional second local segment
 * @return string
 */
function mf_ratings_nuliga_encode_email($domain, $user, $host, $user2) {
	$local = $user;
	if ($user2 !== '')
		$local .= '.'.$user2;
	$email = $local.'@'.$host;
	if ($domain !== '')
		$email .= '.'.$domain;
	return $email;
}
