-- Public-ballot gate: approved businesses stay off the vote until staff confirm.
-- Also applied live by tocca_admin/includes/ballot_status.php (safe to re-run).

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_choices'
    AND COLUMN_NAME = 'on_ballot'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE tbl_choices ADD COLUMN on_ballot TINYINT(1) NOT NULL DEFAULT 1 AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Approved registrations that have not been emailed a QR are still under evaluation.
-- Run this UPDATE only once, immediately after adding the column.
UPDATE tbl_choices c
INNER JOIN tbl_nominations n ON n.merged_choice_id = c.choice_id
SET c.on_ballot = 0
WHERE n.status IN ('approved','merged')
  AND IFNULL(c.qr_sent, 0) = 0;
