/**
 * ratings module
 * additional SQL queries for GDPR requests
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


-- fide_players --
SELECT * FROM fide_players WHERE player = "/*_FIELD last_name _*/, /*_FIELD first_name _*/";

-- dwz_spieler --
SELECT * FROM dwz_spieler WHERE Spielername = "/*_FIELD last_name _*/,/*_FIELD first_name _*/";
SELECT * FROM dwz_spieler WHERE Spielername = "/*_FIELD last_name _*/,/*_FIELD first_name _*/,%";
