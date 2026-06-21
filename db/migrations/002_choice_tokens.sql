-- Short tokens for nominee QR codes and self-service portal links.
CREATE TABLE IF NOT EXISTS tbl_choice_tokens (
  token_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  choice_id  INT NOT NULL,
  token      VARCHAR(16) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_choice_tokens_token (token),
  UNIQUE KEY uq_choice_tokens_choice (choice_id),
  KEY idx_choice_tokens_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
