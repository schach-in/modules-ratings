/**
 * ratings module
 * SQL updates
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */

/* 2024-05-22-1 */	ALTER TABLE `dwz_spieler` ADD `PID` int unsigned NOT NULL FIRST, CHANGE `ZPS` `ZPS` varchar(5) COLLATE 'latin1_general_ci' NOT NULL AFTER `PID`, CHANGE `Mgl_Nr` `Mgl_Nr` smallint NOT NULL AFTER `ZPS`, CHANGE `Status` `Status` char(1) COLLATE 'latin1_general_ci' NULL AFTER `Mgl_Nr`, CHANGE `Spielername` `Spielername` varchar(60) COLLATE 'latin1_general_ci' NULL AFTER `Status`, DROP `Spielername_G`, CHANGE `Geschlecht` `Geschlecht` char(1) COLLATE 'latin1_general_ci' NULL AFTER `Spielername`, CHANGE `Spielberechtigung` `Spielberechtigung` char(1) COLLATE 'latin1_general_ci' NULL AFTER `Geschlecht`, CHANGE `Geburtsjahr` `Geburtsjahr` year NULL AFTER `Spielberechtigung`, CHANGE `FIDE_Titel` `FIDE_Titel` char(3) COLLATE 'latin1_general_ci' NULL AFTER `FIDE_Elo`, CHANGE `FIDE_Land` `FIDE_Land` char(3) COLLATE 'latin1_general_ci' NULL AFTER `FIDE_ID`, COLLATE 'utf8mb4_unicode_ci';
