-- TWG weighted average per award / business. Applied live by results_formula.php.
CREATE TABLE IF NOT EXISTS tbl_twg_scores (
  question_id INT NOT NULL,
  choice_id INT NOT NULL,
  twg_average DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (question_id, choice_id),
  KEY idx_twg_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
