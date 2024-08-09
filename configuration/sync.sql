/**
 * ratings module
 * sync queries
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2012-2016, 2019-2022, 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


-- verbaende_source --
SELECT Verband, Verbandname, CONCAT(Uebergeordnet, ' ') AS Uebergeordnet
FROM dwz_verbaende;

-- verbaende_existing --
SELECT ok.identifier AS zps_code, contact_id
FROM contacts_identifiers ok
LEFT JOIN contacts USING (contact_id)
WHERE ok.identifier IN (%s)
AND identifier_category_id = /*_ID categories identifiers/pass_dsb _*/;

-- verbaende_deletable --
SELECT ok.identifier AS zps_code, contact_id, contact
FROM contacts_identifiers ok
LEFT JOIN contacts USING (contact_id)
WHERE ok.identifier NOT IN (%s)
AND ok.current = 'yes'
AND identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
AND contacts.contact_category_id = /*_ID categories contact/federation _*/
AND ISNULL(end_date);

-- verbaende_static1 --
contact_category_id = /*_ID categories contact/federation _*/;

-- verbaende_static2 --
contacts_identifiers[0][identifier_category_id] = /*_ID categories identifiers/pass_dsb _*/;

-- verbaende_static3 --
contacts_identifiers[0][current] = 'yes'

-- verbaende_static4 --
contacts_contacts[0][published] = 'yes';

-- verbaende_static5 --
contacts_contacts[0][relation_category_id] = /*_ID categories relation/member _*/;

-- verbaende_field_contacts_contacts[0][main_contact_id] --
SELECT identifier, contact_id FROM contacts_identifiers WHERE identifier IN ('%s') AND current = 'yes';

-- verbaende_field_contacts_contacts[0][main_contact_id]__implode --
/* ', ' */


/** 
 * @todo do not change from chess department to club, not all clubs
 * have SABT in their name if they are just a department
 * maybe check this for import only
 */
-- vereine_source --
SELECT ZPS, Vereinname
, IF(Vereinname REGEXP 'SABT', /*_ID categories contact/chess-department _*/, /*_ID categories contact/club _*/) AS contact_category_id
, CONCAT(Verband, ' ') AS Verband
FROM dwz_vereine;

-- vereine_existing --
SELECT contacts_identifiers.identifier, contact_id
FROM contacts
LEFT JOIN contacts_identifiers USING (contact_id)
WHERE contacts_identifiers.identifier IN (%s)
AND identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
AND current = 'yes';

-- vereine_deletable --
SELECT contacts_identifiers.identifier, contact_id, contact
FROM contacts
LEFT JOIN contacts_identifiers USING (contact_id)
WHERE contacts_identifiers.identifier NOT IN (%s)
AND identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
AND current = 'yes'
AND contact_category_id IN (/*_ID categories contact/club _*/, /*_ID categories contact/chess-department _*/)
AND ISNULL(end_date)

-- vereine_static1 --
contacts_identifiers[0][identifier_category_id] = /*_ID categories identifiers/pass_dsb _*/;

-- vereine_static2 --
contacts_identifiers[0][current] = 'yes';

-- vereine_static3 --
contacts_contacts[0][published] = 'yes';

-- vereine_static4 --
contacts_contacts[0][relation_category_id] = /*_ID categories relation/member _*/;

-- vereine_field_contacts_contacts[0][main_contact_id] --
SELECT identifier, contact_id FROM contacts_identifiers WHERE identifier IN ('%s') AND current = 'yes';

-- vereine_field_contacts_contacts[0][main_contact_id]__implode --
/* ', ' */

-- fide-players_existing --
SELECT player_id, player_id
FROM fide_players
WHERE player_id IN (%s);

-- fide-players_deletable --
SELECT player_id, player_id
FROM fide_players
WHERE player_id NOT IN (%s);

-- wikidata-players_source --
SELECT (SUBSTR(STR(?person), 33) AS ?personId) ?personLabel ?fideId ?wikipediaUrl ?langCode
WHERE {
  ?person wdt:P31 wd:Q5;
		 wdt:P1440 ?fideId.
  OPTIONAL {
	?wikipediaPage schema:about ?person;
					schema:isPartOf / wikibase:wikiGroup "wikipedia";
					schema:inLanguage ?langCode.
	FILTER(?langCode IN ("de", "en"))
	BIND(IRI(CONCAT("https://", ?langCode, ".wikipedia.org/wiki/", SUBSTR(STR(?wikipediaPage), 31))) AS ?wikipediaUrl)
  }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "[AUTO_LANGUAGE],de,en". }
}

-- wikidata-players_existing --
SELECT wikidata_id, wikidata_id
FROM wikidata_players;

