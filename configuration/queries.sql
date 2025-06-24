/**
 * ratings module
 * SQL queries
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2023-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


-- ratings_contact_dsb --
SELECT contact_id
	, PID AS player_id_dsb
	, FIDE_ID AS player_id_fide
	, CONCAT(dwz_spieler.ZPS, "-", IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr)) AS player_pass_dsb
	, DWZ AS dsb_dwz
	, FIDE_Elo AS fide_elo, FIDE_Titel AS fide_title
	, REPLACE(Spielername, ",", ", ") AS dsb_player_last_first
FROM dwz_spieler
LEFT JOIN contacts_identifiers pk
	ON CONCAT(dwz_spieler.ZPS, "-", IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr)) = pk.identifier
	AND identifier_category_id = /*_ID categories identifiers/pass_dsb _*/
	AND pk.current = "yes"
WHERE contact_id IN (%s);

-- ratings_contact_fide --
SELECT contact_id, player_id AS player_id_fide
	, standard_rating AS fide_elo, rapid_rating AS fide_elo_rapid, blitz_rating AS fide_elo_blitz
	, title AS fide_title, title_women AS fide_title_women, title_other AS fide_title_other
	, player AS fide_player_last_first
FROM fide_players
LEFT JOIN contacts_identifiers
	ON contacts_identifiers.identifier = fide_players.player_id
	AND identifier_category_id = /*_ID categories identifiers/id_fide _*/
	AND contacts_identifiers.current = "yes"
WHERE contact_id IN (%s);

-- ratings_debug_fide_dsb_sex --
/* Sex of player differs between FIDE and DSB data */
SELECT PID, ZPS, IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr) AS Mgl_Nr, Spielername, FIDE_ID, FIDE_Land, sex, Geschlecht
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE IF(sex = "F", "W", "M") != Geschlecht
AND NOT ISNULL(sex);

-- ratings_debug_fide_dsb_title --
/* Title of player differs between FIDE and DSB data */
SELECT PID, ZPS, IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr) AS Mgl_Nr, Spielername, FIDE_Titel, FIDE_ID, FIDE_Land, title
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE dwz_spieler.FIDE_Titel != fide_players.title
OR (ISNULL(dwz_spieler.FIDE_Titel) AND NOT ISNULL(fide_players.title))
OR (NOT ISNULL(dwz_spieler.FIDE_Titel) AND ISNULL(fide_players.title));

-- ratings_debug_fide_dsb_nation --
/* Nation of player differs between FIDE and DSB data */
SELECT PID, ZPS, IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr) AS Mgl_Nr, Spielername, FIDE_Titel, FIDE_ID
	, FIDE_Land AS DSB_fed, federation AS FIDE_fed
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE dwz_spieler.FIDE_Land != fide_players.federation;

-- ratings_debug_fide_dsb_elo_missing --
/* Player has FIDE Elo which is missing in DSB database */
SELECT DISTINCT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, standard_rating
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE ISNULL(dwz_spieler.FIDE_Elo) AND NOT ISNULL(fide_players.standard_rating);

-- ratings_debug_fide_dsb_elo_extra --
/* Player has no FIDE Elo, but there is a rating in the DSB database */
SELECT DISTINCT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, standard_rating
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE NOT ISNULL(dwz_spieler.FIDE_Elo) AND ISNULL(fide_players.standard_rating);

-- ratings_debug_fide_dsb_elo_different_above_2000 --
/* FIDE Elo rating is different in DSB database, players >= 2000 Elo */
SELECT DISTINCT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, standard_rating
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE dwz_spieler.FIDE_Elo != fide_players.standard_rating
AND fide_players.standard_rating >= 2000;

-- ratings_debug_fide_dsb_elo_different_below_2000 --
/* FIDE Elo rating is different in DSB database, players < 2000 Elo */
SELECT DISTINCT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, standard_rating
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE dwz_spieler.FIDE_Elo != fide_players.standard_rating
AND fide_players.standard_rating < 2000;

