<?php

/**
 * ratings module
 * import nuLiga club lists into staging tables
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * nuLiga import overview and actions
 *
 * URL params:
 * - (none): status page
 * - import: import all regional lists (POST)
 * - DE.xx.xx: import one federation (searchPattern)
 * - gapfill: GET lookup by ZPS for clubs missing in staging
 * - merge: POST write id_nuliga identifiers onto contacts
 * - clubs: POST enqueue or run hourly import + merge (background job)
 *
 * @param array $params
 * @return array|false
 */
function mod_ratings_make_nuliga($params) {
	wrap_include('nuliga', 'ratings');

	if (!empty($params[0]) AND $params[0] === 'clubs')
		return mod_ratings_make_nuliga_clubs($params);

	wrap_package_activate('zzform');

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (!empty($params[0]) AND $params[0] === 'merge') {
			$data = mf_ratings_nuliga_merge_identifiers();
			$data['merge_done'] = 1;
			$page['text'] = wrap_template('nuliga', $data);
			return $page;
		}
		if (!empty($params[0]) AND $params[0] === 'gapfill') {
			$data = mf_ratings_nuliga_gapfill();
			$data['gapfill_done'] = 1;
			$page['text'] = wrap_template('nuliga', $data);
			return $page;
		}
		$federation = null;
		if (!empty($params[0]) AND $params[0] !== 'import')
			$federation = $params[0];
		$data = mf_ratings_nuliga_import($federation);
		$data['federation_results'] = [];
		foreach ($data['federations'] as $code => $line) {
			$data['federation_results'][] = array_merge(['federation' => $code], $line);
		}
		unset($data['federations']);
		$data['import_done'] = 1;
		$page['text'] = wrap_template('nuliga', $data);
		return $page;
	}

	if (!empty($params[0])) {
		if ($params[0] === 'merge' OR $params[0] === 'gapfill' OR $params[0] === 'import')
			return false;
		if (array_key_exists($params[0], wrap_setting('ratings_nuliga_federations'))) {
			$data = mf_ratings_nuliga_import($params[0]);
			$data['federation_results'] = [];
			foreach ($data['federations'] as $code => $line) {
				$data['federation_results'][] = array_merge(['federation' => $code], $line);
			}
			unset($data['federations']);
			$data['import_done'] = 1;
			$page['text'] = wrap_template('nuliga', $data);
			return $page;
		}
		return false;
	}

	$sql = 'SELECT COUNT(*) AS clubs FROM nuliga_clubs';
	$data = wrap_db_fetch($sql);
	$sql = 'SELECT COUNT(*) AS venues FROM nuliga_venues';
	$data = array_merge($data, wrap_db_fetch($sql));
	$sql = 'SELECT COUNT(*) AS missing FROM contacts_identifiers ok
		LEFT JOIN contacts USING (contact_id)
		LEFT JOIN nuliga_clubs nc ON nc.zps = ok.identifier
		WHERE ok.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
		AND ok.current = "yes"
		AND contacts.contact_category_id IN (
			/*_ID categories contact/club _*/,
			/*_ID categories contact/chess-department _*/
		)
		AND ISNULL(nc.nuliga_club_id)';
	$data = array_merge($data, wrap_db_fetch($sql));

	$sql = 'SELECT run_id, federation, confederation, clubs_fetched, clubs_saved,
			venues_saved, started_at, finished_at, error_message
		FROM nuliga_import_runs
		ORDER BY run_id DESC
		LIMIT 10';
	$data['runs'] = wrap_db_fetch($sql, 'run_id');
	$data['federation_links'] = [];
	foreach (wrap_setting('ratings_nuliga_federations') as $code => $label) {
		$data['federation_links'][] = [
			'federation' => $code,
			'label' => $label,
		];
	}
	$data['overview'] = 1;
	$page['text'] = wrap_template('nuliga', $data);
	return $page;
}

/**
 * Hourly nuLiga import + merge (background job worker).
 *
 * POST without sequential: enqueue worker via job manager.
 * POST with sequential: import all federations, then merge id_nuliga.
 *
 * @param array $params
 * @return array
 */
function mod_ratings_make_nuliga_clubs($params) {
	wrap_setting('cache', false);

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		$page['text'] = wrap_text('nuLiga clubs (POST to enqueue or run).');
		return $page;
	}

	if (!array_key_exists('sequential', $_POST)) {
		wrap_job(wrap_path('nuliga_clubs'), [
			'sequential' => 1,
			'job_category_id' => wrap_category_id('jobs/nuliga'),
			'trigger' => 1,
		]);
		wrap_job_debug('JOB STARTING nuliga clubs', $_POST);
		$page['text'] = wrap_text('Background job queued.');
		return $page;
	}

	$lock = wrap_lock('nuliga', 'sequential', 3600);
	if ($lock) {
		$page['status'] = 403;
		$page['text'] = wrap_text('nuLiga import is already running.');
		return $page;
	}

	$import = mf_ratings_nuliga_import(null);
	$merge = mf_ratings_nuliga_merge_identifiers();
	wrap_unlock('nuliga');

	$page['text'] = sprintf(
		wrap_text('nuLiga clubs: %d clubs saved, merge matched %d, inserted %d, updated %d, skipped %d.'),
		$import['clubs_saved'] ?? 0,
		$merge['matched'] ?? 0,
		$merge['inserted'] ?? 0,
		$merge['updated'] ?? 0,
		$merge['skipped'] ?? 0
	);
	return $page;
}
