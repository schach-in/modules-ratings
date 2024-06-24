/**
 * ratings module
 * SQL queries
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2023-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


-- debug_fide_dsb_sex --
/* Sex of player differs between FIDE and DSB data */
SELECT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, sex, Geschlecht
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE IF(sex = "F", "W", "M") != Geschlecht
AND NOT ISNULL(sex)

-- debug_fide_dsb_title --
/* Title of player differs between FIDE and DSB data */
SELECT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, title
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE dwz_spieler.FIDE_Titel != fide_players.title
OR (ISNULL(dwz_spieler.FIDE_Titel) AND NOT ISNULL(fide_players.title))
OR (NOT ISNULL(dwz_spieler.FIDE_Titel) AND ISNULL(fide_players.title))

-- debug_fide_dsb_elo_missing --
/* Player has FIDE Elo which is missing in DSB database */
SELECT DISTINCT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, standard_rating
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE ISNULL(dwz_spieler.FIDE_Elo) AND NOT ISNULL(fide_players.standard_rating)

-- debug_fide_dsb_elo_extra --
/* Player has no FIDE Elo which but there is a rating in the DSB database */
SELECT DISTINCT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, standard_rating
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE NOT ISNULL(dwz_spieler.FIDE_Elo) AND ISNULL(fide_players.standard_rating)

-- debug_fide_dsb_elo_different_above_2000 --
/* FIDE Elo rating is different in DSB database, players >= 2000 Elo */
SELECT DISTINCT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, standard_rating
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE dwz_spieler.FIDE_Elo != fide_players.standard_rating
AND fide_players.standard_rating >= 2000

-- debug_fide_dsb_elo_different_below_2000 --
/* FIDE Elo rating is different in DSB database, players < 2000 Elo */
SELECT DISTINCT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, standard_rating
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE dwz_spieler.FIDE_Elo != fide_players.standard_rating
AND fide_players.standard_rating < 2000

-- debug_fide_dsb_elo_different_below_2000_recalculation_does_not_match --
/* FIDE Elo rating is different in DSB database, players < 2000 Elo, where substraction of bonus does not match */
SELECT DISTINCT PID, Spielername, FIDE_Elo, FIDE_Titel, FIDE_ID, FIDE_Land, standard_rating
	, ROUND(((standard_rating - 800) / 0.6), 0) AS adjusted_standard_rating
FROM dwz_spieler
LEFT JOIN fide_players
	ON fide_players.player_id = dwz_spieler.FIDE_ID
WHERE dwz_spieler.FIDE_Elo != fide_players.standard_rating
AND fide_players.standard_rating < 2000
HAVING adjusted_standard_rating != dwz_spieler.FIDE_Elo

-- debug_dsb_player_twice_in_same_club --
/* Player has more than one membership entry in the same club */
SELECT dwz_spieler.*
FROM dwz_spieler
JOIN dwz_spieler duplicates
	ON duplicates.PID = dwz_spieler.PID
	AND dwz_spieler.ZPS = duplicates.ZPS
	AND dwz_spieler.Mgl_Nr != duplicates.Mgl_Nr

-- debug_dsb_player_active_in_different_clubs --
/* Player has active status in more than one club */
SELECT dwz_spieler.*
FROM dwz_spieler
JOIN dwz_spieler duplicates
	ON duplicates.PID = dwz_spieler.PID
	AND dwz_spieler.Status = "A"
	AND duplicates.Status = "A"
	AND dwz_spieler.ZPS != duplicates.ZPS
