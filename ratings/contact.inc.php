<?php 

/**
 * ratings module
 * contact functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function mf_ratings_contact($data, $ids) {
	$contact_id = key($data);
	$data[$contact_id] += mf_ratings_by_contact($contact_id);
	
	$data['templates']['contact_6'][] = 'contact-ratings';
	return $data;
}
