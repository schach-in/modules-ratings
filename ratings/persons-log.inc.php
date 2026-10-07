<?php

/**
 * ratings module
 * log one person identifier change
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 *
 * Variables
 * translate_pot = admin
 */


/**
 * log one identifier change
 *
 * @param string $action clear, update or insert
 * @param array $line
 * @param mixed $result
 * @param array $contacts
 * @return array
 */
function mf_ratings_persons_log($action, $line, $result, $contacts) {
	static $categories = [];
	if (!$categories) {
		$sql = 'SELECT category_id, category
			FROM categories
			WHERE category_id IN (
				/*_ID categories identifiers/id-nuliga-person _*/
				, /*_ID categories identifiers/id-fide _*/
				, /*_ID categories identifiers/pass-dsb _*/
			)';
		$categories = wrap_db_fetch($sql, 'category_id');
	}

	$values = [
		$categories[$line['identifier_category_id']]['category'] ?? '',
		$line['identifier']
	];
	$active = !empty($line['current']);
	$apply = ($_SERVER['REQUEST_METHOD'] === 'POST');
	$failed = $apply && (in_array($action, ['insert', 'delete']) ? !$result : is_null($result));
	if ($action === 'insert' && !$apply && $active)
		$text = wrap_text('%s %s would be added as active.', ['values' => $values]);
	elseif ($action === 'insert' && !$apply)
		$text = wrap_text('%s %s would be added as inactive.', ['values' => $values]);
	elseif ($action === 'insert' && $failed)
		$text = wrap_text('%s %s could not be added.', ['values' => $values]);
	elseif ($action === 'insert' && $active)
		$text = wrap_text('%s %s added as active.', ['values' => $values]);
	elseif ($action === 'insert')
		$text = wrap_text('%s %s added as inactive.', ['values' => $values]);
	elseif ($action === 'delete' && !$apply)
		$text = wrap_text('%s %s would be deleted.', ['values' => $values]);
	elseif ($action === 'delete' && $failed)
		$text = wrap_text('%s %s could not be deleted.', ['values' => $values]);
	elseif ($action === 'delete')
		$text = wrap_text('%s %s deleted.', ['values' => $values]);
	elseif (!$apply && $active)
		$text = wrap_text('%s %s would be set to active.', ['values' => $values]);
	elseif (!$apply)
		$text = wrap_text('%s %s would be set to inactive.', ['values' => $values]);
	elseif ($failed && $active)
		$text = wrap_text('%s %s could not be set to active.', ['values' => $values]);
	elseif ($failed)
		$text = wrap_text('%s %s could not be set to inactive.', ['values' => $values]);
	elseif ($active)
		$text = wrap_text('%s %s set to active.', ['values' => $values]);
	else
		$text = wrap_text('%s %s set to inactive.', ['values' => $values]);
	$note = [
		'note' => $text,
		'first_name' => $contacts[$line['contact_id']]['first_name'],
		'last_name' => $contacts[$line['contact_id']]['last_name']
	];
	if ($failed)
		$note['error'] = true;
	return $note;
}
