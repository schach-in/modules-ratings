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
/* 2024-08-01-1 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Arbiter', NULL, NULL, /*_ID categories fide-title _*/, 'fide-title/arbiter', '&alias=fide-title/arbiter', 20, NOW());
/* 2024-08-01-2 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('International Arbiter', 'IA', NULL, /*_ID categories fide-title/arbiter _*/, 'fide-title/arbiter/ia', '&alias=fide-title/arbiter/ia', 1, NOW());
/* 2024-08-01-3 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Arbiter', 'FA', NULL, /*_ID categories fide-title/arbiter _*/, 'fide-title/arbiter/fa', '&alias=fide-title/arbiter/fa', 2, NOW());
/* 2024-08-01-4 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('National Arbiter', 'NA', NULL, /*_ID categories fide-title/arbiter _*/, 'fide-title/arbiter/na', '&alias=fide-title/arbiter/na', 3, NOW());
/* 2024-08-01-5 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Organizer of Chess Tournaments', 'Organizer', NULL, /*_ID categories fide-title _*/, 'fide-title/organizer', '&alias=fide-title/organizer', 21, NOW());
/* 2024-08-01-6 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Premier Organizer', 'PO', NULL, /*_ID categories fide-title/organizer _*/, 'fide-title/organizer/po', '&alias=fide-title/organizer/po', 1, NOW());
/* 2024-08-01-7 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE International Organizer', 'IO', NULL, /*_ID categories fide-title/organizer _*/, 'fide-title/organizer/io', '&alias=fide-title/organizer/io', 2, NOW());
/* 2024-08-01-8 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Associate Organizer', 'AO', NULL, /*_ID categories fide-title/organizer _*/, 'fide-title/organizer/ao', '&alias=fide-title/organizer/ao', 2, NOW());
/* 2024-08-01-9 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Trainer', NULL, NULL, /*_ID categories fide-title _*/, 'fide-title/trainer', '&alias=fide-title/trainer', 22, NOW());
/* 2024-08-01-10 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Senior Trainer', 'FST', NULL, /*_ID categories fide-title/trainer _*/, 'fide-title/trainer/fst', '&alias=fide-title/trainer/fst', 1, NOW());
/* 2024-08-01-11 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Trainer', 'FT', NULL, /*_ID categories fide-title/trainer _*/, 'fide-title/trainer/ft', '&alias=fide-title/trainer/ft', 2, NOW());
/* 2024-08-01-12 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Instructor', 'FI', NULL, /*_ID categories fide-title/trainer _*/, 'fide-title/trainer/fi', '&alias=fide-title/trainer/fi', 3, NOW());
/* 2024-08-01-13 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('National Instructor', 'NI', NULL, /*_ID categories fide-title/trainer _*/, 'fide-title/trainer/ni', '&alias=fide-title/trainer/ni', 4, NOW());
/* 2024-08-01-14 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Developmental Instructor', 'DI', NULL, /*_ID categories fide-title/trainer _*/, 'fide-title/trainer/di', '&alias=fide-title/trainer/di', 5, NOW());
/* 2024-08-01-15 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Chess in Education', 'Education', NULL, /*_ID categories fide-title _*/, 'fide-title/education', '&alias=fide-title/education', 23, NOW());
/* 2024-08-01-16 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Lead School Instructor', 'LSI', NULL, /*_ID categories fide-title/education _*/, 'fide-title/education/lsi', '&alias=fide-title/education/lsi', 1, NOW());
/* 2024-08-01-17 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Senior Lead Instructor', 'SLI', NULL, /*_ID categories fide-title/education _*/, 'fide-title/education/sli', '&alias=fide-title/education/sli', 2, NOW());
/* 2024-08-01-18 */	INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('School Instructor', 'SI', NULL, /*_ID categories fide-title/education _*/, 'fide-title/education/si', '&alias=fide-title/education/si', 3, NOW());
/* 2024-08-08-1 */	CREATE TABLE `wikidata_players` (`wikidata_id` int unsigned NOT NULL, `fide_id` int unsigned NOT NULL, `person` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL, `last_update` timestamp NOT NULL, PRIMARY KEY (`wikidata_id`), UNIQUE KEY `fide_id` (`fide_id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/* 2024-08-08-2 */	CREATE TABLE `wikidata_uris` (`uri_id` int unsigned NOT NULL AUTO_INCREMENT, `wikidata_id` int unsigned NOT NULL, `uri` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, `uri_lang` varchar(2) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL, `last_update` timestamp NOT NULL, PRIMARY KEY (`uri_id`), UNIQUE KEY `wikidata_id_uri_lang` (`wikidata_id`,`uri_lang`), KEY `url_lang` (`uri_lang`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/* 2024-08-08-3 */	INSERT INTO _relations (`master_db`, `master_table`, `master_field`, `detail_db`, `detail_table`, `detail_id_field`, `detail_field`, `delete`) VALUES ((SELECT DATABASE()), 'wikidata_players', 'wikidata_id', (SELECT DATABASE()), 'wikidata_uris', 'uri_id', 'wikidata_id', 'delete');
