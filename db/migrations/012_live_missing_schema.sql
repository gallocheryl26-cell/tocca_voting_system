-- Live (Hostinger u556809062_tocca_db) vs local tocca_db — 17 Aug 2026
-- Run this in phpMyAdmin on the LIVE database only.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS.

-- =============================================================================
-- 1) Missing tables (required for TWG scores, proof photos, type mapping)
-- =============================================================================

CREATE TABLE IF NOT EXISTS tbl_twg_scores (
  question_id INT NOT NULL,
  choice_id INT NOT NULL,
  twg_average DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (question_id, choice_id),
  KEY idx_twg_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_twg_member_scores (
  question_id INT NOT NULL,
  choice_id INT NOT NULL,
  member_key VARCHAR(32) NOT NULL,
  score DECIMAL(5,2) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (question_id, choice_id, member_key),
  KEY idx_twg_member_award (question_id, choice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_draft_vote_proof (
  proof_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  voters_id INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (proof_id),
  KEY idx_draft_proof_voter_q (voters_id, question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_vote_proof (
  proof_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  voters_id INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  vote_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (proof_id),
  KEY idx_vote_proof_voter_q (voters_id, question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_nomination_establishment_types (
  nomination_id INT NOT NULL,
  type_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (nomination_id, type_id),
  KEY idx_net_type (type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_choice_establishment_types (
  choice_id INT NOT NULL,
  type_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (choice_id, type_id),
  KEY idx_cet_type (type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: voter mobile app. Skip if you are not using the app.
CREATE TABLE IF NOT EXISTS tbl_voter_push_tokens (
  id BIGINT NOT NULL AUTO_INCREMENT,
  voters_id INT NOT NULL,
  expo_token VARCHAR(255) NOT NULL,
  platform ENUM('ios','android') NOT NULL,
  device_id VARCHAR(128) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_voter_push_token (expo_token),
  KEY idx_voter_push_voter (voters_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_voter_refresh_tokens (
  id BIGINT NOT NULL AUTO_INCREMENT,
  voters_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  device_id VARCHAR(128) DEFAULT NULL,
  platform ENUM('ios','android','web') NOT NULL DEFAULT 'android',
  app_version VARCHAR(32) DEFAULT NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_voter_refresh_hash (token_hash),
  KEY idx_voter_refresh_voter (voters_id),
  KEY idx_voter_refresh_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2) Missing columns on tables that already exist on live
-- =============================================================================

ALTER TABLE tbl_choices
  ADD COLUMN IF NOT EXISTS public_slug VARCHAR(100) NULL DEFAULT NULL AFTER choice_name;

ALTER TABLE tbl_question_choices
  ADD COLUMN IF NOT EXISTS on_ballot TINYINT(1) NOT NULL DEFAULT 0 AFTER choice_id;

ALTER TABLE tbl_nominations
  ADD COLUMN IF NOT EXISTS merged_choice_id INT NULL DEFAULT NULL;

ALTER TABLE tbl_nomination_question_audit
  ADD COLUMN IF NOT EXISTS reason VARCHAR(64) NULL DEFAULT NULL AFTER action;

-- Copy legacy single type onto the new junction (no-op if already populated)
INSERT IGNORE INTO tbl_choice_establishment_types (choice_id, type_id)
SELECT choice_id, establishment_type_id
FROM tbl_choices
WHERE establishment_type_id IS NOT NULL AND establishment_type_id > 0;

INSERT IGNORE INTO tbl_nomination_establishment_types (nomination_id, type_id)
SELECT nomination_id, establishment_type_id
FROM tbl_nominations
WHERE establishment_type_id IS NOT NULL AND establishment_type_id > 0;

-- Ballot flag: businesses already marked on_ballot
UPDATE tbl_question_choices qc
INNER JOIN tbl_choices c ON c.choice_id = qc.choice_id
SET qc.on_ballot = 1
WHERE c.on_ballot = 1;

-- =============================================================================
-- 3) Site-root bug: tbl_config has no unique key, so Save inserted extra rows
--    and the app kept reading the oldest localhost value.
-- =============================================================================

DELETE c1 FROM tbl_config c1
INNER JOIN tbl_config c2
  ON c1.config_key = c2.config_key AND c1.id < c2.id;

SET @cfg_uniq := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_config'
    AND COLUMN_NAME = 'config_key'
    AND NON_UNIQUE = 0
);
SET @sql := IF(
  @cfg_uniq = 0,
  'ALTER TABLE tbl_config ADD UNIQUE KEY uq_tbl_config_key (config_key)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE tbl_config
SET config_value = 'https://tatakormocawards.com'
WHERE config_key IN ('voting_qr_base_url', 'nomination_qr_base_url');