-- ratings_debug_fide_dsb_elo_different_below_2000_recalculation_does_not_match --
/* FIDE Elo rating is different in DSB database, players < 2000 Elo, where substraction of bonus does not match */
SELECT DISTINCT PID, Spielername, FIDE_Elo, FIDE_ID, FIDE_Land, standard_rating
	, ROUND(((standard_rating - 800) / 0.6), 0) AS adjusted_rating
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE dwz_spieler.FIDE_Elo != fide_players.standard_rating
AND fide_players.standard_rating < 2000
HAVING adjusted_rating != dwz_spieler.FIDE_Elo;

-- ratings_debug_dsb_player_twice_in_same_club --
/* Player has more than one membership entry in the same club */
SELECT dwz_spieler.PID, dwz_spieler.ZPS, IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr) AS Mgl_Nr, dwz_spieler.Status
	, dwz_spieler.Spielername
FROM dwz_spieler
JOIN dwz_spieler duplicates
	ON duplicates.PID = dwz_spieler.PID
	AND dwz_spieler.ZPS = duplicates.ZPS
	AND dwz_spieler.Mgl_Nr != duplicates.Mgl_Nr;

-- ratings_debug_dsb_player_active_in_different_clubs --
/* Player has active status in more than one club */
SELECT dwz_spieler.PID, dwz_spieler.ZPS, IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr) AS Mgl_Nr, dwz_spieler.Status
	, dwz_spieler.Spielername
FROM dwz_spieler
JOIN dwz_spieler duplicates
	ON duplicates.PID = dwz_spieler.PID
	AND dwz_spieler.Status = "A"
	AND duplicates.Status = "A"
	AND dwz_spieler.ZPS != duplicates.ZPS;

-- ratings_debug_fide_dsb_change_last_first --
/* Player’s first and last name are interchanged */
SELECT DISTINCT PID, Spielername, FIDE_ID, player
FROM dwz_spieler
LEFT JOIN fide_players
ON dwz_spieler.fide_id = fide_players.player_id
WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(Spielername, ",", ", "), "ä", "ae"), "ü", "ue"), "ß", "ss"), "ö", "oe"), "Ö", "Oe"), "Ä", "Ae"), "Ü", "Ue") != REPLACE(REPLACE(REPLACE(player, ", Dr.", ""), ", Prof. Dr.", ""), ", Prof.", "")
AND SUBSTRING_INDEX(Spielername, ",", -1) = SUBSTRING_INDEX(player, ", ", 1);

-- ratings_debug_fide_dsb_change_name --
/* Player’s name is different */
SELECT DISTINCT PID, Spielername, FIDE_ID, player
FROM dwz_spieler
LEFT JOIN fide_players
ON dwz_spieler.fide_id = fide_players.player_id
WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(Spielername, ",", ", "), "ä", "ae"), "ü", "ue"), "ß", "ss"), "ö", "oe"), "Ö", "Oe"), "Ä", "Ae"), "Ü", "Ue") != REPLACE(REPLACE(REPLACE(player, ", Dr.", ""), ", Prof. Dr.", ""), ", Prof.", "")
AND SUBSTRING_INDEX(Spielername, ",", -1) != SUBSTRING_INDEX(player, ", ", 1);

-- ratings_debug_fide_id_for_several_players --
/* Different players have same FIDE ID */
SELECT DISTINCT dwz_spieler.PID, dwz_2.PID AS PID2, dwz_spieler.Spielername, dwz_spieler.FIDE_ID FROM dwz_spieler
LEFT JOIN dwz_spieler dwz_2
ON dwz_2.FIDE_ID = dwz_spieler.FIDE_ID
WHERE dwz_2.PID != dwz_spieler.PID;

-- ratings_federation_dsb_id --
SELECT PID AS player_id_dsb
	, CONCAT(dwz_spieler.ZPS, "-", IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr)) AS player_pass_dsb
	, IF(
		(SELECT COUNT(*) FROM dwz_spieler dwz_spieler2
		WHERE dwz_spieler2.PID = dwz_spieler.PID AND dwz_spieler2.Status = "A") > 1
		, NULL, IF(Status = "A", 1, NULL)
	) AS player_pass_dsb_current
	, FIDE_ID AS player_id_fide
	, contacts_identifiers.contact_id AS contact_id
FROM dwz_spieler
JOIN contacts_identifiers
	ON contacts_identifiers.identifier = dwz_spieler.PID
	AND contacts_identifiers.identifier_category_id = /*_ID categories identifiers/id_dsb _*/;

