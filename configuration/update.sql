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
/* 2024-05-27-1 */	CREATE TABLE `dewis_clubs` (`id` int unsigned NOT NULL AUTO_INCREMENT, `club` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, `vkz` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, `parent_id` int DEFAULT NULL, `assessor_id` int DEFAULT NULL, `last_sync_members` datetime DEFAULT NULL, `last_update` timestamp NULL DEFAULT NULL, UNIQUE KEY `id` (`id`), KEY `parent_id` (`parent_id`), KEY `assessor_id` (`assessor_id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/* 2024-05-27-2 */	ALTER TABLE `dewis_clubs` CHANGE `parent_id` `parent_id` int unsigned NULL AFTER `vkz`, CHANGE `assessor_id` `assessor_id` int unsigned NULL AFTER `parent_id`;
/* 2024-05-27-3 */	CREATE TABLE `dewis_members` (`pid` int unsigned NOT NULL, `surname` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, `firstname` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, `title` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, `membership` smallint unsigned DEFAULT NULL, `state` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, `rating` smallint unsigned DEFAULT NULL, `ratingIndex` smallint unsigned DEFAULT NULL, `tcode` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, `finishedOn` date DEFAULT NULL, `gender` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, `yearOfBirth` year DEFAULT NULL, `idfide` int unsigned DEFAULT NULL, `elo` smallint unsigned DEFAULT NULL, `fideTitle` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL, `club_id` int unsigned DEFAULT NULL, `last_update` timestamp NULL DEFAULT NULL, KEY `club_id` (`club_id`), KEY `pid` (`pid`), KEY `idfide` (`idfide`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/* 2024-06-24-1 */	ALTER TABLE `dwz_spieler` ADD INDEX `ZPS` (`ZPS`);
/* 2024-06-24-2 */	ALTER TABLE `dwz_spieler` ADD INDEX `Mgl_Nr` (`Mgl_Nr`);
/* 2024-06-24-3 */	ALTER TABLE `dwz_spieler` ADD INDEX `PID` (`PID`);
/* 2024-07-15-1 */	ALTER TABLE `dwz_spieler` ADD `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
/* 2024-07-15-2 */	ALTER TABLE `fide_players` ADD `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;