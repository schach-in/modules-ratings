/**
 * ratings module
 * SQL for installation
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


-- dewis_clubs --
CREATE TABLE `dewis_clubs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `club` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vkz` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_id` int unsigned DEFAULT NULL,
  `assessor_id` int unsigned DEFAULT NULL,
  `last_sync_members` datetime DEFAULT NULL,
  `last_update` timestamp NULL DEFAULT NULL,
  UNIQUE KEY `id` (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `assessor_id` (`assessor_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- dewis_members --
CREATE TABLE `dewis_members` (
  `pid` int unsigned NOT NULL,
  `surname` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `firstname` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `membership` smallint unsigned DEFAULT NULL,
  `state` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` smallint unsigned DEFAULT NULL,
  `ratingIndex` smallint unsigned DEFAULT NULL,
  `tcode` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `finishedOn` date DEFAULT NULL,
  `gender` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `yearOfBirth` year DEFAULT NULL,
  `idfide` int unsigned DEFAULT NULL,
  `elo` smallint unsigned DEFAULT NULL,
  `fideTitle` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `club_id` int unsigned DEFAULT NULL,
  `last_update` timestamp NULL DEFAULT NULL,
  KEY `club_id` (`club_id`),
  KEY `pid` (`pid`),
  KEY `idfide` (`idfide`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- dwz_spieler --
CREATE TABLE `dwz_spieler` (
  `PID` int unsigned NOT NULL,
  `ZPS` varchar(5) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `Mgl_Nr` smallint NOT NULL,
  `Status` char(1) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `Spielername` varchar(60) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `Geschlecht` char(1) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `Spielberechtigung` char(1) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `Geburtsjahr` year DEFAULT NULL,
  `Letzte_Auswertung` mediumint unsigned DEFAULT NULL,
  `DWZ` smallint unsigned DEFAULT NULL,
  `DWZ_Index` smallint unsigned DEFAULT NULL,
  `FIDE_Elo` smallint unsigned DEFAULT NULL,
  `FIDE_Titel` char(3) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `FIDE_ID` int unsigned DEFAULT NULL,
  `FIDE_Land` char(3) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ZPS`,`Mgl_Nr`),
  KEY `FIDE_ID` (`FIDE_ID`),
  KEY `Spielername` (`Spielername`),
  KEY `ZPS` (`ZPS`),
  KEY `Mgl_Nr` (`Mgl_Nr`),
  KEY `PID` (`PID`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- dwz_verbaende --
CREATE TABLE `dwz_verbaende` (
  `Verband` char(3) NOT NULL DEFAULT '',
  `LV` char(1) NOT NULL DEFAULT '',
  `Uebergeordnet` char(3) NOT NULL DEFAULT '',
  `Verbandname` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`Verband`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;


-- dwz_vereine --
CREATE TABLE `dwz_vereine` (
  `ZPS` varchar(5) NOT NULL DEFAULT '',
  `LV` char(1) NOT NULL DEFAULT '',
  `Verband` char(3) NOT NULL DEFAULT '',
  `Vereinname` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`ZPS`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;


-- fide_players --
CREATE TABLE `fide_players` (
  `player_id` int unsigned NOT NULL,
  `player` varchar(60) DEFAULT NULL,
  `federation` varchar(3) NOT NULL,
  `sex` enum('M','F') CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `title` varchar(4) DEFAULT NULL,
  `title_women` varchar(4) DEFAULT NULL,
  `title_other` varchar(14) DEFAULT NULL,
  `foa_rating` varchar(3) DEFAULT NULL,
  `standard_rating` smallint unsigned DEFAULT NULL,
  `standard_games` smallint unsigned DEFAULT NULL,
  `standard_k_factor` tinyint unsigned DEFAULT NULL,
  `rapid_rating` smallint DEFAULT NULL,
  `rapid_games` smallint DEFAULT NULL,
  `rapid_k_factor` tinyint DEFAULT NULL,
  `blitz_rating` smallint DEFAULT NULL,
  `blitz_games` smallint DEFAULT NULL,
  `blitz_k_factor` tinyint DEFAULT NULL,
  `birth` smallint unsigned DEFAULT NULL,
  `flag` varchar(2) DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`player_id`),
  KEY `standard_rating` (`standard_rating`),
  KEY `player` (`player`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;


-- categories --
INSERT INTO categories (`category`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Title', NULL, NULL, 'fide-title', '&alias=fide-title', NULL, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Grandmaster', 'GM', NULL, /*_ID categories fide-title _*/, 'fide-title/gm', '&alias=fide-title/gm', 1, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('International Master', 'IM', NULL, /*_ID categories fide-title _*/, 'fide-title/im', '&alias=fide-title/im', 2, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Woman Grandmaster', 'WGM', NULL, /*_ID categories fide-title _*/, 'fide-title/wgm', '&alias=fide-title/wgm', 3, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Master', 'FM', NULL, /*_ID categories fide-title _*/, 'fide-title/fm', '&alias=fide-title/fm', 4, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Woman International Master', 'WIM', NULL, /*_ID categories fide-title _*/, 'fide-title/wim', '&alias=fide-title/wim', 5, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Master Candidate', 'CM', NULL, /*_ID categories fide-title _*/, 'fide-title/cm', '&alias=fide-title/cm', 6, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Woman FIDE Master', 'WFM', NULL, /*_ID categories fide-title _*/, 'fide-title/wfm', '&alias=fide-title/wfm', 7, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Woman Master Candidate', 'WCM', NULL, /*_ID categories fide-title _*/, 'fide-title/wcm', '&alias=fide-title/wcm', 8, NOW());

INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Arbiter', NULL, NULL, /*_ID categories fide-title _*/, 'fide-title/arbiter', '&alias=fide-title/arbiter', 20, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('International Arbiter', 'IA', NULL, /*_ID categories fide-title/arbiter _*/, 'fide-title/arbiter/ia', '&alias=fide-title/arbiter/ia', 1, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Arbiter', 'FA', NULL, /*_ID categories fide-title/arbiter _*/, 'fide-title/arbiter/fa', '&alias=fide-title/arbiter/fa', 2, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('National Arbiter', 'NA', NULL, /*_ID categories fide-title/arbiter _*/, 'fide-title/arbiter/na', '&alias=fide-title/arbiter/na', 3, NOW());

INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Organizer of Chess Tournaments', 'Organizer', NULL, /*_ID categories fide-title _*/, 'fide-title/organizer', '&alias=fide-title/organizer', 21, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Premier Organizer', 'PO', NULL, /*_ID categories fide-title/organizer _*/, 'fide-title/organizer/po', '&alias=fide-title/organizer/po', 1, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE International Organizer', 'IO', NULL, /*_ID categories fide-title/organizer _*/, 'fide-title/organizer/io', '&alias=fide-title/organizer/io', 2, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Associate Organizer', 'AO', NULL, /*_ID categories fide-title/organizer _*/, 'fide-title/organizer/ao', '&alias=fide-title/organizer/ao', 2, NOW());

INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Trainer', NULL, NULL, /*_ID categories fide-title _*/, 'fide-title/trainer', '&alias=fide-title/trainer', 22, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Senior Trainer', 'FST', NULL, /*_ID categories fide-title/trainer _*/, 'fide-title/trainer/fst', '&alias=fide-title/trainer/fst', 1, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Trainer', 'FT', NULL, /*_ID categories fide-title/trainer _*/, 'fide-title/trainer/ft', '&alias=fide-title/trainer/ft', 2, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('FIDE Instructor', 'FI', NULL, /*_ID categories fide-title/trainer _*/, 'fide-title/trainer/fi', '&alias=fide-title/trainer/fi', 3, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('National Instructor', 'NI', NULL, /*_ID categories fide-title/trainer _*/, 'fide-title/trainer/ni', '&alias=fide-title/trainer/ni', 4, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Developmental Instructor', 'DI', NULL, /*_ID categories fide-title/trainer _*/, 'fide-title/trainer/di', '&alias=fide-title/trainer/di', 5, NOW());

INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Chess in Education', 'Education', NULL, /*_ID categories fide-title _*/, 'fide-title/education', '&alias=fide-title/education', 23, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Lead School Instructor', 'LSI', NULL, /*_ID categories fide-title/education _*/, 'fide-title/education/lsi', '&alias=fide-title/education/lsi', 1, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('Senior Lead Instructor', 'SLI', NULL, /*_ID categories fide-title/education _*/, 'fide-title/education/sli', '&alias=fide-title/education/sli', 2, NOW());
INSERT INTO categories (`category`, `category_short`, `description`, `main_category_id`, `path`, `parameters`, `sequence`, `last_update`) VALUES ('School Instructor', 'SI', NULL, /*_ID categories fide-title/education _*/, 'fide-title/education/si', '&alias=fide-title/education/si', 3, NOW());
