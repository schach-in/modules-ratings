/**
 * ratings module
 * memberstats import staging tables
 *
 * Based on dwz_spieler / dwz_vereine but frozen for snapshot import:
 * column types here are widened or optional so legacy .txt dumps and
 * pre-2024 .sql dumps load without touching the live DWZ tables.
 *
 * These are regular (non-TEMPORARY) tables: visible across connections
 * for progress monitoring and debugging, and immune to any per-request
 * MySQL reconnect that would silently drop session-local TEMP tables.
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/ratings
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * spieler staging v1 — ZPS-first .sql and all .txt snapshots (15 columns).
 *
 * Matches positional REPLACE INTO rows where the first value is a quoted
 * club code (single- or double-quoted). Includes Spielername_G; no PID.
 * .txt loads use an explicit column list (Spielername_G stays NULL).
 */


-- ratings_memberstats_temp_spieler_v1 --
CREATE TABLE `temp_memberstats_spieler_v1` (
  `ZPS` varchar(5) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  /* alphanumeric in old .txt snapshots (e.g. "B49"); dwz_spieler: smallint */
  `Mgl_Nr` varchar(8) NOT NULL,
  `Status` char(1) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `Spielername` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Spielername_G` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Geschlecht` char(1) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `Spielberechtigung` char(1) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  /* smallint, not YEAR: old .txt uses 1900 for "unknown"; memberstats INSERT clamps */
  `Geburtsjahr` smallint unsigned NULL DEFAULT NULL,
  `Letzte_Auswertung` mediumint unsigned DEFAULT NULL,
  `DWZ` smallint unsigned DEFAULT NULL,
  `DWZ_Index` mediumint unsigned DEFAULT NULL,
  `FIDE_Elo` smallint unsigned DEFAULT NULL,
  `FIDE_Titel` char(3) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `FIDE_ID` int unsigned DEFAULT NULL,
  `FIDE_Land` char(3) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  KEY `FIDE_ID` (`FIDE_ID`),
  KEY `Spielername` (`Spielername`),
  KEY `ZPS` (`ZPS`),
  KEY `Mgl_Nr` (`Mgl_Nr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/**
 * spieler staging v2 — PID-first .sql snapshots (16 columns, post 2024-05-22).
 *
 * Matches positional REPLACE INTO rows where the first value is a bare
 * integer PID. No Spielername_G column.
 */


-- ratings_memberstats_temp_spieler_v2 --
CREATE TABLE `temp_memberstats_spieler_v2` (
  `PID` int unsigned NOT NULL,
  `ZPS` varchar(5) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  `Mgl_Nr` varchar(8) NOT NULL,
  `Status` char(1) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `Spielername` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Geschlecht` char(1) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `Spielberechtigung` char(1) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `Geburtsjahr` smallint unsigned NULL DEFAULT NULL,
  `Letzte_Auswertung` mediumint unsigned DEFAULT NULL,
  `DWZ` smallint unsigned DEFAULT NULL,
  `DWZ_Index` mediumint unsigned DEFAULT NULL,
  `FIDE_Elo` smallint unsigned DEFAULT NULL,
  `FIDE_Titel` char(3) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `FIDE_ID` int unsigned DEFAULT NULL,
  `FIDE_Land` char(3) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  KEY `FIDE_ID` (`FIDE_ID`),
  KEY `Spielername` (`Spielername`),
  KEY `ZPS` (`ZPS`),
  KEY `Mgl_Nr` (`Mgl_Nr`),
  KEY `PID` (`PID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/**
 * Staging copy of dwz_vereine — same four columns, schema frozen here
 * so a later change to dwz_vereine does not break snapshot import.
 */


-- ratings_memberstats_temp_vereine --
CREATE TABLE `temp_memberstats_vereine` (
  `ZPS` varchar(5) NOT NULL DEFAULT '',
  `LV` char(1) NOT NULL DEFAULT '',
  `Verband` char(3) NOT NULL DEFAULT '',
  `Vereinname` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`ZPS`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/**
 * Staging copy of dwz_verbaende — same four columns, schema frozen here.
 * Loaded only when the snapshot ships verbaende.sql, verbaende.txt,
 * verband.sql, or VERBAND.TXT; otherwise this table is not created.
 */


-- ratings_memberstats_temp_verbaende --
CREATE TABLE `temp_memberstats_verbaende` (
  `Verband` char(3) NOT NULL DEFAULT '',
  `LV` char(1) NOT NULL DEFAULT '',
  `Uebergeordnet` char(3) NOT NULL DEFAULT '',
  `Verbandname` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`Verband`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
