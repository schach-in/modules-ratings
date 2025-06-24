<?php

/**
 * ratings module
 * always available functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * get highest FIDE title
 *
 * @param array $line with 'title', 'title_women'
 * @return array
 */
function mf_ratings_fidetitle($line) {
	static $titles = [];

	$line['fide_title'] = '';
	$line['fide_title_long'] = '';
	if (empty($line['title']) AND empty($line['women_title'])) return $line;

	if (!$titles) {
		$sql = 'SELECT category_id, category_short, category
				, SUBSTRING_INDEX(path, "/", -1) AS path_short
			FROM categories
			WHERE main_category_id = /*_ID categories fide-title _*/
			ORDER BY sequence ASC';
		$titles = wrap_db_fetch($sql, '_dummy_', 'numeric');
	}
	$title_fields = ['title', 'women_title'];

	foreach ($titles as $title) {
		foreach ($title_fields as $field) {
			if (!array_key_exists($field, $line)) continue;
			if ($line[$field] !== $title['category_short']) continue;
			$line['fide_title'] = $title['category_short'];
			$line['fide_title_long'] = $title['category'];
			return $line;
		}
	}
	return $line;
}

/**
 * psrse FIDE title_other field for other titles
 *
 * @param array $line
 * @return array
 */
function mf_ratings_fideother($line) {
	static $titles = [];
	if (!$titles) {
		$titles = wrap_category_id('fide-title', 'list');
		$sql = 'SELECT categories.category_id, categories.category, categories.category_short
			FROM categories
			LEFT JOIN categories main_categories
				ON categories.main_category_id = main_categories.category_id
		    WHERE categories.category_id IN (%s)
		    ORDER BY LENGTH(categories.path) - LENGTH(REPLACE(categories.path, "/", "")),
			    main_categories.sequence, categories.sequence';
		$sql = sprintf($sql, implode(', ', $titles));
		$titles = wrap_db_fetch($sql, 'category_id');
	}
	if (empty($line['title_other'])) return $line;
	$other_titles = explode(',', $line['title_other']);
	foreach ($other_titles as $other_title) {
		$other_title = trim($other_title);
		foreach ($titles as $title) {
			if ($title['category_short'] !== $other_title) continue;
			$line['other_titles'][] = [
				'title' => $title['category_short'],
				'title_long' => $title['category']
			];
		}
	}
	return $line;
}

/**
 * get rating data for a given contact ID
 *
 * dsb_dwz
 * fide_title
 * fide_title_women
 * fide_title_other
 * fide_elo
 * fide_elo_rapid
 * fide_elo_blitz
 *
 * @param mixed $contact_ids (int or array of integers)
 * @return array
 */
function mf_ratings_contact($contact_ids) {
	$contact_id = !is_array($contact_ids) ? $contact_ids : NULL;
    $queries = [];
	if (wrap_setting('ratings_list_dsb'))
		$queries[] = wrap_sql_query('ratings_contact_dsb');
	// fide must be last, confederations may list FIDE Elo, too
	if (wrap_setting('ratings_list_fide'))
		$queries[] = wrap_sql_query('ratings_contact_fide');
	if (!$queries) return [];

	$ratings = [];	
	foreach ($queries as $sql) {
		if (!$sql) continue;
		$sql = sprintf($sql, $contact_id ?? implode(', ', $contact_ids));
		$federation_ratings = wrap_db_fetch($sql, 'contact_id');
		foreach ($federation_ratings as $id => $line) {
			if (array_key_exists($id, $ratings))
				$ratings[$id] = array_merge($ratings[$id], $line);
			else $ratings[$id] = $line;
		}
	}
	if ($contact_id) return $ratings[$contact_id] ?? [];
	return $ratings;
}

/**
 * get a list of active players from German Chess Federation (DSB) database
 *
 * @param array $filters
 * @return array
 */
function mf_ratings_players_dsb($filters = []) {
	$where = [];
	$single = false;
	if (!empty($filters['player_id_dsb'])) {
		$where[] = sprintf('PID = %d', $filters['player_id_dsb']);
		$single = true;
	} elseif (!empty($filters['player_pass_dsb'])) {
		list($zps, $mgl_nr) = explode('-', $code);
		$where[] = sprintf(
			'ZPS = "%s" AND IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr) = "%s"'
			, $zps, $mgl_nr
		);
		$single = true;
	} else {
		if (!empty($filters['club_code_dsb']))
			$where[] = sprintf('ZPS = "%s"', wrap_db_escape($filters['club_code_dsb']));
		if (!empty($filters['player_id_dsb_excluded']))
			$where[] = sprintf('PID NOT IN (%s)', wrap_db_escape(implode(',', $filters['player_id_dsb_excluded'])));
		if (!empty($filters['min_age']))
			$where[] = sprintf('Geburtsjahr <= %d', date('Y') - $filters['min_age']);
		if (!empty($filters['max_age']))
			$where[] = sprintf('Geburtsjahr >= %d', date('Y') - $filters['max_age']);
		if (!empty($filters['sex']) AND $filters['sex'] === 'male')
			$where[] = 'Geschlecht = "M"';
		if (!empty($filters['sex']) AND $filters['sex'] === 'female')
			$where[] = 'Geschlecht = "W"';
	}

	$sql = wrap_sql_query('ratings_players_dsb');
	$sql = sprintf($sql, $where ? sprintf(' AND %s ', implode(' AND ', $where)) : '');
	$data = wrap_db_fetch($sql, 'player_id_dsb');
	if ($single) return reset($data);
	return $data;
}
