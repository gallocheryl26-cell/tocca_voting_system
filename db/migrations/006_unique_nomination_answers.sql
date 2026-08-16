-- One answer per field per registration.
-- Without this unique key, save/update INSERTs extra rows instead of updating.

DELETE a FROM tbl_nomination_answers a
INNER JOIN tbl_nomination_answers b
  ON a.nomination_id = b.nomination_id
 AND a.field_id = b.field_id
 AND a.id < b.id;

ALTER TABLE tbl_nomination_answers
  ADD UNIQUE KEY uq_nom_answers_field (nomination_id, field_id);
