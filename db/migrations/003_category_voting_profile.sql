-- Category voting profile: business | media | places | general
-- Applied automatically on first admin/voter request via category_voting_profile_ensure_schema().

ALTER TABLE tbl_categories
  ADD COLUMN IF NOT EXISTS voting_profile VARCHAR(32) NOT NULL DEFAULT 'business'
  AFTER status;
