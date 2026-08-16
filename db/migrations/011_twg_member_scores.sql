-- Per-member TWG scores (1–10). Average is stored in tbl_twg_scores.
CREATE TABLE IF NOT EXISTS tbl_twg_member_scores (
  question_id INT NOT NULL,
  choice_id INT NOT NULL,
  member_key VARCHAR(32) NOT NULL,
  score DECIMAL(5,2) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (question_id, choice_id, member_key),
  KEY idx_twg_member_award (question_id, choice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
