-- Reason captured when an award is removed during registration validation.
-- Also applied automatically via award_removal_schema_ensure().

ALTER TABLE tbl_nomination_question_audit
  ADD COLUMN IF NOT EXISTS reason VARCHAR(64) NULL DEFAULT NULL
  AFTER action;
