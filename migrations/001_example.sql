-- Migration: 001_example
-- This is a template showing how to write a migration.
-- Each migration file runs ONCE (tracked in the `migrations` table).
--
-- Rules:
--   * Name files NNN_short_description.sql so they run in order (001, 002, ...).
--   * Use IF NOT EXISTS / IF EXISTS where possible so re-runs are safe.
--   * You can put multiple statements in one file, separated by semicolons.
--
-- This example does nothing harmful — it just creates and drops a temp marker.
-- Delete this file or leave it; it's safe.

CREATE TABLE IF NOT EXISTS _migration_smoke_test (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS _migration_smoke_test;
