-- Live phpMyAdmin (u556809062_tocca_db) — registration status corrections
-- 1) Run the SELECT first and confirm the rows.
-- 2) Then run the UPDATEs.
-- Do not run on local XAMPP (that copy does not have Figaro / Hunger Wings / Bigbys).

-- Preview
SELECT n.nomination_id, n.status, n.merged_choice_id, a.answer AS business_name,
       c.choice_name, c.status AS choice_status, c.on_ballot
FROM tbl_nominations n
INNER JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id
INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
  AND f.name IN ('business_name','official_business_name','company','company_name','business')
LEFT JOIN tbl_choices c ON c.choice_id = n.merged_choice_id
WHERE a.answer LIKE '%kutaw%'
   OR a.answer LIKE '%figaro%'
   OR a.answer LIKE '%hunger%'
   OR a.answer LIKE '%bigby%'
ORDER BY a.answer, n.nomination_id;

-- Reject Kutaw, Figaro, Hunger Wings (new businesses, wrongly approved)
UPDATE tbl_nominations n
INNER JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id
INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
  AND f.name IN ('business_name','official_business_name','company','company_name','business')
SET n.status = 'rejected', n.updated_at = NOW()
WHERE n.status IN ('approved','merged','pending','in_review','needs_info','submitted')
  AND (
        a.answer LIKE '%kutaw%'
     OR a.answer LIKE '%figaro%'
     OR a.answer LIKE '%hunger%'
  );

-- Deactivate their business records if no other approved/merged registration still points at them
UPDATE tbl_choices c
LEFT JOIN tbl_nominations n
  ON n.merged_choice_id = c.choice_id
 AND n.status IN ('approved','merged')
SET c.status = 0, c.on_ballot = 0
WHERE n.nomination_id IS NULL
  AND (
        c.choice_name LIKE '%kutaw%'
     OR c.choice_name LIKE '%figaro%'
     OR c.choice_name LIKE '%hunger%'
  );

UPDATE tbl_question_choices qc
INNER JOIN tbl_choices c ON c.choice_id = qc.choice_id
SET qc.on_ballot = 0
WHERE c.status = 0
  AND (
        c.choice_name LIKE '%kutaw%'
     OR c.choice_name LIKE '%figaro%'
     OR c.choice_name LIKE '%hunger%'
  );

-- Bigbys: rejected -> approved. Reactivate existing choice if present.
-- If Bigbys was never approved before (no merged_choice_id), do not use SQL:
-- open the registration -> Reopen -> Proceed to evaluation.
UPDATE tbl_nominations n
INNER JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id
INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
  AND f.name IN ('business_name','official_business_name','company','company_name','business')
SET n.status = 'approved', n.updated_at = NOW()
WHERE n.status = 'rejected'
  AND n.merged_choice_id IS NOT NULL
  AND n.merged_choice_id > 0
  AND a.answer LIKE '%bigby%';

UPDATE tbl_choices c
INNER JOIN tbl_nominations n ON n.merged_choice_id = c.choice_id
INNER JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id
INNER JOIN tbl_nomination_fields f ON f.id = a.field_id
  AND f.name IN ('business_name','official_business_name','company','company_name','business')
SET c.status = 1
WHERE n.status = 'approved'
  AND a.answer LIKE '%bigby%';