-- ratings_federation_dsb_pass --
SELECT PID AS player_id_dsb
	, CONCAT(dwz_spieler.ZPS, "-", IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr)) AS player_pass_dsb
	, IF(Status = "A", 1, NULL) AS player_pass_dsb_current
	, FIDE_ID AS player_id_fide
	, REPLACE(Spielername, ",", ", ") AS dsb_player_last_first
	, Geburtsjahr AS birth_year
	, contacts_identifiers.contact_id AS contact_id
FROM dwz_spieler
JOIN contacts_identifiers
	ON contacts_identifiers.identifier = CONCAT(dwz_spieler.ZPS, "-", IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr))
	AND contacts_identifiers.identifier_category_id = /*_ID categories identifiers/pass_dsb _*/;

-- ratings_federation_dsb_id_fide --
SELECT PID AS player_id_dsb
	, CONCAT(dwz_spieler.ZPS, "-", IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr)) AS player_pass_dsb
	, IF(Status = "A", 1, NULL) AS player_pass_dsb_current
	, FIDE_ID AS player_id_fide
	, REPLACE(Spielername, ",", ", ") AS dsb_player_last_first
	, Geburtsjahr AS birth_year
	, contacts_identifiers.contact_id AS contact_id
FROM dwz_spieler
JOIN contacts_identifiers
	ON contacts_identifiers.identifier = FIDE_ID
	AND contacts_identifiers.identifier_category_id = /*_ID categories identifiers/id_fide _*/;

-- ratings_federation_dsb_name_birth --
SELECT PID AS player_id_dsb
	, CONCAT(dwz_spieler.ZPS, "-", IF(dwz_spieler.Mgl_Nr < 100, LPAD(dwz_spieler.Mgl_Nr, 3, "0"), dwz_spieler.Mgl_Nr)) AS player_pass_dsb
	, FIDE_ID AS player_id_fide
	, IF(Status = "A", 1, NULL) AS player_pass_dsb_current
	, REPLACE(Spielername, ",", ", ") AS dsb_player_last_first
	, Geburtsjahr AS birth_year
	, contacts.contact_id AS contact_id
FROM dwz_spieler
JOIN contacts
	ON contact = CONCAT(SUBSTRING_INDEX(Spielername, ",", -1), " ", SUBSTRING_INDEX(SUBSTRING_INDEX(Spielername, ",", -2), ",", 1))
JOIN persons
	ON contacts.contact_id = persons.contact_id
	AND Geburtsjahr = YEAR(date_of_birth);

-- ratings_federation_contact_identifiers --
SELECT contact_identifier_id, contact_id, identifier, identifier_category_id
	, IF(current = "yes", 1, NULL) AS current
FROM contacts_identifiers
WHERE contact_id IN (%s);

-- ratings_players_dsb --
SELECT PID AS player_id_dsb
	, CONCAT(ZPS, "-", IF(Mgl_Nr < 100, LPAD(Mgl_Nr, 3, "0"), Mgl_Nr)) AS player_pass_dsb
	, FIDE_ID AS player_id_fide
	, SUBSTRING_INDEX(Spielername, ",", 1) AS last_name
	, SUBSTRING_INDEX(SUBSTRING_INDEX(Spielername, ",", 2), ",", -1) AS first_name
	, Geburtsjahr AS birth_year
	, (CASE dwz_spieler.Geschlecht WHEN "M" THEN "male" WHEN "W" THEN "female" ELSE "" END) AS sex
	, DWZ AS dwz_dsb
	, fide_players.standard_rating AS elo_fide
	, IFNULL(fide_players.title, fide_players.title_women) AS fide_title
	, contacts.contact_id AS club_contact_id
	, contact AS club_contact
FROM dwz_spieler
LEFT JOIN fide_players
	ON dwz_spieler.FIDE_ID = fide_players.player_id
LEFT JOIN contacts_identifiers ok
	ON dwz_spieler.ZPS = ok.identifier 
	AND ok.current = "yes"
LEFT JOIN contacts USING (contact_id)
WHERE (ISNULL(Status) OR Status != "P")
%s
ORDER BY Spielername;
