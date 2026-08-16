-- Tie approved registrations to businesses by ID, and rename stored refs NOM- → REG-.
-- Also applied live by db/migrations/007_apply.php (safe to re-run).

-- 1) Stable link from registration → business (tbl_choices)
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_nominations'
    AND COLUMN_NAME = 'merged_choice_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE tbl_nominations ADD COLUMN merged_choice_id INT NULL DEFAULT NULL, ADD KEY idx_nom_merged_choice (merged_choice_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Migrate stored reference numbers (skip if the REG- form already exists)
UPDATE tbl_nominations n
LEFT JOIN tbl_nominations taken
  ON taken.reference_no = CONCAT('REG-', SUBSTRING(n.reference_no, 5))
SET n.reference_no = CONCAT('REG-', SUBSTRING(n.reference_no, 5))
WHERE n.reference_no LIKE 'NOM-%'
  AND taken.nomination_id IS NULL;
