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
 * Staging copy of dwz_spieler for one snapshot import.
 *
 * Differences from the live dwz_spieler table (see column comments below):
 *  - PID nullable (introduced with the *_v2 export only)
 *  - Mgl_Nr widened to varchar for alphanumeric legacy .txt values
 *  - Geburtsjahr as smallint, not YEAR (1900 placeholder in old .txt)
 *  - Spielername_G kept for pre-2024 .sql dumps (column order without PID)
 *
 * Pre-2024 spieler.sql: mf_ratings_memberstats_load_sql() drops PID before
 * loading when the dump format is legacy.
 */


-- ratings_memberstats_temp_spieler --
CREATE TABLE `temp_memberstats_spieler` (
  /* optional; absent in pre-2024 .sql and all .txt snapshots */
  `PID` int unsigned NULL DEFAULT NULL,
  `ZPS` varchar(5) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
  /* alphanumeric in old .txt snapshots (e.g. "B49"); dwz_spieler: smallint */
  `Mgl_Nr` varchar(8) NOT NULL,
  `Status` char(1) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `Spielername` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  /* pre-2024 .sql only; between Spielername and Geschlecht when PID is dropped */
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
  PRIMARY KEY (`ZPS`,`Mgl_Nr`),
  KEY `FIDE_ID` (`FIDE_ID`),
  KEY `Spielername` (`Spielername`),
  KEY `ZPS` (`ZPS`),
  KEY `Mgl_Nr` (`Mgl_Nr`),
  KEY `PID` (`PID`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
