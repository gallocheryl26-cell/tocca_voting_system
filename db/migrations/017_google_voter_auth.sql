-- Add Google/Firebase identity to voter records without changing existing voter ids,
-- mobile numbers, access codes, drafts, or submitted votes.
-- Back up the database before applying this migration outside local development.

ALTER TABLE tbl_voters
  MODIFY COLUMN mobile_number VARCHAR(20) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS firebase_uid VARCHAR(128) NULL DEFAULT NULL AFTER mobile_number,
  ADD COLUMN IF NOT EXISTS google_email VARCHAR(254) NULL DEFAULT NULL AFTER firebase_uid,
  ADD COLUMN IF NOT EXISTS auth_provider VARCHAR(24) NOT NULL DEFAULT 'legacy_mobile' AFTER google_email,
  ADD COLUMN IF NOT EXISTS google_linked_at DATETIME NULL DEFAULT NULL AFTER auth_provider;

SET @firebase_uid_unique_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_voters'
    AND INDEX_NAME = 'uq_voters_firebase_uid'
);
SET @firebase_uid_unique_sql := IF(
  @firebase_uid_unique_exists = 0,
  'ALTER TABLE tbl_voters ADD UNIQUE KEY uq_voters_firebase_uid (firebase_uid)',
  'SELECT 1'
);
PREPARE firebase_uid_unique_stmt FROM @firebase_uid_unique_sql;
EXECUTE firebase_uid_unique_stmt;
DEALLOCATE PREPARE firebase_uid_unique_stmt;

CREATE TABLE IF NOT EXISTS tbl_voter_auth_attempts (
  attempt_key CHAR(64) NOT NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  PRIMARY KEY (attempt_key),
  KEY idx_voter_auth_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
