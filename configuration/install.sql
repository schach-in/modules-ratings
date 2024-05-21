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

-- dwz_spieler --
CREATE TABLE `dwz_spieler` (
  `ZPS` varchar(5) NOT NULL DEFAULT '',
  `Mgl_Nr` varchar(4) NOT NULL DEFAULT '',
  `Status` char(1) DEFAULT NULL,
  `Spielername` varchar(100) NOT NULL DEFAULT '',
  `Spielername_G` varchar(100) NOT NULL DEFAULT '',
  `Geschlecht` char(1) DEFAULT NULL,
  `Spielberechtigung` char(1) DEFAULT '',
  `Geburtsjahr` year NOT NULL DEFAULT '0000',
  `Letzte_Auswertung` mediumint unsigned DEFAULT NULL,
  `DWZ` smallint unsigned DEFAULT NULL,
  `DWZ_Index` smallint unsigned DEFAULT NULL,
  `FIDE_Elo` smallint unsigned DEFAULT NULL,
  `FIDE_Titel` char(3) DEFAULT NULL,
  `FIDE_ID` int unsigned DEFAULT NULL,
  `FIDE_Land` char(3) DEFAULT NULL,
  PRIMARY KEY (`ZPS`,`Mgl_Nr`),
  KEY `FIDE_ID` (`FIDE_ID`),
  KEY `Spielername` (`Spielername`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;


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
  PRIMARY KEY (`player_id`),
  KEY `standard_rating` (`standard_rating`),
  KEY `player` (`player`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
